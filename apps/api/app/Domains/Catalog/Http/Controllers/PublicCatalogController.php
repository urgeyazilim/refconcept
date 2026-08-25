<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Http\Controllers;

use App\Domains\Catalog\Models\Attribute;
use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Color;
use App\Domains\Catalog\Models\Material;
use App\Domains\Catalog\Models\Style;
use App\Domains\Commerce\Services\CatalogSearch;
use App\Domains\Products\Http\Resources\ProductResource;
use App\Domains\Products\Models\Product;
use App\Domains\Products\Models\ProductSku;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * The public catalogue.
 *
 * Unauthenticated by design — this is what a visitor and a search engine see. Every
 * query goes through `publiclyVisible()`, which is the single definition of "may be
 * shown": approved by moderation, activated by the seller, and carrying at least one
 * purchasable offer. Nothing here can accidentally expose a draft, because nothing
 * here builds its own visibility condition.
 */
final class PublicCatalogController
{
    public function __construct(private readonly CatalogSearch $search) {}

    /** The category tree, for navigation and filters. */
    public function categories(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'room_type' => ['sometimes', 'string', 'max:40'],
        ]);

        // Ordered by the materialised path, which is what makes the flat list read as
        // a tree: every child follows its parent. Ordering by `position` alone sorts
        // each depth independently and interleaves branches, so a select built from it
        // shows "Kanepe" three entries above the "Oturma Grubu" it belongs to.
        $query = Category::query()->active()->orderBy('path');

        if (isset($validated['room_type'])) {
            $query->where('room_type', $validated['room_type']);
        }

        $categories = $query->get();

        return response()->json([
            'data' => $categories->map(fn (Category $category): array => [
                'id' => $category->id,
                'parent_id' => $category->parent_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'path' => $category->path,
                'depth' => $category->depth,
                'room_type' => $category->room_type,
            ])->all(),
        ]);
    }

    /**
     * The attributes a category expects, with their permitted values.
     *
     * The seller's product form is built from this rather than from a hard-coded list:
     * a category that starts demanding "seat depth" should grow the field by itself,
     * not by a front-end release. `is_required` comes off the pivot, which is what
     * {@see AppDomainsProductsServicesProductCompleteness} reads too, so the
     * form and the submission gate can never disagree about what is mandatory.
     */
    public function categoryAttributes(string $slug): JsonResponse
    {
        $category = Category::query()->active()->where('slug', $slug)->first();

        abort_if($category === null, 404);

        $category->load(['attributes.values']);

        return response()->json([
            'data' => $category->attributes->map(function (Attribute $attribute): array {
                /** @var object{is_required: bool} $pivot */
                $pivot = $attribute->getRelation('pivot');

                return [
                    'code' => $attribute->code,
                    'name' => $attribute->name,
                    'data_type' => $attribute->data_type,
                    'unit' => $attribute->unit,
                    'is_required' => $pivot->is_required === true,
                    'is_variant_defining' => $attribute->is_variant_defining,
                    'values' => $attribute->values
                        ->map(fn ($value): array => [
                            'value' => $value->value,
                            'label' => $value->label,
                        ])->all(),
                ];
            })->all(),
        ]);
    }

    /** Brands, for the seller's product form and the storefront's brand filter. */
    public function brands(): JsonResponse
    {
        return response()->json([
            'data' => Brand::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Brand $brand): array => [
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'slug' => $brand->slug,
                ])->all(),
        ]);
    }

    /** The descriptive vocabulary, so the storefront can render filters. */
    public function vocabulary(): JsonResponse
    {
        return response()->json([
            'data' => [
                'colors' => Color::query()->orderBy('position')->get()
                    ->map(fn (Color $color): array => [
                        'code' => $color->code,
                        'name' => $color->name,
                        'hex' => $color->hex,
                        'family' => $color->family,
                    ])->all(),

                'materials' => Material::query()->orderBy('position')->get()
                    ->map(fn (Material $material): array => [
                        'code' => $material->code,
                        'name' => $material->name,
                        'family' => $material->family,
                    ])->all(),

                // Styles carry their id as well as their code: the storefront filters
                // by code, but a seller's product form has to submit a style_id.
                'styles' => Style::query()->orderBy('position')->get()
                    ->map(fn (Style $style): array => [
                        'id' => $style->id,
                        'code' => $style->code,
                        'name' => $style->name,
                        'description' => $style->description,
                    ])->all(),
            ],
        ]);
    }

    public function products(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'category' => ['sometimes', 'string', 'max:180'],
            'room_type' => ['sometimes', 'string', 'max:40'],
            'style' => ['sometimes', 'string', 'max:60'],
            'search' => ['sometimes', 'string', 'max:120'],

            // Budget filters arrive in minor units, like every other amount.
            'min_price_minor' => ['sometimes', 'integer', 'min:0'],
            'max_price_minor' => ['sometimes', 'integer', 'min:0'],

            'sort' => ['sometimes', 'string', 'in:newest,price_asc,price_desc,name'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:60'],
        ]);

        $query = Product::query()
            ->publiclyVisible()
            ->with(['brand', 'primaryCategory', 'style', 'media', 'skus.dimensions', 'skus.seller']);

        if (isset($validated['category'])) {
            $category = Category::query()->where('slug', $validated['category'])->first();

            if ($category !== null) {
                // Matches the branch and everything under it, via the materialised path.
                $descendantIds = Category::query()->underPath($category->path)->pluck('id');
                $query->whereIn('primary_category_id', $descendantIds);
            } else {
                // An unknown slug returns nothing rather than everything: silently
                // ignoring the filter would show a customer the whole catalogue and look
                // like the filter is broken.
                $query->whereRaw('1 = 0');
            }
        }

        if (isset($validated['room_type'])) {
            $query->whereHas('primaryCategory', function (Builder $category) use ($validated): void {
                $category->where('room_type', $validated['room_type']);
            });
        }

        if (isset($validated['style'])) {
            $query->whereHas('style', function (Builder $style) use ($validated): void {
                $style->where('code', $validated['style']);
            });
        }

        /*
         * Hybrid search: name, description and meaning, fused by rank.
         *
         * Three methods rather than one because a furniture search box receives three
         * kinds of thing — a misspelled name, words from a description, and a description
         * of a feeling — and no single index answers all three. See CatalogSearch for why
         * the fusion is by rank rather than by score.
         */
        $ranked = null;

        if (isset($validated['search'])) {
            $ranked = $this->search->rank(
                (string) $validated['search'],
                $validated['room_type'] ?? null,
            );
        }

        // Price filters compare against the effective price of a purchasable offer,
        // not the list price of any SKU — otherwise a paused, expensive variant would
        // keep a product out of a budget the customer can actually afford.
        if (isset($validated['min_price_minor']) || isset($validated['max_price_minor'])) {
            $query->whereHas('skus', function (Builder $skus) use ($validated): void {
                /** @var Builder<ProductSku> $skus */
                $skus->purchasable()
                    ->when(
                        isset($validated['min_price_minor']),
                        fn (Builder $q) => $q->whereRaw(
                            'COALESCE(sale_price_minor, list_price_minor) >= ?',
                            [$validated['min_price_minor']],
                        ),
                    )
                    ->when(
                        isset($validated['max_price_minor']),
                        fn (Builder $q) => $q->whereRaw(
                            'COALESCE(sale_price_minor, list_price_minor) <= ?',
                            [$validated['max_price_minor']],
                        ),
                    );
            });
        }

        /*
         * Facets are counted before the ranking is applied and before pagination, so they
         * describe the whole result set rather than the page being looked at. Counting
         * after would show "modern (4)" on a page that happens to contain four.
         */
        $facets = $this->search->facets(clone $query);

        if ($ranked !== null) {
            $this->search->applyRanking($query, $ranked);

            // Relevance is its own order and overriding it with "newest" would throw away
            // the whole point of having searched.
            return ProductResource::collection($query->paginate($validated['per_page'] ?? 24))
                ->additional(['facets' => $facets]);
        }

        match ($validated['sort'] ?? 'newest') {
            'price_asc' => $query->orderBy(
                $this->cheapestOfferSubquery(),
            ),
            'price_desc' => $query->orderByDesc(
                $this->cheapestOfferSubquery(),
            ),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('published_at'),
        };

        return ProductResource::collection($query->paginate($validated['per_page'] ?? 24))
            ->additional(['facets' => $facets]);
    }

    public function product(string $slug): JsonResponse
    {
        $product = Product::query()
            ->publiclyVisible()
            ->where('slug', $slug)
            ->with([
                'brand',
                'primaryCategory',
                'style',
                'media',
                'attributeValues.attribute',
                'attributeValues.attributeValue',
                'skus.dimensions',
                'skus.seller',
            ])
            ->first();

        abort_if($product === null, 404);

        return response()->json(['data' => new ProductResource($product)]);
    }

    /**
     * The cheapest purchasable offer, as a scalar subquery for ordering.
     *
     * Sorting on a joined price column would multiply rows by offer count and make
     * pagination lie about how many products exist.
     */
    private function cheapestOfferSubquery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('product_skus')
            ->selectRaw('MIN(COALESCE(sale_price_minor, list_price_minor))')
            ->whereColumn('product_skus.product_id', 'products.id')
            ->where('product_skus.status', 'active')
            ->whereNull('product_skus.deleted_at');
    }
}
