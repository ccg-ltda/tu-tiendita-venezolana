<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('orders', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('reference', 120)->unique();
            $table->string('status', 40);
            $table->string('payment_status', 40);
            $table->string('reservation_status', 40);
            $table->dateTime('reservation_expires_at', 3)->nullable();
            $table->dateTime('paid_at', 3)->nullable();
            $table->dateTime('payment_last_event_at', 3)->nullable();
            $table->char('idempotency_key_hash', 64)->unique();
            $table->char('checkout_payload_hash', 64);
            $table->char('release_id', 36)->nullable()->unique();
            $table->char('release_fingerprint', 64)->nullable();
            $table->dateTime('released_at', 3)->nullable();
            $table->string('customer_name', 120);
            $table->string('customer_email', 254);
            $table->string('customer_phone', 50);
            $table->string('customer_document', 50);
            $table->string('address', 300);
            $table->string('extra', 300)->nullable();
            $table->string('city', 100);
            $table->string('region', 100);
            $table->string('postal', 30)->nullable();
            $table->unsignedInteger('total_cop');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps(3);
            $table->index(['status', 'created_at']);
            $table->index(['payment_status', 'created_at']);
            $table->index(['reservation_status', 'reservation_expires_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('orders'); }
};
