<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Enums;

/**
 * Why a stock balance changed.
 *
 * The type says what happened; the *sign* of the movement says which way the balance
 * went, and is stored rather than derived so that summing a column is just summing a
 * column. Reserve and release move `reserved` without touching `on_hand` — the goods
 * are still in the warehouse, they are simply spoken for.
 */
enum MovementType: string
{
    /** Goods arrived. */
    case Receipt = 'receipt';

    /** A correction the seller made by hand, with a reason. */
    case Adjustment = 'adjustment';

    /** The result of a physical count, which overrides whatever was recorded. */
    case Stocktake = 'stocktake';

    case Reserve = 'reserve';
    case Release = 'release';

    /** Reserved goods left the building. */
    case Dispatch = 'dispatch';

    case Return_ = 'return';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Giriş',
            self::Adjustment => 'Düzeltme',
            self::Stocktake => 'Sayım',
            self::Reserve => 'Rezerve edildi',
            self::Release => 'Rezerv kaldırıldı',
            self::Dispatch => 'Sevk edildi',
            self::Return_ => 'İade',
        };
    }

    /** Whether this type changes what physically exists, as opposed to what is held. */
    public function movesOnHand(): bool
    {
        return match ($this) {
            self::Reserve, self::Release => false,
            default => true,
        };
    }

    /**
     * Whether a seller may create this movement directly.
     *
     * Reserve, release and dispatch belong to the order flow. A seller adjusting
     * `reserved` by hand would desynchronise it from the reservations that explain it.
     */
    public function isSellerInitiated(): bool
    {
        return match ($this) {
            self::Receipt, self::Adjustment, self::Stocktake, self::Return_ => true,
            self::Reserve, self::Release, self::Dispatch => false,
        };
    }
}
