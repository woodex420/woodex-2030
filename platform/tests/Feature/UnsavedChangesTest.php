<?php

use App\Domain\Crm\Models\Lead;
use App\Livewire\Admin\Commerce\ShopEditor;
use App\Livewire\Admin\Crm\LeadShow;
use App\Livewire\Admin\Crm\LeadsIndex;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;

/*
| T-043: what is typed is not lost without a question.
|
| The asking is done in the browser (resources/js/admin/unsaved.js) and is proved there, by the journeys
| (scripts/os-journeys.mjs). What is proved here is the part a browser test cannot see whole: that every form and
| every field of the admin has been looked at. A part of a screen that holds work is marked data-unsaved; whatever
| is not in such a part is on a written list below, with the reason. A new form or a new field that is neither
| fails here, and somebody has to decide.
|
| Not in the admin, and so not read: the sign-in and installation pages (auth/, install/), the client's own pages
| (site/), documents and mail.
*/

/** The views of the admin. */
const UNSAVED_VIEWS = ['livewire/admin/', 'components/admin/', 'admin/'];

/** Forms that are not work, by view and by what they send. */
const UNSAVED_FORMS_OFF = [
    'livewire/admin/assistant-drawer' => ['askDraft' => 'a question to the assistant; the answer is the result'],
    'livewire/admin/profile/security' => ['confirmTwoFactor' => 'a six-digit code that is used once'],
    'livewire/admin/website/find-replace-index' => ['runPreview' => 'a search that shows what it would change; the change is a later, confirmed step'],
];

/** Views whose forms are the browser's own (a whole page is sent): buttons, no text. */
const UNSAVED_PLAIN_FORMS = [
    'admin/system/updates' => 'one button that starts an update',
    'admin/website/studio' => 'the website studio is a program of its own in the page, outside the admin layout; switched off with the website tools',
    'components/admin/header' => 'the sign-out button',
    'livewire/admin/profile/google-sign-in' => 'buttons that link or unlink a Google account',
    'livewire/admin/system/google-setup' => 'buttons that check, connect or disconnect Google',
];

/**
 * Fields that are not work, by the reason and by view. Each is outside every watched part, or carries
 * data-unsaved-skip inside one.
 */
