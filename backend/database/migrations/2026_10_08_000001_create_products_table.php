<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            // Explicit legacy IDs remain insertable; MySQL/MariaDB then advances
            // AUTO_INCREMENT beyond the highest imported identifier.
            $table->id();
            $table->string('category', 100);
            $table->string('subcategory', 100);
            $table->string('name', 500);
            $table->string('presentation', 100);
            $table->unsignedInteger('price_cop');
            $table->unsignedInteger('inventory')->default(0);
            $table->boolean('active')->default(true);
            $table->string('image_path', 255)->nullable();
            $table->string('legacy_img', 255)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps(3);
            $table->index(['active', 'category']);
            $table->index(['category', 'subcategory', 'active']);
        });
    }
    public function down(): void { Schema::dropIfExists('products'); }
};
