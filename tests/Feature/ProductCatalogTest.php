<?php

namespace Tests\Feature;

use App\Application\Orders\Admin\ChangeOrderStatus;
use App\Domain\Orders\ValueObjects\OrderStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Manufacturer;
use App\Models\Order;
use App\Models\Product;
use App\Services\ExchangeRateService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function product(Category $category, array $attributes = [], ?int $stock = 3): Product
    {
        $product = Product::create([
            'name' => 'Shoes', 'slug' => 'shoes-'.uniqid(), 'description' => 'Product description',
            'category_id' => $category->id, 'price_cents_net' => 10000,
            'image_url' => '/shoe.jpg', 'active' => true, ...$attributes,
        ]);
        if ($stock !== null) {
            $product->variants()->create(['name' => '42', 'sku' => uniqid(), 'stock' => $stock]);
        }

        return $product;
    }

    public function test_category_filter_includes_all_descendants_but_excludes_siblings_and_inactive_products(): void
    {
        $root = Category::create(['name' => 'Running', 'slug' => 'running']);
        $child = Category::create(['name' => 'Shoes', 'slug' => 'shoes', 'parent_id' => $root->id]);
        $grandchild = Category::create(['name' => 'Trail', 'slug' => 'trail', 'parent_id' => $child->id]);
        $sibling = Category::create(['name' => 'Clothing', 'slug' => 'clothing', 'parent_id' => $root->id]);
        $this->product($root);
        $this->product($child);
        $this->product($grandchild);
        $this->product($sibling);
        $this->product($grandchild, ['active' => false]);
        $this->get('/shop?category=running')->assertInertia(fn (Assert $page) => $page
            ->component('Store/Catalog')->where('products.total', 4)->has('categories', 4)
            ->where('filters.category', 'running'));
        $this->get('/shop?category=shoes')->assertInertia(fn (Assert $page) => $page->where('products.total', 2));
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('categories.0.products_count', 4));
        $this->get('/shop?category=does-not-exist')->assertNotFound();
    }

    public function test_pagination_preserves_category_search_and_sort(): void
    {
        $category = Category::create(['name' => 'Sport', 'slug' => 'sport']);
        for ($index = 0; $index < 13; $index++) {
            $this->product($category, ['price_cents_net' => 100 + $index]);
        }
        $this->get('/shop?category=sport&q=Shoes&sort=price_asc&page=2')->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 13)->has('products.data', 1)
            ->where('products.data.0.price_cents_net', 112)
            ->where('products.prev_page_url', fn ($url) => str_contains($url, 'category=sport')
                && str_contains($url, 'q=Shoes')
                && str_contains($url, 'sort=price_asc')));
        $this->get('/shop?q[]=unexpected')->assertRedirect()->assertSessionHasErrors('q');
    }

    public function test_product_details_include_dictionaries_ordered_gallery_specs_breadcrumbs_and_related(): void
    {
        $root = Category::create(['name' => 'Sport', 'slug' => 'sport']);
        $child = Category::create(['name' => 'Shoes', 'slug' => 'shoes', 'parent_id' => $root->id]);
        $brand = Brand::create(['name' => 'Brand', 'slug' => 'brand']);
        $manufacturer = Manufacturer::create(['name' => 'Factory', 'slug' => 'factory']);
        $product = $this->product($child, ['brand_id' => $brand->id, 'manufacturer_id' => $manufacturer->id]);
        $other = $this->product($child);
        $this->product($child, ['active' => false]);
        $this->product($root, ['brand' => 'Legacybrand']);
        $product->images()->create(['url' => '/second.jpg', 'alt' => 'Second image', 'position' => 2]);
        $product->images()->create(['url' => '/first.jpg', 'position' => 1]);
        $product->specifications()->create(['name' => 'Weight', 'value' => '300', 'unit' => 'g']);
        $brand->update(['name' => 'New brand']);
        $this->get('/shop/'.$product->slug)->assertInertia(fn (Assert $page) => $page
            ->component('Store/Product')->where('product.brand_record.name', 'New brand')
            ->where('product.manufacturer.name', 'Factory')->where('product.images.0.url', '/first.jpg')
            ->where('product.images.1.alt', 'Second image')->where('product.specifications.0.unit', 'g')
            ->where('product.available', true)
            ->where('breadcrumbs.0.slug', 'sport')->where('breadcrumbs.1.slug', 'shoes')
            ->has('related', 1)->where('related.0.id', $other->id));
        $this->get('/shop?q=New')->assertInertia(fn (Assert $page) => $page->where('products.total', 1));
        $this->get('/shop?q=Legacybrand')->assertInertia(fn (Assert $page) => $page->where('products.total', 1));
    }

    public function test_availability_requires_stock_of_the_products_own_variant(): void
    {
        $category = Category::create(['name' => 'Sport', 'slug' => 'sport']);
        $empty = $this->product($category, [], null);
        $soldOut = $this->product($category, [], 0);
        $mixed = $this->product($category, [], 0);
        $mixed->variants()->create(['name' => '43', 'sku' => 'available', 'stock' => 1]);
        $this->assertFalse($empty->available);
        $this->assertFalse($soldOut->load('variants')->available);
        $this->assertTrue($mixed->load('variants')->available);
        $this->get('/shop/'.$empty->slug)->assertInertia(fn (Assert $page) => $page
            ->where('product.available', false)->has('product.variants', 0)
            ->has('product.images', 0)->has('product.specifications', 0));
        $this->post('/cart/items/'.$soldOut->variants->first()->id)->assertUnprocessable();
        $this->assertDatabaseCount('cart_items', 0);
        $this->post('/cart/items/'.$mixed->variants->last()->id, ['quantity' => 1])->assertRedirect();
        $this->assertDatabaseHas('cart_items', ['product_variant_id' => $mixed->variants->last()->id, 'quantity' => 1]);
        $this->get('/cart')->assertInertia(fn (Assert $page) => $page
            ->component('Store/Cart')->where('cart.items.0.variant_id', $mixed->variants->last()->id)
            ->where('cart.currency', 'EUR'));
        $checkout = $this->get('/checkout');
        $checkout->assertInertia(fn (Assert $page) => $page
            ->component('Store/Checkout')->where('cart.items.0.variant_id', $mixed->variants->last()->id)
            ->has('cart.delivery_quotes.standard')
            ->has('cart.delivery_quotes.express'));
        $quotes = $checkout->viewData('page')['props']['cart']['delivery_quotes'];
        $this->assertGreaterThan($quotes['standard']['total_cents'], $quotes['express']['total_cents']);
        $this->post('/checkout', [
            'email' => 'buyer@example.test', 'phone' => '123456789',
            'first_name' => 'Test', 'last_name' => 'Buyer', 'line1' => 'Test 1',
            'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE',
            'delivery_method' => 'standard',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('orders', ['currency' => 'EUR']);
        $this->assertDatabaseHas('order_items', [
            'product_variant_id' => $mixed->variants->last()->id, 'quantity' => 1,
        ]);
        $this->assertSame(0, $mixed->variants->last()->fresh()->stock);
    }

    public function test_checkout_reserves_stock_and_rejects_when_inventory_runs_out(): void
    {
        $category = Category::create(['name' => 'Sport', 'slug' => 'sport']);
        $product = $this->product($category, [], 3);
        $variant = $product->variants->first();
        $checkout = [
            'email' => 'buyer@example.test', 'phone' => '123456789',
            'first_name' => 'Test', 'last_name' => 'Buyer', 'line1' => 'Test 1',
            'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE',
            'delivery_method' => 'standard',
        ];

        $this->post('/cart/items/'.$variant->id, ['quantity' => 2])->assertRedirect();
        $this->post('/checkout', $checkout)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, $variant->fresh()->stock);
        $order = Order::sole();
        $this->assertNotNull($order->inventory_reserved_at);
        $this->assertNull($order->inventory_released_at);
        $this->assertDatabaseCount('cart_items', 0);

        $this->post('/cart/items/'.$variant->id, ['quantity' => 1])->assertRedirect();
        $variant->update(['stock' => 0]);
        $this->post('/checkout', $checkout)->assertRedirect()->assertSessionHasErrors('cart');
        $this->assertSame(1, Order::count());
        $this->assertSame(0, $variant->fresh()->stock);
    }

    public function test_cancelling_an_order_releases_the_reserved_stock_only_once(): void
    {
        $category = Category::create(['name' => 'Sport', 'slug' => 'sport']);
        $variant = $this->product($category, [], 3)->variants->first();

        $this->post('/cart/items/'.$variant->id, ['quantity' => 2])->assertRedirect();
        $this->post('/checkout', [
            'email' => 'buyer@example.test', 'phone' => '123456789',
            'first_name' => 'Test', 'last_name' => 'Buyer', 'line1' => 'Test 1',
            'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE',
            'delivery_method' => 'standard',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $order = Order::sole();
        $this->assertSame(1, $variant->fresh()->stock);

        app(ChangeOrderStatus::class)->handle($order->id, OrderStatus::Cancelled);
        $order->refresh();
        $this->assertSame('cancelled', $order->status->value);
        $this->assertSame(3, $variant->fresh()->stock);
        $this->assertNotNull($order->inventory_released_at);

        $this->expectException(DomainException::class);
        try {
            app(ChangeOrderStatus::class)->handle($order->id, OrderStatus::Cancelled);
        } finally {
            $this->assertSame(3, $variant->fresh()->stock);
        }
    }

    public function test_order_integrity_migration_rolls_back_and_reapplies(): void
    {
        $migration = require database_path(
            'migrations/2026_10_11_000002_harden_order_and_payment_integrity.php',
        );
        $migration->down();
        $this->assertFalse(Schema::hasColumn('orders', 'inventory_reserved_at'));
        $this->assertFalse(Schema::hasColumn('payment_attempts', 'amount_cents'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('orders', 'inventory_reserved_at'));
        $this->assertTrue(Schema::hasColumn('orders', 'inventory_released_at'));
        $this->assertTrue(Schema::hasColumn('payment_attempts', 'amount_cents'));
        $this->assertTrue(Schema::hasColumn('payment_attempts', 'currency'));
    }

    public function test_inactive_product_returns_404_and_empty_catalog_is_safe(): void
    {
        $category = Category::create(['name' => 'Sport', 'slug' => 'sport']);
        $product = $this->product($category, ['active' => false, 'featured' => true]);
        $this->get('/shop/'.$product->slug)->assertNotFound();
        $this->get('/shop')->assertInertia(fn (Assert $page) => $page->where('products.total', 0));
        $this->get('/')->assertInertia(fn (Assert $page) => $page->has('featured', 0));
        $this->post('/cart/items/'.$product->variants->first()->id)->assertNotFound();
    }

    public function test_category_cycles_and_deleting_used_dictionaries_are_rejected(): void
    {
        $root = Category::create(['name' => 'Sport', 'slug' => 'sport']);
        $child = Category::create(['name' => 'Shoes', 'slug' => 'shoes', 'parent_id' => $root->id]);
        try {
            $root->update(['parent_id' => $child->id]);
            $this->fail('Cycle accepted');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('parent_id', $exception->errors());
        }
        $brand = Brand::create(['name' => 'Brand', 'slug' => 'brand']);
        $manufacturer = Manufacturer::create(['name' => 'Factory', 'slug' => 'factory']);
        $this->product($child, ['brand_id' => $brand->id, 'manufacturer_id' => $manufacturer->id]);
        foreach ([$brand, $manufacturer, $child] as $entry) {
            try {
                $entry->delete();
                $this->fail('Used entry deleted');
            } catch (ValidationException) {
                $this->assertTrue($entry->fresh()->exists);
            }
        }
    }

    public function test_blank_specification_and_invalid_product_links_are_rejected(): void
    {
        $category = Category::create(['name' => 'Sport', 'slug' => 'sport']);
        $product = $this->product($category);
        try {
            $product->update(['manufacturer_id' => 9999]);
            $this->fail('Invalid link accepted');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('manufacturer_id', $exception->errors());
        }
        $this->expectException(ValidationException::class);
        $product->specifications()->create(['name' => 'Weight', 'value' => '  ']);
    }

    public function test_migration_deduplicates_legacy_brands_and_can_be_rolled_back_without_losing_names(): void
    {
        $migration = require database_path('migrations/2026_10_10_000001_complete_product_catalog.php');
        $migration->down();
        $categoryId = DB::table('categories')->insertGetId(['name' => 'Sport', 'slug' => 'sport']);
        foreach (['Nike', ' nike ', 'NIKE', 'ACME!', 'ACME?'] as $index => $name) {
            DB::table('products')->insert([
                'category_id' => $categoryId, 'name' => 'Product', 'slug' => 'product-'.$index,
                'brand' => $name, 'description' => '', 'image_url' => '', 'price_cents_net' => 100,
            ]);
        }
        $migration->up();
        $this->assertDatabaseCount('brands', 3);
        $this->assertSame(1, DB::table('products')
            ->whereIn('slug', ['product-0', 'product-1', 'product-2'])
            ->distinct()->count('brand_id'));
        $this->assertDatabaseHas('brands', ['slug' => 'acme-2']);
        DB::table('brands')->where('slug', 'nike')->update(['name' => 'New Nike']);
        $migration->down();
        $this->assertDatabaseHas('products', ['slug' => 'product-0', 'brand' => 'New Nike']);
        $migration->up();
    }

    public function test_catalog_keeps_the_saved_regional_preference_and_reports_pricing_failures(): void
    {
        ExchangeRate::create(['currency' => 'BYN', 'rate' => 3, 'source' => 'test', 'quoted_at' => now()]);
        $category = Category::create(['name' => 'Sport', 'slug' => 'sport']);
        $this->product($category);
        $this->withSession(['market' => ['country' => 'BY', 'locale' => 'ru']])
            ->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('market.locale', 'ru')->where('market.currency', 'BYN')
            ->where('products.data.0.display_price.currency', 'BYN')
            ->where('products.data.0.display_price.gross_cents', 36000));
        ExchangeRate::query()->delete();
        Http::fake(['*' => Http::response([], 503)]);
        $this->app->forgetInstance(ExchangeRateService::class);
        $this->withSession(['market' => ['country' => 'BY', 'locale' => 'ru']])
            ->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->has('products.data.0.pricing_error')->missing('products.data.0.display_price'));
    }

    public function test_seed_is_repeatable_and_exercises_catalog_states(): void
    {
        $this->seed();
        $this->seed();
        $this->assertDatabaseCount('products', 8);
        $this->assertDatabaseCount('manufacturers', 7);
        $this->assertDatabaseCount('categories', 12);
        $this->assertDatabaseHas('product_specifications', ['name' => 'Pojemność', 'value' => '24', 'unit' => 'l']);
        $this->assertFalse(Product::where('slug', 'court-backpack')->first()->available);
        $this->get('/shop?category=outdoor')->assertInertia(fn (Assert $page) => $page->where('products.total', 2));
    }
}
