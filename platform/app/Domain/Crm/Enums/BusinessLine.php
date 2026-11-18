<?php

declare(strict_types=1);

namespace App\Domain\Crm\Enums;

use App\Support\Concerns\EnumHelpers;
use Illuminate\Container\Container;

/**
 * Business lines. Interior and Project share the WI- series, Furniture uses WF-.
 *
 * The enum keeps all three, because the application came from the Interior studio's and records of any line
 * must stay readable. What an installation *sells* is narrower: config('woodex.business_lines'). Woodex
 * Furniture sells one line, so nothing offers a choice of line and nothing starts on another line there
 * (owner decision D-008). Screens ask offered(), primary() and tag(); they do not list cases().
 */
enum BusinessLine: string
{
    use EnumHelpers;

    case Interior = 'interior';
    case Furniture = 'furniture';
    case Project = 'project';

    /**
     * The lines this installation sells, in the configured order. Never empty: Furniture is the fallback.
     *
     * @return list<self>
     */
    public static function offered(): array
    {
        $container = Container::getInstance();
        $configured = $container->bound('config') ? (array) $container->make('config')->get('woodex.business_lines', []) : [];

        $lines = [];

        foreach ($configured as $key) {
            $line = self::tryFrom(is_string($key) ? trim($key) : '');

            if ($line !== null && ! in_array($line, $lines, true)) {
                $lines[] = $line;
            }
        }

        return $lines === [] ? [self::Furniture] : $lines;
    }

    /** The line a new record starts on. */
    public static function primary(): self
    {
        return self::offered()[0];
    }

    /** True when there is a choice to offer. With one line no chooser, filter or column is shown. */
    public static function severalOffered(): bool
    {
        return count(self::offered()) > 1;
    }

    public function isOffered(): bool
    {
        return in_array($this, self::offered(), true);
    }

    /**
     * @return array<string, string> value => label
     */
    public static function offeredOptions(): array
    {
        $options = [];

        foreach (self::offered() as $line) {
            $options[$line->value] = $line->label();
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public static function offeredValues(): array
    {
        return array_map(static fn (self $line): string => $line->value, self::offered());
    }

    /**
     * The choices for a record that already has a line: the lines on sale, and the record's own line when it is
     * not one of them — so an older record shows what it is and can be moved, and is never changed unseen.
     *
     * @return array<string, string> value => label
     */
    public static function optionsFor(?self $current): array
    {
        $options = self::offeredOptions();

        if ($current !== null && ! $current->isOffered()) {
            $options[$current->value] = $current->label();
        }

        return $options;
    }

    /**
     * The brand a line's documents carry: Interior and Project quote, invoice and print as one (WI),
     * Furniture as its own (WF). Templates, designs, bank accounts and description libraries are kept per brand.
     */
    public function brand(): self
    {
        return $this === self::Furniture ? self::Furniture : self::Interior;
    }

    /**
     * @return list<self>
     */
    public static function offeredBrands(): array
    {
        $brands = [];

        foreach (self::offered() as $line) {
            if (! in_array($line->brand(), $brands, true)) {
                $brands[] = $line->brand();
            }
        }

        return $brands;
    }

    /**
     * @return list<string>
     */
    public static function offeredBrandValues(): array
    {
        return array_map(static fn (self $brand): string => $brand->value, self::offeredBrands());
    }

    /**
     * The brands on sale as the choices of a list.
     *
     * @param  array<string, string>  $labels  the wording a screen uses for a brand; label() where it gives none
     * @return array<string, string> value => label
     */
    public static function offeredBrandOptions(array $labels = []): array
    {
        $options = [];

        foreach (self::offeredBrands() as $brand) {
            $options[$brand->value] = $labels[$brand->value] ?? $brand->label();
        }

        return $options;
    }

    /**
     * The brands a record may be kept for: the brands on sale, and the record's own brand when it is not one of
     * them — so an older record shows what it is and is never changed unseen.
     *
     * @param  array<string, string>  $labels
     * @return array<string, string> value => label
     */
    public static function brandOptionsFor(?self $current, array $labels = []): array
    {
        $options = self::offeredBrandOptions($labels);
        $brand = $current?->brand();

        if ($brand !== null && ! array_key_exists($brand->value, $options)) {
            $options[$brand->value] = $labels[$brand->value] ?? $brand->label();
        }

        return $options;
    }

    public static function primaryBrand(): self
    {
        return self::primary()->brand();
    }

    public static function severalBrandsOffered(): bool
    {
        return count(self::offeredBrands()) > 1;
    }

    /**
     * Examples for the hint of a "Service" field: what a client of this line asks for.
     */
    public function serviceExamples(): string
    {
        return $this->brand() === self::Furniture ? 'office chairs, workstations, storage' : 'kitchen, wardrobes, fit-out';
    }

    /**
     * Suggestions for the "type" of a quotation of this line. Furniture has none of its own yet (its wording
     * is the owner's to give, decision D-8), so nothing is suggested there rather than something invented.
     *
     * @return list<string>
     */
    public function quotationTypes(): array
    {
        return $this->brand() === self::Furniture ? [] : ['Renovation', 'Civil Work', 'Interior Designing', 'Interior', 'Fit-out', 'Architecture'];
    }

    /**
     * What to print beside a record: the line's name where it tells records apart, nothing where every record
     * is of the one line this installation sells. A record of a line that is not sold here still says so.
     */
    public function tag(): string
    {
        return self::severalOffered() || ! $this->isOffered() ? $this->label() : '';
    }

    /**
     * Number sequence used for quotations of this line (D-16).
     */
    public function quoteSequenceKey(): string
    {
        return $this === self::Furniture ? 'quote.furniture' : 'quote.interior';
    }

    public function label(): string
    {
        return match ($this) {
            self::Interior => 'Interior',
            self::Furniture => 'Furniture',
            self::Project => 'Project (turnkey)',
        };
    }
}
