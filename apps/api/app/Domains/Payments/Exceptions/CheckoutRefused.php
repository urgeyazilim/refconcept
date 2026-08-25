<?php

declare(strict_types=1);

namespace App\Domains\Payments\Exceptions;

use App\Domains\Commerce\Exceptions\CartRefused;
use RuntimeException;

/**
 * The checkout would not proceed.
 *
 * Same shape as {@see CartRefused}: the message is
 * written for the customer, and the status travels with it so a controller cannot invent
 * a different answer for the same refusal. A 409 means the request was fine and the world
 * moved; a 422 means the request itself was wrong.
 */
final class CheckoutRefused extends RuntimeException
{
    private function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function noAddress(): self
    {
        return new self('Teslimat adresi seçmeniz gerekiyor.', 422);
    }

    public static function addressNotYours(): self
    {
        // 404 rather than 403: whether a stranger's address exists is not a thing to
        // confirm to somebody guessing at ids.
        return new self('Adres bulunamadı.', 404);
    }

    public static function emptyCart(): self
    {
        return new self('Sepetiniz boş.', 422);
    }

    public static function priceMoved(): self
    {
        return new self(
            'Sepetinizde fiyatı değişen ürünler var. Devam etmeden önce onaylayın.',
            409,
        );
    }

    public static function alreadyPaid(): self
    {
        return new self('Bu ödeme zaten tamamlandı.', 409);
    }

    public static function sessionClosed(): self
    {
        return new self('Bu ödeme oturumu artık geçerli değil. Sepetinize dönün.', 409);
    }

    public static function sessionExpired(): self
    {
        return new self(
            'Ödeme süresi doldu ve ayırdığımız stok serbest bırakıldı. Sepetinizden tekrar başlayın.',
            409,
        );
    }

    public static function paymentInFlight(): self
    {
        return new self('Bir ödeme hâlâ sürüyor. Sonucu bekleyin.', 409);
    }

    public static function packageUnavailable(): self
    {
        return new self('Bu kredi paketi artık satışta değil.', 422);
    }

    public static function verificationRequired(): self
    {
        return new self('Ödeme yapabilmek için e-posta adresinizi doğrulayın.', 403);
    }

    public static function notYours(): self
    {
        return new self('Ödeme oturumu bulunamadı.', 404);
    }
}
