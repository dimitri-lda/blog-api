<?php

namespace App\Presentation\Http\Api\V1;

use App\Application\Payments\ShopPayService;
use App\Application\Security\UserIdentity;
use App\Infrastructure\Payments\ShopPayConfiguration;
use App\Infrastructure\Persistence\Doctrine\Orders\DbalOrderReader;
use DomainException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/orders/{number}/payments/shop-pay')]
final readonly class ShopPayController
{
    public function __construct(
        private ShopPayConfiguration $configuration,
        private ShopPayService $payments,
        private DbalOrderReader $orders,
        private Security $security,
    ) {
    }

    #[Route('/session', name: 'api.v1.shop_pay.session', methods: ['POST'])]
    public function session(string $number, Request $request): JsonResponse
    {
        return $this->respond($number, $request, fn (array $order): array => $this->payments
            ->createSession($order, $request->getClientIp() ?? '127.0.0.1'));
    }

    #[Route('/quote', name: 'api.v1.shop_pay.quote', methods: ['PATCH'])]
    public function quote(
        string $number,
        Request $request,
        #[MapRequestPayload] ShopPayQuotePayload $payload,
    ): JsonResponse {
        return $this->respond($number, $request, fn (array $order): array => $this->payments
            ->updateQuote(
                $order,
                $payload->session_token,
                $payload->shipping_address,
                $payload->delivery_method,
            ));
    }

    #[Route('/submit', name: 'api.v1.shop_pay.submit', methods: ['POST'])]
    public function submit(
        string $number,
        Request $request,
        #[MapRequestPayload] ShopPaySubmitPayload $payload,
    ): JsonResponse {
        return $this->respond($number, $request, fn (array $order): array => $this->payments
            ->submit(
                $order,
                $payload->session_token,
                $payload->payment_method,
                $request->getClientIp() ?? '127.0.0.1',
            ));
    }

    #[Route('/reconcile', name: 'api.v1.shop_pay.reconcile', methods: ['POST'])]
    public function reconcile(string $number, Request $request): JsonResponse
    {
        return $this->respond(
            $number,
            $request,
            fn (array $order): array => ['paid' => $this->payments->reconcile($order)],
        );
    }

    private function respond(string $number, Request $request, callable $action): JsonResponse
    {
        if (!$this->configuration->enabled()) {
            return new JsonResponse([
                'type' => 'https://httpstatuses.com/503',
                'title' => 'Shop Pay is not available.',
                'status' => 503,
            ], 503);
        }

        $order = $this->orders->byNumber($number);
        if ($order === null || !$this->canRead($order, $request)) {
            return new JsonResponse([
                'type' => 'https://httpstatuses.com/404',
                'title' => 'Not Found',
                'status' => 404,
            ], 404);
        }

        try {
            return new JsonResponse(['data' => $action($order)]);
        } catch (DomainException $exception) {
            return new JsonResponse([
                'type' => 'https://httpstatuses.com/422',
                'title' => 'Shop Pay request failed.',
                'status' => 422,
                'detail' => $exception->getMessage(),
            ], 422);
        }
    }

    private function canRead(array $order, Request $request): bool
    {
        $user = $this->security->getUser();

        return ($user instanceof UserIdentity && $order['user_id'] === $user->id())
            || $request->getSession()->get('last_order') === $order['number'];
    }
}
