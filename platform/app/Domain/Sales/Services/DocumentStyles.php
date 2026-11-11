<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Sales\Models\DocumentDesign;

/**
 * The document-design system: brand identity + PDF theme + ordered PDF blocks.
 *
 * A value copy, including logo bytes, is frozen into each new document; documents never follow
 * the mutable design master at render time. Snapshots frozen before themes/blocks existed carry
 * no pdf_theme/quote_blocks/invoice_blocks keys — the normalizers below turn "missing" into the
 * exact pre-Slice-O behaviour (classic theme, every block visible, original order).
 */
final class DocumentStyles
{
    public const THEME_CLASSIC = 'classic';

    /** v2.1 layouts are independent of the colour design. */
    public const QUOTE_LAYOUTS = [
        'classic' => 'Classic BOQ',
        'furniture' => 'Furniture supply sheet',
        'single' => 'Single page',
        'project' => 'Project (summary + trades)',
    ];

    /** @return list<array{key: string, visible: bool}> */
    public static function quoteLayoutBlocks(string $layout, bool $flexible = false): array
    {
        $blocks = self::blocks('quote', null);
        if ($layout === 'furniture' && $flexible) {
            $blocks[1]['visible'] = false;
            $blocks[4]['visible'] = false;
            [$blocks[2], $blocks[3]] = [$blocks[3], $blocks[2]];
        }
        if ($layout === 'single') {
            $blocks[1]['visible'] = false;
            [$blocks[2], $blocks[3]] = [$blocks[3], $blocks[2]];
        }

        return $blocks;
    }

    public static function quoteLayout(mixed $layout): string
    {
        return is_string($layout) && isset(self::QUOTE_LAYOUTS[$layout]) ? $layout : 'classic';
    }

    /** @var array<string, string> */
    public const THEMES = [
        'classic' => 'Classic',
        'minimal' => 'Minimal',
        'premium' => 'Premium',
    ];

    /** @var array<string, string> */
    public const THEME_DESCRIPTIONS = [
        'classic' => 'Navy and gold — dark table heads, cream boxes, dark total band with a gold amount.',
        'minimal' => 'Black on white — white table heads ruled in black, plain boxes, black total band.',
        'premium' => 'Espresso on cream — warm dark-brown ink and total band, cream boxes, gold accents.',
    ];

    /** @var array<string, string> Block key => label, in the original (default) render order. */
    public const QUOTE_BLOCKS = [
        'scope' => 'Scope of work',
        'summary' => 'Summary of cost',
        'totals' => 'Totals & commercial amounts',
        'boq' => 'Items / BOQ (detailed)',
        'payment' => 'Payment schedule',
        'terms' => 'Terms & conditions',
        'notes' => 'Notes',
        'payment_details' => 'Payment details (bank & wallets)',
        'signatures' => 'Signatures',
    ];

    /** @var array<string, string> */
    public const INVOICE_BLOCKS = [
        'items' => 'Particulars',
        'totals' => 'Totals & amount in words',
        'terms' => 'Terms & conditions',
        'payment_details' => 'Payment details',
        'notes' => 'Notes',
        'signatures' => 'Signatures',
    ];

    /** Legacy quotation and invoice locks. Current quotations opt into the explicit flexible policy. */
    /** @var array<string, list<string>> */
    public const LOCKED_BLOCKS = [
        'quote' => ['boq', 'totals'],
        'invoice' => ['items', 'totals'],
    ];

    /** @return array<string, string> */
    public static function catalogue(string $kind): array
    {
        return $kind === 'invoice' ? self::INVOICE_BLOCKS : self::QUOTE_BLOCKS;
    }

    public static function theme(mixed $theme): string
    {
        return is_string($theme) && isset(self::THEMES[$theme]) ? $theme : self::THEME_CLASSIC;
    }

