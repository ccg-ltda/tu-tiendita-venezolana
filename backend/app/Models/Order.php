<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $connection = 'mysql';
    protected $fillable = ['reference', 'status', 'payment_status', 'reservation_status', 'reservation_expires_at', 'paid_at', 'payment_last_event_at', 'idempotency_key_hash', 'checkout_payload_hash', 'release_id', 'release_fingerprint', 'released_at', 'customer_name', 'customer_email', 'customer_phone', 'customer_document', 'address', 'extra', 'city', 'region', 'postal', 'total_cop', 'revision'];
    protected $hidden = ['idempotency_key_hash', 'checkout_payload_hash', 'release_fingerprint'];
    protected function casts(): array { return ['reservation_expires_at' => 'datetime', 'paid_at' => 'datetime', 'payment_last_event_at' => 'datetime', 'released_at' => 'datetime', 'total_cop' => 'integer', 'revision' => 'integer']; }
    public function items(): HasMany { return $this->hasMany(OrderItem::class); }
    public function payments(): HasMany { return $this->hasMany(PaymentAttempt::class); }
    public function orderCoupon(): HasOne { return $this->hasOne(OrderCoupon::class); }
    public function couponReservation(): HasOne { return $this->hasOne(CouponReservation::class); }
    public function checkoutReservation(): HasOne { return $this->hasOne(CheckoutReservation::class); }
    public function notifications(): HasMany { return $this->hasMany(NotificationOutbox::class); }

    public function paymentFlowStatus(): string
    {
        if ($this->status === 'PENDING' && $this->payment_status !== 'APPROVED' && $this->reservation_status === 'RELEASED') {
            return 'PAYMENT_NOT_COMPLETED';
        }

        return $this->payment_status === 'APPROVED' || $this->status !== 'PENDING'
            ? 'OPERATIONAL'
            : 'AWAITING_PAYMENT';
    }
}
