<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('admin_audit_logs', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('username', 40);
            $table->string('action', 40);
            $table->string('resource_type', 40);
            $table->string('resource_id', 128);
            $table->longText('before_json');
            $table->longText('after_json');
            $table->uuid('request_id');
            $table->timestamp('created_at', 3)->useCurrent();
            $table->foreign('admin_id')->references('id')->on('admins')->nullOnDelete()->cascadeOnUpdate();
            $table->index(['admin_id', 'created_at']);
            $table->index(['resource_type', 'resource_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index('request_id');
        });
    }
    public function down(): void { Schema::dropIfExists('admin_audit_logs'); }
};
