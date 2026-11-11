<?php

use App\Support\NumberInput;

it('parses quantities exactly, with up to three decimals', function () {
    expect(NumberInput::quantity('121.125'))->toBe('121.125')
        ->and(NumberInput::quantity('1,355'))->toBe('1355')
        ->and(NumberInput::quantity(' 0012.50 '))->toBe('12.5')
        ->and(NumberInput::quantity('12.'))->toBe('12')
        ->and(NumberInput::quantity('0'))->toBe('0');

    foreach (['', 'abc', '-1', '1.2345', '1e3'] as $bad) {
        expect(fn () => NumberInput::quantity($bad))->toThrow(InvalidArgumentException::class);
    }
});

it('parses rupee amounts into paisa', function () {
    expect(NumberInput::rupees('1200'))->toBe(120_000)
        ->and(NumberInput::rupees('1,200.50'))->toBe(120_050)
        ->and(NumberInput::rupees('Rs 35,000'))->toBe(3_500_000)
        ->and(NumberInput::rupees('0.5'))->toBe(50);

    foreach (['', '-5', '12.345', 'twelve'] as $bad) {
        expect(fn () => NumberInput::rupees($bad))->toThrow(InvalidArgumentException::class);
    }
});

it('parses percentages into basis points and caps them', function () {
    expect(NumberInput::percent('2.5'))->toBe(250)
        ->and(NumberInput::percent('10%'))->toBe(1000)
        ->and(NumberInput::percent('100'))->toBe(10_000);

    expect(fn () => NumberInput::percent('100.01'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => NumberInput::percent('-1'))->toThrow(InvalidArgumentException::class);
});

it('prints stored values back for editing', function () {
    expect(NumberInput::fromMinor(120_000))->toBe('1200')
        ->and(NumberInput::fromMinor(120_050))->toBe('1200.50')
        ->and(NumberInput::fromBp(250))->toBe('2.5')
        ->and(NumberInput::fromBp(1000))->toBe('10')
        ->and(NumberInput::fromQuantity('1355.000'))->toBe('1355')
        ->and(NumberInput::fromQuantity('121.125'))->toBe('121.125')
        ->and(NumberInput::fromQuantity(null))->toBe('0');
});
