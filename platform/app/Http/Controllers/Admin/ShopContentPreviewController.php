<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Commerce\Services\ShopContent;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ShopContentPreviewController extends Controller
{
    public function show(Request $request, int $revision, ShopContent $service)
    {
        abort_unless($request->user()->can('commerce.catalog.view'), 403);
        $row = $service->revision($revision);
        $manager = $request->user()->can('commerce.catalog.manage');
        abort_unless($manager || (int) $service->state()->published_revision_id === $revision, 403);
        $content = $service->normalize(json_decode($row->content, true, flags: JSON_THROW_ON_ERROR));
        if ($manager) {
            $content = $service->validate($content);
        }
        $sections = $service->project($content);
        if ($manager) {
            DB::table('shop_content_previews')->updateOrInsert(['revision_id' => $revision, 'user_id' => $request->user()->id], ['previewed_at' => now(), 'context_hash' => $service->fingerprint($content, $sections)]);
        }

        return response()->view('commerce.shop-content-preview', compact('revision', 'content', 'sections'))
            ->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'");
    }
}
