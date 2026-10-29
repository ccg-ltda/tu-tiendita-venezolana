<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            // Nullable at the schema level (no doctrine/dbal, so no ->change());
            // every row is backfilled below and the app always writes a slug on
            // create, so NULL never actually occurs in practice.
            $table->string('slug', 220)->nullable()->unique()->after('name');
        });

        $used = [];
        DB::table('products')->orderBy('id')->select('id', 'name')->each(function (object $product) use (&$used): void {
            $base = Str::slug($product->name) ?: 'producto';
            $slug = $base;
            $suffix = 2;
            while (in_array($slug, $used, true)) $slug = $base.'-'.($suffix++);
            $used[] = $slug;
            DB::table('products')->where('id', $product->id)->update(['slug' => $slug]);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
