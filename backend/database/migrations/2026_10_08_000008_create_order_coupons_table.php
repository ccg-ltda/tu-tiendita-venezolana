<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('order_coupons', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('coupon_id')->constrained('coupons')->restrictOnUpdate()->restrictOnDelete();
            $table->string('coupon_code', 80);
            $table->unsignedInteger('coupon_discount_cop');
            $table->unsignedInteger('eligible_subtotal_cop');
            $table->string('status', 40)->default('APPLIED');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps(3);
            $table->index('coupon_id');
        });
    }
    public function down(): void { Schema::dropIfExists('order_coupons'); }
};
