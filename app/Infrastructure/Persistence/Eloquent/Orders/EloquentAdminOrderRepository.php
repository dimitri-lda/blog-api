<?php

namespace App\Infrastructure\Persistence\Eloquent\Orders;

use App\Domain\Orders\Repository\AdminOrderRepository;
use App\Domain\Orders\ValueObjects\OrderStatus;
use App\Models\Order;

final class EloquentAdminOrderRepository implements AdminOrderRepository
{
    public function paginate(?string $search, ?OrderStatus $status, int $page, int $perPage): array
    {
        $query = Order::query()->withCount('items')->latest();

        if ($search) {
            $query->where(fn ($orders) => $orders
                ->where('number', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }

        if ($status) {
            $query->where('status', $status->value);
        }

        $orders = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $orders->getCollection()->map(fn (Order $order) => [
                'id' => $order->id,
                'number' => $order->number,
                'email' => $order->email,
                'status' => $order->status,
                'total_cents' => $order->total_cents,
                'currency' => $order->currency,
                'items_count' => $order->items_count,
                'created_at' => $order->created_at?->toIso8601String(),
            ])->values()->all(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ];
    }

    public function findDetails(int $id): ?array
    {
        $order = Order::query()->with(['items', 'address', 'user'])->find($id);

        if (! $order) {
            return null;
        }

        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status,
            'email' => $order->email,
            'phone' => $order->phone,
            'delivery_method' => $order->delivery_method,
            'subtotal_cents' => $order->subtotal_cents,
            'delivery_cents' => $order->delivery_cents,
            'total_cents' => $order->total_cents,
            'currency' => $order->currency,
            'market' => $order->market,
            'locale' => $order->locale,
            'exchange_rate' => $order->exchange_rate,
            'vat_rate_basis_points' => $order->vat_rate_basis_points,
            'net_subtotal_cents' => $order->net_subtotal_cents,
            'tax_cents' => $order->tax_cents,
            'delivery_net_cents' => $order->delivery_net_cents,
            'delivery_tax_cents' => $order->delivery_tax_cents,
            'created_at' => $order->created_at?->toIso8601String(),
            'customer' => $order->user ? ['id' => $order->user->id, 'name' => $order->user->name, 'email' => $order->user->email] : null,
            'address' => $order->address ? [
                'first_name' => $order->address->first_name,
                'last_name' => $order->address->last_name,
                'line1' => $order->address->line1,
                'line2' => $order->address->line2,
                'city' => $order->address->city,
                'postal_code' => $order->address->postal_code,
                'country' => $order->address->country,
            ] : null,
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'variant_name' => $item->variant_name,
                'unit_price_cents' => $item->unit_price_cents,
                'net_unit_price_cents' => $item->net_unit_price_cents,
                'tax_cents' => $item->tax_cents,
                'quantity' => $item->quantity,
                'line_total_cents' => $item->line_total_cents,
            ])->values()->all(),
        ];
    }

    public function statusOf(int $id): ?OrderStatus
    {
        $status = Order::query()->whereKey($id)->value('status');

        if (! $status) {
            return null;
        }

        return $status instanceof OrderStatus ? $status : OrderStatus::tryFrom($status);
    }

    public function updateStatus(int $id, OrderStatus $status): void
    {
        Order::query()->whereKey($id)->update(['status' => $status->value]);
    }
}
