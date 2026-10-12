<?php

namespace App\Presentation\Http\Api\V1;

use App\Application\Cart\CartException;
use App\Infrastructure\Http\MarketContextResolver;
use App\Infrastructure\Persistence\Doctrine\Cart\DbalCartService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class CartController
{
    public function __construct(
        private DbalCartService $carts,
        private MarketContextResolver $markets,
    ) {
    }

    #[Route('/api/v1/cart', name: 'api.v1.cart.show', methods: ['GET'])]
    public function show(Request $request): JsonResponse
    {
        return $this->response($request);
    }

    #[Route('/api/v1/cart/items', name: 'api.v1.cart.items.add', methods: ['POST'])]
    public function add(
        Request $request,
        #[MapRequestPayload] AddCartItemPayload $payload,
    ): JsonResponse {
        try {
            $this->carts->add($request, $payload->variant_id, $payload->quantity);
        } catch (CartException $exception) {
            return $this->error($exception);
        }

        return $this->response($request, 201);
    }

    #[Route('/api/v1/cart/items/{id}', name: 'api.v1.cart.items.update', methods: ['PATCH'])]
    public function update(
        int $id,
        Request $request,
        #[MapRequestPayload] UpdateCartItemPayload $payload,
    ): JsonResponse {
        try {
            $this->carts->update($request, $id, $payload->quantity);
        } catch (CartException $exception) {
            return $this->error($exception);
        }

        return $this->response($request);
    }

    #[Route('/api/v1/cart/items/{id}', name: 'api.v1.cart.items.remove', methods: ['DELETE'])]
    public function remove(int $id, Request $request): JsonResponse
    {
        try {
            $this->carts->remove($request, $id);
        } catch (CartException $exception) {
            return $this->error($exception);
        }

        return $this->response($request);
    }

    private function response(Request $request, int $status = 200): JsonResponse
    {
        $market = $this->markets->forRequest($request);

        return new JsonResponse([
            'data' => $this->carts->data($request, $market),
        ], $status);
    }

    private function error(CartException $exception): JsonResponse
    {
        $notFound = str_ends_with($exception->getMessage(), 'not found.');
        $status = $notFound ? 404 : 422;

        return new JsonResponse([
            'type' => "https://httpstatuses.com/{$status}",
            'title' => $notFound ? 'Not Found' : 'Cart update failed.',
            'status' => $status,
            'detail' => $exception->getMessage(),
        ], $status);
    }
}
