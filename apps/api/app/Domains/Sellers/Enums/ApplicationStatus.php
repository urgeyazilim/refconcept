<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Enums;

use App\Domains\Sellers\Services\ApplicationWorkflow;

/**
 * The seller application state machine. Mirrored by a CHECK constraint.
 *
 *   draft ──submit──> submitted ──pick up──> in_review ──> approved
 *     │                   │                      │
 *     └──withdraw─────────┴──────────────────────┴──> rejected / withdrawn
 *
 * Transitions run through {@see ApplicationWorkflow}
 * so no controller can move an application sideways.
 */
enum ApplicationStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    /** Whether the applicant may still edit the form. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Whether this state is final; a decided application never moves again. */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Approved, self::Rejected, self::Withdrawn => true,
            default => false,
        };
    }

    /** @return array<int, self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted, self::Withdrawn],
            self::Submitted => [self::InReview, self::Approved, self::Rejected, self::Withdrawn],
            self::InReview => [self::Approved, self::Rejected],
            self::Approved, self::Rejected, self::Withdrawn => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Taslak',
            self::Submitted => 'Gönderildi',
            self::InReview => 'İncelemede',
            self::Approved => 'Onaylandı',
            self::Rejected => 'Reddedildi',
            self::Withdrawn => 'Geri çekildi',
        };
    }
}
