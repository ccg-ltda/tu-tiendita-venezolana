<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckoutReservationItem extends Model
{
    protected $connection = 'mysql';
    protected $fillable = ['checkout_reservation_id', 'product_id', 'quantity', 'inventory_before', 'inventory_after', 'product_revision_before', 'product_revision_after'];
    protected function casts(): array { return ['checkout_reservation_id' => 'integer', 'product_id' => 'integer', 'quantity' => 'integer', 'inventory_before' => 'integer', 'inventory_after' => 'integer', 'product_revision_before' => 'integer', 'product_revision_after' => 'integer']; }
    public function reservation(): BelongsTo { return $this->belongsTo(CheckoutReservation::class, 'checkout_reservation_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
