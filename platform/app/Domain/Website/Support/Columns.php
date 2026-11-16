<?php

declare(strict_types=1);

namespace App\Domain\Website\Support;

use Illuminate\Support\Str;

/**
 * The columns section (Phase 2, docs/audit/08 §3 "layout primitives").
 *
 * A columns section holds 2–4 children in one JSON value, not in extra rows: the page keeps one
 * PageBlock per section, so versions, undo, copy/paste and the fingerprint all keep working without
 * a schema change. Children are limited to three leaf kinds — text, image, quote — deliberately:
 * columns cannot nest, and raw HTML cannot hide inside one.
 */
final class Columns
{
    public const int MIN = 2;

    public const int MAX = 4;

    /** @var list<string> */
    public const array KINDS = ['text', 'image', 'quote'];

    /** @var array<string, string> */
    public const array KIND_LABELS = [
        'text' => 'Text',
        'image' => 'Photo',
        'quote' => 'Quote',
    ];

    /** @return array{blocks: list<array<string, mixed>>} */
    public static function defaults(): array
    {
        return ['blocks' => [self::blank('text'), self::blank('text')]];
    }

    /** @return array<string, mixed> */
    public static function blank(string $kind): array
    {
        return match ($kind) {
            'image' => ['kind' => 'image', 'image_id' => null, 'caption' => ''],
            'quote' => ['kind' => 'quote', 'quote' => '', 'cite' => ''],
            default => ['kind' => 'text', 'heading' => '', 'body' => ''],
        };
    }

    /**
     * Keep 2–4 recognised children and only the fields their kind uses. Text is stored escaped at
     * render time, but tags are still stripped here so the JSON holds words, not markup.
     *
     * @param  array<string, mixed>  $data
     * @return array{blocks: list<array<string, mixed>>}
     */
    public static function clean(array $data): array
    {
        $children = [];

        foreach ((array) ($data['blocks'] ?? []) as $child) {
            if (! is_array($child) || count($children) === self::MAX) {
                continue;
            }

            $kind = (string) ($child['kind'] ?? 'text');
            $kind = in_array($kind, self::KINDS, true) ? $kind : 'text';

            $children[] = match ($kind) {
                'image' => [
                    'kind' => 'image',
                    'image_id' => is_numeric($child['image_id'] ?? null) ? (int) $child['image_id'] : null,
                    'caption' => self::words($child['caption'] ?? '', 180),
                ],
                'quote' => [
                    'kind' => 'quote',
                    'quote' => self::words($child['quote'] ?? '', 500),
                    'cite' => self::words($child['cite'] ?? '', 80),
                ],
                default => [
                    'kind' => 'text',
                    'heading' => self::words($child['heading'] ?? '', 160),
                    'body' => self::words($child['body'] ?? '', 2000, true),
                ],
            };
        }

        while (count($children) < self::MIN) {
            $children[] = self::blank('text');
        }

        return ['blocks' => $children];
    }

    /** Visible text only: tags removed, whitespace collapsed, length capped. */
    private static function words(mixed $value, int $limit, bool $keepLines = false): string
    {
        $text = strip_tags((string) $value);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = $keepLines
            ? trim((string) preg_replace('/[ \t]+/', ' ', $text))
            : trim((string) preg_replace('/\s+/', ' ', $text));

        return Str::limit($text, $limit, '');
    }
}
