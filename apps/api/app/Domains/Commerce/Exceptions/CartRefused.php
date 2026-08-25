<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Exceptions;

use RuntimeException;

/**
 * The basket would not take it.
 *
 * Every message is written for the customer, because that is where it ends up. The status
 * travels with it so a controller cannot invent its own answer: a stock problem is a 409,
 * because the request was fine and the world changed underneath it, while a quantity of
 * two hundred is a 422 because the request itself was wrong.
 */
final class CartRefused extends RuntimeException
{
    private function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function notPurchasable(): self
    {
        return new self('Bu ürün şu anda satın alınamıyor.', 422);
    }

    public static function invalidQuantity(): self
    {
        return new self('Bir üründen en fazla 99 adet ekleyebilirsiniz.', 422);
    }

    public static function notEnoughStock(int $available): self
    {
        return new self(
            $available <= 0
                ? 'Bu ürün stokta kalmadı.'
                // The number, because "yetersiz stok" leaves the customer guessing at what
                // quantity would work and trying again until it does.
                : sprintf('Bu üründen yalnızca %d adet kaldı.', $available),
            409,
        );
    }

    public static function stockVanished(string $detail): self
    {
        return new self(
            'Sepetinizdeki bir ürün ödeme başlarken tükendi: '.$detail,
            409,
        );
    }

    public static function notEditable(): self
    {
        return new self(
            'Ödeme adımındaki bir sepet değiştirilemez. Önce ödemeden vazgeçin.',
            409,
        );
    }

    public static function notYours(): self
    {
        // A 404 rather than a 403: whether somebody else's cart line exists is not a thing
        // to confirm to a stranger.
        return new self('Bu satır bulunamadı.', 404);
    }

    public static function empty(): self
    {
        return new self('Sepetiniz boş.', 422);
    }
}
