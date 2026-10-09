<?php

namespace App\Services;

use App\Exceptions\CheckoutPaymentEventException;
use App\Exceptions\CheckoutReservationPlanningException;
use App\Models\CheckoutReservation;
use App\Models\CheckoutReservationItem;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponReservation;
use App\Models\Order;
use App\Models\OrderCoupon;
use App\Models\OrderItem;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\Subcategory;
use App\Repositories\MySqlProductPromotionRepository;
use App\Promotions\ProductPromotionPriceResolver;
use App\Promotions\PromotionContractException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SQL source of truth for checkout stock, reservations and payment state.
 * Products, promotions, reservations and coupons are persisted atomically in MySQL.
 */
final class SqlCheckoutService
{
    private const RESERVATION_MINUTES = 10;
    private const RELEASE_GRACE_MINUTES = 10;

    public function __construct(
        private readonly CheckoutPayloadCanonicalizer $canonicalizer,
        private readonly CheckoutReferenceGenerator $references,
        private readonly MySqlProductPromotionRepository $promotions,
        private readonly ProductPromotionPriceResolver $prices,
        private readonly CheckoutPaymentEventNormalizer $paymentEvents,
        private readonly SqlCouponPlanner $coupons,
        private readonly OrderNotificationOutboxStore $notifications,
    ) {}

