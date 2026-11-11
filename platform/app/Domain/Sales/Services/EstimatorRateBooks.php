<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Sales\Enums\Unit;
use App\Domain\Sales\Models\EstimatorRate;
use App\Domain\Website\Studio\RateBook;
use App\Models\User;
use App\Settings\EstimatorSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** One public-file/database publishing path for the native hub and legacy Studio API. */
final class EstimatorRateBooks
{
    private function authorize(User $by): void
    {
        $by = User::query()->findOrFail($by->id);
        if (! $by->is_active || ! $by->can('website.edit')) {
            throw new AuthorizationException;
        }
    }

    public function revision(): string
    {
        return hash_file('sha256', RateBook::path());
    }

    /** @return array<string, mixed> */
    public function read(User $by): array
    {
        $this->authorize($by);

        return ['book' => $this->rateBookForClient(RateBook::read()), 'templates' => $this->rateBookTemplates(), 'page' => '/estimator/', 'revision' => $this->revision(), 'consistent' => $this->consistent()];
    }

    public function consistent(): bool
    {
        $book = RateBook::normalise(RateBook::read());
        $rows = EstimatorRate::query()->where('is_active', true)->get()->keyBy('key');
        if ($rows->count() !== count($book['services'])) {
            return false;
        }
        foreach ($book['services'] as $i => $s) {
            $row = $rows->get($s['key']);
            if ($row === null || $row->label !== $s['label'] || $row->unit !== Unit::normalize($s['unit'])->value || (string) $row->unit_label !== $s['unitLabel'] || (string) $row->hint !== $s['hint'] || (int) $row->position !== $i) {
                return false;
            }
            foreach (RateBook::FINISHES as $f) {
                if ((int) $row->{$f.'_minor'} !== (int) $s['rates'][$f] * 100) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function publish(User $by, array $input, ?string $revision = null): array
    {
        $this->authorize($by);

        return Cache::lock('estimator-publish:'.sha1(RateBook::path()), 30)->block(5, function () use ($by, $input, $revision): array {
            if ($revision !== null && ! hash_equals($this->revision(), $revision)) {
                throw new RuntimeException('Rates changed since this editor was opened. Reload before publishing.');
            }
            $book = RateBook::normalise($input);
            $before = RateBook::read();
            $bytes = file_get_contents(RateBook::path());
            $book['catalog'] = $before['catalog'];
            try {
                DB::transaction(function () use ($book): void {
                    RateBook::write($book);
                    $this->mirrorEstimatorRates($book['services']);
                });
            } catch (Throwable $e) {
                // Compensate exact bytes, not a re-encoded approximation of the old public file.
                if (file_put_contents(RateBook::path(), $bytes) === false) {
                    throw new RuntimeException('Publishing failed and the public file could not be restored. Restore the rate-book backup before retrying.', previous: $e);
                }
                throw $e;
            }

            return [...$this->read($by), 'saved' => 'The estimator page and the bot now use these rates.'];
        });
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function template(User $by, array $payload): array
    {
        $this->authorize($by);
        $op = (string) ($payload['op'] ?? '');
        $name = trim((string) ($payload['name'] ?? ''));
        $settings = EstimatorSettings::shelf();
        $settings->refresh();
        /** @var array<string, array{at: string, services: list<mixed>, finishes: list<mixed>, terms: list<mixed>}> $books */
        $books = $settings->rate_books;

        if ($op === 'delete') {
            if (! isset($books[$name])) {
                throw new RuntimeException('That rate book is not in the list.');
            }

            unset($books[$name]);
            $settings->rate_books = $books;
            $settings->save();

            return ['templates' => $this->rateBookTemplates(), 'note' => "“{$name}” deleted."];
        }

        if ($op === 'apply') {
            if (! isset($books[$name])) {
                throw new RuntimeException('That rate book is not in the list.');
            }

            $snapshot = $books[$name];

            return [
                'book' => $this->rateBookForClient([
                    'services' => $snapshot['services'],
                    'finishes' => $snapshot['finishes'],
                    'catalog' => RateBook::read()['catalog'],
                    'terms' => $snapshot['terms'],
                ]),
                'note' => "“{$name}” loaded into the editor — check it, then press Save & publish.",
            ];
        }

        if ($op !== 'save') {
            throw new RuntimeException('Unknown action for the saved rate books.');
        }

        if ($name === '' || mb_strlen($name) > 60) {
            throw new RuntimeException('Give the rate book a name of at most 60 characters.');
        }

        if (count($books) >= 12 && ! isset($books[$name])) {
            throw new RuntimeException('There are already 12 saved rate books — delete one first.');
        }

        $now = RateBook::read();
        $books[$name] = [
            'at' => now()->toIso8601String(),
            'services' => array_values($now['services']),
            'finishes' => array_values($now['finishes']),
            'terms' => array_values($now['terms']),
        ];

        // Newest first, so the list in the screen reads the same way the snapshots were taken.
        $books = array_reverse($books, true);

        $settings->rate_books = $books;
        $settings->save();

        return ['templates' => $this->rateBookTemplates(), 'note' => "“{$name}” saved."];
    }

    /**
     * The book as the screen edits it: catalogue entries are counted, not sent — the screen says they are
     * kept as they are. Finishes always arrive as the three the calculator uses, in order.
     *
     * @param  array<string, mixed>  $book
     * @return array<string, mixed>
     */
    private function rateBookForClient(array $book): array
    {
        $services = [];

        foreach ((array) ($book['services'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rates = is_array($row['rates'] ?? null) ? $row['rates'] : [];

            $services[] = [
                'key' => (string) ($row['key'] ?? ''),
                'label' => (string) ($row['label'] ?? ''),
                'hint' => (string) ($row['hint'] ?? ''),
                'unit' => (string) ($row['unit'] ?? 'sqft'),
                'unitLabel' => (string) ($row['unitLabel'] ?? ''),
                'rates' => [
                    'essential' => (int) ($rates['essential'] ?? 0),
                    'standard' => (int) ($rates['standard'] ?? 0),
                    'premium' => (int) ($rates['premium'] ?? 0),
                ],
            ];
        }

        $finishes = [];

        foreach (RateBook::FINISHES as $finish) {
            $label = ucfirst($finish);
            $hint = '';

            foreach ((array) ($book['finishes'] ?? []) as $row) {
                if (is_array($row) && (string) ($row['key'] ?? '') === $finish) {
                    $label = (string) ($row['label'] ?? $label);
                    $hint = (string) ($row['hint'] ?? '');
                    break;
                }
            }

            $finishes[] = ['key' => $finish, 'label' => $label, 'hint' => $hint];
        }

        $terms = [];

        foreach ((array) ($book['terms'] ?? []) as $term) {
            if (is_scalar($term) && trim((string) $term) !== '') {
                $terms[] = (string) $term;
            }
        }

        return [
            'services' => $services,
            'finishes' => $finishes,
            'terms' => $terms,
            'catalog_count' => count((array) ($book['catalog'] ?? [])),
        ];
    }

    /**
     * The saved snapshots for the screen, newest first.
     *
     * @return list<array{name: string, at: string, services: int}>
     */
    private function rateBookTemplates(): array
    {
        try {
            /** @var array<string, array{at?: string, services?: list<mixed>}> $books */
            $books = json_decode((string) (DB::table('settings')->where('group', 'estimator')->where('name', 'rate_books')->value('payload') ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        $rows = [];

        foreach ($books as $name => $snapshot) {
            $rows[] = [
                'name' => (string) $name,
                'at' => (string) ($snapshot['at'] ?? ''),
                'services' => count((array) ($snapshot['services'] ?? [])),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return $rows;
    }

    /**
     * Keep the bot and the staff estimate builder on the same prices as the public page. Rows are never
     * deleted — a service taken off the website may still be referenced by an old estimate — they are
     * switched off instead.
     *
     * @param  list<array<string, mixed>>  $services
     */
    private function mirrorEstimatorRates(array $services): void
    {
        $keys = [];

        foreach ($services as $i => $service) {
            $key = (string) $service['key'];
            $keys[] = $key;
            $rates = (array) $service['rates'];

            EstimatorRate::updateOrCreate(['key' => $key], [
                'label' => (string) $service['label'],
                'hint' => $service['hint'] !== '' ? (string) $service['hint'] : null,
                'unit' => Unit::normalize((string) $service['unit'])->value,
                'unit_label' => (string) $service['unitLabel'],
                'essential_minor' => (int) $rates['essential'] * 100,
                'standard_minor' => (int) $rates['standard'] * 100,
                'premium_minor' => (int) $rates['premium'] * 100,
                'is_active' => true,
                'position' => $i,
            ]);
        }

        EstimatorRate::query()->whereNotIn('key', $keys)->update(['is_active' => false]);
    }
}
