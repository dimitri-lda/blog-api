<?php

namespace App\Http\Controllers;

use App\Application\Payments\ShopPayService;
use App\Http\Requests\Store\ShopPayQuoteRequest;
use App\Http\Requests\Store\ShopPaySubmitRequest;
use App\Models\Order;
use App\Services\ShopPay\ShopPayConfiguration;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopPayPaymentController extends Controller
{
    public function __construct(
        private readonly ShopPayConfiguration $config,
        private readonly ShopPayService $service,
    ) {}

    public function createSession(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);
        if ($response = $this->guardEnabled()) {
            return $response;
        }

        return $this->respond(fn () => $this->service->createSession($order, $request->ip() ?? '127.0.0.1'));
    }

    public function quote(ShopPayQuoteRequest $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);
        if ($response = $this->guardEnabled()) {
            return $response;
        }
        $validated = $request->validated();

        return $this->respond(fn () => $this->service->updateQuote(
            $order,
            $validated['session_token'],
            $validated['shipping_address'],
            $validated['delivery_method'],
        ));
    }

    public function submit(ShopPaySubmitRequest $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);
        if ($response = $this->guardEnabled()) {
            return $response;
        }
        $validated = $request->validated();

        return $this->respond(fn () => $this->service->submit(
            $order,
            $validated['session_token'],
            $validated['payment_method'],
            $request->ip() ?? '127.0.0.1',
        ));
    }

    public function reconcile(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);
        if ($response = $this->guardEnabled()) {
            return $response;
        }

        return $this->respond(fn () => ['paid' => $this->service->reconcile($order)]);
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        abort_unless(
            ($request->user() && $request->user()->id === $order->user_id)
                || $request->session()->get('last_order') === $order->number,
            404,
        );
    }

    private function guardEnabled(): ?JsonResponse
    {
        return $this->config->enabled()
            ? null
            : response()->json(['message' => 'Shop Pay is not available.'], 503);
    }

    private function respond(callable $action): JsonResponse
    {
        try {
            return response()->json($action());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
