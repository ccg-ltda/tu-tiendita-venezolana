<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminAuditLog extends Model
{
    protected $connection = 'mysql';
    public const UPDATED_AT = null;
    protected $fillable = ['admin_id', 'username', 'action', 'resource_type', 'resource_id', 'resource_label', 'before_json', 'after_json', 'request_id'];
    protected $hidden = ['before_json', 'after_json'];
    protected function casts(): array { return ['admin_id' => 'integer', 'before_json' => 'array', 'after_json' => 'array']; }
    public function admin(): BelongsTo { return $this->belongsTo(Admin::class); }
}
