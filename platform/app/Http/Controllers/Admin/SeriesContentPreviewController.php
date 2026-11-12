<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Commerce\Models\Series;
use App\Domain\Commerce\Services\SeriesContent;
use App\Domain\Commerce\Services\SeriesHero;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SeriesContentPreviewController extends Controller
{
    public function image(Request $request, int $revision)
    {
        abort_unless($request->user()->can('commerce.catalog.view'), 403);
        $row = DB::table('series_content_revisions')->where('id', $revision)->first();
        abort_unless($row, 404);
        $series = Series::findOrFail($row->series_id);
        abort_unless($request->user()->can('commerce.catalog.manage') || $series->published_revision_id === $revision, 403);
        $content = json_decode($row->content, true, flags: JSON_THROW_ON_ERROR);
        abort_unless(is_string($content['hero_path'] ?? null), 404);
        $path = app(SeriesHero::class)->path($content['hero_path']);

        return response()->file($path, ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    public function show(Request $request, int $revision, SeriesContent $service)
    {
        abort_unless($request->user()->can('commerce.catalog.view'), 403);
        $row = DB::table('series_content_revisions')->where('id', $revision)->first();
        abort_unless($row, 404);
        $series = Series::findOrFail($row->series_id);
        $manager = $request->user()->can('commerce.catalog.manage');
        abort_unless($manager || $series->published_revision_id === $revision, 403);
        $content = $service->normalize(json_decode($row->content, true, flags: JSON_THROW_ON_ERROR));
        // Published viewers omit withdrawn/archived products instead of exposing drafts.
        if ($manager) {
            $content = $service->validate($content, $content['hero_path'] ?? null);
        }
        $products = $service->products($content);
        if ($manager) {
            DB::table('series_content_previews')->updateOrInsert(['revision_id' => $revision, 'user_id' => $request->user()->id], ['previewed_at' => now(), 'context_hash' => $service->fingerprint($series, $content, $products)]);
        }

        return response()->view('commerce.series-content-preview', compact('series', 'revision', 'content', 'products'))
            ->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'");
    }
}
