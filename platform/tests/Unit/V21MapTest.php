<?php

use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Enums\CallOutcome;
use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Enums\VisitStatus;
use App\Domain\System\Import\V21Map;

const V21_TEST_BANDS = [
    'under-1m' => 'Under Rs 10 lakh',
    '1m-3m' => 'Rs 10–30 lakh',
    '3m-7m' => 'Rs 30–70 lakh',
    '7m-15m' => 'Rs 70 lakh – 1.5 crore',
    '15m-plus' => 'Above Rs 1.5 crore',
];

it('converts Pakistani plot sizes to square feet', function (?string $input, ?int $sqft) {
    expect(V21Map::areaToSqft($input))->toBe($sqft);
})->with([
    ['10 marla', 2250],
    ['5 Marla', 1125],
    ['1 kanal', 4500],
    ['1.5 Kanal', 6750],
    ['2 kanal house', 9000],
    ['2,500 sq ft', 2500],
    ['1800', 1800],
    ['100 sqm', 1076],
    ['big house', null],
    ['', null],
    [null, null],
]);

it('matches free-text budgets to bands', function (string $input, ?string $band) {
    expect(V21Map::budgetBand($input, V21_TEST_BANDS))->toBe($band);
})->with([
    ['25 lakh', '1m-3m'],
    ['25L', '1m-3m'],
    ['8 lac', 'under-1m'],
    ['Rs 30-40 lakh', '3m-7m'],
    ['1 crore', '7m-15m'],
    ['1.5 crore', '15m-plus'],
    ['2,500,000', '1m-3m'],
    ['5 million', '3m-7m'],
    ['7m-15m', '7m-15m'],
    ['Rs 10–30 lakh', '1m-3m'],
    ['flexible', null],
    ['', null],
]);

it('maps v2.1 stages, roles and sources', function () {
    expect(V21Map::stage('visit'))->toBe('site_visit')
        ->and(V21Map::stage('quote'))->toBe('quotation')
        ->and(V21Map::stage('hold'))->toBe('on_hold')
        ->and(V21Map::stage('archived'))->toBeNull()
        ->and(V21Map::role('owner'))->toBe('owner')
        ->and(V21Map::role('designer'))->toBe('support')
        ->and(V21Map::source('facebook'))->toBe([LeadSource::SocialMedia, 'Facebook'])
        ->and(V21Map::source('walkin'))->toBe([LeadSource::WalkIn, null])
        ->and(V21Map::source('architect'))->toBe([LeadSource::Referral, 'Architect / consultant'])
        ->and(V21Map::source('mystery-source'))->toBe([LeadSource::Other, 'mystery-source']);
});

it('recognises common call outcomes written as text', function () {
    expect(V21Map::callOutcome('No answer'))->toBe(CallOutcome::NoAnswer)
        ->and(V21Map::callOutcome('phone switched off'))->toBe(CallOutcome::SwitchedOff)
        ->and(V21Map::callOutcome('Busy'))->toBe(CallOutcome::Busy)
        ->and(V21Map::callOutcome('asked to call back tomorrow'))->toBe(CallOutcome::CallBack)
        ->and(V21Map::callOutcome('Client interested'))->toBe(CallOutcome::Connected)
        ->and(V21Map::callOutcome('Positive'))->toBeNull();
});

it('maps note kinds and booking statuses', function () {
    expect(V21Map::noteType('call', false))->toBe(ActivityType::Call)
        ->and(V21Map::noteType('call', true))->toBe(ActivityType::System)
        ->and(V21Map::noteType(null, false))->toBe(ActivityType::Note)
        ->and(V21Map::visitStatus('pending'))->toBe(VisitStatus::Scheduled)
        ->and(V21Map::visitStatus('done'))->toBe(VisitStatus::Completed)
        ->and(V21Map::visitStatus('noshow'))->toBe(VisitStatus::NoShow);
});

it('finds the city in a free-text location', function () {
    expect(V21Map::cityFrom('DHA Phase 6, Lahore'))->toBe('Lahore')
        ->and(V21Map::cityFrom('Bahria Enclave islamabad'))->toBe('Islamabad')
        ->and(V21Map::cityFrom('Bahria Town'))->toBeNull();
});
