<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BlogPost extends Model
{
    protected $connection = 'mysql';

    protected $fillable = [
        'title',
        'slug',
        'content',
        'image_url',
        'published_at',
        'status',
        'sheet_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'date',
            'sheet_synced_at' => 'datetime',
        ];
    }
}
