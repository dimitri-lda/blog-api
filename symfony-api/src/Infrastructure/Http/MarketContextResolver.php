<?php

namespace App\Infrastructure\Http;

use App\Application\Commerce\MarketContext;
use App\Application\Commerce\MarketContextFactory;
use Symfony\Component\HttpFoundation\Request;

final readonly class MarketContextResolver
{
    public function __construct(
        private MarketContextFactory $factory,
        private GeoIpResolver $geoIp,
        private string $defaultCountry,
    ) {
    }

    public function forRequest(Request $request): MarketContext
    {
        $selected = $request->getSession()->get('market');
        if (
            is_array($selected)
            && isset($selected['country'], $selected['locale'])
            && is_string($selected['country'])
            && is_string($selected['locale'])
        ) {
            return $this->factory->make($selected['country'], $selected['locale']);
        }

        $context = $this->factory->make(
            $this->geoIp->country($request->getClientIp()) ?? $this->defaultCountry,
        );
        $this->store($request, $context);

        return $context;
    }

    public function set(Request $request, string $country, ?string $locale): MarketContext
    {
        $context = $this->factory->make($country, $locale);
        $this->store($request, $context);

        return $context;
    }

    private function store(Request $request, MarketContext $context): void
    {
        $request->getSession()->set('market', [
            'country' => $context->country,
            'locale' => $context->locale,
        ]);
    }
}
