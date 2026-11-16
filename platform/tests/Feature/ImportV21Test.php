<?php

use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Enums\VisitStatus;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\SiteVisit;
use App\Domain\System\Import\V21Importer;
use App\Domain\System\Models\LegacyRef;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/*
| A throwaway SQLite database shaped like the live v2.1 MySQL tables (crm-lib, sales17-lib, booking-lib).
*/
beforeEach(function () {
    config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
    DB::purge('legacy');
    $schema = Schema::connection('legacy');

    $schema->create('wx_users', function (Blueprint $t) {
        $t->increments('id');
        $t->string('name');
        $t->string('email');
        $t->string('role')->default('editor');
        $t->string('pass_hash')->default('$2y$10$legacy');
        $t->boolean('active')->default(true);
        $t->dateTime('created_at');
        $t->dateTime('last_login')->nullable();
    });
    $schema->create('wx_clients', function (Blueprint $t) {
        $t->increments('id');
        foreach (['name', 'phone', 'email', 'company', 'city', 'address', 'notes', 'type', 'source', 'tags', 'line', 'designation'] as $column) {
            $t->string($column)->nullable();
        }
        $t->dateTime('created_at');
        $t->dateTime('updated_at')->nullable();
    });
    $schema->create('wx_leads', function (Blueprint $t) {
        $t->increments('id');
        $t->dateTime('created_at');
        foreach (['source', 'page', 'name', 'phone', 'email', 'service', 'message', 'fields', 'stage', 'lost_reason', 'tags', 'ip', 'company', 'designation', 'location', 'line', 'lead_type', 'project_type', 'budget', 'area', 'priority', 'quote_status', 'next_type'] as $column) {
            $t->text($column)->nullable();
        }
        $t->integer('assigned_to')->nullable();
        $t->date('followup')->nullable();
        $t->bigInteger('value')->default(0);
        $t->integer('client_id')->nullable();
        $t->boolean('is_read')->default(false);
        $t->dateTime('last_contact')->nullable();
        $t->dateTime('next_at')->nullable();
    });
    $schema->create('wx_lead_notes', function (Blueprint $t) {
        $t->increments('id');
        $t->integer('lead_id');
        $t->dateTime('t');
        $t->string('user_name')->nullable();
        $t->text('text');
        $t->boolean('sys')->default(false);
        $t->string('kind')->nullable();
        $t->string('outcome')->nullable();
    });
    $schema->create('wx_bookings', function (Blueprint $t) {
        $t->increments('id');
        $t->dateTime('created_at');
        $t->integer('lead_id')->nullable();
        foreach (['name', 'phone', 'email', 'type', 'city', 'address', 'note', 'src'] as $column) {
            $t->string($column)->nullable();
        }
        $t->date('d');
        $t->string('tm', 5);
        $t->integer('dur')->default(60);
        $t->string('status')->default('pending');
        $t->integer('staff_id')->nullable();
        $t->integer('reminded')->default(0);
    });

    $db = DB::connection('legacy');

    $db->table('wx_users')->insert([
        ['id' => 1, 'name' => 'Ali Owner', 'email' => 'Ali@Woodex.com.pk', 'role' => 'owner', 'active' => 1, 'created_at' => '2025-01-10 10:00:00'],
        ['id' => 2, 'name' => 'Sara Sales', 'email' => 'sara@woodex.com.pk', 'role' => 'sales', 'active' => 1, 'created_at' => '2025-02-01 10:00:00'],
        ['id' => 3, 'name' => 'Broken', 'email' => 'not-an-email', 'role' => 'sales', 'active' => 1, 'created_at' => '2025-02-01 10:00:00'],
        ['id' => 4, 'name' => 'Old Designer', 'email' => 'designer@woodex.com.pk', 'role' => 'designer', 'active' => 0, 'created_at' => '2025-03-01 10:00:00'],
    ]);

    $db->table('wx_clients')->insert([
        ['id' => 1, 'name' => 'Imran Builders', 'phone' => '0300-1234567', 'email' => 'imran@example.com', 'company' => 'Acme Builders', 'city' => 'Lahore', 'type' => 'company', 'tags' => 'VIP, DHA', 'designation' => 'CEO', 'created_at' => '2025-04-01 12:00:00'],
    ]);

    $db->table('wx_leads')->insert([
        ['id' => 1, 'created_at' => '2025-05-01 09:30:00', 'source' => 'facebook', 'name' => 'Imran Builders', 'phone' => '03001234567', 'stage' => 'visit', 'assigned_to' => 2, 'client_id' => 1,
            'area' => '10 marla', 'budget' => '25 lakh', 'value' => 2500000, 'location' => 'DHA Phase 6, Lahore', 'line' => 'interior', 'project_type' => 'Kitchen design',
            'next_at' => '2026-12-01 15:00:00', 'next_type' => 'whatsapp', 'last_contact' => '2025-05-02 11:00:00', 'priority' => 'high', 'tags' => 'kitchen'],
        ['id' => 2, 'created_at' => '2025-06-01 10:00:00', 'source' => 'walkin', 'name' => 'Nadia Hussain', 'phone' => '+92 333 7654321', 'stage' => 'hold', 'assigned_to' => null, 'client_id' => null,
            'area' => '1 kanal', 'budget' => 'flexible', 'value' => 0, 'location' => 'Bahria Town', 'line' => 'project', 'project_type' => null,
            'next_at' => null, 'next_type' => null, 'last_contact' => null, 'priority' => null, 'tags' => null],
        ['id' => 3, 'created_at' => '2025-07-01 10:00:00', 'source' => 'portal', 'name' => 'Nadia H.', 'phone' => '03337654321', 'stage' => 'lost', 'assigned_to' => 99, 'client_id' => null,
            'area' => null, 'budget' => null, 'value' => 0, 'location' => null, 'line' => 'furniture', 'project_type' => null,
            'next_at' => null, 'next_type' => null, 'last_contact' => '2025-07-03 10:00:00', 'priority' => 'low', 'tags' => null],
        ['id' => 4, 'created_at' => '2025-08-01 10:00:00', 'source' => 'contact', 'name' => 'Mystery Stage', 'phone' => null, 'stage' => 'archived', 'assigned_to' => null, 'client_id' => null,
            'area' => 'big house', 'budget' => null, 'value' => 0, 'location' => null, 'line' => null, 'project_type' => null,
            'next_at' => null, 'next_type' => null, 'last_contact' => null, 'priority' => null, 'tags' => null],
    ]);
    $db->table('wx_leads')->where('id', 3)->update(['lost_reason' => 'Budget too low']);
    $db->table('wx_leads')->where('id', 4)->update(['email' => 'mystery@example.com']);

    $db->table('wx_lead_notes')->insert([
        ['id' => 1, 'lead_id' => 1, 't' => '2025-05-01 10:00:00', 'user_name' => 'Sara Sales', 'text' => 'Called, no response.', 'sys' => 0, 'kind' => 'call', 'outcome' => 'No answer'],
        ['id' => 2, 'lead_id' => 1, 't' => '2025-05-01 09:31:00', 'user_name' => null, 'text' => 'Lead assigned to Sara Sales', 'sys' => 1, 'kind' => null, 'outcome' => null],
        ['id' => 3, 'lead_id' => 2, 't' => '2025-06-02 10:00:00', 'user_name' => 'Former Staff', 'text' => 'Discussed basement.', 'sys' => 0, 'kind' => 'meeting', 'outcome' => 'Positive'],
        ['id' => 4, 'lead_id' => 99, 't' => '2025-06-02 10:00:00', 'user_name' => null, 'text' => 'Orphan', 'sys' => 0, 'kind' => 'note', 'outcome' => null],
    ]);

    $db->table('wx_bookings')->insert([
        ['id' => 1, 'created_at' => '2025-05-03 10:00:00', 'lead_id' => 1, 'name' => 'Imran Builders', 'phone' => '03001234567', 'type' => 'visit', 'city' => 'Lahore', 'address' => 'House 5, DHA Phase 6',
            'd' => '2025-05-05', 'tm' => '11:30', 'dur' => 60, 'status' => 'done', 'staff_id' => 2, 'note' => 'Bring veneer samples', 'reminded' => 1],
        ['id' => 2, 'created_at' => '2025-09-01 10:00:00', 'lead_id' => null, 'name' => 'Walk Up', 'phone' => '0345 1112223', 'type' => 'office', 'city' => 'Lahore', 'address' => null,
            'd' => '2025-09-04', 'tm' => '16:00', 'dur' => 30, 'status' => 'noshow', 'staff_id' => null, 'note' => null, 'reminded' => 0],
    ]);

    Storage::fake('local');
});

