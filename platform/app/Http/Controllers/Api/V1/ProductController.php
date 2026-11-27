<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Commerce\Services\PublishedCatalogue;
use App\Http\Controllers\Controller;
use App\Support\Api\ApiAnswer;
use App\Support\Api\Problem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GET /api/v1/catalogue/products: the products the admin has **published**, in pages, newest change first.
 *
 * What the shop reads. A draft is never handed out, and never is a withdrawn or an archived product: to the shop
 * the three look the same — as if the product had never existed. The filters are the ones the contract names and
 * no other (`category`, `series`, `since`): a misspelt one is refused by the door of the address, not ignored.
 *
 * GET /api/v1/catalogue/products/{product}: one published product, by the address the shop knows it by.
 * GET /api/v1/catalogue/products/{product}/picture/{image}/{version}: one of its photographs.
 */
final class ProductController extends Controller
{
    /** What a page holds when the client does not say. The contract's own limit is 100. */
    private const int PER_PAGE = 24;

    public function index(Request $request, PublishedCatalogue $catalogue): JsonResponse
    {
        if (! config('woodex.product_sync_enabled')) {
            throw new Problem(503, 'UNAVAILABLE', 'The product list is switched off at the moment.');
        }

        $perPage = min(100, max(1, $request->integer('per_page', self::PER_PAGE)));
        $page = max(1, $request->integer('page', 1));

        $result = $catalogue->page([
            'category' => $request->query('category'),
            'series' => $request->query('series'),
            'since' => $request->query('since'),
        ], $perPage);

        // A filter that names a category or a series nothing has is not an empty list: it is an address that
        // does not exist, and saying so is the difference between "we sell none" and "you typed it wrong".
        if ($result === null) {
            throw new Problem(404, 'NOT_FOUND', 'No category or series of this shop has that name.');
        }

        $paginator = new LengthAwarePaginator($result['items'], $result['total'], $perPage, $page, ['path' => $request->url()]);

        return ApiAnswer::page($paginator, static fn (array $product): array => self::addresses($product, $page, $perPage));
    }

    public function show(string $product, PublishedCatalogue $catalogue): JsonResponse
    {
        if (! config('woodex.product_sync_enabled')) {
            throw new Problem(503, 'UNAVAILABLE', 'The product list is switched off at the moment.');
        }

        $found = $catalogue->one($product);

        if ($found === null) {
            throw new Problem(404, 'NOT_FOUND', 'No published product of this shop has that address.');
        }

        return ApiAnswer::one(self::addresses($found, 1, 1));
    }

    /**
     * The records are looked up in the service, not by the route: the door of the address (its limit, the query
     * parameters it takes) is then passed before anything is asked of the database.
     */
    public function picture(string $product, int $image, int $version, PublishedCatalogue $catalogue): BinaryFileResponse
    {
        $file = $catalogue->pictureFile($product, $image, $version);

        if ($file === null) {
            throw new Problem(404, 'NOT_FOUND', 'No photograph of a published product is at this address.');
        }

        return response()->file($file['path'], [
            'Content-Type' => $file['mime'],
            'Cache-Control' => 'public, max-age=60',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * The product's place in the whole list, not only on the page it came in.
     *
     * `position` is the product's place in the whole list, not in the page: a client that reads page two of the
     * list it asked for receives 25, 26, 27 — not 1, 2, 3 — and can put the list back together.
     *
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    private static function addresses(array $product, int $page, int $perPage): array
    {
        $product['position'] = ($page - 1) * $perPage + (int) ($product['position'] ?? 1);

        return $product;
    }
}
