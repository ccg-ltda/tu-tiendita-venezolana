<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('notification_outbox', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('notification_key', 180)->unique();
            $table->foreignId('order_id')->constrained()->restrictOnUpdate()->restrictOnDelete();
            $table->string('notification_type', 40);
            $table->string('recipient_kind', 20);
            $table->string('status', 20)->default('PENDING');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('available_at', 3)->nullable();
            $table->dateTime('sent_at', 3)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->longText('payload_json');
            $table->timestamps(3);
            $table->index(['status', 'available_at', 'created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('notification_outbox'); }
};