it('imports users, clients, leads, notes and bookings with the v2.1 mappings', function () {
    $report = app(V21Importer::class)->run('legacy');

    expect($report->counts['users'])->toMatchArray(['source' => 4, 'created' => 3, 'failed' => 1])
        ->and($report->counts['leads'])->toMatchArray(['source' => 4, 'created' => 4])
        ->and($report->counts['notes'])->toMatchArray(['source' => 4, 'created' => 3, 'skipped' => 1])
        ->and($report->counts['bookings'])->toMatchArray(['source' => 2, 'created' => 2]);

    // Users: emails lower-cased, unknown roles become support, no passwords carried over.
    $sara = User::query()->where('email', 'sara@woodex.com.pk')->sole();
    $designer = User::query()->where('email', 'designer@woodex.com.pk')->sole();
    expect(User::query()->where('email', 'ali@woodex.com.pk')->sole()->hasRole('owner'))->toBeTrue()
        ->and($sara->hasRole('sales'))->toBeTrue()
        ->and($sara->last_login_at)->toBeNull()
        ->and($designer->hasRole('support'))->toBeTrue()
        ->and($designer->is_active)->toBeFalse();

    // Client → contact with company and tags; lead 1 links to it.
    $client = Contact::query()->where('phone', '+923001234567')->sole();
    expect($client->is_client)->toBeTrue()
        ->and($client->client_type->value)->toBe('company')
        ->and($client->company->name)->toBe('Acme Builders')
        ->and($client->meta['designation'])->toBe('CEO')
        ->and($client->tags->pluck('slug')->sort()->values()->all())->toBe(['dha', 'vip']);

    $lead1 = Lead::query()->where('ref', 'V21-00001')->sole();
    expect($lead1->contact_id)->toBe($client->id)
        ->and($lead1->stage->key)->toBe('site_visit')
        ->and($lead1->owner_id)->toBe($sara->id)
        ->and($lead1->area_sqft)->toBe(2250)
        ->and($lead1->budget_band)->toBe('1m-3m')
        ->and($lead1->expected_value_minor)->toBe(250_000_000)
        ->and($lead1->source->value)->toBe('social_media')
        ->and($lead1->source_detail)->toBe('Facebook')
        ->and($lead1->city)->toBe('Lahore')
        ->and($lead1->area)->toBe('DHA Phase 6, Lahore')
        ->and($lead1->service)->toBe('Kitchen design')
        ->and($lead1->priority->value)->toBe('high')
        ->and($lead1->next_action_type->value)->toBe('whatsapp')
        ->and($lead1->next_action_at->format('Y-m-d H:i'))->toBe('2026-12-01 15:00')
        ->and($lead1->first_response_at)->not->toBeNull()
        ->and($lead1->sla_due_at)->toBeNull()
        ->and($lead1->created_at->format('Y-m-d H:i'))->toBe('2025-05-01 09:30')
        ->and($lead1->tags->pluck('slug')->all())->toBe(['kitchen']);

    // Leads 2 and 3 are the same person (phone in two formats) → one contact, two leads.
    $nadia = Contact::query()->where('phone', '+923337654321')->sole();
    $lead2 = Lead::query()->where('ref', 'V21-00002')->sole();
    $lead3 = Lead::query()->where('ref', 'V21-00003')->sole();
    expect($nadia->leads()->count())->toBe(2)
        ->and($lead2->stage->key)->toBe('on_hold')
        ->and($lead2->area_sqft)->toBe(4500)
        ->and($lead2->budget_band)->toBeNull()
        ->and($lead2->meta['legacy']['budget'])->toBe('flexible')
        ->and($lead2->business_line->value)->toBe('project')
        ->and($lead2->source->value)->toBe('walk_in')
        ->and($lead3->stage->key)->toBe('lost')
        ->and($lead3->lost_reason)->toBe('Budget too low')
        ->and($lead3->lost_at)->not->toBeNull()
        ->and($lead3->owner_id)->toBeNull()
        ->and($lead3->source->value)->toBe('portal');

    // Unknown stage → New, with a warning; no SLA timer for imported leads.
    $lead4 = Lead::query()->where('ref', 'V21-00004')->sole();
    expect($lead4->stage->key)->toBe('new')
        ->and($lead4->sla_due_at)->toBeNull()
        ->and($lead4->contact->email)->toBe('mystery@example.com')
        ->and(collect($report->warnings)->pluck('message')->implode(' | '))
        ->toContain('Unknown stage "archived"')
        ->toContain('Budget "flexible"')
        ->toContain('Area "big house"')
        ->toContain('Assigned user #99')
        ->toContain('Invalid email "not-an-email"');

    // Notes → timeline with types, outcomes and authors.
    $call = Activity::query()->where('data->legacy_note_id', 1)->sole();
    expect($call->type)->toBe(ActivityType::Call)
        ->and($call->outcome)->toBe('no_answer')
        ->and($call->user_id)->toBe($sara->id)
        ->and($call->direction)->toBe('out')
        ->and(Activity::query()->where('data->legacy_note_id', 2)->sole()->type)->toBe(ActivityType::System);

    $meeting = Activity::query()->where('data->legacy_note_id', 3)->sole();
    expect($meeting->type)->toBe(ActivityType::Meeting)
        ->and($meeting->data['legacy_user'])->toBe('Former Staff')
        ->and($meeting->body)->toStartWith('Outcome: Positive');

    // Bookings → site visits (Asia/Karachi times), including a booking that had no enquiry.
    $done = SiteVisit::query()->where('lead_id', $lead1->id)->sole();
    expect($done->status)->toBe(VisitStatus::Completed)
        ->and($done->scheduled_at->format('Y-m-d H:i'))->toBe('2025-05-05 11:30')
        ->and($done->assigned_to)->toBe($sara->id)
        ->and($done->address)->toBe('House 5, DHA Phase 6, Lahore')
        ->and($done->outcome_notes)->toBe('Bring veneer samples');

    $noShow = SiteVisit::query()->where('status', 'no_show')->sole();
    expect($noShow->address)->toBe('Woodex studio (office meeting)')
        ->and($noShow->lead->ref)->toBe('V21-B00002')
        ->and($noShow->contact->phone)->toBe('+923451112223');

    // No alerts or audit entries for imported rows.
    expect(DB::table('notifications')->count())->toBe(0)
        ->and(DB::table('activity_log')->count())->toBe(0);
});