    /**
     * Normalize a stored/submitted block list: drop unknown keys, keep the first of duplicates,
     * insert any known block that is missing (visible) at its catalogue position, force locked visible.
     *
     * @return list<array{key: string, visible: bool}>
     */
    public static function blocks(string $kind, mixed $stored, bool $flexible = false): array
    {
        $catalogue = self::catalogue($kind);
        $locked = $kind === 'quote' && $flexible ? [] : self::LOCKED_BLOCKS[$kind === 'invoice' ? 'invoice' : 'quote'];
        $blocks = [];
        $seen = [];

        foreach (is_array($stored) ? $stored : [] as $entry) {
            $key = is_array($entry) ? ($entry['key'] ?? null) : null;

            if (! is_string($key) || ! isset($catalogue[$key]) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $blocks[] = ['key' => $key, 'visible' => in_array($key, $locked, true) || (bool) ($entry['visible'] ?? true)];
        }

        // A block the stored list predates (e.g. a design saved before "Summary of cost" existed) joins at
        // its catalogue position — after the last listed block that precedes it in the catalogue — instead
        // of landing after Signatures. The owner's own relative order is never disturbed.
        $positions = array_flip(array_keys($catalogue));

        foreach (array_keys($catalogue) as $key) {
            if (isset($seen[$key])) {
                continue;
            }

            $insertAt = 0;

            foreach ($blocks as $index => $block) {
                if ($positions[$block['key']] < $positions[$key]) {
                    $insertAt = $index + 1;
                }
            }

            array_splice($blocks, $insertAt, 0, [['key' => $key, 'visible' => true]]);
        }

        return $blocks;
    }

    /**
     * Ordered visible block keys for a frozen design array (used by the PDF blades).
     *
     * @param  array<string, mixed>|null  $design
     * @return list<string>
     */
    public static function visibleBlocks(string $kind, ?array $design): array
    {
        $stored = is_array($design) ? ($design[$kind === 'invoice' ? 'invoice_blocks' : 'quote_blocks'] ?? null) : null;

        return array_values(array_map(
            static fn (array $block): string => $block['key'],
            array_filter(self::blocks($kind, $stored, self::flexible($design)), static fn (array $block): bool => $block['visible']),
        ));
    }

    /** @param array<string, mixed>|null $design */
    public static function flexible(?array $design): bool
    {
        return ($design['quote_block_policy'] ?? '') === 'flexible';
    }

    /** @param array<string, mixed>|null $design
     * @return list<array{key: string, visible: bool}>
     */
    public static function quotationBlocks(mixed $stored, ?array $design): array
    {
        return self::blocks('quote', $stored, self::flexible($design));
    }

    /** Current quotation-only layout; invoices intentionally retain copy() revision 2.
     * @return array<string, mixed>
     */
    public function quotationCopy(BusinessLine $line): array
    {
        $design = $this->copy($line, true);
        $design['quote_block_policy'] = 'flexible';
        if ($line === BusinessLine::Furniture) {
            $design['furniture_revision'] = 5;
            if (empty($design['logo_data_uri']) || ($design['logo_sha256'] ?? '') === hash_file('sha256', public_path('images/furniture-mark.png'))) {
                if (($design['furniture_style']['use_default_logo'] ?? 'yes') === 'yes') {
                    $bytes = file_get_contents(public_path('images/furniture-mark-v4.png'));
                    $design['logo_data_uri'] = 'data:image/png;base64,'.base64_encode($bytes);
                    $design['logo_sha256'] = hash('sha256', $bytes);
                }
            }
            // Adopt the supplied palette only for the old default; keep custom brand colours.
            if ($design['accent_color'] === '#276749') {
                $design['accent_color'] = '#347647';
            }
        }

        if ($line !== BusinessLine::Furniture) {
            $design['interior_revision'] = 3;
            if (empty($design['logo_data_uri'])) {
                $bytes = file_get_contents(public_path('images/interior-mark.png'));
                $design['logo_data_uri'] = 'data:image/png;base64,'.base64_encode($bytes);
                $design['logo_sha256'] = hash('sha256', $bytes);
            }
        }

        return $design;
    }

    /** @return array<string, mixed> */
    public function copy(BusinessLine $line, bool $flexibleQuoteBlocks = false): array
    {
        $design = DocumentDesign::query()->where('business_line', $line === BusinessLine::Furniture ? 'furniture' : 'interior')->firstOrFail();

        $identity = $design->only(['id', 'business_line', 'revision', 'brand_name', 'accent_color', 'header_text', 'footer_text', 'logo_data_uri', 'logo_sha256']);
        $style = FurnitureDocument::style($design->furniture_style);
        if ($line === BusinessLine::Furniture && empty($identity['logo_data_uri']) && ($style['use_default_logo'] ?? 'yes') === 'yes') {
            $bytes = file_get_contents(public_path('images/furniture-mark.png'));
            $identity['logo_data_uri'] = 'data:image/png;base64,'.base64_encode($bytes);
            $identity['logo_sha256'] = hash('sha256', $bytes);
        }

        return [
            ...$identity,
            'furniture_revision' => $line === BusinessLine::Furniture ? 2 : 1,
            'furniture_style' => FurnitureDocument::style($design->furniture_style),
            'quote_layout' => $line === BusinessLine::Furniture ? 'furniture' : 'classic',
            'pdf_theme' => self::theme($design->pdf_theme ?? null),
            'quote_blocks' => $flexibleQuoteBlocks && $line === BusinessLine::Furniture && $design->quote_blocks === null
                ? self::quoteLayoutBlocks('furniture', true)
                : self::blocks('quote', $design->quote_blocks, $flexibleQuoteBlocks),
            'invoice_blocks' => self::blocks('invoice', $design->invoice_blocks),
        ];
    }
}
