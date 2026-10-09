<?php

namespace Tests\Unit\Services;

use App\Coupons\CouponDiscountResolver;
use App\Exceptions\CheckoutReservationPlanningException;
use App\Models\Coupon;
use App\Models\CouponReservation;
use App\Promotions\ProductPromotionPriceResolver;
use App\Repositories\MySqlProductRepository;
use App\Repositories\MySqlProductPromotionRepository;
use App\Repositories\MySqlCouponRepository;
use App\Services\CheckoutCouponPreviewService;
use App\Services\SqlCouponPlanner;
use App\Services\CheckoutPayloadCanonicalizer;
use DateTimeImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CheckoutCouponPreviewServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.mysql', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('mysql');
        Schema::connection('mysql')->create('coupons', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary(); $table->string('code')->unique(); $table->text('description')->nullable();
            $table->boolean('active'); $table->string('discount_type'); $table->unsignedInteger('discount_value');
            $table->unsignedInteger('minimum_order_cop')->nullable(); $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0); $table->dateTime('starts_at')->nullable(); $table->dateTime('ends_at')->nullable();
            $table->unsignedInteger('revision')->default(1); $table->timestamps();
        });
        Schema::connection('mysql')->create('coupon_reservations', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('order_id')->unique(); $table->unsignedBigInteger('coupon_id');
            $table->string('coupon_code'); $table->string('state'); $table->dateTime('reservation_expires_at');
            $table->dateTime('consumed_at')->nullable(); $table->dateTime('released_at')->nullable(); $table->unsignedInteger('revision')->default(1); $table->timestamps();
        });
    }

    public function test_percent_coupon_excludes_promotional_line_from_eligible_subtotal(): void
    {
        [$service] = $this->service([$this->coupon()], [$this->promotion()]);
        $preview = $service->preview(' bienvenida10 ', [['id' => 1, 'qty' => 1], ['id' => 2, 'qty' => 1]], $this->now());
        $this->assertSame(29000, $preview['subtotal_cop']);
        $this->assertSame(20000, $preview['eligible_subtotal_cop']);
        $this->assertSame(2000, $preview['coupon_discount_cop']);
        $this->assertSame(27000, $preview['total_cop']);
        $this->assertSame(9000, $preview['excluded_promotional_subtotal_cop']);
    }

    public function test_fixed_coupon_is_limited_to_eligible_subtotal(): void
    {
        [$service] = $this->service([$this->coupon(['discount_type' => 'fixed', 'discount_value' => 50000])]);
        $preview = $service->preview('BIENVENIDA10', [['id' => 1, 'qty' => 1]], $this->now());
        $this->assertSame(20000, $preview['coupon_discount_cop']);
        $this->assertSame(0, $preview['total_cop']);
    }

    #[DataProvider('rejectedCoupons')]
    public function test_coupon_errors_are_controlled(array $coupon, string $code): void
    {
        [$service] = $this->service($coupon === [] ? [] : [$coupon]);
        try { $service->preview('BIENVENIDA10', [['id' => 1, 'qty' => 1]], $this->now()); $this->fail('Expected preview error.'); }
        catch (CheckoutReservationPlanningException $exception) { $this->assertSame($code, $exception->checkoutCode()); }
    }

    public static function rejectedCoupons(): array
    {
        $base = ['coupon_id'=>1,'code'=>'BIENVENIDA10','description'=>null,'active'=>true,'discount_type'=>'percent','discount_value'=>10,'minimum_order_cop'=>null,'max_uses'=>null,'used_count'=>0,'starts_at'=>null,'ends_at'=>null,'created_at'=>'2026-01-01T00:00:00.000Z','updated_at'=>'2026-01-01T00:00:00.000Z','revision'=>1];
        return [
            'not found' => [[], 'COUPON_NOT_FOUND'], 'inactive' => [array_replace($base, ['active'=>false]), 'COUPON_INACTIVE'],
            'scheduled' => [array_replace($base, ['starts_at'=>'2099-01-01T00:00:00.000-05:00']), 'COUPON_SCHEDULED'],
            'expired' => [array_replace($base, ['ends_at'=>'2020-01-01T00:00:00.000-05:00']), 'COUPON_EXPIRED'],
            'exhausted' => [array_replace($base, ['max_uses'=>1,'used_count'=>1]), 'COUPON_EXHAUSTED'],
            'minimum' => [array_replace($base, ['minimum_order_cop'=>20001]), 'COUPON_MINIMUM_NOT_MET'],
        ];
    }

    public function test_preview_with_only_promotional_lines_is_ineligible_and_never_writes(): void
    {
        [$service] = $this->service([$this->coupon()], [$this->promotion()]);
        try { $service->preview('BIENVENIDA10', [['id' => 2, 'qty' => 1]], $this->now()); $this->fail('Expected preview error.'); }
        catch (CheckoutReservationPlanningException $exception) { $this->assertSame('COUPON_NO_ELIGIBLE_ITEMS', $exception->checkoutCode()); }
        $this->assertSame(0, CouponReservation::query()->count());
        $this->assertSame(0, Coupon::query()->firstOrFail()->used_count);
    }

    public function test_preview_rejects_a_product_with_an_inactive_classification(): void
    {
        [$service] = $this->service([$this->coupon()], [], ['category_active' => false]);

        try {
            $service->preview('BIENVENIDA10', [['id' => 1, 'qty' => 1]], $this->now());
            $this->fail('Expected preview error.');
        } catch (CheckoutReservationPlanningException $exception) {
            $this->assertSame('PRODUCT_INACTIVE', $exception->checkoutCode());
        }
    }

    private function service(array $coupons, array $promotions = [], array $firstProductChanges = []): array
    {
        foreach ($coupons as $coupon) {
            $model = new Coupon;
            $model->id = $coupon['coupon_id'];
            $model->forceFill([
                'code' => $coupon['code'], 'description' => $coupon['description'], 'active' => $coupon['active'],
                'discount_type' => $coupon['discount_type'], 'discount_value' => $coupon['discount_value'],
                'minimum_order_cop' => $coupon['minimum_order_cop'], 'max_uses' => $coupon['max_uses'],
                'used_count' => $coupon['used_count'], 'starts_at' => $coupon['starts_at'], 'ends_at' => $coupon['ends_at'], 'revision' => $coupon['revision'],
            ]);
            $model->save();
        }
        $planner = new SqlCouponPlanner(new MySqlCouponRepository, new CouponDiscountResolver);
        $products = \Mockery::mock(MySqlProductRepository::class);
        $products->shouldReceive('all')->andReturn([array_replace(['product_id'=>1,'price_cop'=>20000,'inventory'=>9,'active'=>true], $firstProductChanges),['product_id'=>2,'price_cop'=>10000,'inventory'=>9,'active'=>true]]);
        $promotionStore = \Mockery::mock(MySqlProductPromotionRepository::class);
        $promotionStore->shouldReceive('byProductIds')->andReturn(collect($promotions)->keyBy('product_id')->all());
        return [new CheckoutCouponPreviewService($products, $promotionStore, new ProductPromotionPriceResolver, new CheckoutPayloadCanonicalizer, $planner)];
    }
    private function now(): DateTimeImmutable { return new DateTimeImmutable('2026-10-06T12:00:00.000Z'); }
    private function coupon(array $changes = []): array { return array_replace(['coupon_id'=>1,'code'=>'BIENVENIDA10','description'=>null,'active'=>true,'discount_type'=>'percent','discount_value'=>10,'minimum_order_cop'=>null,'max_uses'=>null,'used_count'=>0,'starts_at'=>null,'ends_at'=>null,'created_at'=>'2026-01-01T00:00:00.000Z','updated_at'=>'2026-01-01T00:00:00.000Z','revision'=>1], $changes); }
    private function promotion(): array { return ['product_id'=>2,'active'=>true,'discount_type'=>'percent','discount_value'=>10,'starts_at'=>null,'ends_at'=>null,'updated_at'=>'2026-01-01T00:00:00.000Z','revision'=>1]; }
}
