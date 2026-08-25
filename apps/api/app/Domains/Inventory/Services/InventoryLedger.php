<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Services;

use App\Domains\Identity\Models\User;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\ReservationStatus;
use App\Domains\Inventory\Exceptions\InsufficientStock;
use App\Domains\Inventory\Models\StockItem;
use App\Domains\Inventory\Models\StockLocation;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Models\StockReservation;
use App\Domains\Products\Models\ProductSku;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only writer of stock balances.
 *
 * Everything here follows one shape: open a transaction, take a row lock on the stock
 * item, read the balance *inside* the lock, decide, write both the new balance and
 * the movement that explains it, commit. Nothing outside this class may update
 * `stock_items`.
 *
 * The reason is the classic one and it is worth stating plainly. Two checkouts for
 * the last sofa arrive at the same moment. Both read `on_hand = 1`, both decide one is
 * available, both write `reserved = 1`. Two customers are promised one sofa, and
 * nothing in the data says anything went wrong — the row looks perfectly consistent.
 * `SELECT ... FOR UPDATE` is what makes the second transaction wait until the first
 * has committed, so it reads `reserved = 1` and correctly refuses.
 *
 * Two further defences sit behind that, because a lock only helps code that takes it:
 *
 *  - a CHECK constraint refuses `reserved > on_hand` at the storage layer;
 *  - a partial unique index refuses a second live hold for the same reference, which
 *    is what makes reserving idempotent when a client retries.
 *
 * Deadlocks are avoided by always locking in one order — see {@see reserveMany()}.
 */
final class InventoryLedger
{
    /**
     * Adds stock that has physically arrived, or corrects what is recorded.
     *
     * `$delta` is signed: a delivery is positive, a breakage negative. A reason is
     * mandatory for anything but a receipt, because an unexplained adjustment is
     * indistinguishable from a mistake six months later.
     */
    public function adjust(
        StockItem $item,
        int $delta,
        MovementType $type,
        ?User $actor = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
    ): StockItem {
        if (! $type->movesOnHand()) {
            throw new InvalidArgumentException(
                'Reserve and release change what is held, not what exists; use reserve()/release().',
            );
        }

        if ($delta === 0) {
            return $item;
        }

        return DB::transaction(function () use ($item, $delta, $type, $actor, $reason, $referenceType, $referenceId): StockItem {
            $locked = $this->lock($item);

            $onHand = $locked->on_hand + $delta;

            if ($onHand < 0) {
                // Removing more than exists would leave a balance that means nothing.
                throw new InsufficientStock(
                    $this->skuCodeFor($locked),
                    abs($delta),
                    $locked->on_hand,
                );
            }

            if ($onHand < $locked->reserved) {
                // The goods are already promised to somebody. Writing this away would
                // let a paid order find nothing in the warehouse.
                throw new InsufficientStock(
                    $this->skuCodeFor($locked),
                    abs($delta),
                    $locked->on_hand - $locked->reserved,
                );
            }

            $locked->on_hand = $onHand;

            if ($type === MovementType::Stocktake) {
                $locked->counted_at = now();
            }

            $locked->save();

            $this->record($locked, $type, $delta, $actor, $reason, $referenceType, $referenceId);

            return $locked;
        });
    }

    /**
     * Sets the balance to what a physical count found.
     *
     * Expressed as a target rather than a delta because that is what a stocktake
     * produces: somebody counted seven, and the difference from what was recorded is
     * the interesting part, not the input.
     */
    public function stocktake(StockItem $item, int $counted, ?User $actor = null, ?string $reason = null): StockItem
    {
        if ($counted < 0) {
            throw new InvalidArgumentException('A physical count cannot be negative.');
        }

        return DB::transaction(function () use ($item, $counted, $actor, $reason): StockItem {
            $locked = $this->lock($item);
            $delta = $counted - $locked->on_hand;

            if ($delta === 0) {
                $locked->counted_at = now();
                $locked->save();

                return $locked;
            }

            return $this->adjust(
                $locked,
                $delta,
                MovementType::Stocktake,
                $actor,
                $reason ?? 'Fiziksel sayım',
            );
        });
    }

