<?php

namespace App\Infrastructure\Persistence\Eloquent\Orders;

use App\Domain\Orders\Repository\AdminOrderRepository;
use App\Domain\Orders\ValueObjects\OrderStatus;
use App\Models\Order;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

final class EloquentAdminOrderRepository implements AdminOrderRepository
{
    public function paginate(?string $search, ?OrderStatus $status, int $page, int $perPage): array
    {
        $query = Order::query()->withCount('items')->latest();

        if ($search) {
            $pattern = $this->likePattern($search);
            $query->where(fn ($orders) => $orders
                ->where('number', 'like', $pattern)
                ->orWhere('email', 'like', $pattern));
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
            'customer' => $order->user ? [
                'id' => $order->user->id,
                'name' => $order->user->name,
                'email' => $order->user->email,
            ] : null,
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
        DB::transaction(function () use ($id, $status): void {
            $order = Order::with('items')->whereKey($id)->lockForUpdate()->first();
            if (! $order) {
                return;
            }
            $order->status->transitionTo($status);

            if ($status === OrderStatus::Cancelled
                && $order->inventory_reserved_at !== null
                && $order->inventory_released_at === null) {
                $variants = ProductVariant::whereIn(
                    'id',
                    $order->items->pluck('product_variant_id')->filter()->all(),
                )->lockForUpdate()->get()->keyBy('id');
                foreach ($order->items as $item) {
                    $variant = $item->product_variant_id
                        ? $variants->get($item->product_variant_id)
                        : null;
                    if ($variant) {
                        $variant->increment('stock', $item->quantity);
                    }
                }
                $order->inventory_released_at = now();
            }

            $order->status = $status;
            $order->save();
        });
    }

    private function likePattern(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
    }
}
