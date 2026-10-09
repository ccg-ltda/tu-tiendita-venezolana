<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentEventOutbox extends Model
{
    protected $table = 'payment_event_outbox';
    protected $fillable = ['wompi_transaction_id', 'reference', 'event_status', 'payment_method', 'amount_in_cents', 'currency', 'event_occurred_at', 'status', 'attempts', 'available_at', 'last_error'];
    protected function casts(): array { return ['amount_in_cents' => 'integer', 'event_occurred_at' => 'datetime', 'attempts' => 'integer', 'available_at' => 'datetime']; }
}
