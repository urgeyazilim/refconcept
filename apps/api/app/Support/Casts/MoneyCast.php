<?php

declare(strict_types=1);

namespace App\Support\Casts;

use App\Support\ValueObjects\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts a minor-unit integer column into a {@see Money}.
 *
 * The currency comes from a sibling column, so an amount can never be interpreted in
 * the wrong currency by accident. Registered as `MoneyCast::class.':currency'`; the
 * amount column is the attribute the cast is attached to.
 *
 * The point is that model code never sees a bare integer where a price belongs.
 * A bare integer invites `$a->price + $b->price` across currencies, or a division
 * that quietly loses a kuruş; a Money refuses both.
 *
 * @implements CastsAttributes<Money|null, Money|int|null>
 */
final class MoneyCast implements CastsAttributes
{
    public function __construct(private readonly string $currencyColumn = 'currency') {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        $currency = $attributes[$this->currencyColumn] ?? 'TRY';

        return Money::of((int) $value, (string) $currency);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if (is_int($value)) {
            return [$key => $value];
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException(
                sprintf('%s must be set from a Money or an integer of minor units.', $key)
            );
        }

        $existing = $attributes[$this->currencyColumn] ?? null;

        // Writing a EUR amount onto a row whose currency column says TRY would produce
        // a number that reads as the wrong figure forever after.
        if ($existing !== null && strtoupper((string) $existing) !== $value->currency) {
            throw new InvalidArgumentException(
                sprintf('Cannot store %s on a row denominated in %s.', $value->currency, $existing)
            );
        }

        return [
            $key => $value->amountMinor,
            $this->currencyColumn => $value->currency,
        ];
    }
}
