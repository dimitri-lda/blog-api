<?php

namespace App\Console\Commands;

use App\Application\Payments\ShopPayService;
use App\Domain\Payments\PaymentSessionStatus;
use App\Models\PaymentSession;
use App\Services\ShopPay\ShopPayConfiguration;
use Illuminate\Console\Command;

final class ReconcileShopPayPayments extends Command
{
    protected $signature = 'shop-pay:reconcile';

    protected $description = 'Reconcile processing Shop Pay sessions with the Shopify Admin API.';

    public function handle(ShopPayService $shopPay, ShopPayConfiguration $config): int
    {
        if (! $config->enabled()) {
            $this->line('Shop Pay is not configured; skipping reconciliation.');

            return self::SUCCESS;
        }
        PaymentSession::with('order')
            ->where('status', PaymentSessionStatus::Processing)
            ->each(function (PaymentSession $session) use ($shopPay) {
                try {
                    $paid = $shopPay->reconcile($session->order);
                    $this->line("{$session->source_identifier}: ".($paid ? 'paid' : 'pending'));
                } catch (\Throwable $exception) {
                    $this->error("{$session->source_identifier}: {$exception->getMessage()}");
                }
            });

        return self::SUCCESS;
    }
}
