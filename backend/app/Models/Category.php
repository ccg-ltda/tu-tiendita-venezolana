<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $connection = 'mysql';
    protected $fillable = ['name', 'slug', 'active', 'sort_order'];
    protected function casts(): array { return ['active' => 'boolean', 'sort_order' => 'integer']; }
    public function subcategories(): HasMany { return $this->hasMany(Subcategory::class); }
    public function products(): HasMany { return $this->hasMany(Product::class); }
}