const UNSAVED_FIELDS_EXEMPT = [
    'a filter, a search, or ticks that choose rows: sent the moment it changes; leaving loses nothing' => [
        'livewire/admin/accounts/bills-index' => ['projectFilter', 'statusFilter', 'vendorFilter'],
        'livewire/admin/accounts/expenses-index' => ['filterFrom', 'filterTo'],
        'livewire/admin/accounts/profit-loss' => ['from', 'to'],
        'livewire/admin/accounts/trial-balance' => ['from', 'to', 'showEmpty'],
        'livewire/admin/commerce/categories' => ['search'],
        'livewire/admin/commerce/product-media-picker' => ['mediaSearch', 'mediaFolder', 'librarySelection'],
        'livewire/admin/commerce/products' => ['search', 'status', 'categoryFilter', 'seriesFilter', 'selected'],
        'livewire/admin/commerce/series-editor' => ['heroSearch'],
        'livewire/admin/conversations/inbox' => ['channel', 'assignment', 'search'],
        'livewire/admin/crm/companies-index' => ['search', 'type', 'sort'],
        'livewire/admin/crm/company-show' => ['contactSearch'],
        'livewire/admin/crm/contact-book' => ['search'],
        'livewire/admin/crm/contacts-index' => ['search', 'type', 'tag'],
        'livewire/admin/crm/lead-show' => ['activityType'],
        'livewire/admin/crm/leads-index' => ['search', 'stage', 'month', 'source', 'owner', 'sort', 'perPage', 'selected'],
        'livewire/admin/crm/pipeline-board' => ['line', 'owner'],
        'livewire/admin/crm/web-chat-leads' => ['search', 'focus', 'status', 'from', 'to'],
        'livewire/admin/dashboard' => ['preset', 'line'],
        'livewire/admin/global-search' => ['term'],
        'livewire/admin/marketing/attribution-index' => ['range'],
        'livewire/admin/notifications-page' => ['search'],
        'livewire/admin/projects/project-board' => ['line', 'owner'],
        'livewire/admin/projects/project-costs' => ['selectedProjectId'],
        'livewire/admin/projects/projects-index' => ['search', 'stage', 'owner'],
        'livewire/admin/reports/revenue-collections' => ['line'],
        'livewire/admin/sales/approvals' => ['search', 'line'],
        'livewire/admin/sales/description-library' => ['search', 'line', 'section'],
        'livewire/admin/sales/document-designer' => ['line'],
        'livewire/admin/sales/estimations/enquiries' => ['search'],
        'livewire/admin/sales/estimations/estimates' => ['search', 'service', 'status'],
        'livewire/admin/sales/invoice-create' => ['contactSearch'],
        'livewire/admin/sales/invoice-tracking-board' => ['year', 'line'],
        'livewire/admin/sales/invoices-index' => ['search'],
        'livewire/admin/sales/payments-index' => ['search'],
        'livewire/admin/sales/proposals-index' => ['search'],
        'livewire/admin/sales/quote-builder' => ['productSearch', 'catalogSearch', 'catalogCategory', 'descriptionSearch'],
        'livewire/admin/sales/quote-templates' => ['kind', 'showInactive'],
        'livewire/admin/sales/quotes-index' => ['search', 'line', 'owner', 'sort'],
        'livewire/admin/sales/rate-book' => ['search', 'category', 'state'],
        'livewire/admin/sales/recurring-invoices' => ['search'],
        'livewire/admin/system/audit-log' => ['causerId', 'subjectType', 'search'],
        'livewire/admin/system/change-requests' => ['search'],
        'livewire/admin/system/integration-log' => ['logIntegration', 'failedOnly'],
        'livewire/admin/system/integrations' => ['appSearch'],
        'livewire/admin/website/media-library' => ['folder', 'search', 'onlyMissingAlt'],
        'livewire/admin/website/pages-index' => ['search', 'section'],
        'livewire/admin/website/redirects-index' => ['search'],
        'livewire/admin/website/seo-overview-index' => ['search'],
    ],
    'stored the moment it is chosen: the screen saves it by itself' => [
        'components/admin/attachments' => ['uploads'],
        'livewire/admin/crm/leads-index' => ['drawerStage', 'drawerOwner'],
        'livewire/admin/projects/project-show' => ['uploads'],
        'livewire/admin/sales/quote-builder' => ['quoteLayout', 'pdfTheme'],
    ],
    'a tick that confirms an action; it says nothing a save would keep' => [
        'livewire/admin/commerce/category-editor' => ['confirmPublish', 'confirmUnpublish'],
        'livewire/admin/commerce/product-editor' => ['confirmPublish', 'confirmWithdraw'],
        'livewire/admin/commerce/product-transfers' => ['confirmCatalogue', 'confirm'],
        'livewire/admin/commerce/products' => ['confirmBulk'],
        'livewire/admin/commerce/series-editor' => ['confirmPublish', 'confirmWithdraw'],
        'livewire/admin/commerce/shop-editor' => ['confirmPublish', 'confirmWithdraw'],
        'livewire/admin/sales/estimate-builder' => ['confirmReprice'],
        'livewire/admin/website/media-library' => ['confirmReplacement', 'publishReplacement'],
    ],
    'what an action is run with (a file, a choice, a number): chosen again in one step, never typed text' => [
        'livewire/admin/commerce/product-editor' => ['replacementUpload'],
        'livewire/admin/commerce/product-transfers' => ['package'],
        'livewire/admin/commerce/products' => ['bulkCategory', 'bulkSeries', 'bulkAvailability'],
        'livewire/admin/conversations/inbox' => ['attachment'],
        'livewire/admin/marketing/social-planner' => ['scheduledAt'],
        'livewire/admin/sales/quote-builder' => ['sendEmail', 'sendWhatsapp'],
        'livewire/admin/system/data-import' => ['steps'],
        'livewire/admin/system/settings-page' => ['whatsapp.probe_number'],
        'livewire/admin/website/media-library' => ['replacementUpload', 'reuseId'],
    ],
    'a password typed to confirm an action; it must never be kept' => [
        'livewire/admin/profile/security' => ['password'],
    ],
];