    /** @param array{customer:array<string,string|null>,items:list<array{id:int|string,qty:int|string>,coupon_code?:string|null} $checkout */
    public function prepare(array $checkout, string $idempotencyKey): array
    {
        $canonical = $this->canonicalizer->canonicalize($checkout['customer'], $checkout['items'], $checkout['coupon_code'] ?? null);
        $idempotencyHash = hash('sha256', $idempotencyKey);
        try {
            return DB::connection('mysql')->transaction(function () use ($checkout, $canonical, $idempotencyHash): array {
                $existing = Order::query()->where('idempotency_key_hash', $idempotencyHash)->lockForUpdate()->first();
                if ($existing !== null) return $this->replay($existing, $canonical['payload_hash']);

                // Coupon first, then products ascending: a stable lock order for
                // the two limited resources participating in a checkout.
                $this->coupons->lock($canonical['coupon_code']);
                $ids = array_column($canonical['items'], 'product_id');
                $products = Product::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $categoryIds = $products->pluck('category_id')->filter()->unique()->sort()->values()->all();
                $subcategoryIds = $products->pluck('subcategory_id')->filter()->unique()->sort()->values()->all();
                $categories = Category::query()->whereIn('id', $categoryIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $subcategories = Subcategory::query()->whereIn('id', $subcategoryIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $promotions = $this->promotions->byProductIds($ids);
                $now = now('UTC');
                $lines = [];
                $total = 0;
                $eligibleSubtotal = 0;
                foreach ($canonical['items'] as $item) {
                    $product = $products->get($item['product_id']);
                    if ($product === null) throw new CheckoutReservationPlanningException('PRODUCT_NOT_FOUND');
                    if (! $product->active) throw new CheckoutReservationPlanningException('PRODUCT_INACTIVE');
                    $category = $product->category_id === null ? null : $categories->get($product->category_id);
                    if ($product->category_id !== null && ($category === null || ! $category->active)) {
                        throw new CheckoutReservationPlanningException('PRODUCT_INACTIVE');
                    }
                    $subcategory = $product->subcategory_id === null ? null : $subcategories->get($product->subcategory_id);
                    if ($product->subcategory_id !== null && ($subcategory === null || ! $subcategory->active || ($product->category_id !== null && $subcategory->category_id !== $product->category_id))) {
                        throw new CheckoutReservationPlanningException('PRODUCT_INACTIVE');
                    }
                    if ($product->price_cop < 1) throw new CheckoutReservationPlanningException('INVALID_PRODUCT_PRICE');
                    if ($product->inventory < $item['quantity']) throw new CheckoutReservationPlanningException('INSUFFICIENT_STOCK');
                    $pricing = $this->effectivePrice($product->price_cop, $promotions[$product->id] ?? null, $now);
                    $subtotal = $pricing['effective_price_cop'] * $item['quantity'];
                    if ($subtotal > PHP_INT_MAX - $total) throw new CheckoutReservationPlanningException('INVALID_REQUEST');
                    $total += $subtotal;
                    if (! $pricing['has_active_promotion']) $eligibleSubtotal += $subtotal;
                    $lines[] = compact('product', 'pricing', 'subtotal') + ['quantity' => $item['quantity']];
                }

                // A temporary reference is unobservable: it is replaced before
                // the transaction commits once AUTO_INCREMENT has assigned id.
                $order = Order::query()->create([
                    'reference' => 'RESERVATION-'.Str::uuid(),
                    'status' => 'RESERVATION_PREPARING',
                    'payment_status' => 'PENDING',
                    'reservation_status' => 'ACTIVE',
                    'reservation_expires_at' => $now->copy()->addMinutes(self::RESERVATION_MINUTES),
                    'idempotency_key_hash' => $idempotencyHash,
                    'checkout_payload_hash' => $canonical['payload_hash'],
                    'customer_name' => $checkout['customer']['name'],
                    'customer_email' => $checkout['customer']['email'],
                    'customer_phone' => $checkout['customer']['phone'],
                    'customer_document' => $checkout['customer']['document'],
                    'address' => $checkout['customer']['address'],
                    'extra' => $checkout['customer']['extra'],
                    'city' => $checkout['customer']['city'],
                    'region' => $checkout['customer']['region'],
                    'postal' => $checkout['customer']['postal'],
                    'total_cop' => $total,
                    'revision' => 1,
                ]);
                $order->reference = $this->references->generate($order->id, $now);
                $coupon = $this->coupons->evaluateLocked($canonical['coupon_code'], $eligibleSubtotal, $now);
                if ($coupon !== null) {
                    $total -= $coupon['discount_cop'];
                    if ($total < 0) throw new CheckoutReservationPlanningException('INVALID_REQUEST');
                }
                $order->total_cop = $total;
                $order->status = 'PENDING';
                $order->save();

                $reservation = CheckoutReservation::query()->create([
                    'order_id' => $order->id,
                    'state' => 'ACTIVE',
                    'expires_at' => $order->reservation_expires_at,
                ]);
                if ($coupon !== null) {
                    OrderCoupon::query()->create([
                        'order_id' => $order->id, 'coupon_id' => $coupon['coupon']['coupon_id'],
                        'coupon_code' => $coupon['coupon']['code'], 'discount_type' => $coupon['coupon']['discount_type'],
                        'discount_value' => $coupon['coupon']['discount_value'], 'coupon_discount_cop' => $coupon['discount_cop'],
                        'eligible_subtotal_cop' => $eligibleSubtotal, 'status' => 'APPLIED', 'revision' => 1,
                    ]);
                    CouponReservation::query()->create([
                        'order_id' => $order->id, 'coupon_id' => $coupon['coupon']['coupon_id'],
                        'coupon_code' => $coupon['coupon']['code'], 'state' => 'RESERVED',
                        'reservation_expires_at' => $order->reservation_expires_at, 'revision' => 1,
                    ]);
                }
                foreach ($lines as $line) {
                    /** @var Product $product */
                    $product = $line['product'];
                    $before = $product->inventory;
                    $revisionBefore = $product->revision;
                    $product->inventory = $before - $line['quantity'];
                    $product->revision = $revisionBefore + 1;
                    $product->save();
                    OrderItem::query()->create([
                        'order_id' => $order->id, 'product_id' => $product->id,
                        'product_name' => $product->name, 'unit_price_cop' => $line['pricing']['effective_price_cop'],
                        'quantity' => $line['quantity'], 'subtotal_cop' => $line['subtotal'],
                    ]);
                    CheckoutReservationItem::query()->create([
                        'checkout_reservation_id' => $reservation->id, 'product_id' => $product->id,
                        'quantity' => $line['quantity'], 'inventory_before' => $before,
                        'inventory_after' => $product->inventory, 'product_revision_before' => $revisionBefore,
                        'product_revision_after' => $product->revision,
                    ]);
                }
                return $this->prepared($order, false);
            }, 3);
        } catch (\Throwable $exception) {
            // Coupon reservations are persisted transactionally with checkout.
            // release it on every failed SQL prepare; release() is idempotent.
            if (! $exception instanceof QueryException) throw $exception;
            // Concurrent inserts of a new key race at the database UNIQUE
            // constraint.  Read the winner and apply the normal replay rules.
            $existing = Order::query()->where('idempotency_key_hash', $idempotencyHash)->first();
            if ($existing !== null) return $this->replay($existing, $canonical['payload_hash']);
            throw $exception;
        }
    }

    /** @return array{ok:bool,data?:array<string,mixed>,error?:array{code:string}} */
    public function recordPaymentEvent(mixed $request): array
    {
        try { $event = $this->paymentEvents->normalize($request); }
        catch (CheckoutPaymentEventException $exception) { return $this->paymentError($exception->paymentCode); }

        try {
            $result = DB::connection('mysql')->transaction(function () use ($event): array {
                $order = Order::query()->where('reference', $event['reference'])->lockForUpdate()->first();
                if ($order === null) throw new CheckoutPaymentEventException('ORDER_NOT_FOUND');
                if ($order->total_cop * 100 !== $event['amount_in_cents']) throw new CheckoutPaymentEventException('AMOUNT_MISMATCH');
                $paymentWasApproved = $order->payment_status === 'APPROVED';
                $payment = PaymentAttempt::query()->where('wompi_transaction_id', $event['id'])->lockForUpdate()->first();
                if ($payment !== null && ($payment->order_id !== $order->id || $payment->amount_in_cents !== $event['amount_in_cents'] || $payment->currency !== 'COP')) throw new CheckoutPaymentEventException('TRANSACTION_CONFLICT');
                $occurred = \Carbon\CarbonImmutable::parse($event['event_occurred_at'])->utc();
                $replayed = $payment !== null;
                if ($payment === null) {
                    $payment = PaymentAttempt::query()->create(['order_id' => $order->id, 'wompi_transaction_id' => $event['id'], 'status' => $event['status'], 'payment_method' => $event['payment_method'], 'amount_in_cents' => $event['amount_in_cents'], 'currency' => 'COP']);
                    $payment->updated_at = $occurred;
                    $payment->save();
                } elseif ($payment->updated_at === null || $occurred->greaterThan($payment->updated_at)) {
                    // An approved payment is terminal for stock; a later stale
                    // decline must never undo its recorded approval.
                    if (! ($payment->status === 'APPROVED' && $event['status'] !== 'APPROVED')) $payment->status = $event['status'];
                    $payment->payment_method = $event['payment_method'];
                    $payment->updated_at = $occurred;
                    $payment->save();
                }

                $result = 'RECORDED';
                if ($event['status'] === 'APPROVED') {
                    $reservation = CheckoutReservation::query()->where('order_id', $order->id)->lockForUpdate()->first();
                    if ($reservation === null) throw new CheckoutPaymentEventException('CONSISTENCY_UNCERTAIN');
                    $couponReservation = CouponReservation::query()->where('order_id', $order->id)->lockForUpdate()->first();
                    if ($order->status === 'PAYMENT_REVIEW_REQUIRED' && $order->payment_status === 'APPROVED' && $reservation->state === 'ACTIVE') {
                        $result = 'PAYMENT_REVIEW_REQUIRED';
                    } elseif ($reservation->state === 'ACTIVE' && $couponReservation?->state === 'RELEASED') {
                        $order->status = 'PAYMENT_REVIEW_REQUIRED'; $order->payment_status = 'APPROVED'; $order->reservation_status = 'ACTIVE'; $order->paid_at = $occurred; $order->payment_last_event_at = $occurred; $order->revision++;
                        $result = 'PAYMENT_REVIEW_REQUIRED';
                    } elseif ($reservation->state === 'ACTIVE') {
                        if ($couponReservation?->state === 'RESERVED') {
                            $coupon = Coupon::query()->whereKey($couponReservation->coupon_id)->lockForUpdate()->first();
                            if ($coupon === null) throw new CheckoutPaymentEventException('CONSISTENCY_UNCERTAIN');
                            $couponReservation->state = 'CONSUMED'; $couponReservation->consumed_at = now('UTC'); $couponReservation->revision++; $couponReservation->save();
                            $coupon->used_count++; $coupon->revision++; $coupon->save();
                        }
                        $reservation->state = 'CONSUMED'; $reservation->consumed_at = now('UTC'); $reservation->save();
                        $order->payment_status = 'APPROVED'; $order->reservation_status = 'CONSUMED'; $order->paid_at = $occurred; $order->payment_last_event_at = $occurred; $order->revision++;
                        $result = 'APPROVED';
                    } elseif ($reservation->state === 'RELEASED') {
                        $order->status = 'PAYMENT_REVIEW_REQUIRED'; $order->payment_status = 'APPROVED'; $order->reservation_status = 'RELEASED'; $order->paid_at ??= $occurred; $order->payment_last_event_at = $occurred; $order->revision++;
                        $result = 'PAYMENT_REVIEW_REQUIRED';
                    } elseif ($reservation->state === 'CONSUMED') {
                        $result = 'APPROVED';
                    } else throw new CheckoutPaymentEventException('CONSISTENCY_UNCERTAIN');
                    $order->save();
                    if (! $paymentWasApproved && $order->payment_status === 'APPROVED') {
                        $this->enqueueOrderCreatedNotifications($order);
                    }
                } elseif ($order->payment_last_event_at === null || $occurred->greaterThan($order->payment_last_event_at)) {
                    $order->payment_last_event_at = $occurred; $order->revision++; $order->save();
                }
                return ['ok' => true, 'data' => ['order_id' => $order->id, 'payment_attempt_id' => $payment->id, 'payment_event_replayed' => $replayed, 'event_result' => $result, 'status' => $order->status, 'payment_status' => $order->payment_status, 'reservation_status' => $order->reservation_status, 'revision' => $order->revision]];
            }, 3);
            return $result;
        } catch (CheckoutPaymentEventException $exception) { return $this->paymentError($exception->paymentCode); }
        catch (\Throwable) { return $this->paymentError('INTERNAL_ERROR'); }
    }

    /** @return list<array<string,mixed>> */
    public function expiredCandidates(int $limit = 20): array
    {
        if ($limit < 1 || $limit > 50) throw new \InvalidArgumentException('Release candidate limit must be between 1 and 50.');
        $cutoff = now('UTC')->subMinutes(self::RELEASE_GRACE_MINUTES);
        return CheckoutReservation::query()->with(['order.payments'])->where('state', 'ACTIVE')->where('expires_at', '<=', $cutoff)->orderBy('expires_at')->limit($limit)->get()->filter(fn (CheckoutReservation $reservation) => $reservation->order !== null && $reservation->order->status === 'PENDING' && $reservation->order->payment_status === 'PENDING' && ! $reservation->order->payments->contains('status', 'APPROVED'))->map(function (CheckoutReservation $reservation): array {
            $order = $reservation->order;
            return ['order_id' => $order->id, 'reference' => $order->reference, 'revision' => $order->revision, 'reservation_expires_at' => $this->iso($reservation->expires_at), 'total_cop' => $order->total_cop, 'payment_attempts' => $order->payments->map(fn (PaymentAttempt $payment): array => ['wompi_transaction_id' => $payment->wompi_transaction_id, 'status' => $payment->status, 'amount_in_cents' => $payment->amount_in_cents, 'currency' => $payment->currency, 'updated_at' => $this->iso($payment->updated_at)])->values()->all()];
        })->values()->all();
    }

    /** @param list<array{wompi_transaction_id:string,status:string,checked_at:string}> $verified */
    public function release(string $reference, int $expectedRevision, array $verified): array
    {
        $outcome = DB::connection('mysql')->transaction(function () use ($reference, $expectedRevision, $verified): array {
            $order = Order::query()->where('reference', $reference)->lockForUpdate()->first();
            if ($order === null) throw new \RuntimeException('ORDER_NOT_FOUND');
            $reservation = CheckoutReservation::query()->where('order_id', $order->id)->lockForUpdate()->first();
            if ($reservation === null) throw new CheckoutPaymentEventException('CONSISTENCY_UNCERTAIN');
            if ($reservation->state === 'RELEASED') return ['result' => 'REPLAY', 'reference' => $reference, 'revision' => $order->revision, 'order_id' => $order->id];
            if ($order->revision !== $expectedRevision || $reservation->state !== 'ACTIVE' || $order->status !== 'PENDING' || $order->payment_status !== 'PENDING' || $reservation->expires_at->greaterThan(now('UTC'))) return ['result' => 'HELD', 'reference' => $reference];
            $payments = PaymentAttempt::query()->where('order_id', $order->id)->lockForUpdate()->get()->keyBy('wompi_transaction_id');
            if ($payments->contains('status', 'APPROVED')) return ['result' => 'PAYMENT_APPROVED', 'reference' => $reference];
            foreach ($verified as $attempt) {
                $payment = $payments->get($attempt['wompi_transaction_id']);
                if ($payment === null || ! in_array($attempt['status'], ['DECLINED', 'VOIDED', 'ERROR'], true)) return ['result' => 'HELD', 'reference' => $reference];
                $payment->status = $attempt['status']; $payment->updated_at = \Carbon\CarbonImmutable::parse($attempt['checked_at'])->utc(); $payment->save();
            }
            $items = CheckoutReservationItem::query()->where('checkout_reservation_id', $reservation->id)->orderBy('product_id')->lockForUpdate()->get();
            $products = Product::query()->whereIn('id', $items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($items as $item) {
                $product = $products->get($item->product_id);
                if ($product === null) throw new CheckoutPaymentEventException('CONSISTENCY_UNCERTAIN');
                $product->inventory += $item->quantity; $product->revision++; $product->save();
            }
            $now = now('UTC');
            $couponReservation = CouponReservation::query()->where('order_id', $order->id)->lockForUpdate()->first();
            if ($couponReservation?->state === 'RESERVED') {
                $couponReservation->state = 'RELEASED'; $couponReservation->released_at = $now; $couponReservation->revision++; $couponReservation->save();
            }
            $reservation->state = 'RELEASED'; $reservation->released_at = $now; $reservation->save();
            $order->reservation_status = 'RELEASED'; $order->released_at = $now; $order->release_id = (string) Str::uuid(); $order->release_fingerprint = hash('sha256', json_encode([$order->id, $reference, $expectedRevision, $verified], JSON_THROW_ON_ERROR)); $order->revision++; $order->save();
            return ['result' => 'RELEASED', 'reference' => $reference, 'revision' => $order->revision, 'order_id' => $order->id];
        }, 3);
        return $outcome;
    }

    /** @return array<string,mixed>|null */
    public function adminOrder(int $orderId): ?array
    {
        $order = Order::query()->with(['items', 'payments'])->find($orderId);
        if ($order === null) return null;
        return ['id' => $order->id, 'reference' => $order->reference, 'status' => $order->status, 'payment_status' => $order->payment_status, 'reservation_status' => $order->reservation_status, 'payment_flow_status' => $order->paymentFlowStatus(), 'paid_at' => $this->iso($order->paid_at), 'payment' => null, 'customer_name' => $order->customer_name, 'customer_email' => $order->customer_email, 'customer_phone' => $order->customer_phone, 'customer_document' => $order->customer_document, 'address' => $order->address, 'extra' => $order->extra, 'city' => $order->city, 'region' => $order->region, 'postal' => $order->postal, 'total' => $order->total_cop, 'created_at' => $this->iso($order->created_at), 'items' => $order->items->map(fn (OrderItem $item): array => ['id' => $item->id, 'product_id' => $item->product_id, 'product_name' => $item->product_name, 'unit_price' => $item->unit_price_cop, 'quantity' => $item->quantity])->all()];
    }

    /** @return array{ok:bool,data?:array<string,mixed>,error?:array{code:string}} */
    public function updateAdminStatus(int $orderId, string $target): array
    {
        $allowed = ['PENDING', 'PROCESSING', 'READY', 'SHIPPED', 'DELIVERED'];
        if ($orderId < 1 || ! in_array($target, $allowed, true)) return ['ok' => false, 'error' => ['code' => 'INVALID_REQUEST']];
        return DB::connection('mysql')->transaction(function () use ($orderId, $target): array {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
            if ($order === null) return ['ok' => false, 'error' => ['code' => 'ORDER_NOT_FOUND']];
            if ($order->status === $target) return ['ok' => true, 'data' => ['order_id' => $order->id, 'status' => $order->status, 'updated_at' => $this->iso($order->updated_at), 'revision' => $order->revision, 'idempotency_replayed' => true]];
            $flows = ['PENDING' => ['PROCESSING'], 'PROCESSING' => ['READY'], 'READY' => ['SHIPPED', 'DELIVERED'], 'SHIPPED' => ['DELIVERED']];
            if ($order->status === 'DELIVERED' || $order->status === 'CANCELLED' || ! in_array($target, $flows[$order->status] ?? [], true)) return ['ok' => false, 'error' => ['code' => 'INVALID_STATUS_TRANSITION']];
            if ($order->status === 'PENDING' && $order->payment_status !== 'APPROVED') return ['ok' => false, 'error' => ['code' => 'PAYMENT_NOT_APPROVED']];
            $order->status = $target; $order->revision++; $order->save();
            $this->enqueueStatusNotification($order, $target);
            return ['ok' => true, 'data' => ['order_id' => $order->id, 'status' => $order->status, 'updated_at' => $this->iso($order->updated_at), 'revision' => $order->revision, 'idempotency_replayed' => false]];
        }, 3);
    }

    private function replay(Order $order, string $payloadHash): array
    {
        if (! hash_equals($order->checkout_payload_hash, $payloadHash)) throw new CheckoutReservationPlanningException('IDEMPOTENCY_CONFLICT');
        if ($order->reservation_status !== 'ACTIVE' || $order->reservation_expires_at === null || $order->reservation_expires_at->lessThanOrEqualTo(now('UTC'))) throw new CheckoutReservationPlanningException('RESERVATION_EXPIRED');
        if ($order->status !== 'PENDING' || $order->payment_status !== 'PENDING') throw new CheckoutReservationPlanningException('RESERVATION_EXPIRED');
        return $this->prepared($order, true);
    }

    private function prepared(Order $order, bool $replayed): array
    {
        return ['order_id' => $order->id, 'reference' => $order->reference, 'status' => $order->status, 'payment_status' => $order->payment_status, 'reservation_status' => $order->reservation_status, 'reservation_expires_at' => $this->iso($order->reservation_expires_at), 'total_cop' => $order->total_cop, 'created_at' => $this->iso($order->created_at), 'revision' => $order->revision, 'idempotency_replayed' => $replayed];
    }

    private function effectivePrice(int $basePrice, ?array $promotion, \DateTimeInterface $now): array
    {
        try {
            if ($promotion !== null) $this->prices->resolve($basePrice, array_replace($promotion, ['active' => true, 'starts_at' => null, 'ends_at' => null]), $now);
            return $this->prices->resolve($basePrice, $promotion, $now);
        } catch (PromotionContractException) { throw new CheckoutReservationPlanningException('INVALID_PROMOTION_CONTRACT'); }
    }

    private function enqueueOrderCreatedNotifications(Order $order): void
    {
        $items = OrderItem::query()->where('order_id', $order->id)->orderBy('id')->get()->map(static fn (OrderItem $item): array => [
            'product_id' => $item->product_id, 'product_name' => $item->product_name,
            'unit_price_cop' => $item->unit_price_cop, 'quantity' => $item->quantity,
        ])->all();
        $payload = $this->notificationPayload($order, $items);
        $this->notifications->enqueue([...$payload, 'notification_type' => 'PEDIDO_CREADO', 'recipient_kind' => 'customer']);
        $this->notifications->enqueue([...$payload, 'notification_type' => 'PEDIDO_CREADO', 'recipient_kind' => 'admin']);
    }

    private function enqueueStatusNotification(Order $order, string $status): void
    {
        $type = match ($status) {
            'PROCESSING' => 'EN_PREPARACION',
            'SHIPPED' => 'EN_CAMINO',
            'DELIVERED' => 'ENTREGADO',
            default => null,
        };
        if ($type === null) return;
        $items = OrderItem::query()->where('order_id', $order->id)->orderBy('id')->get()->map(static fn (OrderItem $item): array => [
            'product_id' => $item->product_id, 'product_name' => $item->product_name,
            'unit_price_cop' => $item->unit_price_cop, 'quantity' => $item->quantity,
        ])->all();
        $this->notifications->enqueue([...$this->notificationPayload($order, $items), 'notification_type' => $type, 'recipient_kind' => 'customer']);
    }

    /** @param list<array<string,int|string>> $items @return array<string,mixed> */
    private function notificationPayload(Order $order, array $items): array
    {
        return ['order_id' => $order->id, 'reference' => $order->reference, 'customer_name' => $order->customer_name,
            'customer_email' => $order->customer_email, 'customer_phone' => $order->customer_phone,
            'address' => $order->address, 'extra' => $order->extra, 'city' => $order->city,
            'total_cop' => $order->total_cop, 'status' => $order->status,
            'payment_status' => $order->payment_status, 'reservation_status' => $order->reservation_status,
            'items' => $items];
    }

    private function iso(?\DateTimeInterface $time): ?string { return $time === null ? null : \DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.v\\Z'); }
    private function paymentError(string $code): array { return ['ok' => false, 'error' => ['code' => $code]]; }
}
