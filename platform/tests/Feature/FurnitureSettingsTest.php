<?php

use App\Domain\Sales\Services\DocumentReadiness;
use App\Livewire\Admin\System\SettingsPage;
use App\Settings\BusinessSettings;
use App\Settings\SalesSettings;

beforeEach(function () {
    // The product as it is shipped: Furniture alone on sale (the suite's default is all three lines).
    config(['documents.test_bypass' => false, 'woodex.business_lines' => ['furniture']]);
});

it('starts with Furniture branding and no inherited legal identity or commercial wording', function () {
    $business = app(BusinessSettings::class);
    $sales = app(SalesSettings::class);
    expect($business->brand_name)->toBe('Woodex Furniture')
        ->and($business->legal_name)->toBe('')
        ->and($business->email)->toBe('')
        ->and($business->phone)->toBe('')
        ->and($sales->execution_terms)->toBe('')
        ->and($sales->design_terms)->toBe('')
        ->and($sales->invoice_terms)->toBe('')
        ->and($sales->signatories)->toBe([]);
});

it('preserves customized settings when the cleanup runs again', function () {
    $business = app(BusinessSettings::class);
    $business->legal_name = 'Synthetic Furniture Company';
    $business->email = 'owner@example.test';
    $business->save();
    $sales = app(SalesSettings::class);
    $sales->execution_terms = 'Owner supplied test terms';
    $sales->signatories = [['name' => 'Synthetic Signer', 'title' => 'Owner']];
    $sales->save();

    $migration = require database_path('settings/2026_11_11_200000_neutralize_interior_identity.php');
    $migration->up();
    $business->refresh();
    $sales->refresh();

    expect($business->legal_name)->toBe('Synthetic Furniture Company')
        ->and($business->email)->toBe('owner@example.test')
        ->and($sales->execution_terms)->toBe('Owner supplied test terms')
        ->and($sales->signatories)->toBe([['name' => 'Synthetic Signer', 'title' => 'Owner']]);
});

it('tells authorized staff, point by point, what still stops a document from being issued', function () {
    $this->actingAs($this->userWithRole('owner'))->get('/admin/settings')
        ->assertOk()
        ->assertSee('Documents cannot be issued yet')
        ->assertSee('Fill in the legal name under Settings → Business.')
        ->assertSee('Fill in the invoice terms under Settings → Sales rules.')
        ->assertSee('Add a signatory, with name and title, under Settings → Sales rules.')
        ->assertSee('The owner has not yet confirmed')
        // The brand name is filled in from the start, and the Interior brand's terms are not asked of a
        // business that sells Furniture alone.
        ->assertDontSee('Fill in the brand name')
        ->assertDontSee('execution terms')
        ->assertDontSee('design terms');
});

it('asks for the Interior brand\'s terms only where that brand is on sale', function () {
    expect(array_keys(DocumentReadiness::termsAskedFor()))->toBe(['invoice_terms']);

    config(['woodex.business_lines' => ['furniture', 'interior']]);

    expect(array_keys(DocumentReadiness::termsAskedFor()))->toBe(['execution_terms', 'design_terms', 'invoice_terms']);

    $this->actingAs($this->userWithRole('owner'))->get('/admin/settings?tab=sales')
        ->assertOk()
        ->assertSee('Execution terms')
        ->assertSee('Design terms')
        ->assertSee('Fill in the execution terms under Settings → Sales rules.');
});

it('lets the owner fill in everything a document needs from the Settings screen, with Furniture alone on sale', function () {
    config(['documents.owner_confirmed' => true]);
    $owner = $this->userWithRole('owner');

    $business = app(BusinessSettings::class);
    foreach (['legal_name' => 'Synthetic Furniture Company', 'address' => 'Synthetic address, Lahore', 'phone' => '+923001234567', 'email' => 'office@example.test'] as $key => $value) {
        $business->{$key} = $value;
    }
    $business->save();

    // The invoice terms had no field on the screen: on a new installation they are blank, and a blank kept
    // every quotation and invoice from being issued, for good.
    expect(app(DocumentReadiness::class)->problems())->toHaveCount(2);

    Livewire\Livewire::actingAs($owner)->test(SettingsPage::class, ['tab' => 'sales'])
        ->assertSee('Invoice terms')
        ->assertDontSee('Execution terms')
        ->assertDontSee('Design terms')
        ->call('addSignatory')
        ->set('sales.signatories.0.name', 'Synthetic Signer')
        ->set('sales.signatories.0.title', 'Director')
        // The other rules can be saved while the wording is still undecided; the notice keeps the point open.
        ->call('saveSales')
        ->assertHasNoErrors()
        ->assertSee('Documents cannot be issued yet')
        ->assertSee('Fill in the invoice terms under Settings → Sales rules.')
        ->assertDontSee('Add a signatory')
        ->set('sales.invoice_terms', "Payment is due within the days stated on this invoice.\nGoods remain ours until paid in full.")
        ->call('saveSales')
        ->assertHasNoErrors()
        ->assertDontSee('Documents cannot be issued yet');

    $sales = app(SalesSettings::class)->refresh();

    expect($sales->invoice_terms)->toContain('Goods remain ours until paid in full.')
        ->and($sales->signatories)->toBe([['name' => 'Synthetic Signer', 'title' => 'Director']])
        // Interior's two texts are neither asked for nor touched.
        ->and($sales->execution_terms)->toBe('')
        ->and($sales->design_terms)->toBe('')
        ->and(app(DocumentReadiness::class)->problems())->toBe([]);
});

it('does not grant settings access to sales staff', function () {
    $this->actingAs($this->userWithRole('sales'))->get('/admin/settings')->assertForbidden();
});
