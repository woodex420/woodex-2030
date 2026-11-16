<?php

declare(strict_types=1);

namespace App\Domain\Website\Seo;

use App\Domain\Website\Builder\BuilderFile;
use DOMDocument;
use DOMElement;
use DOMXPath;
use JsonException;

/** Local published-file inspection only. Never fetches canonical/image/schema URLs or writes pages. */
final class SearchMetadataAudit
{
    /** @return array{entries: list<array{path: string, url: string, canonical: string, issues: list<array{code: string, message: string}>}>, counts: array{files: int, flagged: int, canonical: int, social: int, jsonld: int, indexing: int, unreadable: int}} */
    public function handle(): array
    {
        $entries = [];
        $root = realpath(public_path());
        foreach (BuilderFile::pages() as $file) {
            $path = realpath(public_path($file['path']));
            if ($root === false || $path === false || ! is_file($path) || ! is_readable($path) || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || filesize($path) > 2_097_152) {
                $entry = ['canonical' => '', 'issues' => [['code' => 'unreadable', 'message' => 'File is unavailable, oversized or outside the public root; not read.']]];
            } else {
                $entry = $this->inspect((string) file_get_contents($path), $file['url']);
            }
            $entries[] = ['path' => $file['path'], 'url' => $file['url']] + $entry;
        }
        $counts = ['files' => count($entries), 'flagged' => 0, 'canonical' => 0, 'social' => 0, 'jsonld' => 0, 'indexing' => 0, 'unreadable' => 0];
        foreach ($entries as $entry) {
            if ($entry['issues'] !== []) {
                $counts['flagged']++;
            }
            foreach (['canonical', 'social', 'jsonld', 'indexing', 'unreadable'] as $group) {
                if (array_any($entry['issues'], fn (array $issue): bool => str_starts_with($issue['code'], $group))) {
                    $counts[$group]++;
                }
            }
        }

        return ['entries' => $entries, 'counts' => $counts];
    }

    /** @return array{canonical: string, issues: list<array{code: string, message: string}>} */
    public function inspect(string $html, string $pageUrl): array
    {
        $dom = new DOMDocument;
        $prior = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prior);
        }
        $xpath = new DOMXPath($dom);
        $canonical = [];
        $meta = [];
        foreach ($xpath->query('//head/link[@rel]') ?: [] as $node) {
            if ($node instanceof DOMElement && in_array('canonical', preg_split('/\s+/', strtolower(trim($node->getAttribute('rel')))) ?: [], true)) {
                $canonical[] = trim($node->getAttribute('href'));
            }
        }
        foreach ($xpath->query('//head/meta') ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $name = strtolower(trim($node->getAttribute('property') ?: $node->getAttribute('name')));
                $meta[$name][] = trim($node->getAttribute('content'));
            }
        }
        $issues = [];
        $value = $canonical[0] ?? '';
        if ($canonical === [] || $value === '') {
            $issues[] = ['code' => 'canonical_missing', 'message' => 'No nonempty canonical link. Review the preferred public URL in the builder.'];
        }
        if (count($canonical) > 1) {
            $issues[] = ['code' => 'canonical_multiple', 'message' => 'Multiple canonical links; review which URL is intended.'];
        }
        if ($value !== '') {
            $expected = rtrim((string) config('woodex.public_site_url'), '/').$pageUrl;
            if (! str_starts_with(strtolower($value), 'https://')) {
                $issues[] = ['code' => 'canonical_absolute', 'message' => 'Prefer an absolute HTTPS canonical URL.'];
            }
            if (rtrim($value, '/') !== rtrim($expected, '/')) {
                $issues[] = ['code' => 'canonical_target', 'message' => 'Canonical differs from this page on the configured public site; review intent before changing.'];
            }
        }
        foreach (['robots', 'googlebot'] as $agent) {
            foreach ($meta[$agent] ?? [] as $directive) {
                if (preg_match('/(?:^|[,;\s])(?:noindex|none)(?:$|[,;\s])/i', $directive)) {
                    $issues[] = ['code' => 'indexing_restricted', 'message' => 'Indexing is restricted by '.$agent.' metadata. This may be intentional; do not remove automatically.'];
                    break 2;
                }
            }
        }
        $missing = array_values(array_filter(['og:title', 'og:description', 'og:image'], fn (string $key): bool => ! array_any($meta[$key] ?? [], fn (string $value): bool => $value !== '')));
        if ($missing !== []) {
            $issues[] = ['code' => 'social_missing', 'message' => 'Social preview metadata missing: '.implode(', ', $missing).'.'];
        }
        foreach ($xpath->query('//script[@type]') ?: [] as $node) {
            if (! $node instanceof DOMElement || strtolower(trim(explode(';', $node->getAttribute('type'), 2)[0])) !== 'application/ld+json') {
                continue;
            }
            try {
                $decoded = json_decode($node->textContent, true, 64, JSON_THROW_ON_ERROR);
                if (! is_array($decoded) || $decoded === []) {
                    $issues[] = ['code' => 'jsonld_shape', 'message' => 'JSON-LD must contain a nonempty object or array.'];
                }
            } catch (JsonException) {
                $issues[] = ['code' => 'jsonld_syntax', 'message' => 'JSON-LD cannot be parsed. Syntax only checked; schema eligibility is not certified.'];
            }
        }

        return ['canonical' => $value, 'issues' => $issues];
    }
}
