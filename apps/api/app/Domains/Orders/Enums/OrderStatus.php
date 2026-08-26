<?php

declare(strict_types=1);

namespace App\Domains\Orders\Enums;

/**
 * Where the customer's order — the whole of it — has got to.
 *
 * **Derived, not set.** Nobody moves a master order by hand: it is computed from its
 * seller orders every time one of them changes, because it is a summary of them and a
 * summary that can be written independently is a summary that will eventually disagree
 * with what it summarises.
 *
 * `partially_shipped` exists for the same reason the seller split exists. On a three-seller
 * order the parcels leave on three different days, and a customer told "shipped" who then
 * waits a week for the other two has been misled by a status that was technically true.
 */
enum OrderStatus: string
{
    case Paid = 'paid';
    case Processing = 'processing';
    case PartiallyShipped = 'partially_shipped';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Ödendi',
            self::Processing => 'Hazırlanıyor',
            self::PartiallyShipped => 'Kısmen kargoda',
            self::Shipped => 'Kargoda',
            self::Delivered => 'Teslim edildi',
            self::Cancelled => 'İptal edildi',
            self::Refunded => 'İade edildi',
            self::PartiallyRefunded => 'Kısmen iade edildi',
        };
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled, self::Refunded], true);
    }

    /**
     * Works out the whole from its parts.
     *
     * The rules, in the order they are applied:
     *   - every part cancelled → the order is cancelled
     *   - every part finished and at least one delivered → delivered
     *   - every live part shipped → shipped
     *   - some shipped, some not → partially_shipped
     *   - anything past confirmation → processing
     *   - otherwise → paid
     *
     * Cancelled parts are excluded from "have they all shipped": on a two-seller order
     * where one seller cancels, the customer's order is shipped once the other one goes,
     * not stuck forever waiting on a parcel nobody is sending.
     *
     * @param  list<SellerOrderStatus>  $parts
     */
    public static function fromSellerOrders(array $parts): self
    {
        if ($parts === []) {
            return self::Paid;
        }

        $live = array_values(array_filter(
            $parts,
            static fn (SellerOrderStatus $status): bool => $status !== SellerOrderStatus::Cancelled,
        ));

        if ($live === []) {
            return self::Cancelled;
        }

        $delivered = array_filter($live, static fn (SellerOrderStatus $s): bool => $s === SellerOrderStatus::Delivered);

        if (count($delivered) === count($live)) {
            return self::Delivered;
        }

        $shipped = array_filter($live, static fn (SellerOrderStatus $s): bool => $s->hasShipped());

        if (count($shipped) === count($live)) {
            return self::Shipped;
        }

        if ($shipped !== []) {
            return self::PartiallyShipped;
        }

        $moving = array_filter(
            $live,
            static fn (SellerOrderStatus $s): bool => $s !== SellerOrderStatus::AwaitingConfirmation,
        );

        return $moving === [] ? self::Paid : self::Processing;
    }
}
