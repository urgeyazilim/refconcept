<?php

declare(strict_types=1);

namespace App\Domains\Products\Enums;

/**
 * How a seller's stock behaves.
 *
 * Only `track` consults a quantity. The other two exist because furniture is often
 * built to order or sourced on demand, and forcing those sellers to invent a stock
 * number would make every availability check a lie.
 */
enum StockPolicy: string
{
    case Track = 'track';
    case AlwaysAvailable = 'always_available';
    case MadeToOrder = 'made_to_order';

    public function tracksQuantity(): bool
    {
        return $this === self::Track;
    }

    public function label(): string
    {
        return match ($this) {
            self::Track => 'Stok takipli',
            self::AlwaysAvailable => 'Her zaman mevcut',
            self::MadeToOrder => 'Siparişe özel üretim',
        };
    }
}
