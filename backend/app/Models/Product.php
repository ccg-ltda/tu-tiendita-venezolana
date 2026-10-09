<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    protected $connection = 'mysql';
    protected $fillable = ['id', 'category_id', 'subcategory_id', 'category', 'subcategory', 'name', 'presentation', 'price_cop', 'inventory', 'active', 'image_path', 'legacy_img', 'revision'];
    protected function casts(): array { return ['id' => 'integer', 'category_id' => 'integer', 'subcategory_id' => 'integer', 'price_cop' => 'integer', 'inventory' => 'integer', 'active' => 'boolean', 'revision' => 'integer']; }
    public function categoryRelation(): BelongsTo { return $this->belongsTo(Category::class, 'category_id'); }
    public function subcategoryRelation(): BelongsTo { return $this->belongsTo(Subcategory::class, 'subcategory_id'); }
    public function promotion(): HasOne { return $this->hasOne(ProductPromotion::class); }
    public function orderItems(): HasMany { return $this->hasMany(OrderItem::class); }
    public function checkoutReservationItems(): HasMany { return $this->hasMany(CheckoutReservationItem::class); }
}
