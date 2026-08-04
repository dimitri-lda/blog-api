<?php

namespace App\Http\Middleware;

use App\Services\MarketContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SetMarketLocale
{
    public function __construct(private readonly MarketContext $market) {}

    public function handle(Request $request, Closure $next): Response
    {
        $context = $this->market->forRequest($request);
        app()->setLocale($context['locale']);
        $request->attributes->set('market', $context);

        return $next($request);
    }
}
