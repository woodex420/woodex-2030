<?php

declare(strict_types=1);

namespace App\Domain\System\Import;

use App\Domain\Crm\Actions\ScoreLead;
use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Enums\ClientType;
use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Enums\StageType;
use App\Domain\Crm\Enums\VisitStatus;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\PipelineStage;
use App\Domain\Crm\Models\SiteVisit;
use App\Domain\Crm\Models\Tag;
use App\Domain\System\Models\LegacyRef;
use App\Models\User;
use App\Settings\SalesSettings;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

/**
 * Imports Woodex v2.1 (marketingwoodex) CRM data: users, clients, leads, notes and bookings
 * (docs/04-DATABASE-SCHEMA.md §8). Idempotent through legacy_refs: re-running skips rows already
 * imported. Quotations, invoices and projects follow in P2/P3/P8 with their modules.
 *
 * No alerts are sent and no audit entries are written for imported rows.
 */
final class V21Importer
{
    public const STEPS = ['users', 'clients', 'leads', 'notes', 'bookings'];

    private const TABLES = [
        'users' => 'wx_users',
        'clients' => 'wx_clients',
        'leads' => 'wx_leads',
        'notes' => 'wx_lead_notes',
        'bookings' => 'wx_bookings',
    ];

    private ImportReport $report;

    private ConnectionInterface $legacy;

    /** @var array<string, array<string, int>> legacy table => legacy id => model id */
    private array $refs = [];

    /** @var array<string, int> lower-cased v2.1 user name => user id */
    private array $usersByName = [];

    /** @var array<string, PipelineStage> */
    private array $stages = [];

    public function __construct(
        private readonly SalesSettings $sales,
        private readonly ScoreLead $score,
    ) {}

    /**
     * @param  list<string>  $only  subset of STEPS; empty = all
     * @param  (Closure(string, int): void)|null  $progress  called with (step, rows processed)
     */
    public function run(string $connection = 'legacy', array $only = [], bool $dryRun = false, ?Closure $progress = null): ImportReport
    {
        $this->report = new ImportReport($dryRun);
        $this->legacy = DB::connection($connection);
        $this->stages = PipelineStage::query()->get()->keyBy('key')->all();
        $this->refs = [];
        $this->usersByName = User::query()->pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [Str::lower((string) $name) => (int) $id])->all();

        $steps = $only === [] ? self::STEPS : array_values(array_intersect(self::STEPS, $only));

        DB::beginTransaction();

        try {
            activity()->withoutLogging(function () use ($steps, $progress, $connection): void {
                foreach ($steps as $step) {
                    $table = self::TABLES[$step];

                    if (! Schema::connection($connection)->hasTable($table)) {
                        $this->report->warn($step, '-', "Table {$table} not found in the v2.1 database; step skipped.");

                        continue;
                    }

                    $this->report->count($step, 'source', 0);
                    $processed = 0;

                    foreach ($this->legacy->table($table)->orderBy('id')->lazyById(500) as $row) {
                        /** @var stdClass $row */
                        $this->report->count($step, 'source');

                        try {
                            match ($step) {
                                'users' => $this->importUser($row),
                                'clients' => $this->importClient($row),
                                'leads' => $this->importLead($row),
                                'notes' => $this->importNote($row),
                                'bookings' => $this->importBooking($row),
                            };
                        } catch (Throwable $exception) {
                            $this->report->count($step, 'failed');
                            $this->report->warn($step, (string) ($row->id ?? '?'), 'Failed: '.Str::limit($exception->getMessage(), 300));
                        }

                        if ($progress !== null && ++$processed % 100 === 0) {
                            $progress($step, $processed);
                        }
                    }
                }
            });

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $this->report->finish();

        return $this->report;
    }

    // ---------------------------------------------------------------- users

