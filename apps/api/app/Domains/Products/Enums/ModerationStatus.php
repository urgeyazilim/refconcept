<?php

declare(strict_types=1);

namespace App\Domains\Products\Enums;

use App\Domains\Products\Services\ProductModerationWorkflow;

/**
 * Where a product stands with the moderation team.
 *
 *   draft ──submit──> pending_review ──pick up──> in_review ──> approved
 *                            │                        │
 *                            └────────────────────────┴──> rejected ──resubmit──> pending_review
 *
 * A rejected product can be fixed and resubmitted; that is the whole point of
 * recording which fields were flagged.
 */
enum ModerationStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * Whether the seller may still edit the listing content.
     *
     * Locked only while a reviewer is actually looking at it: editing a listing
     * somebody is mid-review on would mean approving something nobody read.
     *
     * An **approved** listing stays editable, because the alternative is a marketplace
     * where a live listing can never have a typo fixed or a better photograph added.
     * The price of that is that an edit sends it back for review — see
     * {@see ProductModerationWorkflow::contentChanged()} — so nothing reaches a
     * customer that a reviewer did not see.
     */
    public function isEditable(): bool
    {
        return match ($this) {
            self::Draft, self::Rejected, self::Approved => true,
            self::PendingReview, self::InReview => false,
        };
    }

    /** Whether an edit to this listing has to go back through moderation. */
    public function requiresRereview(): bool
    {
        return $this === self::Approved;
    }

    /** @return array<int, self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingReview],
            self::PendingReview => [self::InReview, self::Approved, self::Rejected],
            self::InReview => [self::Approved, self::Rejected],
            self::Rejected => [self::PendingReview],
            // Approved is not final. A complaint pulls it into re-review (the operator's
            // recall), and the seller editing it sends it back to the queue.
            self::Approved => [self::InReview, self::PendingReview],
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
            self::PendingReview => 'İnceleme bekliyor',
            self::InReview => 'İnceleniyor',
            self::Approved => 'Onaylandı',
            self::Rejected => 'Reddedildi',
        };
    }
}
