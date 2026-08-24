<?php

declare(strict_types=1);

namespace App\Domains\Products\Policies;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Services\AccessControl;
use App\Domains\Products\Models\Product;

/**
 * Who may see and change a product listing.
 *
 * The tenant rule again, in the place it matters most so far: a seller may do
 * anything to their own listings and nothing at all to a competitor's. Moderation is
 * platform-only — a seller approving their own listing would make review theatre.
 */
final class ProductPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        // Any signed-in user can list *their own* products; the query scopes by
        // organization, so this only gates access to the endpoint itself.
        return true;
    }

    public function view(User $user, Product $product): bool
    {
        // Anyone may see a published listing, signed in or not.
        if ($product->isPubliclyVisible()) {
            return true;
        }

        if ($this->access->hasPermission($user, Permission::OrganizationsView)) {
            return true;
        }

        return $this->ownsProduct($user, $product);
    }

    public function create(User $user): bool
    {
        // Listing requires an active seller account, which means membership of a seller
        // organization — not merely a verified customer account.
        return $this->access->organizationIds($user) !== [];
    }

    /**
     * Editing is the seller's, and only while the listing is not under review.
     *
     * Editing a listing a reviewer is currently looking at would mean approving
     * something nobody read.
     */
    public function update(User $user, Product $product): bool
    {
        return $this->ownsProduct($user, $product) && $product->isEditable();
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->ownsProduct($user, $product);
    }

    /**
     * Submitting is for a listing that is not already through the gate.
     *
     * An approved listing is editable, but there is nothing to submit: an edit re-queues
     * it on its own. Offering the button anyway would let a seller take their own live
     * product off sale for no reason and no benefit.
     */
    public function submit(User $user, Product $product): bool
    {
        return $this->ownsProduct($user, $product)
            && $product->isEditable()
            && ! $product->moderation_status->requiresRereview();
    }

    /** Pausing and reactivating a listing stays with the seller. */
    public function setStatus(User $user, Product $product): bool
    {
        return $this->ownsProduct($user, $product);
    }

    public function moderate(User $user, Product $product): bool
    {
        return $this->access->hasPermission($user, Permission::OrganizationsManage);
    }

    public function viewModerationQueue(User $user): bool
    {
        return $this->access->hasPermission($user, Permission::OrganizationsView);
    }

    private function ownsProduct(User $user, Product $product): bool
    {
        if ($product->organization_id === null) {
            // Platform-curated entries have no seller owner; only staff touch them.
            return false;
        }

        if (! $this->access->belongsToOrganization($user, $product->organization_id)) {
            return false;
        }

        return $this->access->hasPermission(
            $user,
            Permission::SellerProfileManage,
            $product->organization_id,
        );
    }
}
