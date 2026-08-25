<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Services;

use App\Domains\Commerce\Enums\CartStatus;
use App\Domains\Commerce\Enums\LineIssue;
use App\Domains\Commerce\Exceptions\CartRefused;
use App\Domains\Commerce\Models\Cart;
use App\Domains\Commerce\Models\CartItem;
use App\Domains\Identity\Models\User;
use App\Domains\Inventory\Exceptions\InsufficientStock;
use App\Domains\Inventory\Services\InventoryLedger;
use App\Domains\Products\Models\ProductSku;
use Illuminate\Support\Facades\DB;

/**
 * The basket, and the two things that can go wrong with one.
 *
 * A cart is a promise made at a moment and honoured later, and between the two the world
 * moves: prices change and stock runs out. Everything in this class is about handling that
 * honestly rather than pretending it does not happen.
 *
 * **Stock is not held while something sits in a basket.** That is a deliberate decision and
 * the opposite one is tempting. Holding it would mean a browser tab left open for a week
 * keeps a sofa off the market, and a marketplace's job is to sell the sofa. So a cart is a
 * wish list with prices attached, and the hold is taken for minutes at checkout by the
 * ledger that already knows how to do it safely.
 *
 * **A price is snapshotted when the line is added.** Revalidation compares the snapshot to
 * today and *reports*: it never silently applies a rise, and never blocks a fall. A
 * customer who is quietly charged more than they were shown has been misled, whatever the
 * terms say.
 */
final class CartService
{
    /**
     * How long a checkout hold lasts.
     *
     * Long enough to finish paying — 3D Secure, a bank app, a mistyped card — and short
     * enough that an abandoned attempt returns the stock to sale within the same shopping
     * session somebody else is having.
     */
    public const CHECKOUT_HOLD_SECONDS = 900;

    public function __construct(private readonly InventoryLedger $stock) {}

    /**
     * The customer's basket, created on first use.
     *
     * One open cart per customer, enforced by a partial unique index. Two would mean items
     * split across two places with only one of them visible.
     */
    public function forUser(User $user): Cart
    {
        $existing = Cart::query()
            ->where('user_id', $user->getKey())
            ->open()
            ->first();

        return $existing ?? Cart::query()->create(['user_id' => $user->getKey()]);
    }

    /**
     * Adds an offer, or raises the quantity if it is already there.
     *
     * @throws CartRefused
     */
    public function add(User $user, ProductSku $sku, int $quantity = 1, ?string $designMatchId = null): CartItem
    {
        if ($quantity < 1 || $quantity > 99) {
            throw CartRefused::invalidQuantity();
        }

        $sku->loadMissing(['product', 'seller']);

        if (! $sku->isAvailable() || $sku->product?->isPubliclyVisible() !== true) {
            /*
             * Refused rather than added-and-flagged. A basket that accepts something
             * nobody can buy is a basket that fails at payment, which is the worst moment
             * to find out.
             */
            throw CartRefused::notPurchasable();
        }

        return DB::transaction(function () use ($user, $sku, $quantity, $designMatchId): CartItem {
            $cart = $this->lockedCartFor($user);

            if (! $cart->status->isEditable()) {
                throw CartRefused::notEditable();
            }

            $existing = CartItem::query()
                ->where('cart_id', $cart->getKey())
                ->where('sku_id', $sku->getKey())
                ->first();

            $wanted = ($existing->quantity ?? 0) + $quantity;

            if ($wanted > 99) {
                throw CartRefused::invalidQuantity();
            }

            // Checked against what is actually sellable, so a customer is told now rather
            // than at payment. Not *held* — see the class comment.
            $this->assertAvailable($sku, $wanted);

            $price = $sku->effectivePrice()->amountMinor;

            if ($existing !== null) {
                $existing->forceFill([
                    'quantity' => $wanted,
                    /*
                     * Adding more re-snapshots the price. The customer is looking at
                     * today's figure when they press the button, so that is the number
                     * they have agreed to — carrying an older one forward would show them
                     * a total they cannot find on the page.
                     */
                    'unit_price_minor' => $price,
                    'list_price_minor' => $sku->list_price_minor->amountMinor,
                    'price_changed_at' => null,
                ])->save();

                $this->touch($cart);

                return $existing;
            }

            $item = CartItem::query()->create([
                'cart_id' => $cart->getKey(),
                'sku_id' => $sku->getKey(),
                'product_id' => $sku->product_id,
                'seller_id' => $sku->seller_id,
                'quantity' => $quantity,
                'unit_price_minor' => $price,
                'list_price_minor' => $sku->list_price_minor->amountMinor,
                'tax_rate_bps' => $sku->tax_rate_bps,
                'design_match_id' => $designMatchId,
            ]);

            $this->touch($cart);

            return $item;
        });
    }

