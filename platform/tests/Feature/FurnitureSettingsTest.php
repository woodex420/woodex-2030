<?php

use App\Settings\BusinessSettings;
use App\Settings\SalesSettings;

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

it('warns authorized staff that Furniture setup is incomplete', function () {
    $this->actingAs($this->userWithRole('owner'))->get('/admin/settings')
        ->assertOk()->assertSee('Furniture setup pending owner confirmation');
});

it('does not grant settings access to sales staff', function () {
    $this->actingAs($this->userWithRole('sales'))->get('/admin/settings')->assertForbidden();
});