/**
 * Views that are drawn inside a watched part of another view, so that their fields belong to that part:
 * the view => [how it is drawn, the views that draw it].
 */
const UNSAVED_HOSTED = [
    'components/admin/company-fields' => ['<x-admin.company-fields', ['livewire/admin/crm/companies-index', 'livewire/admin/crm/company-show']],
    'components/admin/content-editor' => ['<x-admin.content-editor', ['livewire/admin/commerce/category-editor', 'livewire/admin/commerce/product-editor']],
    'livewire/admin/commerce/product-category-tree' => ["@include('livewire.admin.commerce.product-category-tree'", ['livewire/admin/commerce/product-editor']],
    'livewire/admin/commerce/product-gallery' => ["@include('livewire.admin.commerce.product-gallery'", ['livewire/admin/commerce/product-editor']],
    'livewire/admin/commerce/product-media-picker' => ["@include('livewire.admin.commerce.product-media-picker'", ['livewire/admin/commerce/product-editor']],
];

/** @return array<string, string> the admin's views: name without the ending => source */
function unsavedViews(): array
{
    $views = [];
    foreach ((new Finder)->files()->in(resource_path('views'))->name('*.blade.php')->sortByName() as $file) {
        $name = str_replace('\\', '/', substr($file->getRelativePathname(), 0, -strlen('.blade.php')));
        foreach (UNSAVED_VIEWS as $folder) {
            if (str_starts_with($name, $folder)) {
                $views[$name] = $file->getContents();
            }
        }
    }

    return $views;
}

/** Where the tag ends that is being read from $from. Quotes are respected: Blade writes ">" inside attributes. */
function unsavedTagEnd(string $source, int $from): int
{
    $quote = null;
    for ($i = $from, $length = strlen($source); $i < $length; $i++) {
        $character = $source[$i];
        if ($quote !== null) {
            $quote = $character === $quote ? null : $quote;
        } elseif ($character === '"' || $character === "'") {
            $quote = $character;
        } elseif ($character === '>') {
            return $i + 1;
        }
    }

    return strlen($source);
}

/** Where the element ends whose opening tag ended at $openEnd: its own closing tag, the same name nested counted. */
function unsavedElementEnd(string $source, string $name, int $openEnd): int
{
    $depth = 1;
    $at = $openEnd;
    while ($depth > 0) {
        if (preg_match('/<(\/?)'.preg_quote($name, '/').'(?=[\s>\/])/', $source, $found, PREG_OFFSET_CAPTURE, $at) !== 1) {
            return strlen($source);
        }
        $at = $found[0][1] + strlen($found[0][0]);
        if ($found[1][0] === '/') {
            $depth--;
        } else {
            $at = unsavedTagEnd($source, $at);
            $depth += substr($source, $at - 2, 2) === '/>' ? 0 : 1;
        }
    }

    return $at;
}

/**
 * The parts of a view that say what they are: watched, or declared not to be work.
 *
 * @return array{watched: list<array{from: int, to: int, saves: list<string>}>, off: list<array{from: int, to: int}>}
 */
