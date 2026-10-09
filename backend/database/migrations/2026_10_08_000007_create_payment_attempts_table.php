<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnUpdate()->restrictOnDelete();
            $table->string('wompi_transaction_id', 200)->unique();
            $table->string('status', 40);
            $table->string('payment_method', 100);
            $table->unsignedBigInteger('amount_in_cents');
            $table->char('currency', 3)->default('COP');
            $table->timestamps(3);
            $table->index(['order_id', 'created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('payment_attempts'); }
};
