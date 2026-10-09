<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table): void {
            // Laravel DatabaseSessionHandler writes this conventional field.
            $table->string('user_id')->nullable()->index()->after('admin_id');
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table): void {
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
