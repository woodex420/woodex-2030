<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Crm\Actions\ExportContactData;
use App\Domain\Crm\Models\Contact;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * SYS-013: a person's data as one JSON download. Leads permission — it is the
 * CRM's memory of a person.
 */
final class ContactDataExportController
{
    public function __invoke(Contact $contact, ExportContactData $export): Response
    {
        Gate::authorize('view', $contact);

        $payload = $export->export($contact);

        return response(
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
            200,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="woodex-contact-'.$contact->id.'-'.now()->format('Y-m-d').'.json"',
            ],
        );
    }
}
