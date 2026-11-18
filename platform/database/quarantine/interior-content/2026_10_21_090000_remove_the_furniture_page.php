<?php

use App\Domain\Website\Models\Page;
use Illuminate\Database\Migrations\Migration;

/**
 * The furniture page is deleted (owner's decision, 21 Oct 2026).
 *
 * `/furniture/` was the last page the CMS renderer wrote: it had no v1 original, so when the shadow rows were
 * retired it was exported to `public/furniture/index.html` in the new design — the only page on the site that
 * did not match the other 141. The owner asked for that design to leave the project completely, and for this
 * page to go with it rather than be rebuilt.
 *
 * The file is deleted in the same commit. This deletes the row behind it, because a row with no file is still a
 * page to the SiteRouter: without this, `/furniture/` would answer again — in the CMS design — on the next
 * request after the file is gone.
 *
 * Blocks and versions go with the row (cascade). The words are not lost: `database/data/website-bundle/pages.json`
 * is the 17 Oct export and still holds the page, and it is only loaded on a fresh install or by an explicit
 * `woodex:import-website`. Reviving the page therefore takes a deliberate act, not a deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Page::query()->where('slug', '/furniture/')->get()->each->forceDelete();
    }

    public function down(): void
    {
        // Nothing to restore: the page was deleted, not changed, and its content is in the bundle.
    }
};
