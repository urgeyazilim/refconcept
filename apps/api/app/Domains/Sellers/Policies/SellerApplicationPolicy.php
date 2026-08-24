<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Policies;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Services\AccessControl;
use App\Domains\Sellers\Models\SellerApplication;

/**
 * Who may see and act on a seller application.
 *
 * Two audiences with completely different rights:
 *
 *  - the **applicant**, who may read and edit their own draft and withdraw it, but
 *    can never decide it;
 *  - **platform staff**, who may read every application and decide it, but never edit
 *    the applicant's answers — an operator editing a tax number and then approving it
 *    would destroy the meaning of the record.
 */
final class SellerApplicationPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->hasPermission($user, Permission::OrganizationsView);
    }

    public function view(User $user, SellerApplication $application): bool
    {
        if ($this->isApplicant($user, $application)) {
            return true;
        }

        return $this->access->hasPermission($user, Permission::OrganizationsView);
    }

    /** Anyone with a verified account may apply; being a seller is not a prerequisite. */
    public function create(User $user): bool
    {
        return $user->isVerified();
    }

    /** Only the applicant, and only while it is still a draft. */
    public function update(User $user, SellerApplication $application): bool
    {
        return $this->isApplicant($user, $application) && $application->isEditable();
    }

    public function submit(User $user, SellerApplication $application): bool
    {
        return $this->isApplicant($user, $application) && $application->isEditable();
    }

    public function withdraw(User $user, SellerApplication $application): bool
    {
        return $this->isApplicant($user, $application) && ! $application->status->isFinal();
    }

    /** Deciding is a platform action, never the applicant's. */
    public function decide(User $user, SellerApplication $application): bool
    {
        return $this->access->hasPermission($user, Permission::OrganizationsManage);
    }

    public function reviewDocuments(User $user, SellerApplication $application): bool
    {
        return $this->access->hasPermission($user, Permission::OrganizationsManage);
    }

    private function isApplicant(User $user, SellerApplication $application): bool
    {
        return $application->applicant_user_id === $user->getKey();
    }
}
