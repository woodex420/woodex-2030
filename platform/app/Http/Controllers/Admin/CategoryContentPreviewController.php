<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Models\CategoryContentRevision;
use App\Domain\Commerce\Services\CategoryContent;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class CategoryContentPreviewController extends Controller
{
    public function show(Request $request, CategoryContentRevision $revision, CategoryContent $content)
    {
        $category = $this->authorizeRevision($request, $revision);
        $data = $revision->content;
        // Revalidate current category membership and file availability before recording a preview.
        $content->validate($category, $data, $data['hero_path'] ?? null);
        if ($request->user()->can('commerce.catalog.manage')) {
            DB::table('category_content_previews')->updateOrInsert(['revision_id' => $revision->id, 'user_id' => $request->user()->id], ['previewed_at' => now(), 'context_hash' => $content->previewFingerprint($category, $data)]);
        }

        $data['hero_url'] = ! empty($data['hero_path']) ? route('admin.commerce.category-content.hero', $revision) : null;

        return response()->view('commerce.category-content-preview', [
            'category' => $category, 'revision' => $revision, 'content' => $data,
            'products' => $content->products($category, $data),
        ])->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'");
    }

    public function image(Request $request, CategoryContentRevision $revision)
    {
        $this->authorizeRevision($request, $revision);
        $path = $revision->content['hero_path'] ?? null;
        abort_unless(is_string($path) && preg_match('~^commerce/category-heroes/[a-zA-Z0-9]+\.(jpg|jpeg|png|webp)$~', $path) && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function authorizeRevision(Request $request, CategoryContentRevision $revision): Category
    {
        abort_unless($request->user()->can('commerce.catalog.view'), 403);
        $category = Category::findOrFail($revision->category_id);
        abort_unless($request->user()->can('commerce.catalog.manage') || (int) $category->published_revision_id === $revision->id, 403);

        return $category;
    }
}
