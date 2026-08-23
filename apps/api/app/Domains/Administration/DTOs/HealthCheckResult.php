<?php

declare(strict_types=1);

namespace App\Domains\Administration\DTOs;

use App\Domains\Administration\Enums\HealthStatus;

final readonly class HealthCheckResult
{
    public function __construct(
        public string $name,
        public HealthStatus $status,
        public ?string $message = null,
        public ?float $durationMs = null,
    ) {}

    public static function ok(string $name, ?string $message = null, ?float $durationMs = null): self
    {
        return new self($name, HealthStatus::Ok, $message, $durationMs);
    }

    public static function degraded(string $name, string $message, ?float $durationMs = null): self
    {
        return new self($name, HealthStatus::Degraded, $message, $durationMs);
    }

    public static function down(string $name, string $message, ?float $durationMs = null): self
    {
        return new self($name, HealthStatus::Down, $message, $durationMs);
    }

    /**
     * @return array{status: string, message?: string, duration_ms?: float}
     */
    public function toArray(): array
    {
        $payload = ['status' => $this->status->value];

        if ($this->message !== null) {
            $payload['message'] = $this->message;
        }

        if ($this->durationMs !== null) {
            $payload['duration_ms'] = round($this->durationMs, 2);
        }

        return $payload;
    }
}
