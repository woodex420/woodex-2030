<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Sales\Data\QuoteBlueprint;
use App\Domain\Sales\Enums\MilestonePlanKey;
use App\Domain\Sales\Enums\TemplateKind;
use App\Domain\Sales\Models\QuoteTemplate;
use Illuminate\Support\Str;
use JsonException;

/**
 * Imports a v2.1 quotation template pack (the `{templates: [...]}` file the old admin shipped as the
 * "Woodex starter templates") as native quote templates. Existing template names are skipped — never
 * overwritten — matching the v2.1 rule. Rates in the pack are rupees; they land as rate_minor.
 *
 * The bundled starter pack is the same data file QuoteTemplateSeeder installs from (converted from
 * the v2.1 library, verified identical to the v2.1 production `_templates/quotation-templates.json`),
 * so seeding and the "Import Woodex starter templates" button can never drift apart.
 */
final class TemplatePackImporter
{
    /** Starter pack, relative to database/ — the file QuoteTemplateSeeder reads. */
    public const STARTER_FILE = 'seeders/data/quote-templates.json';

    /**
     * @return array<string, mixed>|null
     */
    public static function starterContents(): ?array
    {
        $path = database_path(self::STARTER_FILE);

        if (! is_file($path)) {
            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        return array_is_list($decoded) ? ['templates' => $decoded] : $decoded;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{imported: list<string>, skipped: list<string>}
     */
    public function import(array $decoded): array
    {
        $existing = QuoteTemplate::query()->pluck('name')
            ->map(fn (string $name): string => mb_strtolower(trim($name)))->all();
        $position = (int) QuoteTemplate::query()->max('position');
        $imported = [];
        $skipped = [];
        $entries = is_array($decoded['templates'] ?? null) ? $decoded['templates'] : (array_is_list($decoded) ? $decoded : []);

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $name = trim((string) ($entry['name'] ?? ''));

            if ($name === '' || mb_strlen($name) > 120) {
                continue;
            }

            if (in_array(mb_strtolower($name), $existing, true)) {
                $skipped[] = $name;

                continue;
            }

            $sections = QuoteBlueprint::sections(['sections' => $this->sections($entry)]);

            if ($sections === []) {
                $skipped[] = $name;

                continue;
            }

            // Same classification as QuoteTemplateSeeder: an explicit v2.1 kind wins, scope
            // templates are recognised by name, design work by its 75%-advance terms.
            $isDesign = (string) ($entry['kind'] ?? '') === 'design' || str_contains((string) ($entry['terms'] ?? ''), '75%');
            $isScope = str_contains($name, 'Scope of Work') || str_contains($name, 'Solution')
                || str_contains($name, 'Residential') || str_contains($name, 'Commercial');
            $base = Str::slug($name) ?: 'template';
            $slug = $base;
            $n = 2;
            while (QuoteTemplate::query()->where('slug', $slug)->exists()) {
                $slug = $base.'-'.$n++;
            }

            QuoteTemplate::query()->create([
                'name' => $name,
                'slug' => $slug,
                'description' => $this->clean($entry['description'] ?? null, 500),
                'business_line' => mb_strtolower($name) === 'furniture' ? BusinessLine::Furniture->value : BusinessLine::Interior->value,
                'kind' => $isScope ? TemplateKind::Scope->value : ($isDesign ? TemplateKind::Design->value : TemplateKind::Execution->value),
                'milestone_plan' => $isDesign ? MilestonePlanKey::Design->value : MilestonePlanKey::Execution->value,
                'terms' => $this->clean($entry['terms'] ?? null, 10000),
                'notes' => null,
                'scope_html' => null,
                'blueprint' => ['sections' => $sections, 'presentation' => [
                    'quote_layout' => DocumentStyles::quoteLayout($entry['layout'] ?? null),
                    'pdf_theme' => DocumentStyles::theme($entry['design'] ?? null),
                ]],
                'is_active' => true,
                'position' => ++$position,
            ]);

            $existing[] = mb_strtolower($name);
            $imported[] = $name;
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * v2.1 sections/items (desc/qty/unit/rate in rupees) in blueprint input shape.
     *
     * @param  array<string, mixed>  $entry
     * @return list<array<string, mixed>>
     */
    private function sections(array $entry): array
    {
        $sections = [];

        foreach (array_slice((array) ($entry['sections'] ?? []), 0, 50) as $section) {
            if (! is_array($section)) {
                continue;
            }

            $items = [];

            foreach (array_slice((array) ($section['items'] ?? []), 0, 200) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $description = trim((string) ($item['desc'] ?? ''));

                if ($description === '') {
                    continue;
                }

                $items[] = [
                    'product_name' => mb_substr(trim((string) ($item['product_name'] ?? '')), 0, 160),
                    'description' => $description,
                    'unit' => (string) ($item['unit'] ?? ''),
                    'qty' => $item['qty'] ?? null,
                    'rate_minor' => (int) round(((float) ($item['rate'] ?? 0)) * 100),
                    'catalog_item_id' => null,
                ];
            }

            $sections[] = [
                'name' => trim((string) ($section['name'] ?? '')) ?: 'Section',
                'items' => $items,
            ];
        }

        return $sections;
    }

    private function clean(mixed $value, int $max): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
