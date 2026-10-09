<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CheckoutReservation extends Model
{
    protected $connection = 'mysql';
    protected $fillable = ['order_id', 'state', 'expires_at', 'consumed_at', 'released_at'];
    protected function casts(): array { return ['order_id' => 'integer', 'expires_at' => 'datetime', 'consumed_at' => 'datetime', 'released_at' => 'datetime']; }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function items(): HasMany { return $this->hasMany(CheckoutReservationItem::class); }
}
