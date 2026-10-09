<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subcategory extends Model
{
    protected $connection = 'mysql';
    protected $fillable = ['category_id', 'name', 'slug', 'active', 'sort_order'];
    protected function casts(): array { return ['category_id' => 'integer', 'active' => 'boolean', 'sort_order' => 'integer']; }
    public function category(): BelongsTo { return $this->belongsTo(Category::class); }
    public function products(): HasMany { return $this->hasMany(Product::class); }
}
