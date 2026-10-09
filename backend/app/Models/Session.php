<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Session extends Model
{
    protected $primaryKey = 'id';
    public $incrementing = false;
    public $timestamps = false;
    protected $fillable = ['id', 'admin_id', 'ip_address', 'user_agent', 'payload', 'last_activity'];
    protected $hidden = ['payload'];
    protected function casts(): array { return ['admin_id' => 'integer', 'last_activity' => 'integer']; }
    public function admin(): BelongsTo { return $this->belongsTo(Admin::class); }
}
