<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Projects\Models\Project;
use App\Domain\Sales\Models\Invoice;

/** The caller must hold the project row lock until invoice insertion commits. */
final class InvoiceNumbering
{
    public static function next(Project $project): string
    {
        $prefix = $project->code.'-';
        $maximum = 0;
        $numbers = Invoice::withTrashed()->where(function ($query) use ($project): void {
            $query->where('project_id', $project->id);
            if ($project->quote_id !== null) {
                $query->orWhere('quote_id', $project->quote_id);
            }
        })->pluck('number');

        foreach ($numbers as $number) {
            if (str_starts_with($number, $prefix)) {
                $suffix = substr($number, strlen($prefix));
                if (ctype_digit($suffix)) {
                    $maximum = max($maximum, (int) $suffix);
                }
            }
        }

        if ($maximum === PHP_INT_MAX) {
            throw new \OverflowException('The invoice suffix is exhausted.');
        }

        return $prefix.($maximum + 1);
    }
}
