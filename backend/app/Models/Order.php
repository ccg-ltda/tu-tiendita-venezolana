<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'reference',
        'status',
        'customer_name',
        'customer_email',
        'customer_phone',
        'customer_document',
        'address',
        'extra',
        'city',
        'region',
        'postal',
        'total',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
