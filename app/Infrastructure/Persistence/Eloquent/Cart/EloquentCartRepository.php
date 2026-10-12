<?php

namespace App\Infrastructure\Persistence\Eloquent\Cart;

use App\Domain\Cart\Repository\CartRepository;
use App\Domain\Orders\Entities\OrderLine;
use App\Models\Cart;
use DomainException;

final class EloquentCartRepository implements CartRepository
{
    public function orderLinesFor(?int $userId, ?string $token): array
    {
        $cart = $this->find($userId, $token)?->load('items.variant.product');
        $lines = $cart?->items->map(function ($item): OrderLine {
            $variant = $item->variant;
            $price = $variant->price_cents_net ?? $variant->product->price_cents_net;
            if (! $variant->product->active || $variant->stock < $item->quantity) {
                throw new DomainException("Product variant {$variant->id} is unavailable.");
            }

            return new OrderLine($variant->id, $variant->product->localized_name, $variant->localized_name, $price, $item->quantity);
        })->all() ?? [];

        return $lines;
    }

    private function find(?int $userId, ?string $token): ?Cart
    {
        return $userId !== null ? Cart::where('user_id', $userId)->first() : Cart::where('token', $token)->first();
    }

    public function clear(?int $userId, ?string $token): void
    {
        $this->find($userId, $token)?->items()->delete();
    }
}
