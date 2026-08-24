<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Services;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Identity\Enums\SystemRole;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserRole;
use App\Domains\Organizations\Enums\MembershipStatus;
use App\Domains\Organizations\Enums\OrganizationStatus;
use App\Domains\Organizations\Enums\OrganizationType;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\OrganizationUser;
use App\Domains\Sellers\Enums\ApplicationStatus;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Exceptions\InvalidTransition;
use App\Domains\Sellers\Models\Seller;
use App\Domains\Sellers\Models\SellerApplication;
use App\Domains\Sellers\Models\SellerStatusHistory;
use App\Domains\Sellers\Notifications\ApplicationApproved;
use App\Domains\Sellers\Notifications\ApplicationRejected;
use App\Domains\Sellers\Notifications\ApplicationSubmitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only place a seller application changes status.
 *
 * Status is not an attribute anyone assigns; it is the outcome of a transition that
 * was checked, recorded and audited. Controllers call these methods and nothing else
 * writes `seller_applications.status` or `sellers.status`.
 *
 * Approving is the interesting one: it is not a flag flip but the creation of a
 * tenant. An organization, a seller, the applicant's membership and their role grant
 * all appear together or not at all — a seller without a membership would be a
 * company nobody can sign into, and a membership without a role grants access to
 * nothing.
 */