    /**
     * Sets a line's quantity, or removes it at zero.
     *
     * @throws CartRefused
     */
    public function setQuantity(User $user, CartItem $item, int $quantity): ?CartItem
    {
        return DB::transaction(function () use ($user, $item, $quantity): ?CartItem {
            $cart = $this->lockedCartFor($user);

            if ($item->cart_id !== $cart->getKey()) {
                throw CartRefused::notYours();
            }

            if (! $cart->status->isEditable()) {
                throw CartRefused::notEditable();
            }

            if ($quantity <= 0) {
                $item->delete();
                $this->touch($cart);

                return null;
            }

            if ($quantity > 99) {
                throw CartRefused::invalidQuantity();
            }

            $item->loadMissing('sku');

            if ($item->sku === null) {
                throw CartRefused::notPurchasable();
            }

            $this->assertAvailable($item->sku, $quantity);

            $item->forceFill(['quantity' => $quantity])->save();
            $this->touch($cart);

            return $item;
        });
    }

    public function remove(User $user, CartItem $item): void
    {
        $this->setQuantity($user, $item, 0);
    }

    public function clear(User $user): Cart
    {
        return DB::transaction(function () use ($user): Cart {
            $cart = $this->lockedCartFor($user);

            if (! $cart->status->isEditable()) {
                throw CartRefused::notEditable();
            }

            $cart->items()->delete();
            $this->touch($cart);

            return $cart;
        });
    }

    /**
     * Compares the basket against the world as it is now.
     *
     * The heart of the phase. Returns a list of what changed rather than changing anything
     * silently — except for the two cases where leaving the line alone would be worse than
     * touching it: a line nobody can buy is removed, and a line asking for more than exists
     * is reduced. Both are reported.
     *
     * A price is never adjusted here. Showing a customer one figure and charging another is
     * the thing this whole mechanism exists to prevent, and quietly "fixing" the snapshot
     * would be exactly that with extra steps.
     *
     * @return array<int, array{item_id: string, product: string|null, issue: LineIssue, from: int|null, to: int|null}>
     */
    public function revalidate(Cart $cart): array
    {
        /*
         * The product's SKUs are loaded too, because isPubliclyVisible() reads them —
         * without it this is an N+1 on the single busiest endpoint a shop has, and strict
         * mode only makes it visible outside production.
         */
        $cart->loadMissing(['items.sku.seller', 'items.product.skus.seller']);

        /*
         * What this basket is already holding, per SKU.
         *
         * Without it a customer who takes the last three of three into checkout and then
         * reloads the page is told the thing they are buying is sold out — by their own
         * hold — and the basket empties itself. The ledger is right that nothing is
         * sellable; it is this cart that the stock is not sellable *to* anybody else.
         */
        $ownHolds = $this->heldQuantitiesFor($cart);

        $issues = [];

        foreach ($cart->items as $item) {
            $sku = $item->sku;
            $name = $item->product?->name;

            /*
             * Whether the offer exists at all, not whether any are left: the quantity is
             * the ledger's business and is checked against it a few lines down, where this
             * cart's own reservations are counted properly.
             */
            if ($sku === null || ! $sku->isOffered() || $item->product?->isListable() !== true) {
                $issues[] = [
                    'item_id' => (string) $item->getKey(),
                    'product' => $name,
                    'issue' => $sku === null ? LineIssue::Unavailable : LineIssue::OutOfStock,
                    'from' => $item->quantity,
                    'to' => 0,
                ];

                // Removed rather than left for the customer to discover at payment.
                $item->delete();

                continue;
            }

            $sellable = $this->stock->sellableFor($sku)
                + ($ownHolds[(string) $sku->getKey()] ?? 0);

            if ($sku->stock_policy->tracksQuantity() && $sellable < $item->quantity) {
                if ($sellable <= 0) {
                    $issues[] = [
                        'item_id' => (string) $item->getKey(),
                        'product' => $name,
                        'issue' => LineIssue::OutOfStock,
                        'from' => $item->quantity,
                        'to' => 0,
                    ];

                    $item->delete();

                    continue;
                }

                $issues[] = [
                    'item_id' => (string) $item->getKey(),
                    'product' => $name,
                    'issue' => LineIssue::QuantityReduced,
                    'from' => $item->quantity,
                    'to' => $sellable,
                ];

                // Reduced, not removed: somebody who wanted three and can have two would
                // rather have two than nothing.
                $item->forceFill(['quantity' => $sellable])->save();
            }

            $current = $sku->effectivePrice()->amountMinor;

            if ($current !== $item->unit_price_minor) {
                $issues[] = [
                    'item_id' => (string) $item->getKey(),
                    'product' => $name,
                    'issue' => $current > $item->unit_price_minor
                        ? LineIssue::PriceIncreased
                        : LineIssue::PriceDecreased,
                    'from' => $item->unit_price_minor,
                    'to' => $current,
                ];

                // Marked, not rewritten. What the customer agreed to is what they were
                // shown, and the new figure is something they get to accept.
                $item->forceFill(['price_changed_at' => now()])->save();
            }
        }

        return $issues;
    }

