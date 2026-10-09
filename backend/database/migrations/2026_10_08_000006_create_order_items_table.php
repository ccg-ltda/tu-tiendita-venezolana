<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnUpdate()->restrictOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->string('product_name', 500);
            $table->unsignedInteger('unit_price_cop');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('subtotal_cop');
            $table->timestamp('created_at', 3)->useCurrent();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnUpdate()->restrictOnDelete();
            $table->index('product_id');
        });
    }
    public function down(): void { Schema::dropIfExists('order_items'); }
};
