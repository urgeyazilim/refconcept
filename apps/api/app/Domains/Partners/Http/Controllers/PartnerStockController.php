<?php

declare(strict_types=1);

namespace App\Domains\Partners\Http\Controllers;

use App\Domains\Inventory\Exceptions\InsufficientStock;
use App\Domains\Inventory\Services\InventoryLedger;
use App\Domains\Partners\Models\ApiCredential;
use App\Domains\Pricing\Services\PriceBook;
use App\Domains\Products\Models\ProductSku;
use App\Domains\Sellers\Models\Seller;
use App\Support\ValueObjects\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The machine-facing half of the seller API.
 *
 * This is what an ERP or a warehouse system talks to, and it is shaped for that
 * rather than for a screen: SKUs are addressed by the seller's own code, not by a
 * UUID they would have to store a mapping for. Nobody's inventory system is going to
 * keep a table of RefConcept ids.
 *
 * Writes are batched and report per-SKU results instead of failing the whole request
 * on one bad line. A nightly sync that refuses 4,000 updates because one product was
 * discontinued is a sync that gets switched off.
 */
final class PartnerStockController
{
    public function __construct(
        private readonly InventoryLedger $inventory,
        private readonly PriceBook $prices,
    ) {}

    /** Current stock for the caller's SKUs. */
    public function index(Request $request): JsonResponse
    {
        $seller = $this->seller($request);

        $validated = $request->validate([
            'sku' => ['sometimes', 'array', 'max:500'],
            'sku.*' => ['string', 'max:80'],
        ]);

        $query = ProductSku::query()->where('seller_id', $seller->getKey());

        if (isset($validated['sku'])) {
            $query->whereIn('sku', $validated['sku']);
        }

        $skus = $query->limit(500)->get();

        return response()->json([
            'data' => $skus->map(fn (ProductSku $sku): array => [
                'sku' => $sku->sku,
                'sellable' => $this->inventory->sellableFor($sku),
                'status' => $sku->status->value,
            ])->all(),
        ]);
    }

    /**
     * Sets stock levels from the seller's own system.
     *
     * A stocktake rather than an adjustment: an ERP reports what it believes it has,
     * which is a count, not a delta. Sending a delta from an external system would
     * double-count every retry.
     */
    public function updateStock(Request $request): JsonResponse
    {
        $seller = $this->seller($request);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*.sku' => ['required', 'string', 'max:80'],
            'items.*.quantity' => ['required', 'integer', 'min:0'],
        ]);

        /** @var array<int, array{sku: string, quantity: int}> $items */
        $items = $validated['items'];

        $known = ProductSku::query()
            ->where('seller_id', $seller->getKey())
            ->whereIn('sku', array_column($items, 'sku'))
            ->get()
            ->keyBy('sku');

        $results = [];

        foreach ($items as $entry) {
            $sku = $known->get($entry['sku']);

            if ($sku === null) {
                $results[] = ['sku' => $entry['sku'], 'ok' => false, 'error' => 'unknown_sku'];

                continue;
            }

            try {
                $stockItem = $this->inventory->itemFor($sku);

                $this->inventory->stocktake(
                    $stockItem,
                    $entry['quantity'],
                    null,
                    'Partner API senkronizasyonu',
                );

                $sku->forceFill(['stock_quantity' => $this->inventory->sellableFor($sku)])->save();

                $results[] = ['sku' => $entry['sku'], 'ok' => true, 'sellable' => $sku->stock_quantity];
            } catch (InsufficientStock $e) {
                // The count is below what is already promised to customers. Refusing
                // this one line is right: somebody has to decide which order to cancel,
                // and it is not this endpoint.
                $results[] = [
                    'sku' => $entry['sku'],
                    'ok' => false,
                    'error' => 'reserved_exceeds_count',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'data' => $results,
            'meta' => [
                'accepted' => count(array_filter($results, static fn (array $r): bool => $r['ok'] === true)),
                'rejected' => count(array_filter($results, static fn (array $r): bool => $r['ok'] === false)),
            ],
        ]);
    }

    /**
     * Sets prices from the seller's own system.
     *
     * Minor units on the wire, as everywhere. An ERP sending "48900.00" would be
     * asking a parser to decide what that means, and the answer differs by locale.
     */
    public function updatePrices(Request $request): JsonResponse
    {
        $seller = $this->seller($request);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*.sku' => ['required', 'string', 'max:80'],
            'items.*.list_price_minor' => ['required', 'integer', 'min:0', 'max:99999999999'],
            'items.*.sale_price_minor' => ['nullable', 'integer', 'min:0', 'max:99999999999'],
        ]);

        /** @var array<int, array{sku: string, list_price_minor: int, sale_price_minor: int|null}> $items */
        $items = $validated['items'];

        $known = ProductSku::query()
            ->where('seller_id', $seller->getKey())
            ->whereIn('sku', array_column($items, 'sku'))
            ->get()
            ->keyBy('sku');

        $results = [];

        foreach ($items as $entry) {
            $sku = $known->get($entry['sku']);

            if ($sku === null) {
                $results[] = ['sku' => $entry['sku'], 'ok' => false, 'error' => 'unknown_sku'];

                continue;
            }

            $sale = $entry['sale_price_minor'] ?? null;

            if ($sale !== null && $sale > $entry['list_price_minor']) {
                $results[] = ['sku' => $entry['sku'], 'ok' => false, 'error' => 'sale_above_list'];

                continue;
            }

            DB::transaction(function () use ($sku, $entry, $sale): void {
                $this->prices->setPrice(
                    $sku,
                    Money::of($entry['list_price_minor'], $sku->currency),
                    $sale === null ? null : Money::of($sale, $sku->currency),
                    null,
                    'api',
                );
            });

            $results[] = ['sku' => $entry['sku'], 'ok' => true];
        }

        return response()->json([
            'data' => $results,
            'meta' => [
                'accepted' => count(array_filter($results, static fn (array $r): bool => $r['ok'] === true)),
                'rejected' => count(array_filter($results, static fn (array $r): bool => $r['ok'] === false)),
            ],
        ]);
    }

    /**
     * The seller behind the credential on this request.
     *
     * Read from the request attributes the middleware set, never from anything the
     * caller sent: a body-supplied seller id would be a tenancy hole with a friendly
     * interface.
     */
    private function seller(Request $request): Seller
    {
        $credential = $request->attributes->get('partner_credential');

        abort_unless($credential instanceof ApiCredential, 401);

        $seller = Seller::query()->where('organization_id', $credential->organization_id)->first();

        abort_if($seller === null, 403, 'Bu kimlik bilgisine bağlı satıcı hesabı yok.');

        return $seller;
    }
}
