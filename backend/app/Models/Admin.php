<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Admin extends Model
{
    protected $connection = 'mysql';
    protected $fillable = ['name', 'username', 'password_hash', 'role', 'active', 'password_changed_at', 'revision'];
    protected $hidden = ['password_hash'];
    protected function casts(): array { return ['active' => 'boolean', 'password_changed_at' => 'datetime', 'revision' => 'integer']; }
    public function auditLogs(): HasMany { return $this->hasMany(AdminAuditLog::class); }
}
