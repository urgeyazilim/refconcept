<?php

declare(strict_types=1);

namespace App\Support\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An exact monetary amount.
 *
 * Held as an integer number of minor units (kuruş for TRY) plus a currency. Floats
 * are forbidden for money throughout this project, and this class is why that rule is
 * enforceable rather than merely stated: `0.1 + 0.2 !== 0.3` in binary floating point,
 * and a marketplace that splits one payment across several sellers, deducts a
 * commission and posts a balanced double-entry journal cannot absorb that error.
 *
 * Arithmetic is closed over the same currency. Adding TRY to EUR throws rather than
 * silently producing a number that means nothing.
 *
 * Division is deliberately absent. Splitting money is not division — it is
 * allocation, because the remainder has to go somewhere. Use {@see allocate()}.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    /** Minor units per major unit, by currency. */
    private const SCALE = [
        'TRY' => 100,
        'EUR' => 100,
        'USD' => 100,
        'GBP' => 100,
    ];

    private function __construct(
        public int $amountMinor,
        public string $currency,
    ) {}

    public static function of(int $amountMinor, string $currency = 'TRY'): self
    {
        $currency = strtoupper($currency);

        if (! isset(self::SCALE[$currency])) {
            throw new InvalidArgumentException("Unsupported currency: {$currency}");
        }

        return new self($amountMinor, $currency);
    }

    public static function zero(string $currency = 'TRY'): self
    {
        return self::of(0, $currency);
    }

    /**
     * Builds from a major-unit string such as "48900.50".
     *
     * Takes a string rather than a float on purpose: accepting a float would reintroduce
     * exactly the representation error this class exists to prevent, at the boundary
     * where it is hardest to notice.
     */
    public static function fromDecimalString(string $amount, string $currency = 'TRY'): self
    {
        $currency = strtoupper($currency);
        $scale = self::SCALE[$currency] ?? throw new InvalidArgumentException("Unsupported currency: {$currency}");
        $digits = (int) log10($scale);

        $normalised = self::normaliseSeparators($amount);

        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $normalised, $matches)) {
            throw new InvalidArgumentException("Not a valid decimal amount: {$amount}");
        }

        $sign = $matches[1] === '-' ? -1 : 1;
        $whole = (int) $matches[2];
        $fraction = str_pad(substr($matches[3] ?? '', 0, $digits), $digits, '0');

        return self::of($sign * (($whole * $scale) + (int) $fraction), $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountMinor + $other->amountMinor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountMinor - $other->amountMinor, $this->currency);
    }

    public function multiply(int $factor): self
    {
        return new self($this->amountMinor * $factor, $this->currency);
    }

    /**
     * Applies a rate expressed in basis points, rounding half up.
     *
     * Basis points rather than a percentage float: 12.5% is 1250 exactly, whereas
     * 0.125 is not representable and 12.5/100 compounds the error on every line.
     */
    public function percentage(int $basisPoints): self
    {
        if ($basisPoints < 0) {
            throw new InvalidArgumentException('Basis points cannot be negative.');
        }

        $product = $this->amountMinor * $basisPoints;

        // Round half away from zero, matching how invoices are rounded.
        $rounded = intdiv(abs($product) + 5_000, 10_000);

        return new self(($this->amountMinor < 0 ? -1 : 1) * $rounded, $this->currency);
    }

    /**
     * Splits an amount into parts weighted by ratios, distributing the remainder.
     *
     * This is the operation a marketplace actually needs: 100,00 ₺ across three
     * sellers is 33,34 + 33,33 + 33,33, never three amounts that sum to 99,99. The
     * remaining minor units are handed out one at a time from the largest ratio down,
     * so the parts always sum exactly back to the original.
     *
     * @param  array<int, int>  $ratios
     * @return array<int, self>
     */
    public function allocate(array $ratios): array
    {
        if ($ratios === []) {
            throw new InvalidArgumentException('Cannot allocate across zero parts.');
        }

        foreach ($ratios as $ratio) {
            if ($ratio < 0) {
                throw new InvalidArgumentException('Allocation ratios cannot be negative.');
            }
        }

        $total = array_sum($ratios);

        if ($total === 0) {
            throw new InvalidArgumentException('Allocation ratios cannot all be zero.');
        }

        $shares = [];
        $allocated = 0;

        foreach ($ratios as $index => $ratio) {
            $share = intdiv($this->amountMinor * $ratio, $total);
            $shares[$index] = $share;
            $allocated += $share;
        }

        $remainder = $this->amountMinor - $allocated;
        $order = array_keys($ratios);

        usort($order, static fn (int $a, int $b): int => $ratios[$b] <=> $ratios[$a]);

        $step = $remainder < 0 ? -1 : 1;

        for ($i = 0; $i < abs($remainder); $i++) {
            $shares[$order[$i % count($order)]] += $step;
        }

        ksort($shares);

        return array_map(fn (int $amount): self => new self($amount, $this->currency), $shares);
    }

    public function isZero(): bool
    {
        return $this->amountMinor === 0;
    }

    public function isPositive(): bool
    {
        return $this->amountMinor > 0;
    }

    public function isNegative(): bool
    {
        return $this->amountMinor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->amountMinor === $other->amountMinor && $this->currency === $other->currency;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amountMinor > $other->amountMinor;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amountMinor < $other->amountMinor;
    }

    public function negated(): self
    {
        return new self(-$this->amountMinor, $this->currency);
    }

    /** The major-unit value as a string; never used for arithmetic. */
    public function toDecimalString(): string
    {
        $scale = self::SCALE[$this->currency];
        $digits = (int) log10($scale);
        $sign = $this->amountMinor < 0 ? '-' : '';
        $absolute = abs($this->amountMinor);

        return sprintf(
            '%s%d.%0'.$digits.'d',
            $sign,
            intdiv($absolute, $scale),
            $absolute % $scale,
        );
    }

    /** Localised for display. Presentation only. */
    public function format(string $locale = 'tr_TR'): string
    {
        $symbol = match ($this->currency) {
            'TRY' => '₺',
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            default => $this->currency,
        };

        $scale = self::SCALE[$this->currency];
        $formatted = number_format(
            $this->amountMinor / $scale,
            (int) log10($scale),
            ',',
            '.',
        );

        return str_starts_with($locale, 'tr') ? "{$formatted} {$symbol}" : "{$symbol}{$formatted}";
    }

    /**
     * @return array{amount_minor: int, currency: string, formatted: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currency,
            'formatted' => $this->format(),
        ];
    }

    public function __toString(): string
    {
        return $this->format();
    }

    /**
     * Reduces a human-written amount to a plain decimal.
     *
     * Turkish notation groups with "." and separates the decimal with "," — the exact
     * opposite of English. Guessing wrong turns 1.234,56 ₺ into 1,23 ₺, so the rule is
     * mechanical: whichever separator appears last is the decimal one, and every other
     * separator is grouping.
     */
    private static function normaliseSeparators(string $amount): string
    {
        $trimmed = str_replace([' ', "\u{00A0}"], '', trim($amount));

        $lastComma = strrpos($trimmed, ',');
        $lastDot = strrpos($trimmed, '.');

        if ($lastComma !== false && $lastDot !== false) {
            return $lastComma > $lastDot
                ? str_replace(',', '.', str_replace('.', '', $trimmed))
                : str_replace(',', '', $trimmed);
        }

        if ($lastComma !== false) {
            return str_replace(',', '.', $trimmed);
        }

        return $trimmed;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Cannot combine {$this->currency} with {$other->currency}."
            );
        }
    }
}
