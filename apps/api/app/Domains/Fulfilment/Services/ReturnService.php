<?php

declare(strict_types=1);

namespace App\Domains\Fulfilment\Services;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Fulfilment\Enums\ReturnStatus;
use App\Domains\Fulfilment\Exceptions\FulfilmentRefused;
use App\Domains\Fulfilment\Models\ReturnItem;
use App\Domains\Fulfilment\Models\ReturnRequest;
use App\Domains\Identity\Models\User;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Services\InventoryLedger;
use App\Domains\Orders\Enums\SellerOrderStatus;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Orders\Models\SellerOrder;
use App\Domains\Products\Models\ProductSku;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sending things back.
 *
 * Per line and per quantity, because a customer who bought four chairs and wants to return
 * one is the ordinary case. An order-level model turns that into a support conversation.
 *
 * A request is *asked for*, not decided. The seller sees what arrives and can accept some
 * of it — three chairs sent back, one arrived scratched — which is why every line has both
 * a requested and an approved quantity.
 *
 * **An open return blocks the seller's payout**, and that is the whole reason the
 * settlement hold exists. Paying somebody for goods that are on their way back means
 * chasing money from a person who has already spent it.
 */
final class ReturnService
{
    public function __construct(
        private readonly RefundService $refunds,
        private readonly InventoryLedger $stock,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * A customer asks to send things back.
     *
     * @param  array<int, array{order_item_id: string, quantity: int}>  $lines
     *
     * @throws FulfilmentRefused
     */
    public function open(
        SellerOrder $sellerOrder,
        array $lines,
        string $reasonCode,
        ?string $note,
        User $customer,
    ): ReturnRequest {
        if ($sellerOrder->status !== SellerOrderStatus::Delivered) {
            throw FulfilmentRefused::notDelivered();
        }

        $window = $this->windowDays();
        $deadline = $sellerOrder->delivered_at?->copy()->addDays($window);

        if ($deadline !== null && $deadline->isPast()) {
            throw FulfilmentRefused::returnWindowClosed($window);
        }

        if ($lines === []) {
            throw FulfilmentRefused::nothingToReturn();
        }

        return DB::transaction(function () use ($sellerOrder, $lines, $reasonCode, $note, $customer): ReturnRequest {
            $return = ReturnRequest::query()->create([
                'reference' => $this->reference(),
                'order_id' => $sellerOrder->order_id,
                'seller_order_id' => $sellerOrder->getKey(),
                'requested_by' => $customer->getKey(),
                'reason_code' => $reasonCode,
                'reason_note' => $note,
                'currency' => $sellerOrder->currency,
            ]);

            $requested = 0;

            foreach ($lines as $line) {
                $item = OrderItem::query()
                    ->where('seller_order_id', $sellerOrder->getKey())
                    ->whereKey($line['order_item_id'])
                    ->first();

                if ($item === null) {
                    throw FulfilmentRefused::lineNotYours();
                }

                $wanted = max(1, (int) $line['quantity']);
                $remaining = $item->quantity - $this->returnedQuantity($item);

                if ($wanted > $remaining) {
                    /*
                     * Counting what is already inside an open or completed return, not
                     * only what has been refunded. Otherwise the same chair can be
                     * requested twice while the first request is still being decided.
                     */
                    throw FulfilmentRefused::tooManyToReturn($item->product_name, $remaining);
                }

                ReturnItem::query()->create([
                    'return_id' => $return->getKey(),
                    'order_item_id' => $item->getKey(),
                    'quantity' => $wanted,
                    'unit_price_minor' => $item->unit_price_minor,
                    // Snapshotted with the line: a refund's commission reversal has to use
                    // the rate that was charged, not the rate in force today.
                    'commission_rate_bps' => $item->commission_rate_bps,
                ]);

                $requested += $item->unit_price_minor * $wanted;
            }

            $return->forceFill(['requested_minor' => $requested])->save();

            return $return->fresh(['items']) ?? $return;
        });
    }

    /**
     * The seller decides.
     *
     * `$approved` is a map of return item id to accepted quantity, because accepting some
     * of a request is normal and an all-or-nothing decision would push every partial case
     * into e-mail.
     *
     * @param  array<string, int>  $approved
     *
     * @throws FulfilmentRefused
     */
    public function decide(
        ReturnRequest $return,
        bool $accept,
        array $approved,
        User $actor,
        ?string $note = null,
    ): ReturnRequest {
        $next = $accept ? ReturnStatus::Approved : ReturnStatus::Rejected;

        if (! $accept && ($note === null || trim($note) === '')) {
            // Refusing a return costs the customer something, so it is the decision that
            // has to be explained.
            throw FulfilmentRefused::reasonRequired();
        }

        return DB::transaction(function () use ($return, $next, $approved, $actor, $note): ReturnRequest {
            /** @var ReturnRequest $locked */
            $locked = ReturnRequest::query()->whereKey($return->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransitionTo($next)) {
                throw FulfilmentRefused::badReturnTransition($locked->status->label(), $next->label());
            }

            $total = 0;

            if ($next === ReturnStatus::Approved) {
                $locked->loadMissing('items');

                foreach ($locked->items as $item) {
                    $quantity = min($item->quantity, max(0, (int) ($approved[$item->id] ?? $item->quantity)));

                    $item->forceFill([
                        'approved_quantity' => $quantity,
                        'refund_minor' => $item->unit_price_minor * $quantity,
                    ])->save();

                    $total += $item->unit_price_minor * $quantity;
                }
            }

            $locked->forceFill([
                'status' => $next,
                'approved_minor' => $total,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();

            $this->audit->record(
                action: 'fulfilment.return.'.$next->value,
                subject: $locked,
                context: ['reference' => $locked->reference, 'approved_minor' => $total],
                reason: $note,
                actor: $actor,
            );

            return $locked;
        });
    }

    /**
     * Moves a return along its lifecycle.
     *
     * @throws FulfilmentRefused
     */
    public function advance(ReturnRequest $return, ReturnStatus $next, ?User $actor = null): ReturnRequest
    {
        $updated = DB::transaction(function () use ($return, $next, $actor): ReturnRequest {
            /** @var ReturnRequest $locked */
            $locked = ReturnRequest::query()->whereKey($return->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === $next) {
                return $locked;
            }

            if (! $locked->status->canTransitionTo($next)) {
                throw FulfilmentRefused::badReturnTransition($locked->status->label(), $next->label());
            }

            $locked->status = $next;

            if ($next === ReturnStatus::Received) {
                $locked->received_at ??= now();
            }

            $locked->save();

            if ($next === ReturnStatus::Completed) {
                $this->restock($locked, $actor);
            }

            return $locked;
        });

        if ($next === ReturnStatus::Completed) {
            /*
             * The money follows the goods, but as its own object with its own lifecycle:
             * a provider can refuse a refund on a payment that is too old, and a return
             * that swallowed that failure would leave a customer waiting on nothing.
             */
            $this->refunds->openForReturn($updated, $actor);
        }

        $this->audit->record(
            action: 'fulfilment.return.'.$next->value,
            subject: $updated,
            context: ['reference' => $updated->reference],
            actor: $actor,
        );

        return $updated;
    }

    /**
     * Whether anything unresolved is holding this seller order's money.
     *
     * Asked by the settlement builder. Anything short of finished counts: a request nobody
     * has looked at yet is exactly the case where paying out would be most embarrassing.
     */
    public function blocksSettlement(SellerOrder $sellerOrder): bool
    {
        return ReturnRequest::query()
            ->where('seller_order_id', $sellerOrder->getKey())
            ->blocking()
            ->exists();
    }

    /** How much of a line is already inside a return that has not been refused. */
    public function returnedQuantity(OrderItem $item): int
    {
        return (int) ReturnItem::query()
            ->where('order_item_id', $item->getKey())
            ->whereHas('returnRequest', fn ($query) => $query->whereNotIn('status', [
                ReturnStatus::Rejected->value,
                ReturnStatus::Cancelled->value,
            ]))
            ->sum('quantity');
    }

    /** How many days after delivery a customer may still ask. */
    public function windowDays(): int
    {
        return (int) config('refconcept.returns.window_days', 14);
    }

    // --- internals -----------------------------------------------------------

    /**
     * Puts accepted goods back on the shelf.
     *
     * Only what was accepted, and only once the seller has it in their hands. Restocking
     * on approval would put a sofa back on sale while it is still in a courier's van.
     */
    private function restock(ReturnRequest $return, ?User $actor): void
    {
        $return->loadMissing('items.orderItem');

        foreach ($return->items as $item) {
            if ($item->approved_quantity < 1 || $item->orderItem?->sku_id === null) {
                continue;
            }

            $sku = ProductSku::query()->find($item->orderItem->sku_id);

            if ($sku === null || ! $sku->stock_policy->tracksQuantity()) {
                continue;
            }

            $this->stock->adjust(
                $this->stock->itemFor($sku),
                $item->approved_quantity,
                MovementType::Return_,
                $actor,
                'İade: '.$return->reference,
                'return',
                (string) $return->getKey(),
            );
        }
    }

    private function reference(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $reference = 'IA-'.now()->format('Ym').'-'.Str::upper(Str::random(5));

            if (! ReturnRequest::query()->where('reference', $reference)->exists()) {
                return $reference;
            }
        }

        throw new \RuntimeException('İade referansı üretilemedi.');
    }
}