    /**
     * Holds stock for a reference, or returns the hold that already exists.
     *
     * Idempotent by design: a client retrying a checkout after a network timeout must
     * not take the stock twice. The existing hold is returned unchanged rather than
     * topped up, because "reserve 2" arriving twice means two, not four.
     */
    public function reserve(
        StockItem $item,
        int $quantity,
        string $referenceType,
        string $referenceId,
        ?int $holdSeconds = null,
    ): StockReservation {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('A reservation must be for at least one unit.');
        }

        return DB::transaction(function () use ($item, $quantity, $referenceType, $referenceId, $holdSeconds): StockReservation {
            $locked = $this->lock($item);

            $existing = StockReservation::query()
                ->where('stock_item_id', $locked->getKey())
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->held()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $this->expireStaleHolds($locked);
            $locked->refresh();

            if ($locked->sellable() < $quantity) {
                throw new InsufficientStock($this->skuCodeFor($locked), $quantity, $locked->sellable());
            }

            $locked->reserved += $quantity;
            $locked->save();

            $reservation = StockReservation::query()->create([
                'stock_item_id' => $locked->getKey(),
                'quantity' => $quantity,
                'status' => ReservationStatus::Held,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'expires_at' => $holdSeconds === null ? null : now()->addSeconds($holdSeconds),
            ]);

            $this->record(
                $locked,
                MovementType::Reserve,
                $quantity,
                null,
                null,
                $referenceType,
                $referenceId,
            );

            return $reservation;
        });
    }

    /**
     * Reserves several SKUs for one reference, all or nothing.
     *
     * Rows are locked in a fixed order — by stock item id — because two baskets
     * containing the same two products in opposite orders will otherwise each hold
     * what the other is waiting for, and PostgreSQL will kill one of them as a
     * deadlock. Sorting turns that into a queue instead.
     *
     * @param  array<string, int>  $quantitiesByStockItemId
     * @return array<int, StockReservation>
     */
    public function reserveMany(
        array $quantitiesByStockItemId,
        string $referenceType,
        string $referenceId,
        ?int $holdSeconds = null,
    ): array {
        ksort($quantitiesByStockItemId);

        return DB::transaction(function () use ($quantitiesByStockItemId, $referenceType, $referenceId, $holdSeconds): array {
            $reservations = [];

            foreach ($quantitiesByStockItemId as $stockItemId => $quantity) {
                $item = StockItem::query()->findOrFail($stockItemId);

                $reservations[] = $this->reserve(
                    $item,
                    $quantity,
                    $referenceType,
                    $referenceId,
                    $holdSeconds,
                );
            }

            return $reservations;
        });
    }

    /** Gives the stock back — the customer changed their mind, or the hold timed out. */
    public function release(StockReservation $reservation, ReservationStatus $as = ReservationStatus::Released): StockReservation
    {
        if (! in_array($as, [ReservationStatus::Released, ReservationStatus::Expired], true)) {
            throw new InvalidArgumentException('A hold is released or expired; consuming it is a dispatch.');
        }

        return DB::transaction(function () use ($reservation, $as): StockReservation {
            $fresh = StockReservation::query()->lockForUpdate()->findOrFail($reservation->getKey());

            if ($fresh->status !== ReservationStatus::Held) {
                // Releasing twice is a retry, not an error: the outcome is what was asked for.
                return $fresh;
            }

            $item = $this->lockById($fresh->stock_item_id);

            $item->reserved = max(0, $item->reserved - $fresh->quantity);
            $item->save();

            $fresh->status = $as;
            $fresh->released_at = now();
            $fresh->save();

            $this->record(
                $item,
                MovementType::Release,
                -$fresh->quantity,
                null,
                $as === ReservationStatus::Expired ? 'Rezervasyon süresi doldu' : null,
                $fresh->reference_type,
                $fresh->reference_id,
            );

            return $fresh;
        });
    }

    /**
     * The goods left the building.
     *
     * Both numbers move: what was held is no longer held, and what existed no longer
     * exists. Doing it in one transaction is what stops a dispatch from briefly
     * showing stock as available between the two writes.
     */
    public function consume(StockReservation $reservation, ?User $actor = null): StockReservation
    {
        return DB::transaction(function () use ($reservation, $actor): StockReservation {
            $fresh = StockReservation::query()->lockForUpdate()->findOrFail($reservation->getKey());

            if ($fresh->status === ReservationStatus::Consumed) {
                return $fresh;
            }

            if ($fresh->status !== ReservationStatus::Held) {
                throw new InvalidArgumentException('Only a live hold can be dispatched.');
            }

            $item = $this->lockById($fresh->stock_item_id);

            $item->reserved = max(0, $item->reserved - $fresh->quantity);
            $item->on_hand = max(0, $item->on_hand - $fresh->quantity);
            $item->save();

            $fresh->status = ReservationStatus::Consumed;
            $fresh->consumed_at = now();
            $fresh->save();

            $this->record(
                $item,
                MovementType::Dispatch,
                -$fresh->quantity,
                $actor,
                null,
                $fresh->reference_type,
                $fresh->reference_id,
            );

            return $fresh;
        });
    }

    /**
     * Releases every hold whose time has run out.
     *
     * Called by the scheduler, and again inside {@see reserve()} for the row being
     * reserved. The second is what stops a stale hold from blocking a sale for up to
     * a whole scheduler interval.
     */
    public function releaseExpired(?StockItem $item = null): int
    {
        $query = StockReservation::query()->expired();

        if ($item !== null) {
            $query->where('stock_item_id', $item->getKey());
        }

        $released = 0;

        foreach ($query->get() as $reservation) {
            $this->release($reservation, ReservationStatus::Expired);
            $released++;
        }

        return $released;
    }

    /**
     * The row for this SKU at this location, created at zero if it does not exist.
     *
     * `firstOrCreate` rather than a check-then-insert: two imports touching the same
     * SKU at once would otherwise both find nothing and both insert, and the unique
     * index would fail one of them for no good reason.
     */
    public function itemFor(ProductSku $sku, ?StockLocation $location = null): StockItem
    {
        $location ??= $this->defaultLocationFor($sku);

        return StockItem::query()->firstOrCreate(
            ['sku_id' => $sku->getKey(), 'location_id' => $location->getKey()],
            ['on_hand' => 0, 'reserved' => 0],
        );
    }

    /**
     * The seller's default location, created on first use.
     *
     * A seller who never thinks about warehouses should still be able to hold stock,
     * so the simple case does not require any setup at all.
     */
    public function defaultLocationFor(ProductSku $sku): StockLocation
    {
        $sellerId = $sku->seller_id;

        $existing = StockLocation::query()
            ->forSeller($sellerId)
            ->where('is_default', true)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return StockLocation::query()->create([
            'seller_id' => $sellerId,
            'code' => 'MAIN',
            'name' => 'Ana depo',
            'is_default' => true,
        ]);
    }

    /** Sellable units for a SKU across every active location. */
    /**
     * Every hold taken against one reference.
     *
     * A basket reserves several SKUs under one reference, so releasing it means finding
     * them all — and a caller that kept its own list of reservation ids would be a second
     * record of the same thing, drifting the first time one path forgot to update it.
     *
     * @return array<int, StockReservation>
     */
    public function reservationsFor(string $referenceType, string $referenceId): array
    {
        return StockReservation::query()
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->held()
            ->get()
            ->all();
    }

    public function sellableFor(ProductSku $sku): int
    {
        return (int) StockItem::query()
            ->forSku($sku->getKey())
            ->whereHas('location', fn ($location) => $location->where('is_active', true))
            ->get()
            ->sum(fn (StockItem $item): int => $item->sellable());
    }

    /**
     * Takes the row lock every write in this class depends on.
     *
     * Returns a freshly read model: the caller's copy may be stale, and deciding from
     * a stale balance inside a lock is the same bug the lock exists to prevent.
     */
    private function lock(StockItem $item): StockItem
    {
        return $this->lockById((string) $item->getKey());
    }

    /**
     * Locks by id, for callers that hold a reservation rather than the item.
     *
     * Reaching through `$reservation->stockItem` would lazy-load the relation, which
     * is disabled outside production and would turn a correct write into a 500.
     */
    private function lockById(string $stockItemId): StockItem
    {
        return StockItem::query()->with('sku')->lockForUpdate()->findOrFail($stockItemId);
    }

    /** Releases holds on this row that have already expired, inside the caller's lock. */
    private function expireStaleHolds(StockItem $item): void
    {
        foreach (StockReservation::query()->expired()->where('stock_item_id', $item->getKey())->get() as $stale) {
            $item->reserved = max(0, $item->reserved - $stale->quantity);
            $item->save();

            $stale->status = ReservationStatus::Expired;
            $stale->released_at = now();
            $stale->save();

            $this->record(
                $item,
                MovementType::Release,
                -$stale->quantity,
                null,
                'Rezervasyon süresi doldu',
                $stale->reference_type,
                $stale->reference_id,
            );
        }
    }

    private function record(
        StockItem $item,
        MovementType $type,
        int $quantity,
        ?User $actor,
        ?string $reason,
        ?string $referenceType,
        ?string $referenceId,
    ): StockMovement {
        $this->project($item);

        return StockMovement::query()->create([
            'stock_item_id' => $item->getKey(),
            'type' => $type,
            'quantity' => $quantity,
            'on_hand_after' => $item->on_hand,
            'reserved_after' => $item->reserved,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'reason' => $reason,
            'created_by' => $actor?->getKey(),
        ]);
    }

    /**
     * Keeps the SKU's own quantity in step with the ledger.
     *
     * `product_skus.stock_quantity` is a projection, not a source: the catalogue's list
     * query cannot run an aggregate per row, so it reads a column instead. Every movement
     * updates it here, in the ledger, because the ledger is the only thing that knows the
     * figure changed.
     *
     * It used to be updated by the seller's stock controller alone, which meant a sale
     * did not touch it at all — the last unit could be bought and paid for while the
     * listing went on saying it was in stock, until a seller happened to open the stock
     * page. The next customer only found out at checkout.
     *
     * Written with a direct update rather than through the model: this runs inside a
     * locked transaction on a hot path, and there is nothing on the SKU to observe.
     */
    private function project(StockItem $item): void
    {
        ProductSku::query()
            ->whereKey($item->sku_id)
            ->update(['stock_quantity' => $this->sellableForId($item->sku_id)]);
    }

    /**
     * The sellable total for a SKU by id.
     *
     * The same question {@see sellableFor()} answers, asked without a model to hand. The
     * active-location filter is repeated deliberately rather than approximated: a
     * projection that disagreed with the authority would be worse than no projection.
     */
    private function sellableForId(string $skuId): int
    {
        return (int) StockItem::query()
            ->forSku($skuId)
            ->whereHas('location', fn ($location) => $location->where('is_active', true))
            ->get()
            ->sum(fn (StockItem $item): int => $item->sellable());
    }

    /**
     * A SKU code for the error message, without assuming the relation is loaded.
     *
     * The value is only ever used in a message a human reads, so one extra query on a
     * failure path is a fair price for not depending on how the caller loaded the row.
     */
    private function skuCodeFor(StockItem $item): string
    {
        if ($item->relationLoaded('sku') && $item->sku !== null) {
            return $item->sku->sku;
        }

        return (string) (ProductSku::query()->whereKey($item->sku_id)->value('sku') ?? $item->sku_id);
    }
}
