<?php

declare(strict_types=1);

namespace App\Domains\Projects\Enums;

/**
 * How far one attempt has got.
 *
 *   pending ──> generating ──> ready
 *                    │
 *                    └──────> failed
 *
 * `pending` and `generating` are different states on purpose: pending means the
 * request has been accepted and credits reserved, generating means a provider is
 * actually working. A customer watching a spinner deserves to know which, and a
 * refund of a version that never reached a provider is a different conversation
 * from one that did.
 */
enum DesignVersionStatus: string
{
    case Pending = 'pending';
    case Generating = 'generating';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Sırada',
            self::Generating => 'Oluşturuluyor',
            self::Ready => 'Hazır',
            self::Failed => 'Başarısız',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Ready, self::Failed], true);
    }

    /**
     * Whether a new version may branch from this one.
     *
     * Only from something that exists: branching from a failed or half-finished
     * attempt would ask the engine to refine an image nobody has.
     */
    public function canBranch(): bool
    {
        return $this === self::Ready;
    }

    /** @return array<int, self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Generating, self::Failed],
            self::Generating => [self::Ready, self::Failed],
            // A finished attempt is finished. Re-running produces a new version, which
            // is the entire point of keeping a tree.
            self::Ready, self::Failed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
