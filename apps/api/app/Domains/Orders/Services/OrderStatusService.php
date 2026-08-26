<?php

declare(strict_types=1);

namespace App\Domains\Orders\Services;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Finance\Services\OrderAccounting;
use App\Domains\Identity\Models\User;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Services\InventoryLedger;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\SellerOrderStatus;
use App\Domains\Orders\Exceptions\OrderRefused;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Orders\Models\OrderStatusChange;
use App\Domains\Orders\Models\SellerOrder;
use App\Domains\Orders\Notifications\SellerOrderShipped;
use App\Domains\Products\Models\ProductSku;
use Illuminate\Support\Facades\DB;

/**
 * The only place an order's status changes.
 *
 * Two rules, and both exist because of the same failure. A status is what a customer, a
 * seller and a finance team all reason from, so:
 *
 *  1. **A seller order moves only along declared transitions.** A seller cannot cancel
 *     something already in a van — what happens after a parcel leaves is a return, with
 *     different rights — and a stale screen must not be able to walk a delivered order
 *     backwards.
 *
 *  2. **The master order is derived, never set.** It is a summary of its parts, computed
 *     after every change. A summary that can be written independently is a summary that
 *     will eventually disagree with what it summarises, and then nobody can tell which of
 *     the two is lying.
 *
 * Every change is written to an append-only history with who made it and why.
 */
