<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/*
| Feature tests boot Laravel with a fresh, seeded SQLite database (phpunit.xml).
| Unit tests are plain PHP (Money, Phone, QuoteCalculator…) and run without the framework.
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
| A test that pins the clock must not move the next one: `travelTo` is process-wide, and a handful of
| files used it without travelling back. Clearing the test clock after every test keeps file order
| from deciding whether a date-sensitive digest test passes (found by the Phase 2 gate, 19 Oct).
*/
afterEach(function (): void {
    Carbon::setTestNow();
});
