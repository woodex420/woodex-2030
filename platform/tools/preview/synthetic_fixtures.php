<?php

declare(strict_types=1);

use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\PipelineStage;
use App\Domain\Sales\Models\CatalogItem;
use App\Models\User;
use App\Settings\BusinessSettings;
use App\Settings\SalesSettings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Clearly labelled synthetic fixtures for a disposable preview database.
 *
 *   php tools/preview/synthetic_fixtures.php staff     staff accounts and a placeholder business identity
 *   php tools/preview/synthetic_fixtures.php data      SYNTHETIC contacts, leads and catalogue lines
 *
 * It refuses to run unless the application is local, on SQLite, on a file named woodex-synthetic-*, with
 * non-sending mail, and with no staff account outside the reserved @woodex.test domain.
 * Passwords come from the environment (WX_PW_OWNER, WX_PW_MANAGER, WX_PW_SALES, WX_PW_ACCOUNTS) and are never stored
 * in the repository. Nothing here is real business data, and none of it may be copied into a real database.
 */
$base = dirname(__DIR__, 2);
$mode = $argv[1] ?? null;
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$db = (string) config('database.connections.sqlite.database');
$guards = [
    'APP_ENV is local' => app()->environment('local'),
    'database is sqlite' => config('database.default') === 'sqlite',
    'database file is woodex-synthetic-*' => str_starts_with(basename((string) realpath($db)), 'woodex-synthetic-'),
    'mail does not send' => in_array(config('mail.default'), ['log', 'array'], true),
    'no staff outside @woodex.test' => ! User::query()->where('email', 'not like', '%@woodex.test')->exists(),
];
foreach ($guards as $what => $ok) {
    if (! $ok) {
        fwrite(STDERR, "REFUSED: {$what} is false\n");
        exit(64);
    }
}

