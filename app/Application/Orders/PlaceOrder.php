<?php

namespace App\Application\Orders;

use App\Domain\Cart\Contracts\CartRepository;
use App\Domain\Orders\Contracts\OrderRepository;
use App\Domain\Orders\Order;
use App\Domain\Orders\ValueObjects\DeliveryMethod;
use App\Domain\Orders\ValueObjects\ShippingAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class PlaceOrder
{
    public function __construct(
        private CartRepository $carts,
        private OrderRepository $orders,
    ) {}

    /** @param array{email:string,phone:string,first_name:string,last_name:string,line1:string,line2?:?string,city:string,postal_code:string,country:string,delivery_method:string} $data */
    public function handle(array $data, ?int $userId, ?string $cartToken): Order
    {
        return DB::transaction(function () use ($data, $userId, $cartToken): Order {
            $order = new Order(
                number: 'SP-'.strtoupper(Str::random(8)),
                customerId: $userId,
                email: $data['email'],
                phone: $data['phone'],
                deliveryMethod: DeliveryMethod::from($data['delivery_method']),
                shippingAddress: new ShippingAddress($data['first_name'], $data['last_name'], $data['line1'], $data['line2'] ?? null, $data['city'], $data['postal_code'], $data['country']),
                lines: $this->carts->orderLinesFor($userId, $cartToken),
            );

            $this->orders->save($order);
            $this->carts->clear($userId, $cartToken);

            return $order;
        });
    }
}
