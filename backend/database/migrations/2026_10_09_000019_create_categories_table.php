<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('mysql')->create('categories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name', 100)->unique();
            $table->string('slug', 120)->unique();
            $table->boolean('active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::connection('mysql')->dropIfExists('categories'); }
};
