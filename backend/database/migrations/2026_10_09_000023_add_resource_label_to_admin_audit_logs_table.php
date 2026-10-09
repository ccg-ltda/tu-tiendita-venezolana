<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('mysql')->table('admin_audit_logs', function (Blueprint $table): void {
            $table->string('resource_label', 255)->nullable()->after('resource_id');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->table('admin_audit_logs', function (Blueprint $table): void {
            $table->dropColumn('resource_label');
        });
    }
};