if ($mode === 'staff') {
    $people = [
        ['owner', 'SYNTHETIC Owner', 'owner@woodex.test', 'WX_PW_OWNER'],
        ['sales_manager', 'SYNTHETIC Sales Manager', 'manager@woodex.test', 'WX_PW_MANAGER'],
        ['sales', 'SYNTHETIC Sales', 'sales@woodex.test', 'WX_PW_SALES'],
        ['accountant', 'SYNTHETIC Accountant', 'accounts@woodex.test', 'WX_PW_ACCOUNTS'],
    ];
    DB::transaction(function () use ($people): void {
        foreach ($people as [$role, $name, $email, $env]) {
            $password = (string) getenv($env);
            if (strlen($password) < 16) {
                throw new RuntimeException("{$env} must hold a generated password");
            }
            $user = User::query()->updateOrCreate(['email' => $email], ['name' => $name, 'password' => Hash::make($password), 'is_active' => true]);
            $user->syncRoles([$role]);
        }
    });

    // Neutral identity: the settings migrations wrote the Interior studio's name, address, phone and e-mail.
    $business = app(BusinessSettings::class);
    $business->legal_name = 'Woodex Furniture — synthetic preview';
    $business->brand_name = 'Woodex Furniture — synthetic preview';
    $business->furniture_brand_name = 'Woodex Furniture';
    $business->tagline = 'Synthetic preview. Not a business record.';
    $business->address = 'Synthetic preview address — not real';
    $business->phone = '+920000000000';
    $business->whatsapp = '+920000000000';
    $business->email = 'preview@woodex.test';
    $business->website = 'https://preview.woodex.test';
    $business->save();

    // Interior payment terms must not appear on Furniture documents; no Furniture terms have been supplied.
    // What stands here is a sample and says so on every document that carries it (the owner's word of
    // 17 November 2026, decision DL-026: the demo may issue documents with sample terms and a sample signer).
    $sales = app(SalesSettings::class);
    $placeholder = 'SAMPLE TERMS — for this demo only. Woodex Furniture has not yet supplied its commercial terms.';
    $sales->execution_terms = $placeholder;
    $sales->design_terms = $placeholder;
    $sales->invoice_terms = $placeholder;
    $sales->signatories = [['name' => 'Sample Signer', 'title' => 'Sample only — not a real signatory']];
    $sales->save();

    echo json_encode(['staff' => User::query()->with('roles:name')->get()->map(fn (User $u) => [$u->email, $u->roles->pluck('name')->all()])->all()], JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

if ($mode === 'data') {
    $stages = PipelineStage::query()->pluck('id', 'key');
    $owner = User::query()->where('email', 'sales@woodex.test')->firstOrFail();
    $plan = ['new' => 3, 'contacted' => 2, 'quotation' => 2, 'negotiation' => 1, 'won' => 1, 'lost' => 1];
    $n = 0;
    DB::transaction(function () use ($plan, $stages, $owner, &$n): void {
        // Written out in full, not through the model factories: those need fakerphp/faker, a development package
        // that a production build (the Vercel demo, "composer install --no-dev") does not have. Every value is
        // fixed, so two builds give the same records.
        foreach ($plan as $stage => $count) {
            for ($i = 0; $i < $count; $i++) {
                $n++;
                $created = now()->subHours(6 + $n * 31);
                $contact = Contact::query()->create([
                    'name' => sprintf('SYNTHETIC Contact %02d', $n),
                    'phone' => sprintf('+9230000000%02d', $n),
                    'whatsapp' => sprintf('+9230000000%02d', $n),
                    'email' => sprintf('contact%02d@woodex.test', $n),
                    'city' => 'Lahore',
                    'area' => 'Synthetic area',
                    'client_type' => 'individual',
                    'preferred_language' => 'en',
                    'source' => 'website_form',
                    'last_activity_at' => $created,
                ]);
                Lead::query()->create([
                    'ref' => sprintf('L-%s-SYN%03d', now()->format('Y'), $n),
                    'contact_id' => $contact->id,
                    'business_line' => 'furniture',
                    'service' => 'furniture',
                    'property_type' => 'office',
                    'city' => 'Lahore',
                    'area' => 'Synthetic area',
                    'area_sqft' => [900, 1350, 2250, 4500, 10000][$n % 5],
                    'lead_type' => 'new',
                    'priority' => ['normal', 'high', 'low', 'normal'][$n % 4],
                    'stage_id' => $stages[$stage],
                    'stage_changed_at' => $created,
                    'score' => 30 + $n * 6,
                    'expected_value_minor' => [800_000, 1_500_000, 3_500_000, 7_500_000][$n % 4] * 100,
                    'next_action_type' => ['call', 'whatsapp', 'visit'][$n % 3],
                    'next_action_at' => now()->addHours($n * 14 - 48),
                    'sla_due_at' => $created->copy()->addMinutes(15),
                    'first_response_at' => $n % 5 === 0 ? null : $created->copy()->addMinutes(5 + $n * 3),
                    'source' => 'website_form',
                    'message' => sprintf('SYNTHETIC enquiry %02d — office furniture for a fictional workspace.', $n),
                    'owner_id' => $owner->id,
                    'won_at' => $stage === 'won' ? now()->subDays(2) : null,
                    'lost_at' => $stage === 'lost' ? now()->subDays(2) : null,
                    'lost_reason' => $stage === 'lost' ? 'Other' : null,
                    'created_at' => $created,
                    'updated_at' => $created,
                ]);
            }
        }
        $lines = [
            ['Desks and tables', 'SYNTHETIC — executive desk, 1800 mm', 'each', 10_000_000, 7_000_000],
            ['Seating', 'SYNTHETIC — task chair', 'each', 2_500_000, 1_600_000],
            ['Seating', 'SYNTHETIC — visitor chair', 'each', 1_200_000, 800_000],
            ['Storage', 'SYNTHETIC — storage cabinet', 'each', 4_000_000, 2_900_000],
            ['Delivery and assembly', 'SYNTHETIC — delivery and assembly', 'job', 500_000, 300_000],
        ];
        foreach ($lines as $position => [$category, $description, $unit, $rate, $cost]) {
            CatalogItem::query()->forceCreate([
                'category' => $category, 'description' => $description, 'unit' => $unit, 'rate_minor' => $rate,
                'internal_cost_minor' => $cost, 'business_line' => 'furniture', 'is_active' => true, 'position' => $position,
            ]);
        }
    });
    echo json_encode(['contacts' => Contact::query()->count(), 'leads' => Lead::query()->count(), 'catalog_items' => CatalogItem::query()->count()]), "\n";
    exit(0);
}

fwrite(STDERR, "usage: php tools/preview/synthetic_fixtures.php staff|data\n");
exit(64);
