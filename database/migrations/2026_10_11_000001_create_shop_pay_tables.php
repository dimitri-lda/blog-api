<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider')->default('shop_pay');
            $table->string('source_identifier')->unique();
            $table->text('session_token')->nullable();
            $table->char('session_token_hash', 64)->nullable()->unique();
            $table->text('checkout_url')->nullable();
            $table->text('receipt_token')->nullable();
            $table->string('status')->default('pending');
            $table->string('provider_order_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_session_id')->constrained()->cascadeOnDelete();
            $table->char('payment_method_hash', 64);
            $table->uuid('idempotency_key')->unique();
            $table->text('payload');
            $table->text('receipt_token')->nullable();
            $table->string('status')->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['payment_session_id', 'payment_method_hash']);
        });
        Schema::create('shopify_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('delivery_id')->unique();
            $table->string('topic');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_webhook_deliveries');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('payment_sessions');
    }
};
