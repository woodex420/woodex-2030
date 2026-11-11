<?php

declare(strict_types=1);

namespace App\Domain\Website\Conversion;

/**
 * The conversion's gate (docs/13 §5): the same page rendered by the v1 file and by the CMS, judged
 * by structure and SEO. The tool records every difference; the studio decides for each whether it
 * is an intended improvement or a bug — a group with an unexplained difference does not switch.
 *
 * Deliberately not attempted here: computed styles at three widths (needs a real browser, run on
 * the host before a group switches) and tracking/form behaviour (covered by the release-gate
 * scenarios in docs/02 §9).
 */
final class ParityCompare
{
    /**
     * @return array{green: bool, checks: list<array{check: string, v1: string, v2: string, pass: bool}>}
     */
    public function compare(string $v1Html, string $v2Html, string $url): array
    {
        $checks = [
            $this->text('The h1', $this->h1($v1Html), $this->h1($v2Html)),
            $this->count('Number of sections', substr_count($v1Html, '<section'), substr_count($v2Html, '<section')),
            $this->count('Heading order (h2 sequence)', count($this->headings($v1Html)), count($this->headings($v2Html)), $this->headings($v1Html), $this->headings($v2Html)),
            $this->text('Internal links', implode(' ', $this->internalLinks($v1Html, $url)), implode(' ', $this->internalLinks($v2Html, $url))),
            $this->count('Images without alt text', $this->imagesWithoutAlt($v1Html), $this->imagesWithoutAlt($v2Html)),
            $this->count('Images without width/height', $this->imagesWithoutDimensions($v1Html), $this->imagesWithoutDimensions($v2Html)),
            $this->text('Title', $this->title($v1Html), $this->title($v2Html)),
            $this->text('Meta description', $this->description($v1Html), $this->description($v2Html)),
            $this->text('Canonical', $this->canonical($v1Html), $this->canonical($v2Html)),
            $this->text('JSON-LD types', implode(' ', $this->jsonLdTypes($v1Html)), implode(' ', $this->jsonLdTypes($v2Html))),
            $this->text('Robots', $this->robots($v1Html), $this->robots($v2Html)),
        ];

        return [
            'green' => ! in_array(false, array_column($checks, 'pass'), true),
            'checks' => $checks,
        ];
    }

    /**
     * @return array{check: string, v1: string, v2: string, pass: bool}
     */
    private function text(string $label, string $v1, string $v2): array
    {
        return ['check' => $label, 'v1' => $v1, 'v2' => $v2, 'pass' => trim($v1) === trim($v2) && trim($v1) !== ''];
    }

    /**
     * @param  list<string>  $v1Detail
     * @param  list<string>  $v2Detail
     * @return array{check: string, v1: string, v2: string, pass: bool}
     */
    private function count(string $label, int $v1, int $v2, array $v1Detail = [], array $v2Detail = []): array
    {
        $summarise = static fn (int $count, array $detail): string => $count.($detail !== [] ? ' · '.implode(' | ', array_slice($detail, 0, 8)) : '');

        return ['check' => $label, 'v1' => $summarise($v1, $v1Detail), 'v2' => $summarise($v2, $v2Detail), 'pass' => $v1 === $v2 && ($v1Detail === [] || $v1Detail === $v2Detail)];
    }

    private function h1(string $html): string
    {
        return preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $match) === 1
            ? trim(preg_replace('/\s+/', ' ', strip_tags((string) $match[1])) ?? '')
            : '';
    }

    /**
     * @return list<string>
     */
    private function headings(string $html): array
    {
        preg_match_all('/<h2[^>]*>(.*?)<\/h2>/is', $html, $matches);

        $headings = [];

        foreach ($matches[1] ?? [] as $heading) {
            $clean = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $heading)));

            if ($clean !== '') {
                $headings[] = $clean;
            }
        }

        return $headings;
    }

    /**
     * @return list<string>
     */
    private function internalLinks(string $html, string $url): array
    {
        preg_match_all('/href="([^"]+)"/', $html, $matches);

        $links = [];

        foreach ($matches[1] ?? [] as $href) {
            $href = (string) $href;

            if (! str_starts_with($href, '/') || str_starts_with($href, '//') || str_starts_with($href, '/assets') || str_starts_with($href, '/img') || str_starts_with($href, '/build')) {
                continue; // external, asset or anchor — the parity gate cares about page links
            }

            $links[] = rtrim($href, '/') ?: '/';
        }

        $links = array_values(array_unique($links));
        sort($links);

        unset($url);

        return $links;
    }

    private function imagesWithoutAlt(string $html): int
    {
        preg_match_all('/<img\b[^>]*>/i', $html, $matches);

        return count(array_filter((array) $matches[0], static fn (string $tag): bool => preg_match('/\balt="[^"]+"/i', $tag) !== 1));
    }

    private function imagesWithoutDimensions(string $html): int
    {
        preg_match_all('/<img\b[^>]*>/i', $html, $matches);

        return count(array_filter((array) $matches[0], static fn (string $tag): bool => preg_match('/\bwidth="[^"]+"/i', $tag) !== 1 || preg_match('/\bheight="[^"]+"/i', $tag) !== 1));
    }

    private function title(string $html): string
    {
        return preg_match('/<title>(.*?)<\/title>/is', $html, $match) === 1 ? trim(html_entity_decode((string) $match[1])) : '';
    }

    private function description(string $html): string
    {
        return preg_match('/<meta name="description" content="([^"]*)"/is', $html, $match) === 1 ? trim(html_entity_decode((string) $match[1])) : '';
    }

    private function canonical(string $html): string
    {
        return preg_match('/rel="canonical" href="([^"]*)"/is', $html, $match) === 1 ? trim((string) $match[1]) : '';
    }

    private function robots(string $html): string
    {
        return preg_match('/<meta name="robots" content="([^"]*)"/is', $html, $match) === 1 ? trim((string) $match[1]) : 'default';
    }

    /**
     * @return list<string>
     */
    private function jsonLdTypes(string $html): array
    {
        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/is', $html, $matches);

        $types = [];

        foreach ($matches[1] ?? [] as $json) {
            $decoded = json_decode(trim((string) $json), true);

            if (is_array($decoded)) {
                $graph = $decoded['@graph'] ?? null;

                foreach (is_array($graph) ? $graph : [$decoded] as $node) {
                    if (is_array($node) && isset($node['@type'])) {
                        $types[] = (string) (is_array($node['@type']) ? implode('|', $node['@type']) : $node['@type']);
                    }
                }
            }
        }

        sort($types);

        return $types;
    }
}
