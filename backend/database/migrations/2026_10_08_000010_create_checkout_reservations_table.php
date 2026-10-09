<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('checkout_reservations', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnUpdate()->restrictOnDelete();
            $table->string('state', 20)->default('ACTIVE');
            $table->dateTime('expires_at', 3);
            $table->dateTime('consumed_at', 3)->nullable();
            $table->dateTime('released_at', 3)->nullable();
            $table->timestamps(3);
            $table->index(['state', 'expires_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('checkout_reservations'); }
};