    /**
     * Accepts the current prices for the whole basket.
     *
     * The customer's answer to "this went up" — an explicit act, so the higher figure is
     * something they agreed to rather than something that happened to them.
     */
    public function acceptPriceChanges(User $user): Cart
    {
        return DB::transaction(function () use ($user): Cart {
            $cart = $this->lockedCartFor($user);
            $cart->loadMissing('items.sku');

            foreach ($cart->items as $item) {
                if ($item->sku === null) {
                    continue;
                }

                $item->forceFill([
                    'unit_price_minor' => $item->sku->effectivePrice()->amountMinor,
                    'list_price_minor' => $item->sku->list_price_minor->amountMinor,
                    'price_changed_at' => null,
                ])->save();
            }

            $this->touch($cart);

            return $cart->fresh(['items']) ?? $cart;
        });
    }

    /**
     * Moves the basket into checkout and takes the stock hold.
     *
     * Everything is reserved together or nothing is — a customer who is told "two of your
     * four items are yours" has been given a problem rather than an order. The ledger locks
     * rows in a fixed order, so two baskets containing the same two products in opposite
     * orders queue instead of deadlocking.
     *
     * Revalidation runs first and refuses if anything material changed, because taking a
     * hold on a basket the customer has not seen the current state of is how somebody ends
     * up paying a price they never agreed to.
     *
     * @return array{cart: Cart, issues: array<int, array<string, mixed>>}
     *
     * @throws CartRefused
     */
    public function beginCheckout(User $user): array
    {
        $cart = $this->forUser($user);

        if ($cart->isEmpty()) {
            throw CartRefused::empty();
        }

        $issues = $this->revalidate($cart);

        $blocking = array_filter(
            $issues,
            static fn (array $issue): bool => $issue['issue']->blocksCheckout(),
        );

        if ($blocking !== []) {
            // Not an error: the customer is shown what moved and asked again. Refusing
            // silently, or proceeding anyway, are the two ways to lose their trust.
            return ['cart' => $cart->fresh(['items']) ?? $cart, 'issues' => array_values($issues)];
        }

        return DB::transaction(function () use ($user, $issues): array {
            $locked = $this->lockedCartFor($user);
            $locked->loadMissing('items.sku');

            if ($locked->isEmpty()) {
                throw CartRefused::empty();
            }

            $quantities = [];

            foreach ($locked->items as $item) {
                if ($item->sku === null || ! $item->sku->stock_policy->tracksQuantity()) {
                    // Untracked stock — made to order, digital — needs no hold. Reserving
                    // against a quantity nobody maintains would be theatre.
                    continue;
                }

                $stockItem = $this->stock->itemFor($item->sku);
                $quantities[(string) $stockItem->getKey()] = $item->quantity;
            }

            if ($quantities !== []) {
                try {
                    $this->stock->reserveMany(
                        $quantities,
                        'cart',
                        (string) $locked->getKey(),
                        self::CHECKOUT_HOLD_SECONDS,
                    );
                } catch (InsufficientStock $e) {
                    /*
                     * Somebody bought the last one between revalidation and the lock. The
                     * transaction rolls back — so no partial hold survives — and the
                     * customer is told which item, by name.
                     */
                    throw CartRefused::stockVanished($e->getMessage());
                }
            }

            $locked->forceFill([
                'status' => CartStatus::CheckingOut,
                'checked_out_at' => now(),
            ])->save();

            return ['cart' => $locked->fresh(['items']) ?? $locked, 'issues' => array_values($issues)];
        });
    }

