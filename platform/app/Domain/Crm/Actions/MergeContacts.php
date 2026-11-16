<?php

declare(strict_types=1);

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Contact;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Merge a duplicate contact into another (CRM-012). Everything moves to the target; the duplicate is
 * soft-deleted with a pointer to the target, and the merge is written to the timeline.
 */
final class MergeContacts
{
    /** @var list<string> tables with a contact_id column */
    private const RELATED_TABLES = ['leads', 'activities', 'tasks', 'site_visits', 'quotes', 'invoices', 'payments', 'projects'];

    public function handle(Contact $target, Contact $duplicate, ?User $by = null): Contact
    {
        if ($target->is($duplicate)) {
            throw new InvalidArgumentException('A contact cannot be merged into itself.');
        }

        return DB::transaction(function () use ($target, $duplicate, $by): Contact {
            $locked = Contact::query()->whereKey([$target->id, $duplicate->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $target = $locked->get($target->id) ?? abort(404);
            $duplicate = $locked->get($duplicate->id) ?? abort(404);

            foreach (self::RELATED_TABLES as $table) {
                DB::table($table)->where('contact_id', $duplicate->id)->update(['contact_id' => $target->id]);
            }

            $target->tags()->syncWithoutDetaching($duplicate->tags()->pluck('tags.id')->all());
            $duplicate->tags()->detach();

            foreach (['phone', 'whatsapp', 'email', 'city', 'area', 'address', 'company_id', 'owner_id'] as $field) {
                if (blank($target->{$field}) && filled($duplicate->{$field})) {
                    $target->{$field} = $duplicate->{$field};
                }
            }

            foreach (array_filter([$duplicate->phone, $duplicate->alt_phone]) as $number) {
                if ($number !== $target->phone && $number !== $target->whatsapp && blank($target->alt_phone)) {
                    $target->alt_phone = $number;
                }
            }

            $target->is_client = $target->is_client || $duplicate->is_client;
            $target->became_client_at ??= $duplicate->became_client_at;
            $target->opted_out_at ??= $duplicate->opted_out_at;
            $target->consent_whatsapp = $target->opted_out_at === null && ($target->consent_whatsapp || $duplicate->consent_whatsapp);
            $target->consent_marketing = $target->opted_out_at === null && ($target->consent_marketing || $duplicate->consent_marketing);

            if (filled($duplicate->notes)) {
                $target->notes = trim(($target->notes ?? '')."\n".$duplicate->notes);
            }

            $target->last_activity_at = now();
            $target->save();

            $duplicate->forceFill(['meta' => array_merge($duplicate->meta ?? [], ['merged_into' => $target->id])])->save();
            $duplicate->delete();

            Activity::create([
                'contact_id' => $target->id,
                'user_id' => $by?->id,
                'type' => ActivityType::System,
                'body' => sprintf('Merged duplicate contact %s (%s)', $duplicate->name, Phone::display($duplicate->phone) ?: ($duplicate->email ?? '#'.$duplicate->id)),
                'data' => ['merged_contact_id' => $duplicate->id],
                'occurred_at' => now(),
            ]);

            return $target->refresh();
        });
    }
}
