<?php

namespace Tests\Feature\Admin;

use App\Domain\Orders\ValueObjects\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['admin.domain' => 'admin.test']);
    }

    public function test_regular_users_cannot_access_admin(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('admin.orders.index'));
        $response->assertForbidden();
    }

    public function test_admin_can_list_and_filter_orders(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->order(['number' => 'SP-MATCH', 'status' => 'paid']);
        $this->order(['number' => 'SP-OTHER', 'status' => 'pending_payment']);

        $response = $this->actingAs($admin)->get(route('admin.orders.index', ['q' => 'SP-MATCH', 'status' => 'paid']));
        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('Admin/Orders/Index')
            ->where('orders.data', fn ($orders) => count($orders) === 1 && $orders[0]['number'] === 'SP-MATCH')
            ->where('filters.status', 'paid'));
    }

    public function test_admin_can_view_order_details(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin']);
        $order = $this->order();
        $order->items()->create(['name' => 'Running shoes', 'variant_name' => '42', 'unit_price_cents' => 5000, 'quantity' => 1, 'line_total_cents' => 5000]);

        $response = $this->actingAs($admin)->get(route('admin.orders.show', $order));
        $response->assertOk()->assertInertia(fn ($page) => $page->component('Admin/Orders/Show')->where('order.number', $order->number)->where('order.items.0.name', 'Running shoes'));
    }

    public function test_admin_can_change_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->order();

        $response = $this->actingAs($admin)->patch(route('admin.orders.status.update', $order), ['status' => 'paid']);
        $response->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
    }

    public function test_admin_cannot_skip_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->order();

        $response = $this->actingAs($admin)->patch(route('admin.orders.status.update', $order), ['status' => 'shipped']);
        $response->assertSessionHasErrors('status');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending_payment']);
    }

    private function order(array $attributes = []): Order
    {
        return Order::create(array_merge(['number' => 'SP-'.fake()->unique()->numerify('########'), 'email' => fake()->safeEmail(), 'phone' => '123456789', 'status' => OrderStatus::PendingPayment, 'delivery_method' => 'standard', 'subtotal_cents' => 5000, 'delivery_cents' => 0, 'total_cents' => 5000, 'currency' => 'EUR'], $attributes));
    }
}
