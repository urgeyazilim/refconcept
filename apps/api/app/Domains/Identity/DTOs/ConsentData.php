<?php

declare(strict_types=1);

namespace App\Domains\Identity\DTOs;

use App\Domains\Identity\Enums\ConsentType;

/**
 * One consent acceptance. The version matters as much as the flag: proving consent
 * later means proving *which text* was accepted.
 */
final readonly class ConsentData
{
    public function __construct(
        public ConsentType $type,
        public string $version,
        public bool $granted = true,
    ) {}

    /**
     * @param  array{type: string, version: string, granted?: bool}  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            type: ConsentType::from($payload['type']),
            version: $payload['version'],
            granted: $payload['granted'] ?? true,
        );
    }
}
