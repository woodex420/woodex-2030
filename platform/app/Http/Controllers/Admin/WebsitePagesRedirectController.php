<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Website\Builder\BuilderFile;
use App\Domain\Website\Models\Page;
use Illuminate\Http\RedirectResponse;

/**
 * The old page-editor URLs, pointed at the one editor (Woodex OS 1, docs/audit/16.19 step 6).
 *
 * `/admin/website/pages/{page}` used to open a block editor that wrote the `pages` table — a copy the file
 * site never read. A page is a file now, so both old addresses open the builder on that file: a bookmark, a
 * link in an email and the browser's back button all still land on something that can change the page.
 */
final class WebsitePagesRedirectController
{
    public function __invoke(?Page $page = null): RedirectResponse
    {
        if ($page === null) {
            return redirect()->route('admin.website.builder');
        }

        return redirect()->route('admin.website.builder', [
            'path' => BuilderFile::pathForUrl($page->path()),
        ]);
    }
}
