<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\ProductMedia;
use App\Domain\Commerce\Services\ProductCatalogue;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class ProductPreviewController extends Controller
{
    public function show(Request $request, Product $product, ProductCatalogue $service)
    {
        abort_unless($request->user()->can('commerce.catalog.view'), 403);
        abort_if($product->archived_at, 404);
        $manage = $request->user()->can('commerce.catalog.manage');
        if ($manage) {
            $data = $product->draft;
            DB::table('commerce_product_previews')->updateOrInsert(['product_id' => $product->id, 'user_id' => $request->user()->id], ['version' => $product->version, 'fingerprint' => $service->fingerprint($product), 'previewed_at' => now()]);
        } else {
            abort_unless($product->published_revision_id, 403);
            $revision = DB::table('commerce_product_revisions')->where('product_id', $product->id)->find($product->published_revision_id);
            abort_unless($revision, 404);
            $data = json_decode($revision->content, true, flags: JSON_THROW_ON_ERROR);
        }
        $related = Product::whereNotNull('published_revision_id')->whereNull('archived_at')->where('id', '!=', $product->id)->get();
        if ($data['related_mode'] === 'manual') {
            $related = collect($data['related_ids'])->map(fn ($id) => $related->firstWhere('id', (int) $id))->filter();
        } elseif ($data['related_mode'] === 'off') {
            $related = collect();
        } else {
            $related = $related->filter(function ($item) use ($data) {
                $revision = DB::table('commerce_product_revisions')->where('product_id', $item->id)->find($item->published_revision_id);

                return $revision && array_intersect(json_decode($revision->content, true)['category_ids'], $data['category_ids']);
            });
        }
        // Only project published identity for related products, never their newer drafts.
        $related = $related->take(12)->map(function ($item) {
            $revision = DB::table('commerce_product_revisions')->where('product_id', $item->id)->find($item->published_revision_id);

            return ['id' => $item->id, 'name' => json_decode($revision->content, true)['name']];
        });

        return response()->view('commerce.product-preview', ['product' => $product, 'data' => $data, 'related' => $related, 'categories' => Category::whereIn('id', $data['category_ids'])->pluck('name')])
            ->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'");
    }

    public function image(Request $request, ProductMedia $media)
    {
        abort_unless($request->user()->can('commerce.catalog.view'), 403);
        $product = Product::findOrFail($media->product_id);
        if (! $request->user()->can('commerce.catalog.manage')) {
            $revision = $product->published_revision_id ? DB::table('commerce_product_revisions')->where('product_id', $product->id)->find($product->published_revision_id) : null;
            abort_unless($revision && ! $product->archived_at && in_array($media->id, array_map('intval', array_column(json_decode($revision->content, true)['images'], 'id')), true), 403);
        }
        abort_unless(preg_match('~^commerce/product-media/[a-f0-9]{64}\.(jpg|png|webp)$~', $media->path) && Storage::disk('local')->exists($media->path), 404);

        return response()->file(Storage::disk('local')->path($media->path), ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }
}
