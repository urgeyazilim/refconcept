<?php

declare(strict_types=1);

namespace App\Domains\Products\Services;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Identity\Models\User;
use App\Domains\Products\Enums\ModerationStatus;
use App\Domains\Products\Enums\ProductStatus;
use App\Domains\Products\Enums\SkuStatus;
use App\Domains\Products\Exceptions\ProductNotSubmittable;
use App\Domains\Products\Models\Product;
use App\Domains\Products\Models\ProductModeration;
use App\Domains\Products\Models\ProductStatusHistory;
use Illuminate\Support\Facades\DB;

/**
 * The only place a product's moderation status changes.
 *
 * Approval is what makes a listing publicly visible, so it is not something a
 * controller sets on a model. Every transition is checked against the state machine,
 * recorded in history, and audited.
 */
final class ProductModerationWorkflow
{
    public function __construct(
        private readonly ProductCompleteness $completeness,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Seller sends a listing for review.
     *
     * @throws ProductNotSubmittable
     */
    public function submit(Product $product, User $actor): Product
    {
        $this->assertCanTransition($product, ModerationStatus::PendingReview);

        $missing = $this->completeness->missingRequirements($product);

        if ($missing !== []) {
            throw ProductNotSubmittable::incomplete($missing);
        }

        return DB::transaction(function () use ($product, $actor): Product {
            $this->transition($product, ModerationStatus::PendingReview, $actor);

            $this->audit->record(
                action: 'products.product.submitted',
                subject: $product,
                actor: $actor,
                organizationId: $product->organization_id,
            );

            return $product;
        });
    }

    public function startReview(Product $product, User $actor): Product
    {
        $this->assertCanTransition($product, ModerationStatus::InReview);

        return DB::transaction(fn (): Product => $this->transition($product, ModerationStatus::InReview, $actor));
    }

    /**
     * Approves a listing and publishes it.
     *
     * Publishing sets `published_at`, which a database constraint refuses unless the
     * moderation status is approved — so the two can never disagree.
     *
     * Approval also puts the listing on sale: it sets the seller's status to active if
     * it was still a draft, and promotes draft offers to active. Sending a listing for
     * review *is* the request to sell it, and without this an approved product sits in
     * the catalogue's blind spot — approved, complete, and invisible, because
     * `publiclyVisible()` requires an active product with a purchasable offer. A
     * seller who wants it off sale afterwards pauses it, which needs no second review.
     *
     * Offers the seller deliberately paused or marked out of stock are left alone;
     * only `draft` is promoted, because that is the state a new offer starts in and
     * never leaves on its own.
     */
    public function approve(Product $product, User $actor, string $reason): Product
    {
        $this->assertCanTransition($product, ModerationStatus::Approved);

        $missing = $this->completeness->missingRequirements($product);

        if ($missing !== []) {
            throw ProductNotSubmittable::incomplete($missing);
        }

        return DB::transaction(function () use ($product, $actor, $reason): Product {
            $this->transition($product, ModerationStatus::Approved, $actor, $reason);

            $product->forceFill([
                'published_at' => $product->published_at ?? now(),
                'status' => $product->status === ProductStatus::Draft
                    ? ProductStatus::Active
                    : $product->status,
            ])->save();

            $product->skus()
                ->where('status', SkuStatus::Draft->value)
                ->update(['status' => SkuStatus::Active->value]);

            ProductModeration::query()->create([
                'product_id' => $product->getKey(),
                'decision' => 'approved',
                'reason' => $reason,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
            ]);

            $this->audit->record(
                action: 'products.product.approved',
                subject: $product,
                reason: $reason,
                actor: $actor,
                organizationId: $product->organization_id,
            );

            return $product;
        });
    }

    /**
     * Rejects a listing, naming the fields at fault.
     *
     * @param  array<int, string>  $flaggedFields
     */
    public function reject(Product $product, User $actor, string $reason, array $flaggedFields = []): Product
    {
        $this->assertCanTransition($product, ModerationStatus::Rejected);

        return DB::transaction(function () use ($product, $actor, $reason, $flaggedFields): Product {
            /*
             * A rejected listing is also pulled out of publication. Leaving an approved
             * product published while its later review said no would keep a rejected
             * listing on sale.
             */
            $this->transition($product, ModerationStatus::Rejected, $actor, $reason, ['published_at' => null]);

            ProductModeration::query()->create([
                'product_id' => $product->getKey(),
                'decision' => 'rejected',
                'reason' => $reason,
                'flagged_fields' => $flaggedFields === [] ? null : $flaggedFields,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
            ]);

            $this->audit->record(
                action: 'products.product.rejected',
                subject: $product,
                context: ['flagged_fields' => $flaggedFields],
                reason: $reason,
                actor: $actor,
                organizationId: $product->organization_id,
            );

            return $product;
        });
    }

    /**
     * Pulls an approved listing back for re-review, e.g. after a complaint.
     */
    public function recall(Product $product, User $actor, string $reason): Product
    {
        $this->assertCanTransition($product, ModerationStatus::InReview);

        return DB::transaction(function () use ($product, $actor, $reason): Product {
            // Unpublished for the duration: a listing under suspicion should not stay
            // on sale while it is being looked at.
            $this->transition($product, ModerationStatus::InReview, $actor, $reason, ['published_at' => null]);

            $this->audit->record(
                action: 'products.product.recalled',
                subject: $product,
                reason: $reason,
                actor: $actor,
                organizationId: $product->organization_id,
            );

            return $product;
        });
    }

    /**
     * A seller changed a published listing, so it goes back to the queue.
     *
     * This is what makes an approved listing editable without making moderation
     * pointless: the seller can fix a typo or swap a photograph, but what a customer
     * sees is always something a reviewer looked at. `published_at` is cleared in the
     * same statement as the status, because a database constraint refuses a published
     * row that is not approved.
     *
     * A no-op unless the listing was approved — a draft being edited is just a draft.
     */
    public function contentChanged(Product $product, User $actor): Product
    {
        if (! $product->moderation_status->requiresRereview()) {
            return $product;
        }

        return DB::transaction(function () use ($product, $actor): Product {
            $this->transition(
                $product,
                ModerationStatus::PendingReview,
                $actor,
                'Satıcı yayındaki üründe değişiklik yaptı.',
                ['published_at' => null],
            );

            $this->audit->record(
                action: 'products.product.resubmitted_after_edit',
                subject: $product,
                actor: $actor,
                organizationId: $product->organization_id,
            );

            return $product;
        });
    }

    /**
     * Seller switches their own listing between active and paused.
     *
     * Deliberately independent of moderation: pausing a listing must not require
     * another review to undo, or sellers would stop pausing and start deleting.
     */
    public function setSellerStatus(Product $product, User $actor, ProductStatus $status): Product
    {
        return DB::transaction(function () use ($product, $actor, $status): Product {
            $from = $product->status;

            $product->forceFill(['status' => $status])->save();

            ProductStatusHistory::query()->create([
                'product_id' => $product->getKey(),
                'field' => 'status',
                'from_status' => $from->value,
                'to_status' => $status->value,
                'changed_by' => $actor->getKey(),
                'changed_at' => now(),
            ]);

            // Archiving the product takes its offers off sale too; an active SKU under
            // an archived product would still be reachable by direct link.
            if ($status === ProductStatus::Archived) {
                $product->skus()->update(['status' => SkuStatus::Archived->value]);
            }

            // Resuming puts back exactly what archiving took away. Only offers that
            // were archived are restored: one the seller had already paused stays
            // paused, or resuming a listing would quietly undo a separate decision.
            if ($status === ProductStatus::Active) {
                $product->skus()
                    ->where('status', SkuStatus::Archived->value)
                    ->update(['status' => SkuStatus::Active->value]);
            }

            return $product;
        });
    }

    private function assertCanTransition(Product $product, ModerationStatus $target): void
    {
        if (! $product->moderation_status->canTransitionTo($target)) {
            throw ProductNotSubmittable::badTransition($product->moderation_status, $target);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transition(
        Product $product,
        ModerationStatus $to,
        User $actor,
        ?string $reason = null,
        array $attributes = [],
    ): Product {
        $from = $product->moderation_status;

        /*
         * A database constraint refuses a published row whose moderation status is not
         * approved. Clearing published_at in a later save would mean the intermediate
         * row violates it, so both columns move in one UPDATE.
         */
        $product->forceFill([...$attributes, 'moderation_status' => $to])->save();

        ProductStatusHistory::query()->create([
            'product_id' => $product->getKey(),
            'field' => 'moderation_status',
            'from_status' => $from->value,
            'to_status' => $to->value,
            'reason' => $reason,
            'changed_by' => $actor->getKey(),
            'changed_at' => now(),
        ]);

        return $product;
    }
}
