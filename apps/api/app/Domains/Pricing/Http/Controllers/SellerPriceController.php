<?php

declare(strict_types=1);

namespace App\Domains\Pricing\Http\Controllers;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Identity\Services\AccessControl;
use App\Domains\Pricing\Models\PriceHistory;
use App\Domains\Pricing\Models\PriceList;
use App\Domains\Pricing\Services\PriceBook;
use App\Domains\Products\Models\ProductSku;
use App\Domains\Sellers\Models\Seller;
use App\Support\ValueObjects\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A seller's prices and the record of how they got that way.
 *
 * Amounts arrive as **integer minor units**, like everywhere else on this API. The
 * bulk endpoint exists because changing four hundred prices one request at a time is
 * how a seller ends up with a half-applied campaign when their connection drops.
 */
final class SellerPriceController
{
    public function __construct(
        private readonly PriceBook $prices,
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
    ) {}

    /** The seller's SKUs with their current effective price and where it came from. */
    public function index(Request $request): JsonResponse
    {
        $seller = $this->seller($request);

        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ProductSku::query()
            ->with('product')
            ->where('seller_id', $seller->getKey())
            ->orderBy('sku');

        if (isset($validated['search'])) {
            $term = $validated['search'];

            $query->where(function ($inner) use ($term): void {
                $inner->where('sku', 'ilike', '%'.$term.'%')
                    ->orWhereHas('product', fn ($product) => $product->where('name', 'ilike', '%'.$term.'%'));
            });
        }

        $skus = $query->paginate($validated['per_page'] ?? 50);

        return response()->json([
            'data' => collect($skus->items())->map(function (ProductSku $sku): array {
                $resolved = $this->prices->resolve($sku);

                return [
                    'sku_id' => $sku->id,
                    'sku' => $sku->sku,
                    'product_name' => $sku->product?->name,
                    'variant_label' => $sku->variant_label,
                    'list_price' => $resolved['list_price']->jsonSerialize(),
                    'sale_price' => $resolved['sale_price']?->jsonSerialize(),
                    'effective_price' => $resolved['effective']->jsonSerialize(),
                    'tax_rate_bps' => $sku->tax_rate_bps,
                    // Which list is in force, so a seller can see why a price is not
                    // what they typed on the product form.
                    'price_source' => $resolved['source'],
                ];
            })->all(),
            'meta' => [
                'current_page' => $skus->currentPage(),
                'last_page' => $skus->lastPage(),
                'per_page' => $skus->perPage(),
                'total' => $skus->total(),
            ],
        ]);
    }

    /**
     * Changes many prices at once.
     *
     * One transaction: a bulk change is a single decision the seller made, and half of
     * it landing is worse than none of it. The whole set is small enough for that to
     * be safe — the spreadsheet path is where thousands of rows go, and it commits
     * row by row for exactly the opposite reason.
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $seller = $this->seller($request);

        $validated = $request->validate([
            'price_list_id' => ['sometimes', 'nullable', 'uuid'],
            'prices' => ['required', 'array', 'min:1', 'max:500'],
            'prices.*.sku_id' => ['required', 'uuid'],
            'prices.*.list_price_minor' => ['required', 'integer', 'min:0', 'max:99999999999'],
            'prices.*.sale_price_minor' => ['nullable', 'integer', 'min:0', 'max:99999999999'],
        ]);

        // isset() already excludes null; a second check would only read as a doubt.
        $list = isset($validated['price_list_id'])
            ? $this->listFor($seller, (string) $validated['price_list_id'])
            : null;

        /** @var array<int, array{sku_id: string, list_price_minor: int, sale_price_minor: int|null}> $prices */
        $prices = $validated['prices'];

        $skuIds = array_column($prices, 'sku_id');

        $skus = ProductSku::query()
            ->whereIn('id', $skuIds)
            ->where('seller_id', $seller->getKey())
            ->get()
            ->keyBy('id');

        // Refused wholesale rather than partially applied: a payload containing another
        // seller's SKU is either an attack or a serious client bug, and neither is
        // something to half-honour.
        foreach ($skuIds as $index => $skuId) {
            if (! $skus->has($skuId)) {
                throw ValidationException::withMessages([
                    "prices.{$index}.sku_id" => ['Bu satış seçeneği size ait değil.'],
                ]);
            }
        }

