<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('coupon_reservations', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('coupon_id')->constrained('coupons')->restrictOnUpdate()->restrictOnDelete();
            $table->string('coupon_code', 80);
            $table->string('state', 20)->default('RESERVED');
            $table->dateTime('reservation_expires_at', 3);
            $table->dateTime('consumed_at', 3)->nullable();
            $table->dateTime('released_at', 3)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps(3);
            $table->index(['coupon_id', 'state', 'reservation_expires_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('coupon_reservations'); }
};
