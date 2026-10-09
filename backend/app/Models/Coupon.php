<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    protected $connection = 'mysql';
    protected $fillable = ['code', 'description', 'active', 'discount_type', 'discount_value', 'minimum_order_cop', 'max_uses', 'used_count', 'starts_at', 'ends_at', 'revision'];
    protected function casts(): array { return ['active' => 'boolean', 'discount_value' => 'integer', 'minimum_order_cop' => 'integer', 'max_uses' => 'integer', 'used_count' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'revision' => 'integer']; }
    public function orderCoupons(): HasMany { return $this->hasMany(OrderCoupon::class); }
    public function reservations(): HasMany { return $this->hasMany(CouponReservation::class); }
}
