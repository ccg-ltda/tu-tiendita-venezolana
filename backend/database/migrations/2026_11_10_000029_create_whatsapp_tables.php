<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Módulo de WhatsApp del panel:
// - whatsapp_clicks: cada clic en una opción del menú de WhatsApp de la tienda (solo el tema, sin datos personales).
// - whatsapp_requests: solicitudes que el equipo registra y atiende (mayorista, soporte, ayuda para comprar).
return new class extends Migration {
    public function up(): void
    {
        Schema::create('whatsapp_clicks', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('topic', 30);
            $table->timestamp('created_at', 3)->useCurrent();
            $table->index(['topic', 'created_at']);
        });

        Schema::create('whatsapp_requests', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('name', 120);
            $table->string('phone', 30);
            $table->string('topic', 30);
            $table->string('message', 1000)->nullable();
            $table->string('status', 20)->default('NEW');
            $table->unsignedBigInteger('assigned_admin_id')->nullable();
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->timestamps(3);
            $table->index(['status', 'created_at']);
            $table->index('topic');
            $table->foreign('assigned_admin_id')->references('id')->on('admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_requests');
        Schema::dropIfExists('whatsapp_clicks');
    }
};
