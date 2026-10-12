<?php

namespace App\Presentation\Http\Api\V1;

use App\Application\Catalog\CategoryNotFound;
use App\Application\Catalog\StorefrontCatalog;
use App\Application\Commerce\MarketContext;
use App\Application\Commerce\PricingService;
use App\Infrastructure\Http\MarketContextResolver;
use App\Infrastructure\Localization\TranslationCatalog;
use DomainException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

final readonly class CatalogController
{
    public function __construct(
        private StorefrontCatalog $catalog,
        private MarketContextResolver $markets,
        private PricingService $pricing,
        private TranslationCatalog $translations,
    ) {
    }

    #[Route('/api/v1/store/home', name: 'api.v1.store.home', methods: ['GET'])]
    public function home(Request $request): JsonResponse
    {
        $market = $this->markets->forRequest($request);
        $categories = array_values(array_filter(
            $this->catalog->categories($market->locale),
            fn (array $category): bool => $category['parent_id'] === null,
        ));

        return new JsonResponse([
            'data' => [
                'categories' => $categories,
                'featured' => $this->priced(
                    $this->catalog->featured($market->locale),
                    $market,
                ),
            ],
        ]);
    }

    #[Route('/api/v1/categories', name: 'api.v1.categories', methods: ['GET'])]
    public function categories(Request $request): JsonResponse
    {
        $market = $this->markets->forRequest($request);

        return new JsonResponse([
            'data' => $this->catalog->categories($market->locale),
        ]);
    }

    #[Route('/api/v1/products', name: 'api.v1.products', methods: ['GET'])]
    public function products(
        Request $request,
        #[MapQueryString(validationFailedStatusCode: 422)] CatalogQuery $query = new CatalogQuery(),
    ): JsonResponse {
        $market = $this->markets->forRequest($request);

        try {
            $page = $this->catalog->page($query->filters(), $query->page, 12, $market->locale);
        } catch (CategoryNotFound) {
            return $this->notFound('Category not found.');
        }

        return new JsonResponse([
            'data' => $this->priced($page['data'], $market),
            'meta' => [
                'current_page' => $page['current_page'],
                'last_page' => $page['last_page'],
                'per_page' => $page['per_page'],
                'total' => $page['total'],
            ],
            'filters' => $query->filters(),
        ]);
    }

    #[Route('/api/v1/products/{slug}', name: 'api.v1.products.show', methods: ['GET'])]
    public function product(string $slug, Request $request): JsonResponse
    {
        $market = $this->markets->forRequest($request);
        $detail = $this->catalog->detail($slug, $market->locale);
        if ($detail === null) {
            return $this->notFound('Product not found.');
        }

        return new JsonResponse([
            'data' => [
                'product' => $this->price($detail['product'], $market),
                'breadcrumbs' => $detail['breadcrumbs'],
                'related' => $this->priced($detail['related'], $market),
            ],
        ]);
    }

    private function priced(array $products, MarketContext $market): array
    {
        return array_map(
            fn (array $product): array => $this->price($product, $market),
            $products,
        );
    }

    private function price(array $product, MarketContext $market): array
    {
        try {
            $product['display_price'] = $this->pricing->displayPrice(
                $product['price_cents_net'],
                $market,
            );
            foreach ($product['variants'] ?? [] as $index => $variant) {
                $product['variants'][$index]['display_price'] = $this->pricing->displayPrice(
                    $variant['price_cents_net'] ?? $product['price_cents_net'],
                    $market,
                );
            }
        } catch (DomainException) {
            $product['pricing_error'] = $this->translations->get(
                'exchange_rate_unavailable',
                [],
                $market->locale,
            );
        }

        return $product;
    }

    private function notFound(string $detail): JsonResponse
    {
        return new JsonResponse([
            'type' => 'https://httpstatuses.com/404',
            'title' => 'Not Found',
            'status' => 404,
            'detail' => $detail,
        ], 404);
    }
}
