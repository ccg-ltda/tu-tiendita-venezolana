<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('coupons', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('code', 80)->unique();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->string('discount_type', 10);
            $table->unsignedInteger('discount_value');
            $table->unsignedInteger('minimum_order_cop')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->dateTime('starts_at', 3)->nullable();
            $table->dateTime('ends_at', 3)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps(3);
            $table->index(['active', 'starts_at', 'ends_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('coupons'); }
};
