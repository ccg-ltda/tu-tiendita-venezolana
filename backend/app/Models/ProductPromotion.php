<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPromotion extends Model
{
    protected $connection = 'mysql';
    protected $primaryKey = 'product_id';
    public $incrementing = false;
    protected $fillable = ['product_id', 'active', 'discount_type', 'discount_value', 'starts_at', 'ends_at', 'revision'];
    protected function casts(): array { return ['product_id' => 'integer', 'active' => 'boolean', 'discount_value' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'revision' => 'integer']; }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
