<?php

declare(strict_types=1);

namespace App\Domains\Orders\Services;

use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Orders\Models\OrderStatusChange;
use App\Domains\Orders\Models\SellerOrder;
use App\Domains\Orders\Notifications\SellerOrderPlaced;
use App\Domains\Payments\Models\CheckoutSession;
use App\Domains\Payments\Services\CheckoutFulfiller;
use App\Domains\Sellers\Models\Seller;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Turns a paid checkout into an order.
 *
 * Called once, from {@see CheckoutFulfiller}, at the moment
 * a payment transitions to captured. Everything about it is built on the assumption that
 * "once" will eventually be wrong — a webhook delivered four times, a retried job, two
 * workers — so the unique index on `checkout_session_id` is the real guarantee and this
 * class simply returns the existing order when it loses that race.
 *
 * **The order is built from the session's snapshot, not from the cart.** By the time this
 * runs the customer has paid, and what they paid for is what the session froze: the cart
 * may already have been emptied in another tab, and the prices in it may have moved. The
 * cart is used only for the things a snapshot does not carry — the image and the SKU code
 * a seller needs on a picking list — and never for a number.
 */
final class OrderFactory
{
    public function __construct(private readonly OrderNumbers $numbers) {}

    /**
     * Builds the master order and its seller orders.
     *
     * Returns the existing order if there already is one, which is the duplicate defence
     * doing its job rather than an error.
     */
    public function fromSession(CheckoutSession $session): Order
    {
        $existing = Order::query()->where('checkout_session_id', $session->getKey())->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            $order = DB::transaction(fn (): Order => $this->build($session));
        } catch (QueryException $e) {
            /*
             * Two confirmations arrived at the same instant and the unique index settled
             * it. The loser reports the winner's order — which is exactly right: one
             * payment, one order.
             */
            $winner = Order::query()->where('checkout_session_id', $session->getKey())->first();

            if ($winner === null) {
                throw $e;
            }

            return $winner;
        }

        $this->notifySellers($order);