function unsavedParts(string $source): array
{
    $parts = ['watched' => [], 'off' => []];
    preg_match_all('/<([\w.:\-]+)(?=[\s>])/', $source, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

    foreach ($tags as $tag) {
        [$name, $from] = [$tag[1][0], $tag[0][1]];
        $openEnd = unsavedTagEnd($source, $from + strlen($tag[0][0]));
        $text = substr($source, $from, $openEnd - $from);

        if ($name === 'x-admin.modal') {
            // The modal marks itself when it is a form (submit="…"), or when it is told what saves it.
            $declared = preg_match('/\sunsaved="([^"]*)"/', $text, $said) === 1 ? $said[1] : null;
            if ($declared === null && preg_match('/\ssubmit="/', $text) !== 1) {
                continue;
            }
        } elseif (preg_match('/\sdata-unsaved(?:="([^"]*)")?(?=[\s>\/])/', $text, $said) === 1) {
            $declared = $said[1] ?? '';
        } else {
            continue;
        }

        $span = ['from' => $from, 'to' => unsavedElementEnd($source, $name, $openEnd)];
        if ($declared === 'off') {
            $parts['off'][] = $span;
        } else {
            $parts['watched'][] = [...$span, 'saves' => array_values(array_diff(preg_split('/\s+/', (string) $declared, -1, PREG_SPLIT_NO_EMPTY), ['*']))];
        }
    }

    return $parts;
}

/** @return list<array{at: int, name: string, live: bool, skip: bool}> every field that is tied to the screen */
function unsavedFields(string $source): array
{
    $fields = [];
    preg_match_all('/<(?:input|select|textarea)\b/', $source, $tags, PREG_OFFSET_CAPTURE);

    foreach ($tags[0] as [$open, $from]) {
        $text = substr($source, $from, unsavedTagEnd($source, $from + strlen($open)) - $from);
        if (preg_match('/wire:model((?:\.[\w.]+)?)="([^"]*)"/', $text, $tied) === 1) {
            $fields[] = ['at' => $from, 'name' => $tied[2], 'live' => str_starts_with($tied[1], '.live'), 'skip' => preg_match('/\sdata-unsaved-skip\b/', $text) === 1];
        }
    }

    return $fields;
}

function unsavedInside(array $spans, int $at): bool
{
    foreach ($spans as $span) {
        if ($span['from'] <= $at && $at < $span['to']) {
            return true;
        }
    }

    return false;
}

/** view => the fields named on the written list, whatever the reason. */
function unsavedExemptByView(): array
{
    $byView = [];
    foreach (UNSAVED_FIELDS_EXEMPT as $views) {
        foreach ($views as $view => $names) {
            $byView[$view] = [...($byView[$view] ?? []), ...$names];
        }
    }

    return $byView;
}

it('watches every form of the admin, or names it on the written list', function () {
    $unwatched = [];
    $seen = ['forms' => 0, 'off' => [], 'plain' => []];

    foreach (unsavedViews() as $view => $source) {
        preg_match_all('/<form\b/', $source, $forms, PREG_OFFSET_CAPTURE);
        foreach ($forms[0] as [, $from]) {
            $text = substr($source, $from, unsavedTagEnd($source, $from + 5) - $from);
            $where = $view.':'.(substr_count($source, "\n", 0, $from) + 1);

            if (preg_match('/wire:submit(?:\.[\w.]+)?="\s*([\w$]+)/', $text, $sends) !== 1) {
                // The browser's own form. Nothing of it is watched, so the view has to be on the list.
                $seen['plain'][$view] = true;
                if (! isset(UNSAVED_PLAIN_FORMS[$view])) {
                    $unwatched[] = "{$where}: a form that is sent as a whole page";
                }

                continue;
            }

            $seen['forms']++;
            if (preg_match('/\sdata-unsaved(?:="([^"]*)")?(?=[\s>\/])/', $text, $said) !== 1) {
                $unwatched[] = "{$where}: the form that sends {$sends[1]} carries no data-unsaved";
            } elseif (($said[1] ?? '') === 'off') {
                $seen['off'][$view][$sends[1]] = true;
                if (! isset(UNSAVED_FORMS_OFF[$view][$sends[1]])) {
                    $unwatched[] = "{$where}: the form that sends {$sends[1]} is switched off without a written reason";
                }
            }
        }

        // A modal that is a form marks itself; one that says unsaved="off" needs its reason too.
        preg_match_all('/<x-admin\.modal\b/', $source, $modals, PREG_OFFSET_CAPTURE);
        foreach ($modals[0] as [, $from]) {
            $text = substr($source, $from, unsavedTagEnd($source, $from + 14) - $from);
            if (preg_match('/\sunsaved="off"/', $text) === 1 && preg_match('/\ssubmit="\s*([\w$]+)/', $text, $sends) === 1) {
                $seen['off'][$view][$sends[1]] = true;
                if (! isset(UNSAVED_FORMS_OFF[$view][$sends[1]])) {
                    $unwatched[] = $view.': the modal that sends '.$sends[1].' is switched off without a written reason';
                }
            }
        }
    }

    expect($unwatched)->toBe([])
        ->and($seen['forms'])->toBeGreaterThan(50);

    // The lists name nothing that is gone.
    foreach (UNSAVED_FORMS_OFF as $view => $sends) {
        expect(array_keys($seen['off'][$view] ?? []))->toEqualCanonicalizing(array_keys($sends));
    }
    expect(array_keys($seen['plain']))->toEqualCanonicalizing(array_keys(UNSAVED_PLAIN_FORMS));
});

it('finds every field of the admin in a watched part, or on the written list', function () {
    $views = unsavedViews();
    $exempt = unsavedExemptByView();
    $problems = [];
    $counted = ['fields' => 0, 'watched' => 0];
    $named = [];

    foreach ($views as $view => $source) {
        $parts = unsavedParts($source);

        foreach (unsavedFields($source) as $field) {
            $counted['fields']++;
            $where = $view.':'.(substr_count($source, "\n", 0, $field['at']) + 1).' '.$field['name'];
            $listed = in_array($field['name'], $exempt[$view] ?? [], true);
            $watched = unsavedInside($parts['watched'], $field['at']);

            if ($listed) {
                $named[$view][$field['name']] = true;
                // Inside a watched part the page has to say so too, or typing there would leave a mark.
                if ($field['skip'] !== ($watched || isset(UNSAVED_HOSTED[$view]))) {
                    $problems[] = $where.($field['skip'] ? ': data-unsaved-skip outside every watched part' : ': on the list, in a watched part, without data-unsaved-skip');
                }
            } elseif ($field['skip']) {
                $problems[] = "{$where}: data-unsaved-skip without a written reason";
            } elseif ($watched || isset(UNSAVED_HOSTED[$view])) {
                $counted['watched']++;
            } elseif (! unsavedInside($parts['off'], $field['at'])) {
                $problems[] = "{$where}: neither in a watched part nor on the list";
            }
        }
    }

    // A filter is exempt because it is sent as it changes. One that waits for a button is something else.
    foreach (UNSAVED_FIELDS_EXEMPT[array_key_first(UNSAVED_FIELDS_EXEMPT)] as $view => $names) {
        foreach (unsavedFields($views[$view] ?? '') as $field) {
            if (in_array($field['name'], $names, true) && ! $field['live']) {
                $problems[] = "{$view} {$field['name']}: listed as a filter, and not sent as it changes";
            }
        }
    }

    // The list names nothing that is gone.
    foreach ($exempt as $view => $names) {
        foreach (array_unique($names) as $name) {
            if (! isset($named[$view][$name])) {
                $problems[] = "{$view} {$name}: on the list, not in the view";
            }
        }
    }

    expect($problems)->toBe([])
        ->and($counted['fields'])->toBeGreaterThan(700)
        ->and($counted['watched'])->toBeGreaterThan(500);
});

it('draws a view whose fields belong to another screen only inside a watched part of that screen', function () {
    $views = unsavedViews();
    $problems = [];

    foreach (UNSAVED_HOSTED as $hosted => [$drawnAs, $hosts]) {
        expect($views)->toHaveKey($hosted);
        $uses = 0;

        foreach ($views as $view => $source) {
            $parts = null;
            for ($at = strpos($source, $drawnAs); $at !== false; $at = strpos($source, $drawnAs, $at + 1)) {
                // A tree draws itself for its branches.
                if ($view === $hosted) {
                    continue;
                }
                $uses++;
                $parts ??= unsavedParts($source);
                if (! in_array($view, $hosts, true)) {
                    $problems[] = "{$view} draws {$hosted} and is not named as one of its hosts";
                } elseif (! unsavedInside($parts['watched'], $at)) {
                    $problems[] = "{$view} draws {$hosted} outside every watched part";
                }
            }
        }

        expect($uses)->toBeGreaterThanOrEqual(count($hosts));
    }

    expect($problems)->toBe([]);
});

it('names, as what saves a part, only actions the same screen can send', function () {
    $views = unsavedViews();
    $unknown = [];
    $checked = 0;

    foreach ($views as $view => $source) {
        // What the screen can send: from this view and from the views drawn inside it.
        $sendable = $source;
        foreach (UNSAVED_HOSTED as $hosted => [, $hosts]) {
            $sendable .= in_array($view, $hosts, true) ? $views[$hosted] : '';
        }

        foreach (unsavedParts($source)['watched'] as $part) {
            foreach ($part['saves'] as $action) {
                $checked++;
                if (preg_match('/wire:(?:click|submit|change)(?:\.[\w.]+)?="\s*'.preg_quote($action, '/').'\b/', $sendable) !== 1) {
                    $unknown[] = "{$view}: \"{$action}\" is named as saving a part, and nothing on the screen sends it";
                }
            }
        }
    }

    expect($unknown)->toBe([])
        ->and($checked)->toBeGreaterThan(20);
});

it('marks a modal that is a form, and one that is told what saves it', function () {
    // A page gets this from the web middleware; a bare render has to bring it.
    View::share('errors', new ViewErrorBag);
    $modal = fn (string $attributes): string => Blade::render('<x-admin.modal name="showIt" title="A modal" '.$attributes.'><input></x-admin.modal>');

    expect($modal('submit="save"'))->toMatch('/<form\s+wire:submit="save"\s+data-unsaved=""/')
        ->and($modal('submit="save" unsaved="saveDraft"'))->toMatch('/<form\s+wire:submit="save"\s+data-unsaved="saveDraft"/')
        ->and($modal('submit="find" unsaved="off"'))->toMatch('/<form\s+wire:submit="find"\s+data-unsaved="off"/')
        ->and($modal('unsaved="reject"'))->toMatch('/<div\s+data-unsaved="reject"/')->not->toContain('<form')
        ->and($modal(''))->not->toContain('data-unsaved');
});

it('puts the mark in the page of a screen with a form, and not in a list', function () {
    $this->actingAs($this->userWithRole('owner'));
    $mark = '/\sdata-unsaved(?:="[^"]*")?(?=[\s>])/';

    // The list of leads holds filters only.
    $list = Livewire::test(LeadsIndex::class);
    expect(preg_match($mark, $list->html()))->toBe(0);

    // With "New lead" open it holds a form.
    $list->set('showCreate', true);
    expect(preg_match_all($mark, $list->html()))->toBe(1)
        ->and($list->html())->toContain('<form wire:submit="createLead" data-unsaved');

    // Every screen of the admin carries the question itself, once, with "Stay" as the button that is offered.
    $page = $this->get(route('admin.leads.index'))->assertOk()->getContent();
    expect(substr_count($page, '<dialog data-unsaved-dialog'))->toBe(1)
        ->and($page)->toContain('data-unsaved-stay')->toContain('data-unsaved-leave')->toContain('Leave without saving');
});

it('says in the answer that something was refused, also where Livewire sends no complaint', function () {
    $this->actingAs($this->userWithRole('owner'));

    // A complaint under a field of the screen.
    $refused = Livewire::test(LeadsIndex::class)->set('showCreate', true)->call('createLead');
    $refused->assertHasErrors('newName');
    expect($refused->effects['wxInvalid'] ?? null)->toBeTrue();

    // A complaint under a name that is no field of the screen. Livewire draws it into the page and sends the
    // browser no list of it, so without the word the browser would take the answer for a save.
    $shop = Livewire::test(ShopEditor::class)->call('publish');
    $shop->assertHasErrors('publish');
    expect($shop->effects['wxInvalid'] ?? null)->toBeTrue()
        ->and($shop->snapshot['memo']['errors'] ?? [])->not->toHaveKey('publish');

    // A save that went through carries no such word.
    $saved = Livewire::test(LeadsIndex::class)
        ->set('showCreate', true)
        ->set('newName', 'Ayesha Khan')
        ->set('newPhone', '0322 4000768')
        ->call('createLead');
    $saved->assertHasNoErrors();
    expect($saved->effects)->not->toHaveKey('wxInvalid');
});

it('registers no Livewire component hook from the application: one registered there is never called', function () {
    // Livewire wires up component hooks when its own provider boots, before the application's. A hook added from
    // AppServiceProvider works in this test run (the list of hooks outlives the application between tests) and
    // in no real request. The first version of the word above was written that way: every test here passed, and
    // the browser was told nothing. Livewire\on('dehydrate', …) has no such order.
    $late = [];
    foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
        if (preg_match('/(?:Livewire::|->)componentHook\(|ComponentHookRegistry::register\(/', $file->getContents()) === 1) {
            $late[] = $file->getRelativePathname();
        }
    }

    expect($late)->toBe([])
        ->and(file_get_contents(app_path('Providers/AppServiceProvider.php')))->toContain('TellsTheBrowserOfComplaints::listen();');
});

