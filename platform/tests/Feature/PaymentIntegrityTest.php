<?php

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Projects\Models\Project;
use App\Domain\Sales\Actions\AllocatePayment;
use App\Domain\Sales\Actions\RecordPayment;
use App\Domain\Sales\Actions\RejectPayment;
use App\Domain\Sales\Actions\SubmitPaymentProof;
use App\Domain\Sales\Actions\VerifyPayment;
use App\Domain\Sales\Enums\InvoiceKind;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Enums\PaymentStatus;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Notifications\PaymentNotification;
use App\Domain\System\Models\NumberSequence;
use App\Livewire\Admin\Sales\PaymentsIndex;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Mail\MailManager;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Queue\Connectors\DatabaseConnector;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    Notification::fake();
    $this->accountant = $this->userWithRole('accountant');
    $this->client = Contact::factory()->create();
});

function integrityInvoice(Contact $client, array $overrides = []): Invoice
{
    static $number = 0;

    return Invoice::query()->create(array_replace([
        'number' => 'TEST-INTEGRITY-'.++$number,
        'contact_id' => $client->id,
        'business_line' => BusinessLine::Interior,
        'kind' => InvoiceKind::Milestone,
        'status' => InvoiceStatus::Issued,
        'currency' => 'PKR',
        'total_minor' => 10_000,
        'balance_minor' => 10_000,
        'issue_date' => '2026-10-01',
        'due_date' => '2026-10-15',
    ], $overrides));
}

function integrityInput(Contact $client, array $overrides = []): array
{
    return array_replace([
        'contact_id' => $client->id,
        'amount_minor' => 5_000,
        'received_on' => '2026-10-17',
        'method' => 'cash',
    ], $overrides);
}

it('rejects another client invoice through the authorized payment form without changing the ledger', function () {
    $other = integrityInvoice(Contact::factory()->create());

    Livewire::actingAs($this->accountant)->test(PaymentsIndex::class)
        ->set('contactId', $this->client->id)->set('invoiceId', $other->id)
        ->set('amount', '50.00')->set('method', 'cash')->call('save')
        ->assertHasErrors('invoiceId');

    expect(Payment::query()->count())->toBe(0)
        ->and($other->refresh()->paid_minor)->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
});

it('never allocates to non-payable or foreign-currency invoice targets', function (string $case) {
    $invoice = integrityInvoice($this->client, match ($case) {
        'draft' => ['status' => InvoiceStatus::Draft],
        'void' => ['status' => InvoiceStatus::Void],
        'paid' => ['status' => InvoiceStatus::Paid, 'balance_minor' => 0, 'paid_minor' => 10_000],
        'credit' => ['kind' => InvoiceKind::CreditNote],
        'currency' => ['currency' => 'USD'],
    });
    $before = $invoice->fresh()->getAttributes();

    expect(fn () => app(RecordPayment::class)->handle(integrityInput($this->client, ['invoice_id' => $invoice->id]), $this->accountant))
        ->toThrow(ValidationException::class);
    expect(Payment::query()->count())->toBe(0)
        ->and($invoice->refresh()->getAttributes())->toBe($before);
})->with(['draft', 'void', 'paid', 'credit', 'currency']);

it('honors an explicit allocation map and keeps its unselected remainder as credit', function () {
    $oldest = integrityInvoice($this->client, ['due_date' => '2026-10-01']);
    $selected = integrityInvoice($this->client);

    $payment = app(RecordPayment::class)->handle(integrityInput($this->client, [
        'amount_minor' => 15_000, 'allocations' => [$selected->id => 6_000],
    ]), $this->accountant);

    expect($oldest->refresh()->paid_minor)->toBe(0)
        ->and($selected->refresh()->paid_minor)->toBe(6_000)
        ->and($payment->unallocated()->minor)->toBe(9_000)
        ->and($payment->allocations()->count())->toBe(1);
});

