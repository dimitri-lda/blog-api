<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::firstOrCreate(['email' => 'test@example.com'], [
            'name' => 'Test User',
            'password' => Hash::make('password'),
        ]);
        $categories = [
            [
                'name' => 'Running', 'slug' => 'running',
                'image_url' => 'https://images.unsplash.com/photo-1552674605-db6ffd4facb5'
                    .'?auto=format&fit=crop&w=700&q=80',
            ],
            [
                'name' => 'Fitness', 'slug' => 'fitness',
                'image_url' => 'https://images.unsplash.com/photo-1534438327276-14e5300c3a48'
                    .'?auto=format&fit=crop&w=700&q=80',
            ],
            [
                'name' => 'Racket sports', 'slug' => 'tennis',
                'image_url' => 'https://images.unsplash.com/photo-1622279457486-62dcc4a431d6'
                    .'?auto=format&fit=crop&w=700&q=80',
            ],
            [
                'name' => 'Outdoor', 'slug' => 'outdoor',
                'image_url' => 'https://images.unsplash.com/photo-1551632811-561732d1e306'
                    .'?auto=format&fit=crop&w=700&q=80',
            ],
        ];
        $products = [
            [
                'category' => 'running', 'name' => 'Cloudswift 4', 'brand' => 'On',
                'slug' => 'cloudswift-4', 'price_cents_net' => 16900,
                'description' => 'A responsive everyday runner with soft landings and confident grip.',
                'image_url' => 'https://images.unsplash.com/photo-1542291026-7eec264c27ff'
                    .'?auto=format&fit=crop&w=900&q=85',
                'featured' => true,
            ],
            [
                'category' => 'fitness', 'name' => 'Move Training Mat', 'brand' => 'daoSport',
                'slug' => 'move-training-mat', 'price_cents_net' => 4900,
                'description' => 'A grippy, comfortable mat for your home and studio practice.',
                'image_url' => 'https://images.unsplash.com/photo-1592432678016-e910b452f9a2'
                    .'?auto=format&fit=crop&w=900&q=85',
                'featured' => true,
            ],
            [
                'category' => 'tennis', 'name' => 'Pure Aero 98', 'brand' => 'Babolat',
                'slug' => 'pure-aero-98', 'price_cents_net' => 22900,
                'description' => 'Spin, precision and feel for the player who controls every point.',
                'image_url' => 'https://images.unsplash.com/photo-1595435934249-5df7ed86e1c0'
                    .'?auto=format&fit=crop&w=900&q=85',
                'featured' => true,
            ],
            [
                'category' => 'outdoor', 'name' => 'Trail Shell Jacket', 'brand' => 'Salomon',
                'slug' => 'trail-shell-jacket', 'price_cents_net' => 13900,
                'description' => 'Lightweight weather protection built for changing mountain days.',
                'image_url' => 'https://images.unsplash.com/photo-1551698618-1dfe5d97d256'
                    .'?auto=format&fit=crop&w=900&q=85',
                'featured' => true,
            ],
            [
                'category' => 'running', 'name' => 'Aero Run Shorts', 'brand' => 'Nike',
                'slug' => 'aero-run-shorts', 'price_cents_net' => 4500,
                'description' => 'Light, breathable shorts that stay out of your way.',
                'image_url' => 'https://images.unsplash.com/photo-1552902865-b72c031ac5ea'
                    .'?auto=format&fit=crop&w=900&q=85',
                'featured' => false,
            ],
            [
                'category' => 'fitness', 'name' => 'Kettlebell 12kg', 'brand' => 'daoSport',
                'slug' => 'kettlebell-12kg', 'price_cents_net' => 5900,
                'description' => 'A durable cast iron kettlebell for full-body strength sessions.',
                'image_url' => 'https://images.unsplash.com/photo-1517963879433-6ad2b056d712'
                    .'?auto=format&fit=crop&w=900&q=85',
                'featured' => false,
            ],
            [
                'category' => 'tennis', 'name' => 'Court Backpack', 'brand' => 'Wilson',
                'slug' => 'court-backpack', 'price_cents_net' => 7900,
                'description' => 'Smart storage for your racket, kit and everyday essentials.',
                'image_url' => 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62'
                    .'?auto=format&fit=crop&w=900&q=85',
                'featured' => false,
            ],
            [
                'category' => 'outdoor', 'name' => 'Alpine Daypack 24L', 'brand' => 'Osprey',
                'slug' => 'alpine-daypack', 'price_cents_net' => 10900,
                'description' => 'A comfortable, versatile daypack for every trail.',
                'image_url' => 'https://images.unsplash.com/photo-1551632811-561732d1e306'
                    .'?auto=format&fit=crop&w=900&q=85',
                'featured' => false,
            ],
        ];
        foreach ($categories as $data) {
            Category::updateOrCreate(['slug' => $data['slug']], $data);
        }
        $subcategories = [
            'running' => [
                'running-shoes' => ['Running shoes', 'Беговая обувь', 'Buty do biegania'],
                'running-clothing' => ['Running clothing', 'Одежда для бега', 'Odzież do biegania'],
            ],
            'fitness' => [
                'training-accessories' => ['Training accessories', 'Тренировочные аксессуары', 'Akcesoria treningowe'],
                'weights' => ['Weights', 'Отягощения', 'Obciążenia'],
            ],
            'tennis' => [
                'rackets' => ['Rackets', 'Ракетки', 'Rakiety'],
                'tennis-bags' => ['Tennis bags', 'Теннисные сумки', 'Torby tenisowe'],
            ],
            'outdoor' => [
                'outdoor-clothing' => ['Outdoor clothing', 'Одежда для активного отдыха', 'Odzież outdoor'],
                'backpacks' => ['Backpacks', 'Рюкзаки', 'Plecaki'],
            ],
        ];
        foreach ($subcategories as $parent => $children) {
            foreach ($children as $slug => [$name, $nameRu, $namePl]) {
                Category::updateOrCreate(['slug' => $slug], [
                    'name' => $name,
                    'name_translations' => ['ru' => $nameRu, 'pl' => $namePl],
                    'parent_id' => Category::where('slug', $parent)->value('id'),
                ]);
            }
        }
        $assignments = [
            'cloudswift-4' => 'running-shoes', 'aero-run-shorts' => 'running-clothing',
            'move-training-mat' => 'training-accessories', 'kettlebell-12kg' => 'weights',
            'pure-aero-98' => 'rackets', 'court-backpack' => 'tennis-bags',
            'trail-shell-jacket' => 'outdoor-clothing', 'alpine-daypack' => 'backpacks',
        ];
        $specifications = [
            'cloudswift-4' => [['Przeznaczenie', 'Bieganie po asfalcie', null]],
            'aero-run-shorts' => [['Typ', 'Spodenki do biegania', null]],
            'move-training-mat' => [['Przeznaczenie', 'Trening w domu i studiu', null]],
            'kettlebell-12kg' => [['Materiał', 'Żeliwo', null], ['Masa', '12', 'kg']],
            'pure-aero-98' => [['Dyscyplina', 'Tenis', null]],
            'court-backpack' => [['Przeznaczenie', 'Transport rakiety i akcesoriów', null]],
            'trail-shell-jacket' => [['Przeznaczenie', 'Aktywność w górach', null]],
            'alpine-daypack' => [['Pojemność', '24', 'l']],
        ];
        foreach ($products as $data) {
            $brand = Brand::updateOrCreate(['slug' => Str::slug($data['brand'])], ['name' => $data['brand']]);
            $manufacturer = Manufacturer::updateOrCreate(['slug' => $brand->slug], ['name' => $brand->name]);
            $category = Category::where('slug', $assignments[$data['slug']])->firstOrFail();
            unset($data['category']);
            $product = Product::updateOrCreate(['slug' => $data['slug']], [
                ...$data,
                'category_id' => $category->id,
                'brand_id' => $brand->id,
                'manufacturer_id' => $manufacturer->id,
            ]);
            ProductVariant::updateOrCreate([
                'product_id' => $product->id,
                'sku' => strtoupper($product->slug).'-ONE',
            ], [
                'name' => 'One size',
                'name_translations' => ['ru' => 'Один размер', 'pl' => 'Rozmiar uniwersalny'],
                'stock' => $product->slug === 'court-backpack' ? 0 : 24,
                'price_cents_net' => $product->price_cents_net,
            ]);
            foreach ($specifications[$product->slug] as $position => [$name, $value, $unit]) {
                $product->specifications()->updateOrCreate(['name' => $name], compact('value', 'unit', 'position'));
            }
            $product->images()->updateOrCreate(['position' => 1], [
                'url' => preg_replace('/w=900/', 'w=1200', $product->image_url),
                'alt' => $product->name.' — zdjęcie demonstracyjne',
            ]);
        }
    }
}
