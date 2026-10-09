<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('admins', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('name', 120);
            $table->string('username', 40)->unique();
            $table->string('password_hash', 255);
            $table->string('role', 30)->default('ADMIN');
            $table->boolean('active')->default(true);
            $table->dateTime('password_changed_at', 3)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps(3);
            $table->index(['active', 'role']);
        });
    }
    public function down(): void { Schema::dropIfExists('admins'); }
};