final class ApplicationWorkflow
{
    public function __construct(
        private readonly OnboardingChecklist $checklist,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Applicant submits a completed application for review.
     *
     * @throws InvalidTransition
     */
    public function submit(SellerApplication $application, User $actor): SellerApplication
    {
        $this->assertCanTransition($application, ApplicationStatus::Submitted);

        $missing = $this->checklist->missingSteps($application);

        if ($missing !== []) {
            throw InvalidTransition::incomplete($missing);
        }

        return DB::transaction(function () use ($application, $actor): SellerApplication {
            $this->transition(
                application: $application,
                to: ApplicationStatus::Submitted,
                actor: $actor,
                attributes: ['submitted_at' => now()],
            );

            $application->applicant?->notify(new ApplicationSubmitted($application));

            return $application;
        });
    }

    /** An operator picks the application up, so it stops looking unattended. */
    public function startReview(SellerApplication $application, User $actor): SellerApplication
    {
        $this->assertCanTransition($application, ApplicationStatus::InReview);

        return DB::transaction(fn (): SellerApplication => $this->transition(
            application: $application,
            to: ApplicationStatus::InReview,
            actor: $actor,
        ));
    }

    /**
     * Approves the application and brings the seller into existence.
     *
     * @throws InvalidTransition
     */
    public function approve(
        SellerApplication $application,
        User $actor,
        string $reason,
        ?int $commissionBps = null,
    ): Seller {
        $this->assertCanTransition($application, ApplicationStatus::Approved);

        $missing = $this->checklist->missingSteps($application);

        if ($missing !== []) {
            throw InvalidTransition::incomplete($missing);
        }

        return DB::transaction(function () use ($application, $actor, $reason, $commissionBps): Seller {
            $organization = Organization::query()->create([
                'name' => $application->company_name,
                'slug' => $this->uniqueSlug($application->display_name),
                'type' => OrganizationType::Seller,
                'status' => OrganizationStatus::Active,
                'owner_user_id' => $application->applicant_user_id,
            ]);

            OrganizationUser::query()->create([
                'organization_id' => $organization->getKey(),
                'user_id' => $application->applicant_user_id,
                'status' => MembershipStatus::Active,
                'joined_at' => now(),
            ]);

            // Membership says which tenant; the role grant says with what authority.
            // Both are required, so both are created here.
            $ownerRole = Role::query()
                ->where('slug', SystemRole::SellerOwner->value)
                ->where('scope', SystemRole::SellerOwner->scope()->value)
                ->firstOrFail();

            UserRole::query()->create([
                'user_id' => $application->applicant_user_id,
                'role_id' => $ownerRole->getKey(),
                'organization_id' => $organization->getKey(),
                'granted_by' => $actor->getKey(),
                'granted_at' => now(),
            ]);

            $seller = Seller::query()->create([
                'organization_id' => $organization->getKey(),
                'application_id' => $application->getKey(),
                'seller_code' => $this->uniqueSellerCode(),
                'display_name' => $application->display_name,
                'default_commission_bps' => $commissionBps,
            ]);

            $seller->forceFill([
                'approved_at' => now(),
                'approved_by' => $actor->getKey(),
            ])->save();

            $this->transition(
                application: $application,
                to: ApplicationStatus::Approved,
                actor: $actor,
                reason: $reason,
                attributes: [
                    'organization_id' => $organization->getKey(),
                    'reviewed_at' => now(),
                    'reviewed_by' => $actor->getKey(),
                    'decision_reason' => $reason,
                ],
            );

            SellerStatusHistory::query()->create([
                'seller_id' => $seller->getKey(),
                'application_id' => $application->getKey(),
                'from_status' => null,
                'to_status' => SellerStatus::Active->value,
                'reason' => $reason,
                'changed_by' => $actor->getKey(),
                'changed_at' => now(),
            ]);

            $this->audit->record(
                action: 'sellers.application.approved',
                subject: $application,
                context: [
                    'seller_id' => $seller->getKey(),
                    'organization_id' => $organization->getKey(),
                    'commission_bps' => $commissionBps,
                ],
                reason: $reason,
                actor: $actor,
                organizationId: (string) $organization->getKey(),
            );

            $application->applicant?->notify(new ApplicationApproved($application, $seller));

            return $seller;
        });
    }

    /**
     * @throws InvalidTransition
     */
    public function reject(SellerApplication $application, User $actor, string $reason): SellerApplication
    {
        $this->assertCanTransition($application, ApplicationStatus::Rejected);

        return DB::transaction(function () use ($application, $actor, $reason): SellerApplication {
            $this->transition(
                application: $application,
                to: ApplicationStatus::Rejected,
                actor: $actor,
                reason: $reason,
                attributes: [
                    'reviewed_at' => now(),
                    'reviewed_by' => $actor->getKey(),
                    'decision_reason' => $reason,
                ],
            );

            $this->audit->record(
                action: 'sellers.application.rejected',
                subject: $application,
                reason: $reason,
                actor: $actor,
            );

            $application->applicant?->notify(new ApplicationRejected($application, $reason));

            return $application;
        });
    }

    /** The applicant changes their mind. Their own action, so no reason is demanded. */
    public function withdraw(SellerApplication $application, User $actor): SellerApplication
    {
        $this->assertCanTransition($application, ApplicationStatus::Withdrawn);

        return DB::transaction(fn (): SellerApplication => $this->transition(
            application: $application,
            to: ApplicationStatus::Withdrawn,
            actor: $actor,
            reason: 'Başvuru sahibi tarafından geri çekildi.',
        ));
    }

    /**
     * Suspends a trading seller.
     *
     * Deliberately does not touch their data or their obligations: open orders still
     * have to be fulfilled and settled. It stops them selling, nothing else.
     */
    public function suspendSeller(Seller $seller, User $actor, string $reason): Seller
    {
        if ($seller->status === SellerStatus::Suspended) {
            return $seller;
        }

        return DB::transaction(function () use ($seller, $actor, $reason): Seller {
            $from = $seller->status;

            $seller->forceFill([
                'status' => SellerStatus::Suspended,
                'suspended_at' => now(),
            ])->save();

            $this->recordSellerStatus($seller, $from->value, SellerStatus::Suspended->value, $reason, $actor);

            $this->audit->record(
                action: 'sellers.seller.suspended',
                subject: $seller,
                reason: $reason,
                actor: $actor,
                organizationId: $seller->organization_id,
            );

            return $seller;
        });
    }

    /**
     * Reactivation is a high-risk action (06_SECURITY_PAYMENT_FINANCE_RULES.md), so it
     * carries a mandatory reason and lands in the audit trail like a payout does.
     */
    public function reactivateSeller(Seller $seller, User $actor, string $reason): Seller
    {
        if ($seller->status === SellerStatus::Active) {
            return $seller;
        }

        return DB::transaction(function () use ($seller, $actor, $reason): Seller {
            $from = $seller->status;

            $seller->forceFill([
                'status' => SellerStatus::Active,
                'suspended_at' => null,
            ])->save();

            $this->recordSellerStatus($seller, $from->value, SellerStatus::Active->value, $reason, $actor);

            $this->audit->record(
                action: 'sellers.seller.reactivated',
                subject: $seller,
                reason: $reason,
                actor: $actor,
                organizationId: $seller->organization_id,
            );

            return $seller;
        });
    }

    /**
     * @throws InvalidTransition
     */
    private function assertCanTransition(SellerApplication $application, ApplicationStatus $target): void
    {
        if (! $application->status->canTransitionTo($target)) {
            throw InvalidTransition::between($application->status, $target);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transition(
        SellerApplication $application,
        ApplicationStatus $to,
        User $actor,
        ?string $reason = null,
        array $attributes = [],
    ): SellerApplication {
        $from = $application->status;

        $application->forceFill([...$attributes, 'status' => $to])->save();

        SellerStatusHistory::query()->create([
            'application_id' => $application->getKey(),
            'from_status' => $from->value,
            'to_status' => $to->value,
            'reason' => $reason,
            'changed_by' => $actor->getKey(),
            'changed_at' => now(),
        ]);

        return $application;
    }

    private function recordSellerStatus(
        Seller $seller,
        string $from,
        string $to,
        string $reason,
        User $actor,
    ): void {
        SellerStatusHistory::query()->create([
            'seller_id' => $seller->getKey(),
            'application_id' => $seller->application_id,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'changed_by' => $actor->getKey(),
            'changed_at' => now(),
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'satici';
        $slug = $base;
        $suffix = 2;

        while (Organization::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * A short human-quotable code for support and invoices, deliberately separate from
     * the UUID primary key (05_ARCHITECTURE_AND_CODE_RULES.md, "IDs / Time").
     */
    private function uniqueSellerCode(): string
    {
        do {
            $code = 'RC-'.strtoupper(Str::random(6));
        } while (Seller::withTrashed()->where('seller_code', $code)->exists());

        return $code;
    }
}
