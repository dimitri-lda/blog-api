<?php

namespace App\Presentation\Http\Api\V1;

use App\Domain\Orders\ValueObjects\OrderStatus;
use App\Infrastructure\Persistence\Doctrine\Orders\DbalAdminOrderService;
use DomainException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/api/v1/admin/orders')]
final readonly class AdminOrderController
{
    public function __construct(private DbalAdminOrderService $orders)
    {
    }

    #[Route('', name: 'api.v1.admin.orders', methods: ['GET'])]
    public function index(
        #[MapQueryString(validationFailedStatusCode: 422)] AdminOrderQuery $query = new AdminOrderQuery(),
    ): JsonResponse {
        $status = $query->status === null ? null : OrderStatus::from($query->status);
        $page = $this->orders->page($query->q, $status, $query->page, 20);

        return new JsonResponse([
            'data' => $page['data'],
            'meta' => $page['meta'],
            'filters' => [
                'q' => $query->q ?? '',
                'status' => $query->status ?? '',
            ],
            'statuses' => array_map(
                fn (OrderStatus $entry): string => $entry->value,
                OrderStatus::cases(),
            ),
        ]);
    }

    #[Route('/{id}', name: 'api.v1.admin.orders.show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $order = $this->orders->details($id);
        if ($order === null) {
            return $this->notFound();
        }
        $status = OrderStatus::from($order['status']);

        return new JsonResponse([
            'data' => [
                'order' => $order,
                'next_statuses' => array_map(
                    fn (OrderStatus $entry): string => $entry->value,
                    $status->allowedNextStatuses(),
                ),
            ],
        ]);
    }

    #[Route('/{id}/status', name: 'api.v1.admin.orders.status', methods: ['PATCH'])]
    public function status(
        int $id,
        #[MapRequestPayload] ChangeOrderStatusPayload $payload,
    ): JsonResponse {
        try {
            $result = $this->orders->changeStatus($id, OrderStatus::from($payload->status));
        } catch (DomainException $exception) {
            if ($exception->getMessage() === 'Order not found.') {
                return $this->notFound();
            }

            return new JsonResponse([
                'type' => 'https://httpstatuses.com/422',
                'title' => 'Validation failed.',
                'status' => 422,
                'errors' => ['status' => [$exception->getMessage()]],
            ], 422);
        }

        return new JsonResponse([
            'data' => [
                'status' => $result['status']->value,
                'next_statuses' => $result['next_statuses'],
            ],
        ]);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse([
            'type' => 'https://httpstatuses.com/404',
            'title' => 'Not Found',
            'status' => 404,
        ], 404);
    }
}
