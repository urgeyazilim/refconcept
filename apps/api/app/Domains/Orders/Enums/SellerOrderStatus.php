<?php

declare(strict_types=1);

namespace App\Domains\Orders\Enums;

/**
 * Where one seller's part of an order has got to.
 *
 *   awaiting_confirmation ──> confirmed ──> preparing ──> shipped ──> delivered
 *            │                    │             │                        │
 *            └────────────────────┴─────────────┘──> cancelled           └──> returned
 *
 * A seller's statuses are not the customer's, and flattening the two is the mistake this
 * enum exists to prevent. A customer's order is "shipped" when *everything* has gone; this
 * one is shipped when *this seller's parcel* has. On a three-seller order those are three
 * different days.
 *
 * `awaiting_confirmation` is a real state rather than a formality: a seller can be out of
 * stock in a way the ledger did not know about — a breakage, a mis-count — and finding
 * that out before the customer is told "on its way" is the whole point of asking.
 *
 * Once shipped, a seller cannot cancel. What happens after the parcel leaves is a return,
 * which is a different process with a different set of rights, and letting a seller press
 * "cancel" on something already in a van would leave money and goods in disagreement.
 */
enum SellerOrderStatus: string
{
    case AwaitingConfirmation = 'awaiting_confirmation';
    case Confirmed = 'confirmed';
    case Preparing = 'preparing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingConfirmation => 'Onay bekliyor',
            self::Confirmed => 'Onaylandı',
            self::Preparing => 'Hazırlanıyor',
            self::Shipped => 'Kargoya verildi',
            self::Delivered => 'Teslim edildi',
            self::Cancelled => 'İptal edildi',
            self::Returned => 'İade edildi',
        };
    }

    /** What the customer reads, which is not always what the seller reads. */
    public function customerLabel(): string
    {
        return match ($this) {
            self::AwaitingConfirmation, self::Confirmed => 'Hazırlanıyor',
            self::Preparing => 'Hazırlanıyor',
            self::Shipped => 'Kargoda',
            self::Delivered => 'Teslim edildi',
            self::Cancelled => 'İptal edildi',
            self::Returned => 'İade edildi',
        };
    }

    /**
     * The states this one may become.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::AwaitingConfirmation => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Preparing, self::Shipped, self::Cancelled],
            // Cancellable up to the moment it leaves: a seller who discovers a breakage
            // while packing should say so rather than ship a damaged item.
            self::Preparing => [self::Shipped, self::Cancelled],
            self::Shipped => [self::Delivered, self::Returned],
            self::Delivered => [self::Returned],
            self::Cancelled, self::Returned => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        // Being told the same thing twice is not a transition — a double-clicked button
        // must not be an error.
        return $this === $next || in_array($next, $this->allowedNext(), true);
    }

    /** Whether the seller may still act on this without an operator. */
    public function isOpen(): bool
    {
        return in_array($this, [
            self::AwaitingConfirmation,
            self::Confirmed,
            self::Preparing,
        ], true);
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled, self::Returned], true);
    }

    /** Whether the goods have left the seller. */
    public function hasShipped(): bool
    {
        return in_array($this, [self::Shipped, self::Delivered, self::Returned], true);
    }
}
