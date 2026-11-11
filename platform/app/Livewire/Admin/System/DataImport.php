<?php

declare(strict_types=1);

namespace App\Livewire\Admin\System;

use App\Domain\System\Import\V21Importer;
use App\Domain\System\Jobs\RunV21Import;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Data import wizard (SYS-006): 1) check the v2.1 connection, 2) dry run, 3) review the report,
 * 4) run for real (needs a dry run from the last 24 hours and typing IMPORT). Jobs run on the queue;
 * the CLI command `woodex:import-v21` stays available for SSH (docs/08).
 */
#[Layout('layouts::admin')]
#[Title('Data import')]
class DataImport extends Component
{
    use InteractsWithAdmin;

    /** @var list<string> */
    public array $steps = V21Importer::STEPS;

    public string $confirmation = '';

    public function mount(): void
    {
        abort_unless($this->user()->can('system.import'), 403);
    }

    public function dryRun(): void
    {
        $this->start(true);
    }

    public function import(): void
    {
        $this->validate(['confirmation' => ['required', 'in:IMPORT']], ['confirmation.in' => 'Type IMPORT in capitals to confirm.']);

        $recent = $this->reports()->first(fn (array $r): bool => $r['dry_run'] && $r['started_at'] !== null && $r['started_at']->gt(now()->subDay()));

        if ($recent === null) {
            $this->addError('confirmation', 'Run a dry run first (within the last 24 hours) and review its warnings.');

            return;
        }

        $this->start(false);
        $this->confirmation = '';
    }

    public function download(string $directory, string $file): StreamedResponse
    {
        abort_unless($this->user()->can('system.import'), 403);
        abort_unless(preg_match('/^\d{8}-\d{6}(-dry-run)?$/', $directory) === 1 && in_array($file, ['warnings.csv', 'duplicates.csv', 'report.json'], true), 404);

        return Storage::disk('local')->download("imports/{$directory}/{$file}", "v21-{$directory}-{$file}");
    }

    private function start(bool $dryRun): void
    {
        abort_unless($this->user()->can('system.import'), 403);
        $steps = array_values(array_intersect(V21Importer::STEPS, $this->steps));

        if ($steps === []) {
            $this->addError('steps', 'Choose at least one part to import.');

            return;
        }

        if (in_array(Cache::get(RunV21Import::STATUS_KEY)['state'] ?? null, ['queued', 'running'], true)) {
            $this->toast('An import is already queued or running.', 'warning');

            return;
        }

        RunV21Import::status('queued', ($dryRun ? 'Dry run' : 'Import').' queued. It starts within a minute (the scheduler runs the queue).');
        RunV21Import::dispatch($dryRun, $steps, $this->user()->id);
        $this->toast($dryRun ? 'Dry run queued.' : 'Import queued.');
    }

    /**
     * @return Collection<int, array{directory: string, dry_run: bool, started_at: Carbon|null, seconds: float, counts: array<mixed>, warnings: int, duplicates: int}>
     */
    private function reports(): Collection
    {
        $disk = Storage::disk('local');

        return collect($disk->directories('imports'))
            ->map(function (string $path) use ($disk): ?array {
                $json = json_decode((string) $disk->get("{$path}/report.json"), true);

                if (! is_array($json)) {
                    return null;
                }

                return [
                    'directory' => basename($path),
                    'dry_run' => (bool) ($json['dry_run'] ?? false),
                    'started_at' => isset($json['started_at']) ? Carbon::parse($json['started_at']) : null,
                    'seconds' => (float) ($json['seconds'] ?? 0),
                    'counts' => (array) ($json['counts'] ?? []),
                    'warnings' => (int) ($json['warnings'] ?? 0),
                    'duplicates' => (int) ($json['duplicates'] ?? 0),
                ];
            })
            ->filter()
            ->sortByDesc('directory')
            ->values();
    }

    public function render(): View
    {
        return view('livewire.admin.system.data-import', [
            'reports' => $this->reports()->take(10),
            'status' => Cache::get(RunV21Import::STATUS_KEY),
            'configured' => filled(config('database.connections.legacy.database')),
            'allSteps' => V21Importer::STEPS,
        ]);
    }
}
