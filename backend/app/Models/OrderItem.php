<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $connection = 'mysql';
    public const UPDATED_AT = null;
    protected $fillable = ['order_id', 'product_id', 'product_name', 'unit_price_cop', 'quantity', 'subtotal_cop'];
    protected function casts(): array { return ['order_id' => 'integer', 'product_id' => 'integer', 'unit_price_cop' => 'integer', 'quantity' => 'integer', 'subtotal_cop' => 'integer']; }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
