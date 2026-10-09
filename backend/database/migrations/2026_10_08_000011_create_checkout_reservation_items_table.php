<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('checkout_reservation_items', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('checkout_reservation_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('inventory_before');
            $table->unsignedInteger('inventory_after');
            $table->unsignedInteger('product_revision_before');
            $table->unsignedInteger('product_revision_after');
            $table->timestamps(3);
            $table->foreign('product_id')->references('id')->on('products')->restrictOnUpdate()->restrictOnDelete();
            $table->unique(['checkout_reservation_id', 'product_id'], 'cr_items_res_product_unique');
            $table->index('product_id');
        });
    }
    public function down(): void { Schema::dropIfExists('checkout_reservation_items'); }
};