it('saves nothing on a dry run', function () {
    $before = [Contact::query()->count(), Lead::query()->count(), User::query()->count()];

    $report = app(V21Importer::class)->run('legacy', dryRun: true);

    expect($report->dryRun)->toBeTrue()
        ->and($report->counts['leads']['created'])->toBe(4)
        ->and([Contact::query()->count(), Lead::query()->count(), User::query()->count()])->toBe($before)
        ->and(LegacyRef::query()->count())->toBe(0);
});

it('is safe to run twice', function () {
    app(V21Importer::class)->run('legacy');
    $counts = [Contact::query()->count(), Lead::query()->count(), Activity::query()->count(), SiteVisit::query()->count()];

    $second = app(V21Importer::class)->run('legacy');

    expect([Contact::query()->count(), Lead::query()->count(), Activity::query()->count(), SiteVisit::query()->count()])->toBe($counts)
        ->and($second->counts['leads'])->toMatchArray(['source' => 4, 'created' => 0, 'skipped' => 4])
        ->and($second->counts['bookings']['skipped'])->toBe(2);
});

it('runs selected steps from the command and writes the report', function () {
    $exit = Artisan::call('woodex:import-v21', ['--only' => ['users,clients']]);

    expect($exit)->toBe(0)
        ->and(User::query()->where('email', 'sara@woodex.com.pk')->exists())->toBeTrue()
        ->and(Contact::query()->count())->toBe(1)
        ->and(Lead::query()->count())->toBe(0)
        ->and(Artisan::output())->toContain('Import finished');

    $directory = collect(Storage::disk('local')->directories('imports'))->sole();
    $report = json_decode(Storage::disk('local')->get("{$directory}/report.json"), true);

    expect($report['counts'])->toHaveKeys(['users', 'clients'])
        ->and($report['counts'])->not->toHaveKey('leads')
        ->and(Storage::disk('local')->get("{$directory}/warnings.csv"))->toContain('not-an-email');
});

it('rejects unknown steps', function () {
    expect(Artisan::call('woodex:import-v21', ['--only' => ['quotes']]))->toBe(2);
});
