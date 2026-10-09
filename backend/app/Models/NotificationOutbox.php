<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationOutbox extends Model
{
    protected $connection = 'mysql';
    protected $table = 'notification_outbox';
    protected $fillable = ['notification_key', 'order_id', 'notification_type', 'recipient_kind', 'status', 'attempts', 'available_at', 'sent_at', 'last_error', 'payload_json'];
    protected $hidden = ['payload_json'];
    protected function casts(): array { return ['order_id' => 'integer', 'attempts' => 'integer', 'available_at' => 'datetime', 'sent_at' => 'datetime', 'payload_json' => 'array']; }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
