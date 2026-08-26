<?php

declare(strict_types=1);

namespace App\Domains\Fulfilment\Services;

use App\Domains\Fulfilment\Exceptions\FulfilmentRefused;
use App\Domains\Fulfilment\Models\Shipment;
use App\Domains\Fulfilment\Models\ShipmentItem;
use App\Domains\Identity\Models\User;
use App\Domains\Orders\Enums\SellerOrderStatus;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Orders\Models\SellerOrder;
use App\Domains\Orders\Services\OrderStatusService;
use Illuminate\Support\Facades\DB;

/**
 * Parcels.
 *
 * A seller order can ship in several: a sofa today and its cushions on Thursday. That is
 * why quantities live on the shipment lines and why the seller order only becomes
 * `shipped` once everything ordered has actually gone — a seller who dispatches one of
 * four chairs and sees "kargoya verildi" has been given a status that will confuse their
 * customer for a week.
 *
 * The carrier is free text on purpose. A marketplace that accepts three carriers has told
 * its sellers how to run their warehouse.
 */
final class ShipmentService
{
    public function __construct(private readonly OrderStatusService $statuses) {}

    /**
     * Records a parcel and what went in it.
     *
     * @param  array<int, array{order_item_id: string, quantity: int}>  $lines
     *
     * @throws FulfilmentRefused
     */
    public function ship(
        SellerOrder $sellerOrder,
        array $lines,
        ?string $carrier,
        ?string $trackingNumber,
        ?User $actor = null,
    ): Shipment {
        if ($sellerOrder->status->hasShipped() && $sellerOrder->status !== SellerOrderStatus::Shipped) {
            throw FulfilmentRefused::alreadyClosed($sellerOrder->status->label());
        }

        if ($lines === []) {
            throw FulfilmentRefused::nothingToShip();
        }

        $shipment = DB::transaction(function () use ($sellerOrder, $lines, $carrier, $trackingNumber): Shipment {
            $shipment = Shipment::query()->create([
                'seller_order_id' => $sellerOrder->getKey(),
                'carrier' => $carrier,
                'tracking_number' => $trackingNumber,
                'status' => 'shipped',
                'shipped_at' => now(),
            ]);

            foreach ($lines as $line) {
                $item = OrderItem::query()
                    ->where('seller_order_id', $sellerOrder->getKey())
                    ->whereKey($line['order_item_id'])
                    ->first();

                if ($item === null) {
                    throw FulfilmentRefused::lineNotYours();
                }

                $already = $this->shippedQuantity($item);
                $wanted = max(1, (int) $line['quantity']);

                if ($already + $wanted > $item->quantity) {
                    /*
                     * Shipping more than was ordered is not a generous mistake — it makes
                     * the return and refund arithmetic unsolvable, because there is no
                     * order line to price the surplus against.
                     */
                    throw FulfilmentRefused::tooMany($item->product_name, $item->quantity - $already);
                }

                ShipmentItem::query()->create([
                    'shipment_id' => $shipment->getKey(),
                    'order_item_id' => $item->getKey(),
                    'quantity' => $wanted,
                ]);
            }

            return $shipment;
        });

        $this->syncSellerOrder($sellerOrder, $actor);

        return $shipment->fresh(['items']) ?? $shipment;
    }

    /**
     * Marks a parcel delivered, and the order with it when nothing is left.
     *
     * Delivery is what starts the settlement hold, so it is recorded per parcel and the
     * order follows only when every parcel has landed. Otherwise a seller's money would
     * start its clock on a partial delivery.
     */
    public function markDelivered(Shipment $shipment, ?User $actor = null): Shipment
    {
        $shipment->forceFill([
            'status' => 'delivered',
            'delivered_at' => $shipment->delivered_at ?? now(),
        ])->save();

        $sellerOrder = $shipment->sellerOrder;

        if ($sellerOrder === null) {
            return $shipment;
        }

        $outstanding = Shipment::query()
            ->where('seller_order_id', $sellerOrder->getKey())
            ->whereNotIn('status', ['delivered', 'returned'])
            ->exists();

        if (! $outstanding && $sellerOrder->status === SellerOrderStatus::Shipped) {
            $this->statuses->advance($sellerOrder, SellerOrderStatus::Delivered, $actor, 'seller');
        }

        return $shipment;
    }

    /** How much of one line has already gone out. */
    public function shippedQuantity(OrderItem $item): int
    {
        return (int) ShipmentItem::query()
            ->where('order_item_id', $item->getKey())
            ->sum('quantity');
    }

    /**
     * Moves the seller order along when everything ordered has gone.
     *
     * Not before. A seller who dispatches one of four chairs and sees "kargoya verildi"
     * has been given a status that will confuse their customer for a week.
     */
    private function syncSellerOrder(SellerOrder $sellerOrder, ?User $actor): void
    {
        $sellerOrder->loadMissing('items');

        foreach ($sellerOrder->items as $item) {
            if ($this->shippedQuantity($item) < $item->quantity) {
                return;
            }
        }

        if ($sellerOrder->status->isOpen()) {
            $this->statuses->advance($sellerOrder->fresh(), SellerOrderStatus::Shipped, $actor, 'seller');
        }
    }
}
