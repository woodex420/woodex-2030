<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Sales\Enums\Unit;
use App\Domain\Sales\Models\QuoteTemplate;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The 16 Woodex quotation templates (QUO-002), converted from the v2.1 template library:
 * Renovation, Civil Work, Interior Designing, Interior, Fit-out, Architecture, 3D Visualization,
 * Furniture, Design Consultancy and seven "Scope of Work" templates.
 *
 * Only adds missing templates: templates the owner edited or hid are never overwritten when the seeder runs again.
 */
class QuoteTemplateSeeder extends Seeder
{
    public function run(): void
    {
        /** @var list<array{name: string, description?: string, terms?: string, sections: list<array<string, mixed>>}> $templates */
        $templates = json_decode(File::get(database_path('seeders/data/quote-templates.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach ($templates as $position => $template) {
            $terms = (string) ($template['terms'] ?? '');
            $isDesign = str_contains($terms, '75%');
            $isScope = str_contains($template['name'], 'Scope of Work') || str_contains($template['name'], 'Solution')
                || str_contains($template['name'], 'Residential') || str_contains($template['name'], 'Commercial');

            QuoteTemplate::query()->firstOrCreate(['slug' => Str::slug($template['name'])], [
                'name' => $template['name'],
                'description' => $template['description'] ?? null,
                'business_line' => $template['name'] === 'Furniture' ? 'furniture' : 'interior',
                'kind' => $isScope ? 'scope' : ($isDesign ? 'design' : 'execution'),
                'milestone_plan' => $isDesign ? 'design_75_25' : 'execution_50_40_10',
                'terms' => $terms,
                'blueprint' => ['sections' => array_map(fn (array $section): array => [
                    'name' => (string) $section['name'],
                    'note' => (string) ($section['note'] ?? ''),
                    'items' => array_map(fn (array $item): array => [
                        'product_name' => (string) ($item['product_name'] ?? ''),
                        'description' => (string) $item['desc'],
                        'unit' => Unit::normalize((string) ($item['unit'] ?? 'job'))->value,
                        'qty' => $item['qty'] === null || $item['qty'] === '' ? null : (string) $item['qty'],
                        'rate_minor' => $item['rate'] === null || $item['rate'] === '' ? null : Money::ofRupees((string) $item['rate'])->minor,
                        'catalog_item_id' => null,
                    ], $section['items'] ?? []),
                ], $template['sections'])],
                'is_active' => true,
                'position' => $position,
            ]);
        }
    }
}
