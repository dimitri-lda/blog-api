<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('inventory_reserved_at')->nullable();
            $table->timestamp('inventory_released_at')->nullable();
        });
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->unsignedInteger('amount_cents')->nullable()->after('payment_method_hash');
            $table->string('currency', 3)->nullable()->after('amount_cents');
        });

        DB::table('payment_attempts')
            ->join('payment_sessions', 'payment_attempts.payment_session_id', '=', 'payment_sessions.id')
            ->join('orders', 'payment_sessions.order_id', '=', 'orders.id')
            ->whereNull('payment_attempts.amount_cents')
            ->select(
                'payment_attempts.id as attempt_id',
                'orders.total_cents',
                'orders.currency',
            )
            ->chunkById(
                200,
                function ($attempts): void {
                    foreach ($attempts as $attempt) {
                        DB::table('payment_attempts')
                            ->where('id', $attempt->attempt_id)
                            ->update([
                                'amount_cents' => $attempt->total_cents,
                                'currency' => $attempt->currency,
                            ]);
                    }
                },
                'payment_attempts.id',
                'attempt_id',
            );

        DB::table('order_items')
            ->whereNotNull('product_variant_id')
            ->whereNotIn(
                'product_variant_id',
                DB::table('product_variants')->select('id'),
            )
            ->update(['product_variant_id' => null]);

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreign('product_variant_id')
                ->references('id')
                ->on('product_variants')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['product_variant_id']);
        });
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropColumn(['amount_cents', 'currency']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['inventory_reserved_at', 'inventory_released_at']);
        });
    }
};