        return $order;
    }

    private function build(CheckoutSession $session): Order
    {
        $cart = $session->cart;
        $cart?->loadMissing(['items.product.media', 'items.sku', 'items.seller']);

        $order = Order::query()->create([
            'order_number' => $this->numbers->next(),
            'user_id' => $session->user_id,
            'checkout_session_id' => $session->getKey(),
            'payment_intent_id' => $session->paidIntent()?->getKey(),
            'currency' => $session->currency,
            'subtotal_minor' => $session->subtotal_minor,
            'discount_minor' => $session->discount_minor,
            'shipping_minor' => $session->shipping_minor,
            'tax_minor' => $session->tax_minor,
            'grand_total_minor' => $session->grand_total_minor,
            'shipping_address' => $session->shipping_address ?? [],
            'billing_address' => $session->billing_address ?? $session->shipping_address ?? [],
            'customer_email' => $session->user?->email,
            'placed_at' => now(),
        ]);

        /** @var array<string, SellerOrder> $sellerOrders */
        $sellerOrders = [];
        $sequence = 0;

        foreach ($session->lines ?? [] as $line) {
            if (($line['type'] ?? 'product') !== 'product') {
                // A credit package has no seller and no parcel; it was fulfilled by the
                // credit ledger and has no business being an order line.
                continue;
            }

            $sellerId = (string) ($line['seller_id'] ?? '');

            if ($sellerId === '') {
                Log::error('Sipariş satırında satıcı yok.', ['order' => $order->getKey()]);

                continue;
            }

            if (! isset($sellerOrders[$sellerId])) {
                $sequence++;
                $sellerOrders[$sellerId] = $this->openSellerOrder($order, $sellerId, $sequence);
            }

            $this->addItem($order, $sellerOrders[$sellerId], $line, $cart);
        }

        foreach ($sellerOrders as $sellerOrder) {
            $this->totalise($sellerOrder);
        }

        /*
         * The history starts at the beginning.
         *
         * Without this the first entry an order ever gets is its first *change*, and
         * "when was this placed" has to be inferred from a timestamp on another table.
         * A record of events that omits the first event is a record with a hole in it.
         */
        OrderStatusChange::query()->create([
            'order_id' => $order->getKey(),
            'from_status' => null,
            'to_status' => $order->status->value,
            'actor_role' => 'system',
        ]);

        return $order->fresh(['sellerOrders.seller', 'items']) ?? $order;
    }

    private function openSellerOrder(Order $order, string $sellerId, int $sequence): SellerOrder
    {
        return SellerOrder::query()->create([
            'order_id' => $order->getKey(),
            'seller_id' => $sellerId,
            'seller_order_number' => $this->numbers->forSeller($order->order_number, $sequence),
            'currency' => $order->currency,
            'subtotal_minor' => 0,
            'total_minor' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function addItem(Order $order, SellerOrder $sellerOrder, array $line, ?object $cart): void
    {
        $skuId = isset($line['sku_id']) ? (string) $line['sku_id'] : null;

        // The cart is consulted only for things a price snapshot does not carry: the image
        // and the SKU code a picking list needs. Never for a number.
        $cartLine = $cart?->items->firstWhere('sku_id', $skuId);

        $quantity = (int) ($line['quantity'] ?? 1);
        $unitPrice = (int) ($line['unit_price_minor'] ?? 0);
        $lineTotal = (int) ($line['line_total_minor'] ?? $unitPrice * $quantity);

        $commissionBps = $this->commissionBpsFor($sellerOrder->seller_id);

        OrderItem::query()->create([
            'order_id' => $order->getKey(),
            'seller_order_id' => $sellerOrder->getKey(),
            'seller_id' => $sellerOrder->seller_id,
            'product_id' => $line['product_id'] ?? null,
            'sku_id' => $skuId,
            'product_name' => (string) ($line['name'] ?? $cartLine?->product->name ?? 'Ürün'),
            'sku_code' => $cartLine?->sku?->sku,
            'variant_label' => $cartLine?->sku?->variant_label,
            'image_url' => $cartLine?->product?->media->first()?->url(),
            'quantity' => $quantity,
            'unit_price_minor' => $unitPrice,
            'list_price_minor' => $cartLine?->list_price_minor,
            'tax_rate_bps' => (int) ($line['tax_rate_bps'] ?? 2000),
            'line_total_minor' => $lineTotal,
            'tax_minor' => (int) ($line['tax_minor'] ?? 0),
            /*
             * Snapshotted, not looked up later. A seller who renegotiates their rate next
             * quarter must not retroactively change what they earned on this sale — which
             * is the rule in 06_SECURITY_PAYMENT_FINANCE_RULES.md and the reason the
             * column exists before Phase 16 builds the resolver that fills it properly.
             */
            'commission_rate_bps' => $commissionBps,
            'commission_minor' => (int) round($lineTotal * $commissionBps / 10_000),
            'design_match_id' => $cartLine?->design_match_id,
        ]);
    }

    private function totalise(SellerOrder $sellerOrder): void
    {
        $items = OrderItem::query()->where('seller_order_id', $sellerOrder->getKey())->get();

        $subtotal = (int) $items->sum('line_total_minor');

        $sellerOrder->forceFill([
            'subtotal_minor' => $subtotal,
            'tax_minor' => (int) $items->sum('tax_minor'),
            'total_minor' => $subtotal,
            'commission_minor' => (int) $items->sum('commission_minor'),
        ])->save();
    }

    /**
     * The seller's rate, or the platform default.
     *
     * The full hierarchy — campaign, seller+category, category — is Phase 16's. This is the
     * bottom two rungs of it, which is what exists today, and it is read here rather than
     * at payout time because the snapshot has to be taken now.
     */
    private function commissionBpsFor(string $sellerId): int
    {
        $sellerRate = Seller::query()->whereKey($sellerId)->value('default_commission_bps');

        return (int) ($sellerRate ?? config('refconcept.commission.platform_default_bps', 1200));
    }

    /**
     * Tells each seller they have something to pack.
     *
     * Outside the transaction on purpose: a mail server having a bad afternoon must not
     * roll back an order the customer has already paid for.
     */
    private function notifySellers(Order $order): void
    {
        $order->loadMissing(['sellerOrders.seller.organization.members']);

        foreach ($order->sellerOrders as $sellerOrder) {
            $recipients = $sellerOrder->seller?->organization->members ?? collect();

            if ($recipients->isEmpty()) {
                continue;
            }

            Notification::send($recipients, new SellerOrderPlaced($sellerOrder));
        }
    }
}
