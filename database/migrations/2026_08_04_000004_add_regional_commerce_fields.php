<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('currency', 3)->unique();
            $table->decimal('rate', 18, 8);
            $table->string('source', 40);
            $table->timestamp('quoted_at');
            $table->timestamps();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->string('market', 20)->nullable()->after('currency');
            $table->string('locale', 5)->nullable()->after('market');
            $table->string('base_currency', 3)->default('EUR')->after('locale');
            $table->decimal('exchange_rate', 18, 8)->nullable()->after('base_currency');
            $table->unsignedSmallInteger('vat_rate_basis_points')->nullable()->after('exchange_rate');
            $table->unsignedInteger('net_subtotal_cents')->nullable()->after('subtotal_cents');
            $table->unsignedInteger('tax_cents')->nullable()->after('net_subtotal_cents');
            $table->unsignedInteger('delivery_net_cents')->nullable()->after('delivery_cents');
            $table->unsignedInteger('delivery_tax_cents')->nullable()->after('delivery_net_cents');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedInteger('net_unit_price_cents')->nullable()->after('unit_price_cents');
            $table->unsignedInteger('tax_cents')->nullable()->after('line_total_cents');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->json('name_translations')->nullable()->after('name');
            $table->json('description_translations')->nullable()->after('description');
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->json('name_translations')->nullable()->after('name');
        });
        Schema::table('product_variants', function (Blueprint $table) {
            $table->json('name_translations')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', fn (Blueprint $t) => $t->dropColumn('name_translations'));
        Schema::table('categories', fn (Blueprint $t) => $t->dropColumn('name_translations'));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['name_translations', 'description_translations']));
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn(['net_unit_price_cents', 'tax_cents']));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['market', 'locale', 'base_currency', 'exchange_rate', 'vat_rate_basis_points', 'net_subtotal_cents', 'tax_cents', 'delivery_net_cents', 'delivery_tax_cents']));
        Schema::dropIfExists('exchange_rates');
    }
};
