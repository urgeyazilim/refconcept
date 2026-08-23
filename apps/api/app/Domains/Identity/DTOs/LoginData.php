<?php

declare(strict_types=1);

namespace App\Domains\Identity\DTOs;

final readonly class LoginData
{
    public function __construct(
        public string $email,
        public string $password,
        public ?string $deviceName = null,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
    ) {}
}
