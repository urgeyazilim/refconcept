<?php

declare(strict_types=1);

namespace App\Domains\Identity\DTOs;

/**
 * Everything needed to create an account, already validated by the form request.
 */
final readonly class RegistrationData
{
    /**
     * @param  array<int, ConsentData>  $consents
     */
    public function __construct(
        public string $email,
        public string $password,
        public array $consents,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $phone = null,
        public string $locale = 'tr',
        public string $timezone = 'Europe/Istanbul',
        public bool $marketingOptIn = false,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public ?string $deviceName = null,
    ) {}
}