        try {
            DB::transaction(function () use ($prices, $skus, $list, $request): void {
                foreach ($prices as $entry) {
                    /** @var ProductSku $sku */
                    $sku = $skus->get($entry['sku_id']);

                    $listPrice = Money::of((int) $entry['list_price_minor'], $sku->currency);
                    $salePrice = ($entry['sale_price_minor'] ?? null) === null
                        ? null
                        : Money::of((int) $entry['sale_price_minor'], $sku->currency);

                    if ($list === null) {
                        $this->prices->setPrice($sku, $listPrice, $salePrice, $request->user());
                    } else {
                        $this->prices->setListPrice($list, $sku, $listPrice, $salePrice, $request->user());
                    }
                }
            });
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['prices' => [$e->getMessage()]]);
        }

        $this->audit->record(
            action: 'pricing.prices.bulk_updated',
            subject: $seller,
            context: ['count' => count($prices), 'price_list_id' => $list?->getKey()],
            actor: $request->user(),
            organizationId: $seller->organization_id,
        );

        return response()->json([
            'message' => sprintf('%d fiyat güncellendi.', count($prices)),
        ]);
    }

    /** Every change to one SKU's price, newest first. */
    public function history(Request $request, ProductSku $sku): JsonResponse
    {
        $seller = $this->seller($request);

        abort_unless($sku->seller_id === $seller->getKey(), 404);

        $history = PriceHistory::query()
            ->with('author')
            ->forSku($sku->getKey())
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $history->map(fn (PriceHistory $entry): array => [
                'field' => $entry->field,
                'old_price' => $entry->oldPrice()?->jsonSerialize(),
                'new_price' => $entry->newPrice()?->jsonSerialize(),
                'change_bps' => $entry->changeBps(),
                'source' => $entry->source,
                'author' => $entry->author?->displayName(),
                'changed_at' => $entry->changed_at->toIso8601String(),
            ])->all(),
        ]);
    }

    // --- price lists ---------------------------------------------------------

    public function lists(Request $request): JsonResponse
    {
        $seller = $this->seller($request);

        // Creating the default on read keeps the simple case free of setup.
        $this->prices->defaultListFor($seller->getKey());

        $lists = PriceList::query()->forSeller($seller->getKey())->orderByDesc('is_default')->get();

        return response()->json([
            'data' => $lists->map(fn (PriceList $list): array => [
                'id' => $list->id,
                'code' => $list->code,
                'name' => $list->name,
                'currency' => $list->currency,
                'is_default' => $list->is_default,
                'status' => $list->status,
                'is_effective' => $list->isEffective(),
                'starts_at' => $list->starts_at?->toIso8601String(),
                'ends_at' => $list->ends_at?->toIso8601String(),
                'item_count' => $list->items()->count(),
            ])->all(),
        ]);
    }

    public function storeList(Request $request): JsonResponse
    {
        $seller = $this->seller($request);

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:60',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('price_lists', 'code')->where('seller_id', $seller->getKey()),
            ],
            'name' => ['required', 'string', 'max:160'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);

        $list = PriceList::query()->create([
            ...$validated,
            'seller_id' => $seller->getKey(),
            'currency' => 'TRY',
        ]);

        return response()->json(['data' => ['id' => $list->id]], 201);
    }

    /** Ends a campaign. Yesterday's prices come back because nothing overwrote them. */
    public function endList(Request $request, PriceList $priceList): JsonResponse
    {
        $seller = $this->seller($request);

        abort_unless($priceList->seller_id === $seller->getKey(), 404);
        abort_if($priceList->is_default, 422, 'Varsayılan fiyat listesi sonlandırılamaz.');

        $priceList->forceFill(['status' => 'ended', 'ends_at' => now()])->save();

        return response()->json(['message' => 'Fiyat listesi sonlandırıldı.']);
    }

    private function listFor(Seller $seller, string $listId): PriceList
    {
        $list = PriceList::query()->whereKey($listId)->forSeller($seller->getKey())->first();

        abort_if($list === null, 404, 'Bu fiyat listesi bulunamadı.');

        return $list;
    }

    private function seller(Request $request): Seller
    {
        $organizationIds = $this->access->organizationIds($request->user());

        abort_if($organizationIds === [], 403, 'Satıcı hesabınız bulunmuyor.');

        $seller = Seller::query()->whereIn('organization_id', $organizationIds)->first();

        abort_if($seller === null, 403, 'Onaylı satıcı hesabınız bulunmuyor.');

        return $seller;
    }
}
