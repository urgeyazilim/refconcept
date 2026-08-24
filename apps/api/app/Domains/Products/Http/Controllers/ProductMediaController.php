<?php

declare(strict_types=1);

namespace App\Domains\Products\Http\Controllers;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Products\Http\Resources\ProductResource;
use App\Domains\Products\Models\Product;
use App\Domains\Products\Models\ProductMedia;
use App\Domains\Products\Services\ProductImageStorage;
use App\Domains\Products\Services\ProductModerationWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Product imagery, owned by the seller.
 *
 * Authorisation is on the *product*, never on the media row: an image is only ever
 * reachable through the listing it belongs to, which is what stops one seller
 * rearranging another's gallery by guessing ids. The `update` ability is the right
 * one for all four verbs — adding, renaming, reordering and removing an image are
 * all edits to the listing.
 */
final class ProductMediaController
{
    public function __construct(
        private readonly ProductImageStorage $storage,
        private readonly ProductModerationWorkflow $workflow,
        private readonly AuditLogger $audit,
    ) {}

    public function store(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.(ProductImageStorage::MAX_SIZE_BYTES / 1024),
                'mimetypes:'.implode(',', ProductImageStorage::ALLOWED_MIME_TYPES),
            ],
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);

        try {
            $media = $this->storage->store(
                product: $product,
                file: $request->file('file'),
                uploader: $request->user(),
                altText: $request->input('alt_text'),
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
        }

        $this->audit->record(
            action: 'products.media.uploaded',
            subject: $media,
            context: ['product_id' => $product->getKey(), 'size_bytes' => $media->size_bytes],
            actor: $request->user(),
            organizationId: $product->organization_id,
        );

        $this->afterChange($request, $product);

        return response()->json([
            'data' => new ProductResource($this->reload($product)),
        ], 201);
    }

    /** Alt text only. Position is a whole-gallery operation, so it lives in reorder(). */
    public function update(Request $request, Product $product, ProductMedia $medium): JsonResponse
    {
        $this->authorizeProduct($request, $product);
        $this->assertBelongsTo($medium, $product);

        $validated = $request->validate([
            'alt_text' => ['required', 'nullable', 'string', 'max:300'],
        ]);

        $medium->update(['alt_text' => $validated['alt_text']]);

        $this->afterChange($request, $product);

        return response()->json([
            'data' => new ProductResource($this->reload($product)),
        ]);
    }

    public function reorder(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        $validated = $request->validate([
            'media' => ['required', 'array', 'min:1'],
            'media.*' => ['string', 'uuid'],
        ]);

        /** @var array<int, string> $ids */
        $ids = $validated['media'];

        $this->storage->reorder($product, $ids);

        $this->afterChange($request, $product);

        return response()->json([
            'data' => new ProductResource($this->reload($product)),
        ]);
    }

    public function destroy(Request $request, Product $product, ProductMedia $medium): JsonResponse
    {
        $this->authorizeProduct($request, $product);
        $this->assertBelongsTo($medium, $product);

        $this->storage->delete($medium);

        $this->audit->record(
            action: 'products.media.deleted',
            subject: $medium,
            context: ['product_id' => $product->getKey()],
            actor: $request->user(),
            organizationId: $product->organization_id,
        );

        $this->afterChange($request, $product);

        return response()->json([
            'data' => new ProductResource($this->reload($product)),
        ]);
    }

    /**
     * Changing the gallery of a published listing sends it back for review.
     *
     * The photographs are most of what a reviewer actually looks at, so swapping one
     * after approval without another look is exactly the hole the moderation gate
     * exists to close.
     */
    private function afterChange(Request $request, Product $product): void
    {
        $this->workflow->contentChanged($product, $request->user());
    }

    private function reload(Product $product): Product
    {
        // `skus.seller` for the same reason as everywhere else a product is serialised:
        // the "from" price asks each offer whether its seller may trade.
        return $product->fresh(['media', 'brand', 'primaryCategory', 'skus.dimensions', 'skus.seller']);
    }

    private function assertBelongsTo(ProductMedia $medium, Product $product): void
    {
        // 404 rather than 403: confirming that the id exists elsewhere is itself a leak.
        abort_unless($medium->product_id === $product->getKey(), 404);
    }

    private function authorizeProduct(Request $request, Product $product): void
    {
        abort_unless($request->user()?->can('update', $product) === true, 403);
    }
}
