<?php

namespace App\Http\Controllers\Admin;

use App\Application\Orders\Admin\ChangeOrderStatus;
use App\Application\Orders\Admin\GetOrderDetails;
use App\Application\Orders\Admin\ListOrders;
use App\Domain\Orders\ValueObjects\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangeOrderStatusRequest;
use App\Http\Requests\Admin\ListOrdersRequest;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function index(ListOrdersRequest $request, ListOrders $listOrders): Response
    {
        $filters = $request->validated();
        $status = isset($filters['status']) ? OrderStatus::from($filters['status']) : null;

        return Inertia::render('Admin/Orders/Index', [
            'orders' => $listOrders->handle($filters['q'] ?? null, $status, $filters['page'] ?? 1),
            'filters' => ['q' => $filters['q'] ?? '', 'status' => $filters['status'] ?? ''],
            'statuses' => array_map(fn (OrderStatus $status) => $status->value, OrderStatus::cases()),
        ]);
    }

    public function show(int $order, GetOrderDetails $getOrderDetails): Response
    {
        $details = $getOrderDetails->handle($order);
        abort_unless($details, 404);
        $currentStatus = $details['status'] instanceof OrderStatus
            ? $details['status']
            : OrderStatus::from($details['status']);

        return Inertia::render('Admin/Orders/Show', [
            'order' => $details,
            'nextStatuses' => array_map(fn (OrderStatus $status) => $status->value, $currentStatus->allowedNextStatuses()),
        ]);
    }

    public function updateStatus(int $order, ChangeOrderStatusRequest $request, ChangeOrderStatus $changeOrderStatus): RedirectResponse
    {
        try {
            $changeOrderStatus->handle($order, OrderStatus::from($request->validated('status')));
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['status' => $exception->getMessage()]);
        }

        return back()->with('success', 'Order status updated.');
    }
}
