<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('sessions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->string('id')->primary();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete()->cascadeOnUpdate();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->unsignedInteger('last_activity')->index();
            $table->index('admin_id');
        });
    }
    public function down(): void { Schema::dropIfExists('sessions'); }
};
