<?php

declare(strict_types=1);

use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;

it('exports a single UTC year for an active owner without changing CRM records', function () {
    $this->actingAs($this->userWithRole('owner'));
    $contact = Contact::factory()->create(['name' => 'Synthetic export contact', 'phone' => '+12025550101']);
    Lead::factory()->create(['contact_id' => $contact->id, 'created_at' => '2025-12-31 23:59:59']);
    $lead = Lead::factory()->create(['contact_id' => $contact->id, 'created_at' => '2026-01-01 00:00:00']);
    Lead::factory()->create(['contact_id' => $contact->id, 'created_at' => '2027-01-01 00:00:00']);
    $this->get(route('admin.leads.export', ['year' => 2026]))->assertOk()->assertDownload('woodex-leads-2026.csv')
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertStreamedContent("\xEF\xBB\xBFSr,Date,Name,Contact,Status\n".$lead->id.',01/01/2026,"Synthetic export contact",0012025550101,'.$lead->stage->name."\n");
    $this->assertDatabaseCount('leads', 3);
    $this->assertDatabaseCount('contacts', 1);
});

it('escapes spreadsheet formulas and excludes archived leads and contacts', function () {
    $this->actingAs($this->userWithRole('owner'));
    $contact = Contact::factory()->create(['name' => '=HYPERLINK("https://invalid.example")', 'phone' => '+12025550102']);
    Lead::factory()->create(['contact_id' => $contact->id, 'created_at' => '2025-01-01']);
    $archived = Lead::factory()->create(['created_at' => '2025-01-01']);
    $archived->delete();
    $deletedContactLead = Lead::factory()->create(['created_at' => '2025-01-01']);
    $deletedContactLead->contact->delete();
    $body = $this->get(route('admin.leads.export', ['year' => 2025]))->assertOk()->streamedContent();
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, substr($body, 3));
    rewind($stream);
    fgetcsv($stream, escape: '');
    $row = fgetcsv($stream, escape: '');
    expect($row[2])->toBe("'".$contact->name)->and(fgetcsv($stream, escape: ''))->toBeFalse();
    fclose($stream);
    expect($contact->fresh()->name)->toBe('=HYPERLINK("https://invalid.example")');
});

it('rejects non-owner inactive and invalid-year exports', function () {
    $this->actingAs($this->userWithRole('sales_manager'));
    $this->get(route('admin.leads.export', ['year' => 2025]))->assertForbidden();
    $owner = $this->userWithRole('owner');
    $this->actingAs($owner);
    foreach (['', 1999, now()->year + 1, 'not-a-year'] as $year) {
        $this->getJson(route('admin.leads.export', ['year' => $year]))->assertUnprocessable();
    }
    $owner->update(['is_active' => false]);
    $this->get(route('admin.leads.export', ['year' => 2025]))->assertRedirect(route('login'))->assertSessionHasErrors('email');
});
