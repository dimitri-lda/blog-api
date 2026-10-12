<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['brands', 'manufacturers'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->timestamps();
            });
        }
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('manufacturer_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::table('product_images', function (Blueprint $table) {
            $table->string('alt')->nullable();
        });
        Schema::create('product_specifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('value');
            $table->string('unit')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
        $brands = [];
        foreach (DB::table('products')->orderBy('id')->get(['id', 'brand']) as $product) {
            $name = trim((string) $product->brand);
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower($name);
            if (! isset($brands[$key])) {
                $base = Str::slug($name) ?: 'brand';
                $slug = $base;
                for ($suffix = 2; DB::table('brands')->where('slug', $slug)->exists(); $suffix++) {
                    $slug = $base.'-'.$suffix;
                }
                $brands[$key] = DB::table('brands')->insertGetId([
                    'name' => $name, 'slug' => $slug, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('products')->where('id', $product->id)->update(['brand_id' => $brands[$key]]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('products')->whereNotNull('brand_id')->get(['id', 'brand_id']) as $product) {
            DB::table('products')->where('id', $product->id)->update([
                'brand' => DB::table('brands')->where('id', $product->brand_id)->value('name'),
            ]);
        }
        Schema::dropIfExists('product_specifications');
        Schema::table('product_images', fn (Blueprint $table) => $table->dropColumn('alt'));
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brand_id');
            $table->dropConstrainedForeignId('manufacturer_id');
        });
        Schema::dropIfExists('manufacturers');
        Schema::dropIfExists('brands');
    }
};