it('says in the answer that a save was held back for a warning, where nothing is refused', function () {
    // Two visits for the same person at the same hour: the first press shows the warning and books nothing, the
    // second books anyway. No complaint and no red notice is involved, so the screen has to say it itself.
    $sales = $this->userWithRole('sales');
    $this->actingAs($sales);
    $when = now()->addDays(2)->setTime(15, 0);
    $other = Lead::factory()->create(['owner_id' => $sales->id]);
    $other->siteVisits()->create(['contact_id' => $other->contact_id, 'scheduled_at' => $when, 'assigned_to' => $sales->id, 'status' => 'scheduled']);
    $lead = Lead::factory()->create(['owner_id' => $sales->id]);

    $screen = Livewire::test(LeadShow::class, ['lead' => $lead])
        ->call('openVisit')
        ->set('visitAt', $when->format('Y-m-d\TH:i'))
        ->call('scheduleVisit');

    $screen->assertHasNoErrors()->assertSet('showVisit', true)->assertSee('already has a visit');
    expect($screen->effects['wxInvalid'] ?? null)->toBeTrue()
        ->and($lead->siteVisits()->count())->toBe(0);

    // The word is said for that answer only: the second press books, and its answer is a save.
    $screen->call('scheduleVisit')->assertSet('showVisit', false);
    expect($screen->effects)->not->toHaveKey('wxInvalid')
        ->and($lead->siteVisits()->count())->toBe(1);
});

it('ships the watching script in the built bundle the pages load', function () {
    // The host has no Node.js: what is committed under public/build is what runs (AGENTS.md).
    $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
    $built = file_get_contents(public_path('build/'.$manifest['resources/js/admin.js']['file']));

    expect($built)->toContain('wxUnsaved')
        ->toContain('data-unsaved-dialog')
        ->toContain('wxInvalid')
        ->toContain('beforeunload');
});
