<?php

declare(strict_types=1);

namespace App\Domains\Ai\Enums;

/**
 * How far one unit of AI work has got.
 *
 *   queued ──> running ──> succeeded
 *      │           │
 *      │           ├──> failed
 *      └───────────┴──> cancelled
 *
 * `queued` and `running` are separate because they mean different things to a
 * customer watching and to an operator investigating: queued behind a backlog is a
 * capacity problem, running for ninety seconds is a provider problem, and collapsing
 * them into "in progress" hides which one you have.
 */
enum AiJobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Sırada',
            self::Running => 'Çalışıyor',
            self::Succeeded => 'Tamamlandı',
            self::Failed => 'Başarısız',
            self::Cancelled => 'İptal edildi',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled], true);
    }

    /** @return array<int, self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Queued => [self::Running, self::Cancelled, self::Failed],
            self::Running => [self::Succeeded, self::Failed],
            self::Succeeded, self::Failed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
