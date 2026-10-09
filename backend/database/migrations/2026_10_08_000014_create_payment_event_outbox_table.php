<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('payment_event_outbox', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('wompi_transaction_id', 200)->unique();
            $table->string('reference', 120);
            $table->string('event_status', 40);
            $table->string('payment_method', 100);
            $table->unsignedBigInteger('amount_in_cents');
            $table->char('currency', 3)->default('COP');
            $table->dateTime('event_occurred_at', 3);
            $table->string('status', 20)->default('PENDING');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('available_at', 3)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps(3);
            $table->index(['status', 'available_at', 'created_at']);
            $table->index('reference');
        });
    }
    public function down(): void { Schema::dropIfExists('payment_event_outbox'); }
};
