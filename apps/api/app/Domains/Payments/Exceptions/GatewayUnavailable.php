<?php

declare(strict_types=1);

namespace App\Domains\Payments\Exceptions;

use RuntimeException;

/**
 * There is no provider that can take this payment.
 *
 * A configuration fault, not a payment fault — which is why it is a 503 and not a 422.
 * The customer did nothing wrong, trying again in a moment might genuinely work, and the
 * message says so without naming which provider is missing: an error page is not the
 * place to publish which payment integrations we have switched off today.
 */
final class GatewayUnavailable extends RuntimeException
{
    private function __construct(string $message, public readonly string $gateway)
    {
        parent::__construct($message);
    }

    public static function unknown(string $name): self
    {
        return new self('Ödeme sağlayıcısı tanımlı değil.', $name);
    }

    public static function disabled(string $name): self
    {
        return new self('Ödeme şu anda alınamıyor. Lütfen daha sonra tekrar deneyin.', $name);
    }
}
