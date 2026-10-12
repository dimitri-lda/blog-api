<?php

namespace App\Presentation\Http\Api\V1;

use App\Application\Commerce\CountryCatalog;
use App\Infrastructure\Http\MarketContextResolver;
use App\Infrastructure\Localization\TranslationCatalog;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PreferenceController
{
    public function __construct(
        private MarketContextResolver $markets,
        private CountryCatalog $countries,
        private TranslationCatalog $translations,
    ) {
    }

    #[Route('/api/v1/preferences', name: 'api.v1.preferences.update', methods: ['PUT'])]
    public function __invoke(
        Request $request,
        #[MapRequestPayload] PreferencePayload $payload,
    ): JsonResponse {
        if (!$this->countries->supports($payload->country)) {
            return new JsonResponse([
                'type' => 'https://httpstatuses.com/422',
                'title' => 'Validation failed.',
                'status' => 422,
                'errors' => [
                    'country' => ['Choose a supported country.'],
                ],
            ], 422);
        }

        $market = $this->markets->set($request, $payload->country, $payload->locale);

        return new JsonResponse([
            'data' => [
                'market' => $market->toArray(),
                'message' => $this->translations->get('preferences_updated', [], $market->locale),
            ],
        ]);
    }
}