final class OrderStatusService
{
    public function __construct(
        private readonly InventoryLedger $stock,
        private readonly OrderAccounting $accounting,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Moves one seller's part of an order.
     *
     * @throws OrderRefused
     */
    public function advance(
        SellerOrder $sellerOrder,
        SellerOrderStatus $next,
        ?User $actor = null,
        string $actorRole = 'seller',
        ?string $reason = null,
    ): SellerOrder {
        if ($next === SellerOrderStatus::Cancelled && ($reason === null || trim($reason) === '')) {
            // A cancellation is the one transition that costs the customer something, so
            // it is the one that has to be explained.
            throw OrderRefused::reasonRequired();
        }

        $updated = DB::transaction(function () use ($sellerOrder, $next, $actor, $actorRole, $reason): SellerOrder {
            /** @var SellerOrder $locked */
            $locked = SellerOrder::query()->whereKey($sellerOrder->getKey())->lockForUpdate()->firstOrFail();

            $from = $locked->status;

            if ($from === $next) {
                // A double-clicked button is not an error and not a transition.
                return $locked;
            }

            if (! $from->canTransitionTo($next)) {
                throw OrderRefused::badTransition($from->label(), $next->label());
            }

            $locked->status = $next;

            match ($next) {
                SellerOrderStatus::Confirmed => $locked->confirmed_at ??= now(),
                SellerOrderStatus::Shipped => $locked->shipped_at ??= now(),
                SellerOrderStatus::Delivered => $locked->delivered_at ??= now(),
                SellerOrderStatus::Cancelled => $this->stampCancellation($locked, $reason),
                default => null,
            };

            $locked->save();

            OrderStatusChange::query()->create([
                'order_id' => $locked->order_id,
                'seller_order_id' => $locked->getKey(),
                'from_status' => $from->value,
                'to_status' => $next->value,
                'changed_by' => $actor?->getKey(),
                'actor_role' => $actorRole,
                'reason' => $reason,
            ]);

            if ($next === SellerOrderStatus::Cancelled) {
                $this->returnStock($locked, $actor);
            }

            return $locked;
        });

        $this->recompute($updated->order_id, $actor);

        $this->audit->record(
            action: 'orders.seller_order.'.$next->value,
            subject: $updated,
            context: ['seller_order_number' => $updated->seller_order_number],
            reason: $reason,
            actor: $actor,
        );

        if ($next === SellerOrderStatus::Cancelled) {
            /*
             * The money follows the goods. A cancelled part reverses this seller's share
             * and records what the customer is owed — as its own entry rather than a
             * reversal of the whole sale, because the other sellers' parcels are still on
             * their way.
             */
            $this->accounting->recordSellerCancellation($updated, $reason ?? 'İptal', $actor);
        }

        if (in_array($next, [SellerOrderStatus::Cancelled, SellerOrderStatus::Delivered], true)) {
            // Delivery starts the settlement hold, so the projected balance changes even
            // though no journal line does.
            $this->accounting->rebuildBalance((string) $updated->seller_id, $updated->currency);
        }

        if ($next === SellerOrderStatus::Shipped) {
            $this->notifyCustomer($updated);
        }

        return $updated;
    }

    /**
     * Recomputes the master order from its parts.
     *
     * Public because a change to any seller order has to trigger it, and because a
     * reconciliation job will want to run it over everything without pretending to make a
     * transition.
     */
    public function recompute(string $orderId, ?User $actor = null): ?Order
    {
        return DB::transaction(function () use ($orderId, $actor): ?Order {
            /** @var Order|null $order */
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();

            if ($order === null) {
                return null;
            }

            /*
             * `toBase()` so the values come back as the strings the column holds. Plucking
             * through Eloquent applies the enum cast, and then the mapping below is handed
             * an enum where it expects a string — which fails as a TypeError rather than
             * as anything readable.
             */
            $statuses = SellerOrder::query()
                ->where('order_id', $orderId)
                ->toBase()
                ->pluck('status')
                ->map(static fn (string $value): SellerOrderStatus => SellerOrderStatus::from($value))
                ->all();

            $derived = OrderStatus::fromSellerOrders($statuses);

            if ($derived === $order->status) {
                return $order;
            }

            $from = $order->status;

            $order->status = $derived;

            if ($derived === OrderStatus::Cancelled) {
                $order->cancelled_at ??= now();
            }

            if ($derived === OrderStatus::Delivered) {
                $order->completed_at ??= now();
            }

            $order->save();

            OrderStatusChange::query()->create([
                'order_id' => $order->getKey(),
                'from_status' => $from->value,
                'to_status' => $derived->value,
                'changed_by' => $actor?->getKey(),
                // Derived, so the actor is recorded but the role is not theirs: nobody
                // decided the master status, it followed from what they did.
                'actor_role' => 'system',
            ]);

            return $order;
        });
    }

    // --- internals -----------------------------------------------------------

    private function stampCancellation(SellerOrder $sellerOrder, ?string $reason): void
    {
        $sellerOrder->cancelled_at ??= now();
        $sellerOrder->cancellation_reason = $reason;
    }

    /**
     * Puts cancelled goods back on the shelf.
     *
     * The stock left when the payment was captured, so cancelling has to put it back or
     * the warehouse and the ledger disagree — and the disagreement only surfaces weeks
     * later as a sale that cannot be fulfilled.
     *
     * The customer's refund is a separate matter, handled by finance against the payment,
     * because money and goods move on different timetables and pretending otherwise is how
     * one of them gets lost.
     */
    private function returnStock(SellerOrder $sellerOrder, ?User $actor): void
    {
        $items = OrderItem::query()->where('seller_order_id', $sellerOrder->getKey())->get();

        foreach ($items as $item) {
            if ($item->sku_id === null) {
                continue;
            }

            $sku = ProductSku::query()->find($item->sku_id);

            if ($sku === null || ! $sku->stock_policy->tracksQuantity()) {
                continue;
            }

            $this->stock->adjust(
                $this->stock->itemFor($sku),
                $item->quantity,
                MovementType::Return_,
                $actor,
                'Sipariş iptali: '.$sellerOrder->seller_order_number,
                'seller_order',
                (string) $sellerOrder->getKey(),
            );
        }
    }

    private function notifyCustomer(SellerOrder $sellerOrder): void
    {
        $customer = $sellerOrder->order?->customer;

        if ($customer === null) {
            return;
        }

        $customer->notify(new SellerOrderShipped($sellerOrder));
    }
}
