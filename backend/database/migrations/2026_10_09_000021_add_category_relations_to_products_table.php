<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('mysql')->table('products', function (Blueprint $table): void {
            $table->foreignId('category_id')->nullable()->after('id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('subcategory_id')->nullable()->after('category_id')->constrained('subcategories')->restrictOnDelete();
            $table->index(['category_id', 'subcategory_id', 'active'], 'products_classification_active_index');
        });
    }
    public function down(): void
    {
        Schema::connection('mysql')->table('products', function (Blueprint $table): void {
            $table->dropIndex('products_classification_active_index');
            $table->dropConstrainedForeignId('subcategory_id');
            $table->dropConstrainedForeignId('category_id');
        });
    }
};
