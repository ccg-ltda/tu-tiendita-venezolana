<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    /**
     * The attributes that may be mass assigned.
     *
     * @var list<string>
     */
    protected $fillable = [
        'img',
        'category',
        'subcategory',
        'name',
        'presentation',
        'price',
        'image',
        'inventory',
        'active',
    ];

    /**
     * The model's attribute casting definitions.
     *
     * @var array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'inventory' => 'integer',
            'active' => 'boolean',
        ];
    }
}
