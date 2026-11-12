<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** Read-only deployment gates. Does not enable modules, mutate media, publish or initiate remote deployment/sync. */
final class CommerceReadiness extends Command
{
    protected $signature = 'commerce:readiness {--production : Enforce deployment and remaining feature gates}';

    protected $description = 'Report Commerce implementation/deployment blockers without changing data or configuration';

    public function handle(): int
    {
        $strict = (bool) $this->option('production');
        $rows = [];
        $failed = 0;
        $check = function (string $label, bool $ok, string $detail, bool $required = true) use (&$rows, &$failed): void {
            $rows[] = [$label, $ok ? 'PASS' : ($required ? 'BLOCKED' : 'PENDING'), $detail];
            if (! $ok && $required) {
                $failed++;
            }
        };
        foreach (['commerce_products', 'commerce_product_media', 'commerce_product_revisions', 'website_media', 'master_media_versions', 'master_media_sources', 'master_media_usages', 'series_content_revisions', 'series_content_previews', 'commerce_shop_state', 'shop_content_revisions', 'shop_content_previews'] as $table) {
            $check('Schema: '.$table, Schema::hasTable($table), 'Additive migrations must be installed; never reset production.');
        }
        foreach (['local', 'public'] as $disk) {
            $root = Storage::disk($disk)->path('');
            $check('Persistent '.$disk.' storage', is_dir($root) && is_readable($root) && is_writable($root), 'Directory is readable/writable; persistence and backups need host acceptance.');
        }
        $check('Existing source photographs', is_dir((string) config('woodex.catalogue_source_image_root')), 'Real source-image directory required for catalogue/image import.');
        $check('Image inspection', function_exists('getimagesizefromstring'), 'Actual raster inspection, not filename-only validation.');
        $check('Optional WebP optimization', extension_loaded('gd') && function_exists('imagewebp'), 'Missing encoder must retain originals without claiming compression.', false);
        foreach (['admin.commerce.products', 'admin.commerce.products.edit', 'admin.website.media'] as $route) {
            $check('Route: '.$route, Route::has($route), 'Route presence only; authorization and browser acceptance remain mandatory.');
        }
        if ($strict) {
            $check('Production environment', app()->environment('production'), 'Use the target host configuration, not a preview environment.');
            $check('Debug disabled', ! config('app.debug'), 'APP_DEBUG must be false.');
            $check('Application key', filled(config('app.key')), 'Configured; key contents are deliberately never printed.');
            $url = parse_url((string) config('app.url'));
            $host = strtolower($url['host'] ?? '');
            $permanent = ($url['scheme'] ?? '') === 'https' && $host !== '' && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['query']) && ! isset($url['fragment']) && ! preg_match('/(^localhost$|^127\.|^0\.|\.(test|localhost)$|(?:^|\.)example\.(com|org|net)$|\.arena\.site$|\.e2b\.app$)/', $host);
            $check('Permanent HTTPS application URL', $permanent, 'Sandbox/localhost/example URLs are not permanent upstreams. Reachability is not tested by this command.');
            $check('Production MySQL driver acceptance', in_array('mysql', \PDO::getAvailableDrivers(), true), 'MySQL-hosted production requires pdo_mysql and a real migration/acceptance run.');
        }
        // Explicit implementation gates: remove only after each phase lands and its acceptance is recorded.
        $check('Mixed image/video gallery', false, 'Image multi-select exists; video upload/embed, poster and playback acceptance are not implemented.', $strict);
        $check('Complete Shop/Series management', false, 'Native Series content and Shop section drafts/preview/publication exist; activation/redirect controls, full Shop hero/navigation/footer and React projection remain pending.', $strict);
        $check('Native product/price/quote cutover', false, 'Authoritative React product/variant pricing and quote parity remain pending; media-only sync is not product cutover.', $strict);
        $check('Host storage and live acceptance', false, 'Permanent upstream, persistent storage, backup/restore and deployed RBAC/checkout acceptance must be recorded on the target host.', $strict);
        $this->table(['Check', 'Status', 'Evidence / required action'], $rows);
        $this->line('Read-only report. No records/files/configuration were changed. Only the configured database/schema and local storage were inspected; no remote deployment or external sync was initiated.');
        $this->line('This report is not a production-readiness certificate. Media sync uses the separate React API configuration and requires deployed end-to-end verification.');
        if ($failed) {
            $this->error($failed.' blocking checks. Do not declare complete production Commerce or switch the live catalogue.');

            return self::FAILURE;
        }
        $this->info('Local prerequisites checked; pending features are listed above. Production cutover is not approved by this report.');

        return self::SUCCESS;
    }
}