it('validates the whole explicit map before retaining any allocation or receipt', function (string $case) {
    $first = integrityInvoice($this->client);
    $second = integrityInvoice($case === 'contact' ? Contact::factory()->create() : $this->client);
    $badAmount = match ($case) {
        'negative' => -100,
        'zero' => 0,
        'float' => 100.5,
        'overpayment' => 30_000,
        'overbalance' => 11_000,
        default => 2_000,
    };
    $allocations = [$first->id => 1_000, $case === 'missing' ? 999999 : $second->id => $badAmount];
    expect(fn () => app(RecordPayment::class)->handle(integrityInput($this->client, [
        'amount_minor' => 20_000, 'allocations' => $allocations,
    ]), $this->accountant))->toThrow(ValidationException::class);

    expect(Payment::query()->count())->toBe(0)
        ->and($first->refresh()->paid_minor)->toBe(0)
        ->and($second->refresh()->paid_minor)->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with(['contact', 'negative', 'zero', 'float', 'overpayment', 'overbalance', 'missing']);

it('pays the preferred invoice before older debt and retains only the final excess as credit', function () {
    $oldest = integrityInvoice($this->client, ['due_date' => '2026-10-01']);
    $preferred = integrityInvoice($this->client);

    $payment = app(RecordPayment::class)->handle(integrityInput($this->client, [
        'invoice_id' => $preferred->id, 'amount_minor' => 25_000,
    ]), $this->accountant);

    expect($payment->allocations()->orderBy('id')->pluck('invoice_id')->all())->toBe([$preferred->id, $oldest->id])
        ->and($oldest->refresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($preferred->refresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($payment->unallocated()->minor)->toBe(5_000);
});

it('does not spend automatic excess on ineligible invoice contexts', function () {
    $eligible = integrityInvoice($this->client);
    $otherClient = integrityInvoice(Contact::factory()->create());
    $usd = integrityInvoice($this->client, ['currency' => 'USD']);
    $void = integrityInvoice($this->client, ['status' => InvoiceStatus::Void]);
    $furniture = integrityInvoice($this->client, ['business_line' => BusinessLine::Furniture]);
    $bank = BankAccount::query()->create([
        'business_line' => BusinessLine::Interior, 'title' => 'Test company', 'bank_name' => 'Test bank',
        'account_number' => 'TEST-ONLY', 'is_active' => true,
    ]);
    $payment = app(RecordPayment::class)->handle(integrityInput($this->client, [
        'bank_account_id' => $bank->id, 'amount_minor' => 50_000,
    ]), $this->accountant);

    expect($eligible->refresh()->paid_minor)->toBe(10_000)
        ->and($payment->unallocated()->minor)->toBe(40_000);
    foreach ([$otherClient, $usd, $void, $furniture] as $invoice) {
        expect($invoice->refresh()->paid_minor)->toBe(0);
    }
});

it('rejects an incompatible or inactive receiving account', function (bool $active) {
    $invoice = integrityInvoice($this->client);
    $bank = BankAccount::query()->create([
        'business_line' => BusinessLine::Furniture, 'title' => 'Test company', 'bank_name' => 'Test bank',
        'account_number' => 'TEST-ONLY', 'is_active' => $active,
    ]);
    expect(fn () => app(RecordPayment::class)->handle(integrityInput($this->client, [
        'invoice_id' => $invoice->id, 'bank_account_id' => $bank->id,
    ]), $this->accountant))->toThrow(ValidationException::class);
    expect(Payment::query()->count())->toBe(0)->and($invoice->refresh()->paid_minor)->toBe(0);
})->with([true, false]);

it('rereads a pending proof and never renumbers or redelivers a stale verification', function () {
    $invoice = integrityInvoice($this->client);
    $pending = app(SubmitPaymentProof::class)->handle($invoice, integrityInput($this->client));
    $stale = $pending->fresh();
    $verified = app(VerifyPayment::class)->handle($pending, $this->accountant);
    $receipt = $verified->receipt_number;
    $path = $verified->receipt_pdf_path;
    $next = NumberSequence::query()->where('key', 'receipt')->value('next_number');
    $notifications = Notification::sent($this->accountant, PaymentNotification::class)->count();

    $again = app(VerifyPayment::class)->handle($stale, $this->accountant);
    $direct = app(RecordPayment::class)->receipt($stale, $this->accountant);

    expect($again->receipt_number)->toBe($receipt)
        ->and($direct->receipt_number)->toBe($receipt)
        ->and($again->receipt_pdf_path)->toBe($path)
        ->and($again->allocations()->count())->toBe(1)
        ->and(NumberSequence::query()->where('key', 'receipt')->value('next_number'))->toBe($next)
        ->and(Notification::sent($this->accountant, PaymentNotification::class)->count())->toBe($notifications);
});

it('cannot use rejection to undo verified money or verification to resurrect a rejection', function () {
    $invoice = integrityInvoice($this->client);
    $pending = app(SubmitPaymentProof::class)->handle($invoice, integrityInput($this->client));
    $stale = $pending->fresh();
    app(VerifyPayment::class)->handle($pending, $this->accountant);
    app(RejectPayment::class)->handle($stale, 'Not yet seen in bank', $this->accountant);
    expect($pending->refresh()->status)->toBe(PaymentStatus::Verified)->and($invoice->refresh()->paid_minor)->toBe(5_000);

    $second = app(SubmitPaymentProof::class)->handle($invoice->refresh(), integrityInput($this->client));
    $staleSecond = $second->fresh();
    app(RejectPayment::class)->handle($second, 'Not yet seen in bank', $this->accountant);
    app(VerifyPayment::class)->handle($staleSecond, $this->accountant);
    expect($second->refresh()->status)->toBe(PaymentStatus::Rejected)
        ->and($second->allocations()->count())->toBe(0)->and($second->receipt_number)->toBeNull();
});

it('enforces finance permission and active staff at action boundaries', function () {
    $invoice = integrityInvoice($this->client);
    $sales = $this->userWithRole('sales');
    expect(fn () => app(RecordPayment::class)->handle(integrityInput($this->client), $sales))->toThrow(AuthorizationException::class);
    $pending = app(SubmitPaymentProof::class)->handle($invoice, integrityInput($this->client));
    expect(fn () => app(VerifyPayment::class)->handle($pending, $sales))->toThrow(AuthorizationException::class);
    $this->accountant->forceFill(['is_active' => false])->save();
    expect(fn () => app(VerifyPayment::class)->handle($pending, $this->accountant))->toThrow(AuthorizationException::class);
    expect($pending->refresh()->status)->toBe(PaymentStatus::PendingVerification);
});

it('does not allocate an unverified proof or rewrite allocations after issuing a receipt', function () {
    $invoice = integrityInvoice($this->client);
    $pending = app(SubmitPaymentProof::class)->handle($invoice, integrityInput($this->client));
    expect(fn () => app(AllocatePayment::class)->handle($pending))->toThrow(ValidationException::class);
    $verified = app(VerifyPayment::class)->handle($pending, $this->accountant);
    expect(fn () => app(AllocatePayment::class)->handle($verified, [$invoice->id => 100]))->toThrow(ValidationException::class);
    expect($invoice->refresh()->paid_minor)->toBe(5_000)->and($verified->allocations()->count())->toBe(1);
});

it('removes a generated receipt and rolls back balances and numbering when the enclosing transaction fails', function () {
    $invoice = integrityInvoice($this->client);
    $next = NumberSequence::query()->where('key', 'receipt')->value('next_number');
    expect(fn () => DB::transaction(function () use ($invoice) {
        app(RecordPayment::class)->handle(integrityInput($this->client, ['invoice_id' => $invoice->id]), $this->accountant);
        expect(Storage::disk('local')->allFiles())->toHaveCount(1);
        throw new RuntimeException('Test rollback after receipt generation');
    }))->toThrow(RuntimeException::class);
    expect(Payment::query()->count())->toBe(0)
        ->and($invoice->refresh()->paid_minor)->toBe(0)
        ->and(NumberSequence::query()->where('key', 'receipt')->value('next_number'))->toBe($next)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('rolls back a verification rather than confirming money when receipt storage fails', function () {
    $invoice = integrityInvoice($this->client);
    $pending = app(SubmitPaymentProof::class)->handle($invoice, integrityInput($this->client));
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('put')->once()->andReturnFalse();
    $disk->shouldReceive('delete')->once()->andReturnTrue();
    Storage::shouldReceive('disk')->with('local')->andReturn($disk);
    expect(fn () => app(VerifyPayment::class)->handle($pending, $this->accountant))->toThrow(RuntimeException::class);
    expect($pending->refresh()->status)->toBe(PaymentStatus::PendingVerification)
        ->and($pending->receipt_number)->toBeNull()->and($pending->allocations()->count())->toBe(0)
        ->and($invoice->refresh()->paid_minor)->toBe(0);
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
});

it('validates fresh invoice state and positive integer amounts before accepting a client claim', function () {
    $invoice = integrityInvoice($this->client);
    $stale = $invoice->fresh();
    $invoice->forceFill(['status' => InvoiceStatus::Void])->save();
    expect(fn () => app(SubmitPaymentProof::class)->handle($stale, integrityInput($this->client)))->toThrow(ValidationException::class);
    $invoice->forceFill(['status' => InvoiceStatus::Issued])->save();
    foreach ([0, -1, 100.25, '100'] as $amount) {
        expect(fn () => app(SubmitPaymentProof::class)->handle($invoice, integrityInput($this->client, ['amount_minor' => $amount])))
            ->toThrow(ValidationException::class);
    }
    expect(Payment::query()->count())->toBe(0)->and($invoice->refresh()->paid_minor)->toBe(0);
});

it('enforces client and project context on allocation targets', function (string $case) {
    $projectClient = $case === 'other-client' ? Contact::factory()->create() : $this->client;
    $project = Project::query()->create([
        'code' => 'TEST-PROJECT', 'name' => 'Test project', 'contact_id' => $projectClient->id,
        'business_line' => BusinessLine::Interior,
    ]);
    $invoice = integrityInvoice($this->client); // not part of the selected project
    expect(fn () => app(RecordPayment::class)->handle(integrityInput($this->client, [
        'project_id' => $project->id, 'invoice_id' => $invoice->id,
    ]), $this->accountant))->toThrow(ValidationException::class);
    expect(Payment::query()->count())->toBe(0)->and($invoice->refresh()->paid_minor)->toBe(0);
})->with(['other-client', 'different-project']);

it('preserves paisa exactly and refuses scientific or overprecise amounts on the staff form', function () {
    Livewire::actingAs($this->accountant)->test(PaymentsIndex::class)
        ->set('contactId', $this->client->id)->set('amount', '19.99')->set('method', 'cash')->call('save')->assertHasNoErrors();
    expect(Payment::query()->sole()->amount_minor)->toBe(1_999);
    foreach (['1e5', '19.999', '999999999999999999999'] as $amount) {
        Livewire::actingAs($this->accountant)->test(PaymentsIndex::class)
            ->set('contactId', $this->client->id)->set('amount', $amount)->set('method', 'cash')->call('save')->assertHasErrors('amount');
    }
    expect(Payment::query()->count())->toBe(1);
});

it('queues client mail and team alerts only after the money transaction commits', function (bool $rollBack) {
    $manager = new QueueManager(app());
    $manager->addConnector('database', fn () => new DatabaseConnector(app('db')));
    Queue::swap($manager);
    config(['queue.default' => 'database', 'mail.default' => 'array']);
    Mail::swap(new MailManager(app()));
    Notification::swap(new ChannelManager(app()));
    $this->client->forceFill(['email' => 'client@example.test'])->save();
    $invoice = integrityInvoice($this->client);

    try {
        DB::transaction(function () use ($invoice, $rollBack) {
            app(RecordPayment::class)->handle(integrityInput($this->client, ['invoice_id' => $invoice->id]), $this->accountant);
            expect(DB::table('jobs')->count())->toBe(0);
            if ($rollBack) {
                throw new RuntimeException('Test rollback');
            }
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Test rollback');
    }

    expect(DB::table('jobs')->count())->toBe($rollBack ? 0 : 2)
        ->and(Payment::query()->count())->toBe($rollBack ? 0 : 1);
    if (! $rollBack) {
        $payload = DB::table('jobs')->pluck('payload')->implode(' ');
        expect($payload)->toContain('SendQueuedMailable')->toContain('SendQueuedNotifications');
    }
})->with([true, false]);
