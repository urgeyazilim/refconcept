<?php

declare(strict_types=1);

namespace App\Domains\Products\Http\Controllers;

use App\Domains\Products\Enums\ModerationStatus;
use App\Domains\Products\Exceptions\ProductNotSubmittable;
use App\Domains\Products\Http\Resources\ProductResource;
use App\Domains\Products\Models\Product;
use App\Domains\Products\Services\ProductCompleteness;
use App\Domains\Products\Services\ProductModerationWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Platform moderation of product listings.
 *
 * Approval is what puts a listing in front of customers, so every decision carries a
 * mandatory reason, and a rejection can name the fields at fault — otherwise the
 * seller resubmits the same problem and the queue grows.
 */
final class AdminProductModerationController
{
    public function __construct(
        private readonly ProductModerationWorkflow $workflow,
        private readonly ProductCompleteness $completeness,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->can('viewModerationQueue', Product::class) === true, 403);

        $validated = $request->validate([
            'moderation_status' => ['sometimes', 'string'],
            'search' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Product::query()
            ->with(['organization', 'brand', 'primaryCategory', 'media', 'skus.dimensions', 'skus.seller'])
            ->orderBy('updated_at');

        if (isset($validated['moderation_status'])) {
            $query->where('moderation_status', $validated['moderation_status']);
        } else {
            // The default view is the reviewer's work queue, not the whole catalogue.
            $query->whereIn('moderation_status', [
                ModerationStatus::PendingReview->value,
                ModerationStatus::InReview->value,
            ]);
        }

        if (isset($validated['search'])) {
            $query->where('name', 'ilike', '%'.$validated['search'].'%');
        }

        return ProductResource::collection($query->paginate($validated['per_page'] ?? 20));
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()?->can('moderate', $product) === true, 403);

        $product->load([
            'organization',
            'brand',
            'primaryCategory.attributes',
            'style',
            'media',
            'attributeValues.attribute',
            'attributeValues.attributeValue',
            'skus.dimensions',
            'skus.seller',
            'moderationDecisions.decidedBy',
        ]);

        return response()->json([
            'data' => new ProductResource($product),
            'meta' => [
                'missing_requirements' => $this->completeness->missingRequirements($product),
                'completion_percent' => $this->completeness->completionPercent($product),
                'history' => $product->moderationDecisions
                    ->map(fn ($decision): array => [
                        'decision' => $decision->decision,
                        'reason' => $decision->reason,
                        'flagged_fields' => $decision->flagged_fields,
                        'decided_by' => $decision->decidedBy?->displayName(),
                        'decided_at' => $decision->decided_at->toIso8601String(),
                    ])->all(),
            ],
        ]);
    }

    public function startReview(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()?->can('moderate', $product) === true, 403);

        try {
            $this->workflow->startReview($product, $request->user());
        } catch (ProductNotSubmittable $e) {
            throw $e->toValidationException();
        }

        return response()->json([
            'message' => 'Ürün incelemeye alındı.',
            'data' => new ProductResource($product->fresh()),
        ]);
    }

    public function approve(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()?->can('moderate', $product) === true, 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        try {
            $this->workflow->approve($product, $request->user(), (string) $validated['reason']);
        } catch (ProductNotSubmittable $e) {
            throw $e->toValidationException();
        }

        return response()->json([
            'message' => 'Ürün onaylandı ve yayına alındı.',
            'data' => new ProductResource($product->fresh()),
        ]);
    }

    public function reject(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()?->can('moderate', $product) === true, 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],

            // Naming the fields is what makes a rejection actionable.
            'flagged_fields' => ['sometimes', 'array'],
            'flagged_fields.*' => ['string', 'max:60'],
        ]);

        try {
            $this->workflow->reject(
                $product,
                $request->user(),
                (string) $validated['reason'],
                $validated['flagged_fields'] ?? [],
            );
        } catch (ProductNotSubmittable $e) {
            throw $e->toValidationException();
        }

        return response()->json([
            'message' => 'Ürün reddedildi.',
            'data' => new ProductResource($product->fresh()),
        ]);
    }

    /** Pulls a published listing back for re-review, e.g. after a complaint. */
    public function recall(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()?->can('moderate', $product) === true, 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        try {
            $this->workflow->recall($product, $request->user(), (string) $validated['reason']);
        } catch (ProductNotSubmittable $e) {
            throw $e->toValidationException();
        }

        return response()->json([
            'message' => 'Ürün yayından alındı ve yeniden incelemeye konuldu.',
            'data' => new ProductResource($product->fresh()),
        ]);
    }
}
