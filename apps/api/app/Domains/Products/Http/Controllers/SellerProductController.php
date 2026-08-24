<?php

declare(strict_types=1);

namespace App\Domains\Products\Http\Controllers;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Catalog\Models\Attribute;
use App\Domains\Identity\Services\AccessControl;
use App\Domains\Products\Enums\ProductStatus;
use App\Domains\Products\Exceptions\ProductNotSubmittable;
use App\Domains\Products\Http\Requests\StoreProductRequest;
use App\Domains\Products\Http\Requests\StoreSkuRequest;
use App\Domains\Products\Http\Requests\UpdateProductRequest;
use App\Domains\Products\Http\Resources\ProductResource;
use App\Domains\Products\Models\Product;
use App\Domains\Products\Models\ProductSku;
use App\Domains\Products\Services\ProductCompleteness;
use App\Domains\Products\Services\ProductModerationWorkflow;
use App\Domains\Sellers\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A seller's own product listings.
 *
 * Every query is scoped to the organizations the signed-in user actually belongs to,
 * and every single-product route goes through the policy on top of that. The scope
 * alone would be enough for the list endpoints; the policy is what protects the ones
 * that take an id.
 */
final class SellerProductController
{
    public function __construct(
        private readonly ProductCompleteness $completeness,
        private readonly ProductModerationWorkflow $workflow,
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $organizationIds = $this->access->organizationIds($request->user());

        abort_if($organizationIds === [], 403, 'Satıcı hesabınız bulunmuyor.');

        $validated = $request->validate([
            'status' => ['sometimes', 'string'],
            'moderation_status' => ['sometimes', 'string'],
            'search' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        // `skus.seller` is not decoration: ProductResource's "from" price runs through
        // ProductSku::isAvailable(), which asks the seller whether it may trade. Without
        // it every row lazy-loads — or, with lazy loading disabled, the whole list 500s.
        $query = Product::query()
            ->with(['brand', 'primaryCategory', 'media', 'skus.dimensions', 'skus.seller'])
            ->whereIn('organization_id', $organizationIds)
            ->orderByDesc('updated_at');

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['moderation_status'])) {
            $query->where('moderation_status', $validated['moderation_status']);
        }

        if (isset($validated['search'])) {
            $query->where('name', 'ilike', '%'.$validated['search'].'%');
        }

        return ProductResource::collection($query->paginate($validated['per_page'] ?? 20));
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $this->resolveOrganization($request);

        $product = DB::transaction(function () use ($request, $user, $organizationId): Product {
            $validated = $request->validated();

            // `attributes` and `organization_id` are inputs to this method, not columns
            // on the model: the first is synced through the pivot below, and the second
            // is resolved from the signed-in user rather than trusted from the body.
            $product = Product::query()->create([
                ...Arr::except($validated, ['attributes', 'organization_id']),
                'organization_id' => $organizationId,
                'slug' => $this->uniqueSlug($validated['name']),
                'created_by' => $user->getKey(),
            ]);

            $this->syncAttributes($product, $request->input('attributes', []));

            return $product;
        });

        $this->audit->record(
            action: 'products.product.created',
            subject: $product,
            actor: $user,
            organizationId: $organizationId,
        );

        return response()->json([
            'data' => new ProductResource($this->loadFully($product)),
            'meta' => $this->meta($product),
        ], 201);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, 'view', $product);

        return response()->json([
            'data' => new ProductResource($this->loadFully($product)),
            'meta' => $this->meta($product),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, 'update', $product);

        DB::transaction(function () use ($request, $product): void {
            $product->fill(Arr::except($request->validated(), ['attributes', 'organization_id']))->save();

            if ($request->has('attributes')) {
                $this->syncAttributes($product, $request->input('attributes', []));
            }

            // A published listing that changed has to be looked at again before a
            // customer sees the change.
            $this->workflow->contentChanged($product, $request->user());
        });

        return response()->json([
            'data' => new ProductResource($this->loadFully($product->fresh())),
            'meta' => $this->meta($product->fresh()),
        ]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, 'delete', $product);

        // Soft delete: an order placed against this listing still has to be
        // explainable, and hard-deleting the row would orphan that history.
        $product->delete();

        $this->audit->record(
            action: 'products.product.deleted',
            subject: $product,
            actor: $request->user(),
            organizationId: $product->organization_id,
        );

        return response()->json(['message' => 'Ürün kaldırıldı.']);
    }

    public function submit(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, 'submit', $product);

        try {
            $this->workflow->submit($product, $request->user());
        } catch (ProductNotSubmittable $e) {
            throw $e->toValidationException();
        }

