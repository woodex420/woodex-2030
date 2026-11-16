<?php

use App\Support\Phone;

it('normalises Pakistani numbers to E.164', function (string $input, ?string $expected) {
    expect(Phone::normalize($input))->toBe($expected);
})->with([
    'local with dash' => ['0322-4000768', '+923224000768'],
    'local plain' => ['03224000768', '+923224000768'],
    'without zero' => ['3224000768', '+923224000768'],
    'country code no plus' => ['923224000768', '+923224000768'],
    'international plus' => ['+92 322 4000768', '+923224000768'],
    'double zero' => ['0092 322 4000768', '+923224000768'],
    'trunk zero after code' => ['+92 (0) 322 4000768', '+923224000768'],
    'Lahore landline' => ['042 35761234', '+924235761234'],
    'UAE number' => ['+971 50 123 4567', '+971501234567'],
    'too short mobile' => ['0322 40007', null],
    'garbage' => ['call me', null],
    'empty' => ['', null],
]);

it('formats numbers for display and links', function () {
    expect(Phone::display('+923224000768'))->toBe('+92 322 4000768')
        ->and(Phone::whatsappUrl('+923224000768', 'Hi Woodex'))->toBe('https://wa.me/923224000768?text=Hi%20Woodex')
        ->and(Phone::telUrl('+923224000768'))->toBe('tel:+923224000768')
        ->and(Phone::whatsappUrl(null))->toBeNull();
});
