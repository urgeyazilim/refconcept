<?php

declare(strict_types=1);

namespace App\Domains\Fulfilment\Exceptions;

use RuntimeException;

/**
 * A shipment, return or refund would not proceed.
 *
 * Written for whoever pressed the button — usually a seller or a customer — with the
 * status travelling alongside so a controller cannot invent a different answer.
 */
final class FulfilmentRefused extends RuntimeException
{
    private function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function nothingToShip(): self
    {
        return new self('Kargoya verilecek ürün seçilmedi.', 422);
    }

    public static function tooMany(string $product, int $remaining): self
    {
        // The number, because "fazla" leaves a seller guessing at what would work.
        return new self(
            sprintf('%s için kargoya verilebilecek en fazla %d adet kaldı.', $product, max(0, $remaining)),
            422,
        );
    }

    public static function lineNotYours(): self
    {
        return new self('Bu sipariş satırı bulunamadı.', 404);
    }

    public static function alreadyClosed(string $status): self
    {
        return new self(sprintf('Bu sipariş artık kargolanamaz (%s).', $status), 409);
    }

    public static function returnWindowClosed(int $days): self
    {
        return new self(
            sprintf('İade süresi doldu. Teslimattan sonra %d gün içinde iade talebi oluşturabilirsiniz.', $days),
            422,
        );
    }

    public static function notDelivered(): self
    {
        return new self('Henüz teslim edilmemiş bir sipariş iade edilemez.', 422);
    }

    public static function nothingToReturn(): self
    {
        return new self('İade edilecek ürün seçilmedi.', 422);
    }

    public static function alreadyReturned(string $product): self
    {
        return new self(sprintf('%s için zaten açık bir iade talebiniz var.', $product), 409);
    }

    public static function tooManyToReturn(string $product, int $remaining): self
    {
        return new self(
            sprintf('%s için en fazla %d adet iade edebilirsiniz.', $product, max(0, $remaining)),
            422,
        );
    }

    public static function badReturnTransition(string $from, string $to): self
    {
        return new self(
            sprintf('İade "%s" durumundan "%s" durumuna geçemez.', $from, $to),
            409,
        );
    }

    public static function reasonRequired(): self
    {
        return new self('Bir gerekçe yazmanız gerekiyor.', 422);
    }

    public static function notYours(): self
    {
        // 404 rather than 403: whether somebody else's return exists is not a thing to
        // confirm to a stranger.
        return new self('İade kaydı bulunamadı.', 404);
    }

    public static function refundTooLarge(int $remaining): self
    {
        return new self(
            sprintf('İade tutarı kalan tutardan büyük olamaz (kalan: %d kuruş).', max(0, $remaining)),
            422,
        );
    }

    public static function refundAlreadyOpen(): self
    {
        return new self('Bu iade için zaten süren bir ücret iadesi var.', 409);
    }

    public static function refundNotRetryable(string $status): self
    {
        return new self(sprintf('Bu ücret iadesi tekrar denenemez (%s).', $status), 409);
    }
}
