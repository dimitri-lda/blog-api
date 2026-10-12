<?php

namespace App\Presentation\Http\Api\V1;

use App\Application\Commerce\CountryCatalog;
use App\Application\Orders\PlaceOrderService;
use App\Application\Payments\ShopPayService;
use App\Infrastructure\Payments\ShopPayConfiguration;
use App\Application\Security\UserIdentity;
use App\Infrastructure\Http\MarketContextResolver;
use App\Infrastructure\Persistence\Doctrine\Cart\DbalCartService;
use App\Infrastructure\Persistence\Doctrine\Orders\DbalOrderReader;
use App\Infrastructure\Persistence\Doctrine\Users\DbalProfileService;
use App\Infrastructure\Security\LoginSuccessHandler;
use App\Infrastructure\Security\SecurityUser;
use DomainException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class OrderController
{
    public function __construct(
        private PlaceOrderService $placeOrder,
        private DbalOrderReader $orders,
        private DbalCartService $carts,
        private MarketContextResolver $markets,
        private CountryCatalog $countries,
        private Security $security,
        private DbalProfileService $profiles,
        private ShopPayService $shopPay,
        private ShopPayConfiguration $shopPayConfiguration,
    ) {
    }

    #[Route('/api/v1/checkout', name: 'api.v1.checkout', methods: ['GET'])]
    public function checkout(Request $request): JsonResponse
    {
        $market = $this->markets->forRequest($request);
        $user = $this->security->getUser();

        return new JsonResponse([
            'data' => [
                'cart' => $this->carts->data($request, $market),
                'user' => $user instanceof SecurityUser
                    ? LoginSuccessHandler::user($user)
                    : null,
                'address' => $user instanceof SecurityUser
                    ? $this->profiles->address($user)
                    : null,
            ],
        ]);
    }

    #[Route('/api/v1/orders', name: 'api.v1.orders.create', methods: ['POST'])]
    public function create(
        Request $request,
        #[MapRequestPayload] PlaceOrderPayload $payload,
    ): JsonResponse {
        if (!$this->countries->supports($payload->country)) {
            return $this->validationError('country', 'Choose a supported country.');
        }

        try {
            $order = $this->placeOrder->handle(
                $request,
                $payload->toArray(),
                $this->markets->forRequest($request),
            );
        } catch (DomainException $exception) {
            return $this->validationError('cart', $exception->getMessage());
        }
        $request->getSession()->set('last_order', $order->number);

        return new JsonResponse([
            'data' => [
                'id' => $order->id,
                'number' => $order->number,
            ],
        ], 201);
    }

    #[Route('/api/v1/orders/{number}', name: 'api.v1.orders.show', methods: ['GET'])]
    public function show(string $number, Request $request): JsonResponse
    {
        $order = $this->orders->byNumber($number);
        if ($order === null || !$this->canRead($order, $request)) {
            return new JsonResponse([
                'type' => 'https://httpstatuses.com/404',
                'title' => 'Not Found',
                'status' => 404,
            ], 404);
        }

        $order['shop_pay'] = $this->shopPayConfiguration->enabled()
            ? $this->shopPay->storefrontState($order)
            : null;

        return new JsonResponse(['data' => $order]);
    }

    private function canRead(array $order, Request $request): bool
    {
        $user = $this->security->getUser();

        return ($user instanceof UserIdentity && $order['user_id'] === $user->id())
            || $request->getSession()->get('last_order') === $order['number'];
    }

    private function validationError(string $field, string $message): JsonResponse
    {
        return new JsonResponse([
            'type' => 'https://httpstatuses.com/422',
            'title' => 'Validation failed.',
            'status' => 422,
            'errors' => [$field => [$message]],
        ], 422);
    }
}
