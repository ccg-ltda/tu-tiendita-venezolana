<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pedido manual (clientes que piden por WhatsApp o teléfono). Solo agrega columnas y una tabla; no toca datos existentes. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('channel', 20)->default('WEB')->after('status');
            $table->string('manual_payment_method', 30)->nullable()->after('channel');
            $table->text('internal_note')->nullable()->after('manual_payment_method');
            $table->unsignedBigInteger('created_by_admin_id')->nullable()->after('internal_note');
            $table->index(['channel', 'created_at']);
        });
        Schema::create('order_attachments', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnUpdate()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('mime', 60);
            $table->mediumText('data_base64');
            $table->unsignedBigInteger('uploaded_by_admin_id')->nullable();
            $table->timestamp('created_at', 3)->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_attachments');
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['channel', 'created_at']);
            $table->dropColumn(['channel', 'manual_payment_method', 'internal_note', 'created_by_admin_id']);
        });
    }
};
