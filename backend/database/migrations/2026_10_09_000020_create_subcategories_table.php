<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('mysql')->create('subcategories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->string('name', 100);
            $table->string('slug', 120);
            $table->boolean('active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['category_id', 'name']);
            $table->unique(['category_id', 'slug']);
            $table->index(['category_id', 'active', 'sort_order']);
        });
    }
    public function down(): void { Schema::connection('mysql')->dropIfExists('subcategories'); }
};