        return response()->json([
            'message' => 'Ürün incelemeye gönderildi.',
            'data' => new ProductResource($this->loadFully($product->fresh())),
            'meta' => $this->meta($product->fresh()),
        ]);
    }

    /** Seller pauses or resumes their own listing without another review. */
    public function setStatus(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, 'setStatus', $product);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(ProductStatus::class)],
        ]);

        $this->workflow->setSellerStatus(
            $product,
            $request->user(),
            ProductStatus::from((string) $validated['status']),
        );

        return response()->json([
            'data' => new ProductResource($this->loadFully($product->fresh())),
        ]);
    }

    // --- SKUs ----------------------------------------------------------------

    public function storeSku(StoreSkuRequest $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, 'update', $product);

        $seller = $this->sellerFor($product);
        $validated = $request->validated();

        $sku = DB::transaction(function () use ($request, $product, $seller, $validated): ProductSku {
            $sku = ProductSku::query()->create([
                'product_id' => $product->getKey(),
                'seller_id' => $seller->getKey(),
                'sku' => $validated['sku'],
                'barcode' => $validated['barcode'] ?? null,
                'variant_label' => $validated['variant_label'] ?? null,
                'currency' => $validated['currency'] ?? 'TRY',
                'list_price_minor' => $validated['list_price_minor'],
                'sale_price_minor' => $validated['sale_price_minor'] ?? null,
                'tax_rate_bps' => $validated['tax_rate_bps'] ?? 2000,
                'stock_policy' => $validated['stock_policy'] ?? 'track',
                'stock_quantity' => $validated['stock_quantity'] ?? 0,
                'lead_time_days' => $validated['lead_time_days'] ?? 3,
            ]);

            if (isset($validated['dimensions'])) {
                $sku->dimensions()->create($validated['dimensions']);
            }

            $this->workflow->contentChanged($product, $request->user());

            return $sku;
        });

        return response()->json([
            'data' => new ProductResource($this->loadFully($product->fresh())),
            'meta' => $this->meta($product->fresh()),
            'sku_id' => $sku->getKey(),
        ], 201);
    }

    public function updateSku(StoreSkuRequest $request, Product $product, ProductSku $sku): JsonResponse
    {
        $this->authorizeProduct($request, 'update', $product);

        // The SKU must belong to the product in the path; otherwise a seller could edit
        // another seller's offer by pairing their own product id with its SKU id.
        abort_unless($sku->product_id === $product->getKey(), 404);

        $validated = $request->validated();

        DB::transaction(function () use ($request, $product, $sku, $validated): void {
            // Dimensions live on their own table; passing them to fill() would be a
            // mass-assignment error rather than a silent no-op.
            $sku->fill(Arr::except($validated, ['dimensions']))->save();

            if (isset($validated['dimensions'])) {
                $sku->dimensions()->updateOrCreate(
                    ['sku_id' => $sku->getKey()],
                    $validated['dimensions'],
                );
            }

            $this->workflow->contentChanged($product, $request->user());
        });

        return response()->json([
            'data' => new ProductResource($this->loadFully($product->fresh())),
            'meta' => $this->meta($product->fresh()),
        ]);
    }

    public function destroySku(Request $request, Product $product, ProductSku $sku): JsonResponse
    {
        $this->authorizeProduct($request, 'update', $product);
        abort_unless($sku->product_id === $product->getKey(), 404);

        $sku->delete();

        $this->workflow->contentChanged($product, $request->user());

        return response()->json(['message' => 'Satış seçeneği kaldırıldı.']);
    }

    // --- helpers -------------------------------------------------------------

    /**
     * @param  array<int, array{code: string, value: mixed}>  $attributes
     */
    private function syncAttributes(Product $product, array $attributes): void
    {
        $definitions = Attribute::query()
            ->whereIn('code', array_column($attributes, 'code'))
            ->with('values')
            ->get()
            ->keyBy('code');

        $product->attributeValues()->delete();

        foreach ($attributes as $input) {
            $attribute = $definitions->get($input['code'] ?? '');

            if ($attribute === null) {
                continue;
            }

            $payload = [
                'product_id' => $product->getKey(),
                'attribute_id' => $attribute->getKey(),
            ];

            // The value lands in the column matching the attribute's declared type, so
            // a numeric filter later compares numbers rather than parsing strings.
            if ($attribute->isSelectable()) {
                $value = $attribute->values->firstWhere('value', $input['value']);

                if ($value === null) {
                    throw ValidationException::withMessages([
                        'attributes' => ["'{$attribute->name}' için geçersiz değer."],
                    ]);
                }

                $payload['attribute_value_id'] = $value->getKey();
            } else {
                $payload += match ($attribute->data_type) {
                    'integer' => ['value_integer' => (int) $input['value']],
                    'decimal' => ['value_decimal' => (string) $input['value']],
                    'boolean' => ['value_boolean' => (bool) $input['value']],
                    default => ['value_text' => (string) $input['value']],
                };
            }

            $product->attributeValues()->create($payload);
        }
    }

    private function resolveOrganization(Request $request): string
    {
        $organizationIds = $this->access->organizationIds($request->user());

        abort_if($organizationIds === [], 403, 'Satıcı hesabınız bulunmuyor.');

        $requested = $request->input('organization_id');

        if ($requested !== null) {
            // A seller may belong to more than one organization, but only to their own.
            if (! in_array((string) $requested, $organizationIds, true)) {
                abort(403);
            }

            return (string) $requested;
        }

        return (string) $organizationIds[0];
    }

    private function sellerFor(Product $product): Seller
    {
        $seller = Seller::query()->where('organization_id', $product->organization_id)->first();

        abort_if($seller === null, 422, 'Bu organizasyona bağlı onaylı satıcı hesabı yok.');

        return $seller;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'urun';
        $slug = $base;
        $suffix = 2;

        while (Product::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function loadFully(Product $product): Product
    {
        return $product->load([
            'brand',
            'primaryCategory',
            'style',
            'media',
            'attributeValues.attribute',
            'attributeValues.attributeValue',
            'skus.dimensions',
            'skus.seller',
        ]);
    }

    private function authorizeProduct(Request $request, string $ability, Product $product): void
    {
        abort_unless($request->user()?->can($ability, $product) === true, 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(Product $product): array
    {
        $missing = $this->completeness->missingRequirements($product);

        return [
            'missing_requirements' => $missing,
            'completion_percent' => $this->completeness->completionPercent($product),
            'can_submit' => $product->isEditable() && $missing === [],
        ];
    }
}
