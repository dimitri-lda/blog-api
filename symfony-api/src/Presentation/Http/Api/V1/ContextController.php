<?php

namespace App\Presentation\Http\Api\V1;

use App\Application\Commerce\CountryCatalog;
use App\Infrastructure\Http\MarketContextResolver;
use App\Infrastructure\Localization\TranslationCatalog;
use App\Infrastructure\Security\LoginSuccessHandler;
use App\Infrastructure\Security\SecurityUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class ContextController
{
    public function __construct(
        private MarketContextResolver $markets,
        private CountryCatalog $countries,
        private TranslationCatalog $translations,
        private CsrfTokenManagerInterface $csrf,
        private Security $security,
    ) {
    }

    #[Route('/api/v1/context', name: 'api.v1.context', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $market = $this->markets->forRequest($request);
        $user = $this->security->getUser();

        return new JsonResponse([
            'data' => [
                'auth' => [
                    'user' => $user instanceof SecurityUser ? LoginSuccessHandler::user($user) : null,
                ],
                'market' => $market->toArray(),
                'countries' => $this->countries->all(),
                'translations' => $this->translations->forLocale($market->locale),
                'csrf_token' => $this->csrf->getToken('api')->getValue(),
            ],
        ]);
    }
}
