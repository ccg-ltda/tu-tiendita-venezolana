<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponReservation extends Model
{
    protected $connection = 'mysql';
    protected $fillable = ['order_id', 'coupon_id', 'coupon_code', 'state', 'reservation_expires_at', 'consumed_at', 'released_at', 'revision'];
    protected function casts(): array { return ['order_id' => 'integer', 'coupon_id' => 'integer', 'reservation_expires_at' => 'datetime', 'consumed_at' => 'datetime', 'released_at' => 'datetime', 'revision' => 'integer']; }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function coupon(): BelongsTo { return $this->belongsTo(Coupon::class); }
}
