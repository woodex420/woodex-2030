<?php

use App\Support\AmountInWords;
use App\Support\Money;

it('parses rupee strings exactly into paisa', function () {
    expect(Money::ofRupees('1,200.50')->minor)->toBe(120050)
        ->and(Money::ofRupees(1200)->minor)->toBe(120000)
        ->and(Money::ofRupees('0.005')->minor)->toBe(1); // half up
});

it('multiplies by fractional BOQ quantities rounding half up', function () {
    // 121.125 sft × Rs 1,200 = Rs 145,350.00
    expect(Money::ofRupees(1200)->times('121.125')->minor)->toBe(14_535_000)
        // 0.333 × Rs 10.01 = Rs 3.33333 → Rs 3.33
        ->and(Money::ofRupees('10.01')->times('0.333')->minor)->toBe(333);
});

it('calculates basis-point percentages and rupee rounding', function () {
    expect(Money::ofRupees(1000)->percent(5000)->minor)->toBe(50000)
        ->and(Money::ofMinor(12345)->percent(1000)->minor)->toBe(1235) // 1234.5 → 1235
        ->and(Money::ofMinor(12350)->roundToRupee()->minor)->toBe(12400)
        ->and(Money::ofMinor(12349)->roundToRupee()->minor)->toBe(12300);
});

it('formats international and lakh grouping', function () {
    $amount = Money::ofRupees(450000);

    expect($amount->format())->toBe('Rs 450,000')
        ->and($amount->format(lakhGrouping: true))->toBe('Rs 4,50,000')
        ->and(Money::ofRupees(12500000)->format(lakhGrouping: true))->toBe('Rs 1,25,00,000')
        ->and(Money::ofMinor(-150)->format())->toBe('-Rs 1.50')
        ->and(Money::ofRupees(999)->format(lakhGrouping: true))->toBe('Rs 999');
});

it('rejects invalid amounts and mixed currencies', function () {
    expect(fn () => Money::ofRupees('abc'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::ofMinor(1)->plus(Money::ofMinor(1, 'USD')))->toThrow(InvalidArgumentException::class);
});

it('writes amounts in words in the South Asian and international systems', function () {
    expect(AmountInWords::rupees(Money::ofRupees(450000)))->toBe('Rupees Four Lakh Fifty Thousand Only')
        ->and(AmountInWords::rupees(Money::ofRupees(12500000)))->toBe('Rupees One Crore Twenty Five Lakh Only')
        ->and(AmountInWords::rupees(Money::ofRupees('1001.50')))->toBe('Rupees One Thousand One and Fifty Paisa Only')
        ->and(AmountInWords::rupees(Money::ofRupees(450000), southAsian: false))->toBe('Rupees Four Hundred Fifty Thousand Only')
        ->and(AmountInWords::rupees(Money::zero()))->toBe('Rupees Zero Only');
});
