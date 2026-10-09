<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('webhook_receipts', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('provider', 40);
            $table->string('event_id', 200);
            $table->string('transaction_id', 200)->nullable();
            $table->char('payload_hash', 64);
            $table->string('status', 20)->default('RECEIVED');
            $table->timestamp('processed_at', 3)->nullable();
            $table->timestamps(3);
            $table->unique(['provider', 'event_id']);
            $table->index('transaction_id');
        });
    }
    public function down(): void { Schema::dropIfExists('webhook_receipts'); }
};
