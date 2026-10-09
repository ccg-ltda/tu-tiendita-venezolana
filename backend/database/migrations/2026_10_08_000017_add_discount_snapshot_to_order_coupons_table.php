<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_coupons', function (Blueprint $table): void {
            $table->string('discount_type', 10)->after('coupon_code');
            $table->unsignedInteger('discount_value')->after('discount_type');
        });
    }

    public function down(): void
    {
        Schema::table('order_coupons', function (Blueprint $table): void {
            $table->dropColumn(['discount_type', 'discount_value']);
        });
    }
};
