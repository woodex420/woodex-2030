<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Crm\Models\Lead;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Owner-requested, single-year CRM snapshot. No financial data, provider calls or record changes. */
final class LeadCsvExportController
{
    public function __invoke(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->is_active && $request->user()->hasRole('owner'), 403);
        $data = $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:'.now()->year]]);
        $year = (int) $data['year'];
        $query = Lead::query()->whereHas('contact')->with(['contact', 'stage'])
            ->where('created_at', '>=', $year.'-01-01 00:00:00')->where('created_at', '<', ($year + 1).'-01-01 00:00:00');
        if ((clone $query)->count() > 5000) {
            throw ValidationException::withMessages(['year' => 'This year exceeds the 5,000-row CSV limit; use a narrower approved export. No rows were silently truncated.']);
        }
        // Materialize the bounded snapshot before streaming. No lazy provider calls or duplicate queries in the download.
        $leads = $query->orderBy('id')->get();

        return response()->streamDownload(static function () use ($leads): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new \RuntimeException('The CSV output could not be opened.');
            }
            try {
                fwrite($output, "\xEF\xBB\xBF");
                fputcsv($output, ['Sr', 'Date', 'Name', 'Contact', 'Status'], escape: '');
                foreach ($leads as $lead) {
                    $phone = Phone::normalize($lead->contact?->phone);
                    // 00 is an importer-supported international prefix, with no spreadsheet formula sign.
                    fputcsv($output, [
                        (string) $lead->id, CarbonImmutable::parse((string) $lead->getRawOriginal('created_at'), 'UTC')->format('d/m/Y'),
                        self::cell($lead->contact?->name ?? ''), $phone === null ? '' : '00'.substr($phone, 1),
                        self::cell($lead->stage?->name ?? ''),
                    ], escape: '');
                }
            } finally {
                fclose($output);
            }
        }, 'woodex-leads-'.$year.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private static function cell(string $value): string
    {
        // Quoting alone does not prevent spreadsheet formula execution. Do not modify the stored value.
        return preg_match('/^[\s\x00-\x20]*[=+@-]/u', $value) === 1 ? "'".$value : $value;
    }
}
