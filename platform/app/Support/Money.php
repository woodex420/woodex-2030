<?php

declare(strict_types=1);

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Immutable money value in integer minor units (paisa). Rs 1 = 100 minor.
 *
 * Woodex OS never stores or calculates money as float (D-14). All rounding is HALF UP.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    private function __construct(
        public int $minor,
        public string $currency = 'PKR',
    ) {}

    public static function ofMinor(int $minor, string $currency = 'PKR'): self
    {
        return new self($minor, $currency);
    }

    /**
     * Create from a rupee amount given as int or decimal string ("1200", "1200.5", "1,200.50").
     */
    public static function ofRupees(int|string $rupees, string $currency = 'PKR'): self
    {
        if (is_int($rupees)) {
            return new self($rupees * 100, $currency);
        }

        $clean = str_replace([',', ' ', 'Rs', 'PKR'], '', trim($rupees));

        if ($clean === '' || ! preg_match('/^-?\d+(\.\d+)?$/', $clean)) {
            throw new InvalidArgumentException("Invalid money amount [{$rupees}].");
        }

        $minor = BigDecimal::of($clean)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();

        return new self($minor, $currency);
    }

    public static function zero(string $currency = 'PKR'): self
    {
        return new self(0, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    /**
     * Multiply by a (possibly fractional) quantity such as "121.125", rounding half up to the paisa.
     */
    public function times(int|string $quantity): self
    {
        $result = BigDecimal::of((string) $quantity)
            ->multipliedBy($this->minor)
            ->toScale(0, RoundingMode::HalfUp)
            ->toInt();

        return new self($result, $this->currency);
    }

    /**
     * Percentage expressed in basis points (5000 = 50.00%), rounded half up.
     */
    public function percent(int $basisPoints): self
    {
        $result = BigDecimal::of($this->minor)
            ->multipliedBy($basisPoints)
            ->dividedBy(10000, 0, RoundingMode::HalfUp)
            ->toInt();

        return new self($result, $this->currency);
    }

    /**
     * Round to whole rupees (half up), e.g. 12345 minor (Rs 123.45) -> 12300.
     */
    public function roundToRupee(): self
    {
        $result = BigDecimal::of($this->minor)
            ->dividedBy(100, 0, RoundingMode::HalfUp)
            ->multipliedBy(100)
            ->toInt();

        return new self($result, $this->currency);
    }

    public function min(self $other): self
    {
        $this->assertSameCurrency($other);

        return $this->minor <= $other->minor ? $this : $other;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency === $other->currency;
    }

    /**
     * Decimal rupee string without grouping, e.g. "450000.00".
     */
    public function toDecimalString(): string
    {
        return BigDecimal::ofUnscaledValue($this->minor, 2)->__toString();
    }

    /**
     * Human format: "Rs 450,000" (international) or "Rs 4,50,000" (lakh grouping).
     */
    public function format(bool $lakhGrouping = false, bool $showPaisa = false, string $symbol = 'Rs'): string
    {
        $negative = $this->minor < 0;
        $absolute = abs($this->minor);
        $rupees = intdiv($absolute, 100);
        $paisa = $absolute % 100;

        $grouped = $lakhGrouping ? self::groupLakh((string) $rupees) : number_format($rupees);

        if ($showPaisa || $paisa !== 0) {
            $grouped .= '.'.str_pad((string) $paisa, 2, '0', STR_PAD_LEFT);
        }

        return trim(($negative ? '-' : '').$symbol.' '.$grouped);
    }

    public function __toString(): string
    {
        return $this->format();
    }

    /**
     * @return array{minor: int, currency: string, formatted: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'minor' => $this->minor,
            'currency' => $this->currency,
            'formatted' => $this->format(),
        ];
    }

    private static function groupLakh(string $digits): string
    {
        if (strlen($digits) <= 3) {
            return $digits;
        }

        $lastThree = substr($digits, -3);
        $rest = substr($digits, 0, -3);
        $rest = (string) preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);

        return $rest.','.$lastThree;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency}.");
        }
    }
}
