<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderCoupon extends Model
{
    protected $connection = 'mysql';
    protected $fillable = ['order_id', 'coupon_id', 'coupon_code', 'discount_type', 'discount_value', 'coupon_discount_cop', 'eligible_subtotal_cop', 'status', 'revision'];
    protected function casts(): array { return ['order_id' => 'integer', 'coupon_id' => 'integer', 'discount_value' => 'integer', 'coupon_discount_cop' => 'integer', 'eligible_subtotal_cop' => 'integer', 'revision' => 'integer']; }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function coupon(): BelongsTo { return $this->belongsTo(Coupon::class); }
}
