<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('product_promotions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->unsignedBigInteger('product_id')->primary();
            $table->boolean('active')->default(true);
            $table->string('discount_type', 10);
            $table->unsignedInteger('discount_value');
            $table->dateTime('starts_at', 3)->nullable();
            $table->dateTime('ends_at', 3)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps(3);
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnUpdate()->cascadeOnDelete();
            $table->index(['active', 'starts_at', 'ends_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('product_promotions'); }
};
