<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAttempt extends Model
{
    protected $connection = 'mysql';
    protected $fillable = ['order_id', 'wompi_transaction_id', 'status', 'payment_method', 'amount_in_cents', 'currency'];
    protected function casts(): array { return ['order_id' => 'integer', 'amount_in_cents' => 'integer']; }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
