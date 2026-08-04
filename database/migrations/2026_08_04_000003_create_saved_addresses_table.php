<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('line1', 180);
            $table->string('line2', 180)->nullable();
            $table->string('city', 80);
            $table->string('postal_code', 20);
            $table->string('country', 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_addresses');
    }
};
