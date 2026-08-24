<?php

declare(strict_types=1);

use App\Support\ValueObjects\Money;

/**
 * Money is the foundation every financial figure in the platform rests on, so it is
 * tested against the ways money actually goes wrong: representation error, mixed
 * currencies, and remainders that vanish when an amount is split.
 */
it('holds an exact minor-unit amount', function (): void {
    $money = Money::of(4_890_000, 'TRY');

    expect($money->amountMinor)->toBe(4_890_000)
        ->and($money->currency)->toBe('TRY')
        ->and($money->toDecimalString())->toBe('48900.00');
});

it('parses a decimal string without floating point error', function (): void {
    // 0.1 + 0.2 !== 0.3 in binary floating point. Parsing as text sidesteps it entirely.
    $a = Money::fromDecimalString('0.10');
    $b = Money::fromDecimalString('0.20');

    expect($a->add($b)->equals(Money::fromDecimalString('0.30')))->toBeTrue()
        ->and($a->add($b)->amountMinor)->toBe(30);
});

it('accepts Turkish decimal separators', function (): void {
    expect(Money::fromDecimalString('1.234,56')->amountMinor)->toBe(123_456);
});

it('rejects a value that is not a decimal amount', function (): void {
    expect(fn () => Money::fromDecimalString('abc'))->toThrow(InvalidArgumentException::class);
});

it('rejects an unsupported currency', function (): void {
    expect(fn () => Money::of(100, 'XYZ'))->toThrow(InvalidArgumentException::class);
});

it('refuses to combine different currencies', function (): void {
    $try = Money::of(1000, 'TRY');
    $eur = Money::of(1000, 'EUR');

    // Silently adding these would produce a number that means nothing.
    expect(fn () => $try->add($eur))->toThrow(InvalidArgumentException::class);
});

it('adds, subtracts and multiplies exactly', function (): void {
    $price = Money::of(4_890_000);

    expect($price->add(Money::of(725_000))->amountMinor)->toBe(5_615_000)
        ->and($price->subtract(Money::of(890_000))->amountMinor)->toBe(4_000_000)
        ->and($price->multiply(3)->amountMinor)->toBe(14_670_000);
});

it('applies a basis-point rate with half-up rounding', function (): void {
    // 20% VAT on 48.900,00 ₺
    expect(Money::of(4_890_000)->percentage(2000)->amountMinor)->toBe(978_000);

    // 12.5% commission — the case a percentage float cannot represent exactly.
    expect(Money::of(4_890_000)->percentage(1250)->amountMinor)->toBe(611_250);

    // Rounds half away from zero rather than truncating.
    expect(Money::of(1)->percentage(5000)->amountMinor)->toBe(1);
});

it('rejects a negative rate', function (): void {
    expect(fn () => Money::of(1000)->percentage(-100))->toThrow(InvalidArgumentException::class);
});

it('allocates an indivisible amount without losing a single unit', function (): void {
    // 100,00 ₺ across three equal shares. Dividing gives 33,33 three times and loses
    // a kuruş; allocation must not.
    $parts = Money::of(10_000)->allocate([1, 1, 1]);

    expect(array_map(fn (Money $m): int => $m->amountMinor, $parts))->toBe([3_334, 3_333, 3_333]);

    $total = array_reduce(
        $parts,
        fn (Money $carry, Money $part): Money => $carry->add($part),
        Money::zero(),
    );

    expect($total->amountMinor)->toBe(10_000);
});

it('allocates by weight, giving the remainder to the largest share', function (): void {
    // A marketplace split: seller 70%, platform 30%, on an amount that does not divide.
    $parts = Money::of(10_001)->allocate([70, 30]);

    expect($parts[0]->amountMinor)->toBe(7_001)
        ->and($parts[1]->amountMinor)->toBe(3_000)
        ->and($parts[0]->add($parts[1])->amountMinor)->toBe(10_001);
});

it('allocates a negative amount without losing a unit', function (): void {
    // Refunds are negative allocations and must reconcile just as exactly.
    $parts = Money::of(-10_000)->allocate([1, 1, 1]);

    $total = array_reduce(
        $parts,
        fn (Money $carry, Money $part): Money => $carry->add($part),
        Money::zero(),
    );

    expect($total->amountMinor)->toBe(-10_000);
});

it('refuses meaningless allocations', function (): void {
    expect(fn () => Money::of(100)->allocate([]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::of(100)->allocate([0, 0]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::of(100)->allocate([1, -1]))->toThrow(InvalidArgumentException::class);
});

it('compares amounts', function (): void {
    expect(Money::of(1000)->greaterThan(Money::of(999)))->toBeTrue()
        ->and(Money::of(1000)->lessThan(Money::of(1001)))->toBeTrue()
        ->and(Money::of(0)->isZero())->toBeTrue()
        ->and(Money::of(-1)->isNegative())->toBeTrue();
});

it('formats for display without being usable for arithmetic', function (): void {
    expect(Money::of(4_890_000)->format())->toBe('48.900,00 ₺')
        ->and(Money::of(4_890_000, 'USD')->format('en_US'))->toBe('$48.900,00');
});

it('reads back an amount it wrote out', function (string $written): void {
    // Round-tripping is the property that matters: whatever the formatter produced,
    // the parser must return the same exact minor amount.
    expect(Money::fromDecimalString($written)->toDecimalString())->toBe($written);
})->with(['0.00', '0.01', '48900.00', '1234.56', '-1234.56']);

it('accepts English grouping as well as Turkish', function (): void {
    expect(Money::fromDecimalString('1,234.56')->amountMinor)->toBe(123_456)
        ->and(Money::fromDecimalString('1.234,56')->amountMinor)->toBe(123_456)
        ->and(Money::fromDecimalString('1234.56')->amountMinor)->toBe(123_456);
});

it('serialises with the minor amount alongside the display string', function (): void {
    $json = Money::of(4_890_000)->jsonSerialize();

    // Clients must receive the exact integer, not only a formatted string they might
    // parse back into a float.
    expect($json['amount_minor'])->toBe(4_890_000)
        ->and($json['currency'])->toBe('TRY')
        ->and($json['formatted'])->toBe('48.900,00 ₺');
});
