<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('price_cents', 'price_cents_net');
            $table->renameColumn('compare_at_price_cents', 'compare_at_price_cents_net');
        });
        Schema::table('product_variants', fn (Blueprint $table) => $table->renameColumn('price_cents', 'price_cents_net'));
    }

    public function down(): void
    {
        Schema::table('product_variants', fn (Blueprint $table) => $table->renameColumn('price_cents_net', 'price_cents'));
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('price_cents_net', 'price_cents');
            $table->renameColumn('compare_at_price_cents_net', 'compare_at_price_cents');
        });
    }
};