    private function importUser(stdClass $row): void
    {
        $legacyId = (string) $row->id;

        if (($existing = $this->ref('wx_users', $legacyId)) !== null) {
            $this->rememberUserName((string) ($row->name ?? ''), $existing);
            $this->report->count('users', 'skipped');

            return;
        }

        $email = Str::lower(trim((string) ($row->email ?? '')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->report->count('users', 'failed');
            $this->report->warn('users', $legacyId, "Invalid email \"{$email}\"; user not imported.");

            return;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            $this->report->count('users', 'merged');
            $this->report->duplicate('users', $legacyId, $user->id, 'email');
        } else {
            $user = new User([
                'name' => Str::limit(trim((string) $row->name) ?: Str::before($email, '@'), 120, ''),
                'email' => $email,
                'password' => Str::password(40),
                'is_active' => (bool) ($row->active ?? true),
            ]);
            $user->created_at = $this->date($row->created_at ?? null) ?? now();
            $user->save();

            $role = V21Map::role($row->role ?? null);
            $user->syncRoles([$role]);

            if ($role !== ($row->role ?? null)) {
                $this->report->warn('users', $legacyId, "Unknown role \"{$row->role}\"; given the support role.");
            }

            $this->report->count('users', 'created');
        }

        $this->remember('wx_users', $legacyId, 'user', $user->id, $row);
        $this->rememberUserName((string) ($row->name ?? ''), $user->id);
    }

    // -------------------------------------------------------------- clients

    private function importClient(stdClass $row): void
    {
        $legacyId = (string) $row->id;

        if ($this->ref('wx_clients', $legacyId) !== null) {
            $this->report->count('clients', 'skipped');

            return;
        }

        [$contact, $matchedBy] = $this->findOrNewContact('clients', $legacyId, $row->name ?? null, $row->phone ?? null, $row->email ?? null);
        $isNew = ! $contact->exists;

        $contact->city ??= $this->clip($row->city ?? null, 80);
        $contact->address ??= $this->clip($row->address ?? null, 255);
        if ($isNew) {
            $contact->client_type = ClientType::from(V21Map::clientType($row->type ?? null));
        }
        $contact->is_client = true;
        $contact->became_client_at ??= $this->date($row->created_at ?? null) ?? now();
        $contact->source ??= $this->clip($row->source ?? null, 40);
        $contact->notes = $this->appendText($contact->notes, $row->notes ?? null);
        $contact->meta = array_filter(array_merge($contact->meta ?? [], [
            'designation' => $this->clip($row->designation ?? null, 80),
            'legacy_line' => $row->line ?? null,
            'legacy_client_id' => (int) $row->id,
        ]), fn ($v) => $v !== null && $v !== '');

        if (filled($row->company ?? null) && $contact->company_id === null) {
            $contact->company_id = $this->company((string) $row->company, $contact->client_type ?? ClientType::Individual)->id;
        }

        if ($isNew) {
            $contact->created_at = $this->date($row->created_at ?? null) ?? now();
            $contact->last_activity_at = $this->date($row->updated_at ?? null) ?? $contact->created_at;
        }

        $contact->save();
        $this->attachTags($contact, $row->tags ?? null);

        $this->report->count('clients', $isNew ? 'created' : 'merged');

        if (! $isNew) {
            $this->report->duplicate('clients', $legacyId, $contact->id, $matchedBy);
        }

        $this->remember('wx_clients', $legacyId, 'contact', $contact->id, $row);
    }

    // ---------------------------------------------------------------- leads

    private function importLead(stdClass $row): void
    {
        $legacyId = (string) $row->id;

        if ($this->ref('wx_leads', $legacyId) !== null) {
            $this->report->count('leads', 'skipped');

            return;
        }

        $createdAt = $this->date($row->created_at ?? null) ?? now();
        $contact = null;

        if (filled($row->client_id ?? null) && ($contactId = $this->ref('wx_clients', (string) $row->client_id)) !== null) {
            $contact = Contact::query()->find($contactId);
        }

        if ($contact === null) {
            [$contact, $matchedBy] = $this->findOrNewContact('leads', $legacyId, $row->name ?? null, $row->phone ?? null, $row->email ?? null);

            if (! $contact->exists) {
                $contact->created_at = $createdAt;
                $contact->city = V21Map::cityFrom($row->location ?? null);
                $contact->source = $this->clip($row->source ?? null, 40);
            }
        }

        if (filled($row->company ?? null) && $contact->company_id === null) {
            $contact->company_id = $this->company((string) $row->company, ClientType::Company)->id;

            if (($contact->client_type ?? ClientType::Individual) === ClientType::Individual) {
                $contact->client_type = ClientType::Company;
            }
        }

        if (filled($row->designation ?? null)) {
            $contact->meta = array_merge($contact->meta ?? [], ['designation' => $this->clip($row->designation, 80)]);
        }

        $contact->save();

        $stageKey = V21Map::stage($row->stage ?? null);

        if ($stageKey === null) {
            $this->report->warn('leads', $legacyId, "Unknown stage \"{$row->stage}\"; imported as New.");
            $stageKey = 'new';
        }

        $stage = $this->stages[$stageKey] ?? $this->stages['new'];
        [$source, $sourceDetail] = V21Map::source($row->source ?? null);

        $bands = $this->sales->budget_bands;
        $budgetBand = V21Map::budgetBand($row->budget ?? null, $bands);
        $areaSqft = V21Map::areaToSqft($row->area ?? null);
        $lastContact = $this->date($row->last_contact ?? null);
        $isOpen = $stage->type === StageType::Open;

        $nextAt = $this->date($row->next_at ?? null);
        if ($nextAt === null && filled($row->followup ?? null) && ($day = $this->date($row->followup)) !== null) {
            $nextAt = $day->setTime(11, 0);
        }

        $service = trim((string) (($row->project_type ?? null) ?: ($row->service ?? '')));
        $fields = $this->decodeJson($row->fields ?? null);

        $lead = new Lead([
            'ref' => 'V21-'.str_pad($legacyId, 5, '0', STR_PAD_LEFT),
            'contact_id' => $contact->id,
            'company_id' => $contact->company_id,
            'business_line' => V21Map::line($row->line ?? null),
            'service' => $service !== '' ? Str::limit($service, 60, '') : null,
            'city' => V21Map::cityFrom($row->location ?? null) ?? $contact->city,
            'area' => $this->clip($row->location ?? null, 120),
            'area_sqft' => $areaSqft,
            'budget_band' => $budgetBand,
            'lead_type' => V21Map::leadType($row->lead_type ?? null),
            'priority' => V21Map::priority($row->priority ?? null),
            'stage_id' => $stage->id,
            'stage_changed_at' => $lastContact ?? $createdAt,
            'owner_id' => filled($row->assigned_to ?? null) ? $this->ref('wx_users', (string) $row->assigned_to) : null,
            'expected_value_minor' => (int) ($row->value ?? 0) > 0 ? (int) $row->value * 100 : null,
            'next_action_type' => $isOpen ? V21Map::nextAction($row->next_type ?? null) ?? ($nextAt !== null ? 'call' : null) : null,
            'next_action_at' => $isOpen ? $nextAt : null,
            // Imported leads never start an SLA timer; anything past "new" was contacted in v2.1.
            'first_response_at' => $lastContact ?? ($stageKey !== 'new' ? $createdAt : null),
            'won_at' => $stage->type === StageType::Won ? ($lastContact ?? $createdAt) : null,
            'lost_at' => $stage->type === StageType::Lost ? ($lastContact ?? $createdAt) : null,
            'lost_reason' => $stage->type === StageType::Lost ? $this->clip($row->lost_reason ?? null, 60) : null,
            'lost_note' => $stage->type === StageType::Lost && mb_strlen((string) ($row->lost_reason ?? '')) > 60 ? $row->lost_reason : null,
            'source' => $source,
            'source_detail' => $sourceDetail,
            'message' => filled($row->message ?? null) ? (string) $row->message : null,
            'attribution' => filled($row->page ?? null) ? ['first_touch' => ['landing_page' => (string) $row->page], 'last_touch' => ['landing_page' => (string) $row->page]] : null,
            'meta' => array_filter([
                'legacy' => array_filter([
                    'id' => (int) $row->id,
                    'source' => $row->source ?? null,
                    'stage' => $row->stage ?? null,
                    'budget' => $budgetBand === null && filled($row->budget ?? null) ? (string) $row->budget : null,
                    'area' => filled($row->area ?? null) ? (string) $row->area : null,
                    'service' => mb_strlen($service) > 60 ? $service : null,
                    'form_service' => filled($row->project_type ?? null) && filled($row->service ?? null) ? (string) $row->service : null,
                    'quote_status' => $row->quote_status ?? null,
                    'fields' => $fields,
                ], fn ($v) => $v !== null && $v !== '' && $v !== []),
            ]),
            'last_activity_at' => $lastContact ?? $createdAt,
        ]);

        if ($row->budget ?? null) {
            if ($budgetBand === null) {
                $this->report->warn('leads', $legacyId, "Budget \"{$row->budget}\" did not match a band; kept in the lead details.");
            }
        }

        if (filled($row->area ?? null) && $areaSqft === null) {
            $this->report->warn('leads', $legacyId, "Area \"{$row->area}\" is not a size; kept in the lead details.");
        }

        if (filled($row->assigned_to ?? null) && $lead->owner_id === null) {
            $this->report->warn('leads', $legacyId, "Assigned user #{$row->assigned_to} was not imported; lead left unassigned.");
        }

        $lead->score = $this->score->score($lead);
        $lead->created_at = $createdAt;
        $lead->updated_at = $lastContact ?? $createdAt;
        $lead->save();

        $this->attachTags($lead, $row->tags ?? null);

        Activity::create([
            'contact_id' => $contact->id,
            'lead_id' => $lead->id,
            'type' => ActivityType::System,
            'body' => "Imported from Woodex v2.1 (enquiry #{$legacyId}, {$source->label()}).",
            'data' => ['legacy_lead_id' => (int) $row->id],
            'occurred_at' => $createdAt,
        ]);

        if ($contact->last_activity_at === null || $contact->last_activity_at->lt($lead->last_activity_at ?? $createdAt)) {
            $contact->forceFill(['last_activity_at' => $lead->last_activity_at ?? $createdAt])->save();
        }

        $this->report->count('leads', 'created');
        $this->remember('wx_leads', $legacyId, 'lead', $lead->id, $row);
    }

    // ---------------------------------------------------------------- notes

    private function importNote(stdClass $row): void
    {
        $legacyId = (string) $row->id;

        if ($this->ref('wx_lead_notes', $legacyId) !== null) {
            $this->report->count('notes', 'skipped');

            return;
        }

        $leadId = $this->ref('wx_leads', (string) ($row->lead_id ?? ''));
        $lead = $leadId !== null ? Lead::query()->find($leadId) : null;

        if ($lead === null) {
            $this->report->count('notes', 'skipped');
            $this->report->warn('notes', $legacyId, "Lead #{$row->lead_id} was not imported; note skipped.");

            return;
        }

        $type = V21Map::noteType($row->kind ?? null, (bool) ($row->sys ?? false));
        $outcome = $type === ActivityType::Call ? V21Map::callOutcome($row->outcome ?? null) : null;
        $userName = trim((string) ($row->user_name ?? ''));
        $userId = $userName !== '' ? ($this->usersByName[Str::lower($userName)] ?? null) : null;

        $body = trim((string) ($row->text ?? ''));
        if (filled($row->outcome ?? null) && $outcome === null) {
            $body = 'Outcome: '.trim((string) $row->outcome).($body !== '' ? "\n".$body : '');
        }

        $activity = Activity::create([
            'contact_id' => $lead->contact_id,
            'lead_id' => $lead->id,
            'user_id' => $type === ActivityType::System ? null : $userId,
            'type' => $type,
            'direction' => in_array($type, [ActivityType::Call, ActivityType::WhatsApp, ActivityType::Email], true) ? 'out' : null,
            'outcome' => $outcome?->value,
            'body' => $body !== '' ? $body : null,
            'data' => array_filter([
                'legacy_note_id' => (int) $row->id,
                'legacy_user' => $userId === null && $userName !== '' ? $userName : null,
            ]),
            'occurred_at' => $this->date($row->t ?? null) ?? $lead->created_at ?? now(),
        ]);

        $this->report->count('notes', 'created');
        $this->remember('wx_lead_notes', $legacyId, 'activity', $activity->id, $row);
    }

    // ------------------------------------------------------------- bookings

    private function importBooking(stdClass $row): void
    {
        $legacyId = (string) $row->id;

        if ($this->ref('wx_bookings', $legacyId) !== null) {
            $this->report->count('bookings', 'skipped');

            return;
        }

        $scheduledAt = $this->bookingTime((string) ($row->d ?? ''), (string) ($row->tm ?? ''));

        if ($scheduledAt === null) {
            $this->report->count('bookings', 'failed');
            $this->report->warn('bookings', $legacyId, "Invalid date/time \"{$row->d} {$row->tm}\"; booking skipped.");

            return;
        }

        $lead = null;

        if (filled($row->lead_id ?? null) && ($leadId = $this->ref('wx_leads', (string) $row->lead_id)) !== null) {
            $lead = Lead::query()->find($leadId);
        }

        if ($lead === null) {
            [$contact] = $this->findOrNewContact('bookings', $legacyId, $row->name ?? null, $row->phone ?? null, $row->email ?? null);

            if (! $contact->exists) {
                $contact->city = $this->clip($row->city ?? null, 80);
                $contact->address = $this->clip($row->address ?? null, 255);
                $contact->created_at = $this->date($row->created_at ?? null) ?? now();
                $contact->save();
            }

            $lead = $contact->leads()->latest('id')->first() ?? $this->bookingLead($contact, $row);
        }

        $status = V21Map::visitStatus($row->status ?? null);
        $type = Str::lower((string) ($row->type ?? 'visit'));
        $duration = max(15, min(600, (int) ($row->dur ?? 60)));

        $address = match ($type) {
            'office' => 'Woodex studio (office meeting)',
            'online' => 'Online call',
            default => trim(implode(', ', array_filter([trim((string) ($row->address ?? '')), trim((string) ($row->city ?? ''))]))) ?: null,
        };

        $notes = trim((string) ($row->note ?? ''));

        $visit = new SiteVisit([
            'lead_id' => $lead->id,
            'contact_id' => $lead->contact_id,
            'scheduled_at' => $scheduledAt,
            'duration_minutes' => $duration,
            'address' => $address !== null ? Str::limit($address, 255, '') : null,
            'assigned_to' => filled($row->staff_id ?? null) ? $this->ref('wx_users', (string) $row->staff_id) : null,
            'status' => $status,
            'client_confirmed_at' => in_array($status, [VisitStatus::Confirmed, VisitStatus::Completed], true) ? ($this->date($row->created_at ?? null) ?? $scheduledAt) : null,
            'reminders' => (int) ($row->reminded ?? 0) > 0 ? ['day_before' => true] : null,
            'outcome_notes' => $notes !== '' ? ($type !== 'visit' ? Str::headline($type).': ' : '').$notes : null,
            'completed_at' => $status === VisitStatus::Completed ? $scheduledAt->addMinutes($duration) : null,
        ]);
        $visit->created_at = $this->date($row->created_at ?? null) ?? now();
        $visit->save();

        $this->report->count('bookings', 'created');
        $this->remember('wx_bookings', $legacyId, 'site_visit', $visit->id, $row);
    }

    private function bookingLead(Contact $contact, stdClass $row): Lead
    {
        $createdAt = $this->date($row->created_at ?? null) ?? now();
        $status = V21Map::visitStatus($row->status ?? null);
        $stageKey = $status === VisitStatus::Completed ? 'quotation' : 'site_visit';

        $lead = new Lead([
            'ref' => 'V21-B'.str_pad((string) $row->id, 5, '0', STR_PAD_LEFT),
            'contact_id' => $contact->id,
            'business_line' => 'interior',
            'city' => $this->clip($row->city ?? null, 80),
            'stage_id' => ($this->stages[$stageKey] ?? $this->stages['new'])->id,
            'stage_changed_at' => $createdAt,
            'source' => LeadSource::Booking,
            'source_detail' => 'v2.1 booking without an enquiry',
            'first_response_at' => $createdAt,
            'last_activity_at' => $createdAt,
            'meta' => ['legacy' => ['booking_id' => (int) $row->id]],
        ]);
        $lead->score = $this->score->score($lead);
        $lead->created_at = $createdAt;
        $lead->save();

        $this->report->warn('bookings', (string) $row->id, "Booking had no enquiry; created lead {$lead->ref}.");

        return $lead;
    }

    // -------------------------------------------------------------- helpers

    /**
     * Find a contact by phone, then email; otherwise a new unsaved one.
     *
     * @return array{0: Contact, 1: string}
     */
    private function findOrNewContact(string $step, string $legacyId, ?string $name, ?string $phone, ?string $email): array
    {
        $normalizedPhone = Phone::normalize($phone);
        $email = Str::lower(trim((string) $email));
        $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;

        if (filled($phone) && $normalizedPhone === null) {
            $this->report->warn($step, $legacyId, "Phone \"{$phone}\" is not a valid number; kept in notes.");
        }

        if ($normalizedPhone !== null && ($contact = Contact::query()->wherePhone($normalizedPhone)->first()) !== null) {
            return [$contact, 'phone'];
        }

        if ($email !== null && ($contact = Contact::query()->where('email', $email)->first()) !== null) {
            return [$contact, 'email'];
        }

        $contact = new Contact([
            'name' => Str::limit(trim((string) $name) ?: 'Unknown', 160, ''),
            'phone' => $normalizedPhone,
            'whatsapp' => $normalizedPhone,
            'email' => $email,
            'client_type' => ClientType::Individual,
            'notes' => filled($phone) && $normalizedPhone === null ? 'Phone from v2.1: '.trim((string) $phone) : null,
        ]);

        if ($normalizedPhone === null && $email === null) {
            $this->report->warn($step, $legacyId, 'No usable phone or email; contact created with the name only.');
        }

        return [$contact, 'new'];
    }

    private function company(string $name, ClientType $type): Company
    {
        $name = Str::limit(trim($name), 160, '');

        return Company::query()->whereRaw('lower(name) = ?', [Str::lower($name)])->first()
            ?? Company::create(['name' => $name, 'type' => $type === ClientType::Individual ? ClientType::Company->value : $type->value]);
    }

    private function attachTags(Contact|Lead $model, ?string $tags): void
    {
        $names = collect(explode(',', (string) $tags))->map(fn ($t) => trim($t))->filter()->unique();

        if ($names->isEmpty()) {
            return;
        }

        $ids = $names->map(function (string $name): int {
            $slug = Str::slug($name) ?: Str::lower($name);

            return Tag::query()->firstOrCreate(['slug' => Str::limit($slug, 60, '')], ['name' => Str::limit($name, 60, '')])->id;
        });

        $model->tags()->syncWithoutDetaching($ids->all());
    }

    private function ref(string $table, string $legacyId): ?int
    {
        if (isset($this->refs[$table][$legacyId])) {
            return $this->refs[$table][$legacyId];
        }

        $id = LegacyRef::query()->where('legacy_table', $table)->where('legacy_id', $legacyId)->value('model_id');

        if ($id !== null) {
            $this->refs[$table][$legacyId] = (int) $id;
        }

        return $id !== null ? (int) $id : null;
    }

    private function remember(string $table, string $legacyId, string $modelType, int $modelId, stdClass $row): void
    {
        LegacyRef::create([
            'legacy_table' => $table,
            'legacy_id' => $legacyId,
            'model_type' => $modelType,
            'model_id' => $modelId,
            'checksum' => hash('sha256', (string) json_encode($row)),
            'imported_at' => now(),
        ]);

        $this->refs[$table][$legacyId] = $modelId;
    }

    private function rememberUserName(string $name, int $userId): void
    {
        if (trim($name) !== '') {
            $this->usersByName[Str::lower(trim($name))] = $userId;
        }
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        $value = trim((string) $value);

        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    private function bookingTime(string $date, string $time): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($date)) || ! preg_match('/^\d{1,2}:\d{2}$/', trim($time))) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d H:i', trim($date).' '.trim($time), 'Asia/Karachi')
                ?->setTimezone(config('app.timezone')) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function clip(mixed $value, int $length): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $length, '');
    }

    private function appendText(?string $existing, mixed $addition): ?string
    {
        $addition = trim((string) $addition);

        if ($addition === '') {
            return $existing;
        }

        return filled($existing) && ! str_contains((string) $existing, $addition) ? $existing."\n\n".$addition : ($existing ?: $addition);
    }

    /**
     * @return array<mixed>|string|null
     */
    private function decodeJson(mixed $value): array|string|null
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : Str::limit($value, 2000);
    }
}
