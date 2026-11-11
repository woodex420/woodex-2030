<?php

declare(strict_types=1);

use App\Domain\Website\Data\LegacyUrlMap;
use App\Domain\Website\Models\Redirect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The 54 URLs of the previous website that no longer have a page of their own (docs/09 §3, D-68).
 *
 * They are rows in `redirects`, not code: the owner can change any of them in Admin → Website → SEO & Redirects,
 * and the hit counter shows which ones still receive visitors — those become real pages again. Re-running adds
 * only the rows that are missing and never resets the counter.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (LegacyUrlMap::redirects() as $from => $to) {
            DB::transaction(function () use ($from, $to): void {
                $existing = Redirect::query()->where('from_path', Redirect::normalize($from))->first();

                if ($existing !== null) {
                    return;
                }

                Redirect::query()->create([
                    'from_path' => Redirect::normalize($from),
                    'to_path' => Redirect::normalize($to),
                    'code' => 301,
                    'hits' => 0,
                    'note' => 'Old service URL, redirected to the service that answers it now.',
                ]);
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(LegacyUrlMap::redirects()) as $from) {
            Redirect::query()->where('from_path', Redirect::normalize($from))->delete();
        }
    }
};
