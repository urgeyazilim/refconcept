<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Http\Controllers;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Identity\Services\AccessControl;
use App\Domains\Inventory\Enums\LocationType;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Exceptions\InsufficientStock;
use App\Domains\Inventory\Models\StockItem;
use App\Domains\Inventory\Models\StockLocation;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Services\InventoryLedger;
use App\Domains\Products\Models\ProductSku;
use App\Domains\Sellers\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A seller's stock.
 *
 * Every write goes through {@see InventoryLedger}; nothing here touches a balance
 * directly. The controller's job is authorisation, validation and shape — the
 * invariants belong to the service that holds the lock.
 *
 * Reserve, release and dispatch are deliberately absent. They belong to the order
 * flow, and a seller adjusting `reserved` by hand would desynchronise it from the
 * reservations that explain it.
 */
final class SellerInventoryController
{
    public function __construct(
        private readonly InventoryLedger $ledger,
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
    ) {}

    /** Stock across the seller's SKUs, newest movement first. */
    public function index(Request $request): JsonResponse
    {
        $seller = $this->seller($request);

        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:120'],
            'location_id' => ['sometimes', 'uuid'],
            'needs_attention' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = StockItem::query()
            ->with(['sku.product', 'location'])
            ->whereHas('sku', fn ($sku) => $sku->where('seller_id', $seller->getKey()));

        if (isset($validated['location_id'])) {
            $query->where('location_id', $validated['location_id']);
        }

        if (($validated['needs_attention'] ?? false) === true) {
            $query->needsAttention();
        }

        if (isset($validated['search'])) {
            $term = $validated['search'];

            $query->whereHas('sku', function ($sku) use ($term): void {
                $sku->where('sku', 'ilike', '%'.$term.'%')
                    ->orWhereHas('product', fn ($product) => $product->where('name', 'ilike', '%'.$term.'%'));
            });
        }

        $items = $query->paginate($validated['per_page'] ?? 50);

        return response()->json([
            'data' => collect($items->items())->map(fn (StockItem $item): array => $this->item($item))->all(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function locations(Request $request): JsonResponse
    {
        $seller = $this->seller($request);

        $locations = StockLocation::query()->forSeller($seller->getKey())->orderBy('name')->get();

        return response()->json([
            'data' => $locations->map(fn (StockLocation $location): array => [
                'id' => $location->id,
                'code' => $location->code,
                'name' => $location->name,
                'type' => $location->type->value,
                'type_label' => $location->type->label(),
                'city' => $location->city,
                'is_default' => $location->is_default,
                'is_active' => $location->is_active,
            ])->all(),
        ]);
    }

    public function storeLocation(Request $request): JsonResponse
    {
        $seller = $this->seller($request);

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:60',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('stock_locations', 'code')->where('seller_id', $seller->getKey()),
            ],
            'name' => ['required', 'string', 'max:160'],
            'type' => ['sometimes', Rule::enum(LocationType::class)],
            'city' => ['nullable', 'string', 'max:120'],
        ]);

        $location = StockLocation::query()->create([
            ...$validated,
            'seller_id' => $seller->getKey(),
            // The first location a seller creates becomes their default, so the simple
            // case never requires a second decision.
            'is_default' => ! StockLocation::query()->forSeller($seller->getKey())->exists(),
        ]);

        return response()->json(['data' => ['id' => $location->id]], 201);
    }

    /**
     * A signed correction: a delivery arrived, or something was broken.
     */
    public function adjust(Request $request): JsonResponse
    {
        $seller = $this->seller($request);

        $validated = $request->validate([
            'sku_id' => ['required', 'uuid'],
            'location_id' => ['sometimes', 'uuid'],
            'delta' => ['required', 'integer', 'not_in:0'],
            'type' => ['sometimes', Rule::enum(MovementType::class)],
            // Mandatory for anything but a plain receipt: an unexplained adjustment is
            // indistinguishable from a mistake six months later.
            'reason' => ['required_unless:type,receipt', 'nullable', 'string', 'max:300'],
        ]);

        $sku = $this->skuFor($seller, (string) $validated['sku_id']);
        $type = MovementType::from((string) ($validated['type'] ?? MovementType::Adjustment->value));

        abort_unless($type->isSellerInitiated(), 422, 'Bu hareket türü sipariş akışına aittir.');

        $item = $this->ledger->itemFor($sku, $this->locationFor($seller, $validated['location_id'] ?? null));

        try {
            $updated = $this->ledger->adjust(
                item: $item,
                delta: (int) $validated['delta'],
                type: $type,
                actor: $request->user(),
                reason: $validated['reason'] ?? null,
            );
        } catch (InsufficientStock $e) {
            throw $e->toValidationException('delta');
        }

        $this->syncSkuQuantity($sku);

        $this->audit->record(
            action: 'inventory.stock.adjusted',
            subject: $updated,
            context: ['delta' => (int) $validated['delta'], 'type' => $type->value],
            reason: $validated['reason'] ?? null,
            actor: $request->user(),
            organizationId: $seller->organization_id,
        );

        return response()->json(['data' => $this->item($updated->fresh(['sku.product', 'location']))]);
    }

    /** What a physical count found, which overrides whatever was recorded. */
    public function stocktake(Request $request): JsonResponse
    {
        $seller = $this->seller($request);

        $validated = $request->validate([
            'sku_id' => ['required', 'uuid'],
            'location_id' => ['sometimes', 'uuid'],
            'counted' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        $sku = $this->skuFor($seller, (string) $validated['sku_id']);
        $item = $this->ledger->itemFor($sku, $this->locationFor($seller, $validated['location_id'] ?? null));

        try {
            $updated = $this->ledger->stocktake(
                item: $item,
                counted: (int) $validated['counted'],
                actor: $request->user(),
                reason: $validated['reason'] ?? null,
            );
        } catch (InsufficientStock $e) {
            // Counting fewer than are already promised to customers is a real problem,
            // not a validation slip: somebody has to decide which order to cancel.
            throw $e->toValidationException('counted');
        }

        $this->syncSkuQuantity($sku);

        return response()->json(['data' => $this->item($updated->fresh(['sku.product', 'location']))]);
    }

    /** The ledger behind one balance. */
    public function movements(Request $request, StockItem $stockItem): JsonResponse
    {
        $seller = $this->seller($request);

        $stockItem->loadMissing('sku');

        abort_unless($stockItem->sku?->seller_id === $seller->getKey(), 404);

        $movements = $stockItem->movements()->with('author')->limit(200)->get();

        return response()->json([
            'data' => $movements->map(fn (StockMovement $movement): array => [
                'id' => $movement->id,
                'type' => $movement->type->value,
                'type_label' => $movement->type->label(),
                'quantity' => $movement->quantity,
                'on_hand_after' => $movement->on_hand_after,
                'reserved_after' => $movement->reserved_after,
                'reason' => $movement->reason,
                'reference_type' => $movement->reference_type,
                'author' => $movement->author?->displayName(),
                'created_at' => $movement->created_at->toIso8601String(),
            ])->all(),
        ]);
    }

    // --- helpers -------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function item(StockItem $item): array
    {
        return [
            'id' => $item->id,
            'sku' => [
                'id' => $item->sku?->id,
                'code' => $item->sku?->sku,
                'variant_label' => $item->sku?->variant_label,
                'product_name' => $item->sku?->product?->name,
            ],
            'location' => [
                'id' => $item->location?->id,
                'name' => $item->location?->name,
                'code' => $item->location?->code,
            ],
            'on_hand' => $item->on_hand,
            'reserved' => $item->reserved,
            'sellable' => $item->sellable(),
            'reorder_point' => $item->reorder_point,
            'needs_attention' => $item->isBelowReorderPoint(),
            'counted_at' => $item->counted_at?->toIso8601String(),
        ];
    }

    /**
     * Keeps the SKU's own quantity in step with the ledger.
     *
     * The column on `product_skus` is what the catalogue's purchasable scope reads, so
     * it has to follow the ledger or a sold-out product stays on sale. The ledger
     * stays authoritative; this is a projection of it.
     */
    private function syncSkuQuantity(ProductSku $sku): void
    {
        $sku->forceFill(['stock_quantity' => $this->ledger->sellableFor($sku)])->save();
    }

    private function skuFor(Seller $seller, string $skuId): ProductSku
    {
        $sku = ProductSku::query()->whereKey($skuId)->where('seller_id', $seller->getKey())->first();

        abort_if($sku === null, 404, 'Bu satış seçeneği bulunamadı.');

        return $sku;
    }

    private function locationFor(Seller $seller, ?string $locationId): ?StockLocation
    {
        if ($locationId === null) {
            return null;
        }

        $location = StockLocation::query()
            ->whereKey($locationId)
            ->forSeller($seller->getKey())
            ->first();

        abort_if($location === null, 404, 'Bu depo bulunamadı.');

        return $location;
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
