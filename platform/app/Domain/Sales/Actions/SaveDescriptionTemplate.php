<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Models\DescriptionTemplate;
use App\Domain\Sales\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class SaveDescriptionTemplate
{
    /** @param array<string, mixed> $values */
    public function handle(User $by, array $values, ?int $id = null): DescriptionTemplate
    {
        $by = User::query()->findOrFail($by->id);
        abort_unless($by->is_active, 403);
        Gate::forUser($by)->authorize('manageCatalog', Quote::class);
        foreach (['section', 'title', 'body'] as $field) {
            if (is_string($values[$field] ?? null)) {
                $values[$field] = trim($values[$field]);
            }
        }
        $data = Validator::make($values, [
            'business_line' => ['required', Rule::in(['interior', 'furniture'])],
            'section' => ['required', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:100'],
            'body' => ['required', 'string', 'max:500'],
            'is_active' => ['required', 'boolean'],
        ])->validate();

        return DB::transaction(function () use ($by, $data, $id): DescriptionTemplate {
            $template = $id === null ? new DescriptionTemplate : DescriptionTemplate::query()->lockForUpdate()->findOrFail($id);
            $template->fill($data)->save();
            activity('sales-library')->causedBy($by)->performedOn($template)->log($id === null ? 'Description created' : 'Description updated');

            return $template->refresh();
        });
    }
}