    /**
     * Returns a basket to editable and gives the stock back.
     *
     * The path when somebody backs out of payment. The reservations are released
     * immediately rather than left to expire, because fifteen minutes of a sofa being
     * unbuyable for no reason is fifteen minutes of somebody else being told "sold out".
     */
    public function abandonCheckout(User $user): Cart
    {
        return DB::transaction(function () use ($user): Cart {
            $cart = $this->lockedCartFor($user);

            if ($cart->status !== CartStatus::CheckingOut) {
                return $cart;
            }

            $this->releaseHolds($cart);

            $cart->forceFill(['status' => CartStatus::Open, 'checked_out_at' => null])->save();

            return $cart;
        });
    }

    /**
     * How much of each SKU this cart is already holding.
     *
     * Empty for a cart that is merely open — nothing is held while a basket sits there —
     * so the ordinary path costs one query that returns nothing.
     *
     * @return array<string, int>
     */
    private function heldQuantitiesFor(Cart $cart): array
    {
        if (! $cart->status->holdsStock()) {
            return [];
        }

        $held = [];

        foreach ($this->stock->reservationsFor('cart', (string) $cart->getKey()) as $reservation) {
            $skuId = (string) ($reservation->stockItem->sku_id ?? '');

            if ($skuId === '') {
                continue;
            }

            $held[$skuId] = ($held[$skuId] ?? 0) + $reservation->quantity;
        }

        return $held;
    }

    /** Releases every hold this cart is carrying. */
    public function releaseHolds(Cart $cart): int
    {
        $released = 0;

        foreach ($this->stock->reservationsFor('cart', (string) $cart->getKey()) as $reservation) {
            $this->stock->release($reservation);
            $released++;
        }

        return $released;
    }

    // --- internals -----------------------------------------------------------

    /**
     * The cart, locked for the duration of a change.
     *
     * Two tabs adding the same product at the same moment would otherwise both read "not
     * in the basket" and both insert — and one of them would lose to the unique index,
     * turning a double click into an error the customer sees.
     */
    private function lockedCartFor(User $user): Cart
    {
        $cart = $this->forUser($user);

        /** @var Cart $locked */
        $locked = Cart::query()->lockForUpdate()->findOrFail($cart->getKey());

        return $locked;
    }

    /**
     * @throws CartRefused
     */
    private function assertAvailable(ProductSku $sku, int $wanted): void
    {
        if (! $sku->stock_policy->tracksQuantity()) {
            return;
        }

        $sellable = $this->stock->sellableFor($sku);

        if ($sellable < $wanted) {
            throw CartRefused::notEnoughStock($sellable);
        }
    }

    private function touch(Cart $cart): void
    {
        $cart->forceFill(['last_activity_at' => now()])->save();
    }
}
