<?php

declare(strict_types=1);

use App\Domains\Commerce\Enums\CartStatus;
use App\Domains\Commerce\Enums\LineIssue;
use App\Domains\Commerce\Exceptions\CartRefused;
use App\Domains\Commerce\Models\Cart;
use App\Domains\Commerce\Models\CartItem;
use App\Domains\Commerce\Services\CartService;
use App\Domains\Identity\Models\User;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\ReservationStatus;
use App\Domains\Inventory\Models\StockReservation;
use App\Domains\Inventory\Services\InventoryLedger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * What happens to a basket when the world moves underneath it.
 *
 * The gate for this phase, and the reason it is the gate: a cart is a promise made at a
 * moment and honoured later, and everything difficult about one happens in between. A
 * price rises. The last unit sells. Two customers reach for the same sofa within a second
 * of each other. None of that is exotic — it is a Tuesday on a marketplace — and the
 * failure mode is always the same: somebody finds out at payment.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->carts = app(CartService::class);
    $this->stock = app(InventoryLedger::class);

    [$this->seller] = makeApprovedSeller('Sepet Test A.Ş.', 'sepet-test');

    $this->category = makeCategory('Kanepe', 'kanepe', 'living_room');

    $this->product = makeProduct($this->seller, $this->category, [
        'name' => 'Test kanepe',
        'description' => 'Sepet testleri için kanepe.',
        'price_minor' => 2_000_000,
        'width_mm' => 2_000,
        'stock_quantity' => 5,
    ]);

    $this->sku = $this->product->skus->first();

    // The catalogue's `stock_quantity` is the seller's own figure; the ledger is what
    // actually decides availability, so it gets the opening balance too.
    $this->stockItem = $this->stock->itemFor($this->sku);
    $this->stock->adjust($this->stockItem, 5, MovementType::Receipt);

    $this->customer = User::factory()->create();
    $this->customer->forceFill(['email_verified_at' => now()])->save();

    $this->other = User::factory()->create();
    $this->other->forceFill(['email_verified_at' => now()])->save();
});

it('gives a customer exactly one basket', function (): void {
    $first = $this->carts->forUser($this->customer);
    $second = $this->carts->forUser($this->customer);

    expect($second->getKey())->toBe($first->getKey())
        // Enforced by a partial unique index as well: two open carts means items split
        // across two places with only one of them visible.
        ->and(Cart::query()->where('user_id', $this->customer->getKey())->count())->toBe(1);
});

it('raises the quantity rather than making a second line', function (): void {
    $this->carts->add($this->customer, $this->sku, 1);
    $this->carts->add($this->customer, $this->sku, 2);

    $cart = $this->carts->forUser($this->customer)->fresh(['items']);

    expect($cart->items)->toHaveCount(1)
        ->and($cart->items->first()->quantity)->toBe(3)
        ->and($cart->itemCount())->toBe(3);
});

it('snapshots the price it showed', function (): void {
    $this->carts->add($this->customer, $this->sku, 1);

    $this->sku->forceFill(['list_price_minor' => 2_500_000])->save();

    $item = CartItem::query()->firstOrFail();

    // The customer agreed to what was on the page, not to whatever it costs now.
    expect($item->unit_price_minor)->toBe(2_000_000)
        ->and($item->fresh(['sku'])->priceHasMoved())->toBeTrue();
});

it('reports a price rise without applying it', function (): void {
    $this->carts->add($this->customer, $this->sku, 2);

    $this->sku->forceFill(['list_price_minor' => 2_500_000])->save();

    $cart = $this->carts->forUser($this->customer);
    $issues = $this->carts->revalidate($cart);

    expect($issues)->toHaveCount(1)
        ->and($issues[0]['issue'])->toBe(LineIssue::PriceIncreased)
        ->and($issues[0]['from'])->toBe(2_000_000)
        ->and($issues[0]['to'])->toBe(2_500_000)
        /*
         * The line still says what it said. Quietly rewriting the snapshot would charge
         * somebody more than they were shown, which is the whole thing this mechanism
         * exists to prevent.
         */
        ->and(CartItem::query()->firstOrFail()->unit_price_minor)->toBe(2_000_000)
        ->and($cart->fresh(['items'])->subtotalMinor())->toBe(4_000_000);
});

it('does not block checkout when a price falls', function (): void {
    $this->carts->add($this->customer, $this->sku, 1);

    $this->sku->forceFill(['sale_price_minor' => 1_500_000])->save();

    $issues = $this->carts->revalidate($this->carts->forUser($this->customer));

    // Nobody is harmed by paying less, and stopping a checkout to say "good news" is how
    // a checkout loses people.
    expect($issues[0]['issue'])->toBe(LineIssue::PriceDecreased)
        ->and($issues[0]['issue']->blocksCheckout())->toBeFalse();
});

it('accepts a new price only when the customer says so', function (): void {
    $this->carts->add($this->customer, $this->sku, 1);
    $this->sku->forceFill(['list_price_minor' => 2_400_000])->save();

    $this->carts->revalidate($this->carts->forUser($this->customer));

    expect(CartItem::query()->firstOrFail()->price_changed_at)->not->toBeNull();

    $this->carts->acceptPriceChanges($this->customer);

    $item = CartItem::query()->firstOrFail();

    // An explicit act, so the higher figure is something they agreed to rather than
    // something that happened while they were not looking.
    expect($item->unit_price_minor)->toBe(2_400_000)
        ->and($item->price_changed_at)->toBeNull();
});

it('removes a line nobody can buy any more', function (): void {
    $this->carts->add($this->customer, $this->sku, 1);

    // The seller pauses the offer, which is what withdrawing one actually looks like.
    $this->sku->forceFill(['status' => 'paused'])->save();

    $issues = $this->carts->revalidate($this->carts->forUser($this->customer));

    /*
     * Removed rather than left greyed out. A basket that carries something unbuyable is a
     * basket that fails at payment, and payment is the worst possible moment to find out.
     */
    expect($issues[0]['issue'])->toBe(LineIssue::OutOfStock)
        ->and($issues[0]['issue']->isFatal())->toBeTrue()
        ->and(CartItem::query()->count())->toBe(0);
});

it('reduces a line rather than removing it when some are left', function (): void {
    $this->carts->add($this->customer, $this->sku, 4);

    // Somebody else buys three of the five.
    $this->stock->adjust($this->stockItem->fresh(), -3, MovementType::Adjustment, reason: 'Test satışı');

    $issues = $this->carts->revalidate($this->carts->forUser($this->customer));

    // Somebody who wanted four and can have two would rather have two than nothing.
    expect($issues[0]['issue'])->toBe(LineIssue::QuantityReduced)
        ->and($issues[0]['from'])->toBe(4)
        ->and($issues[0]['to'])->toBe(2)
        ->and(CartItem::query()->firstOrFail()->quantity)->toBe(2);
});

it('refuses to add more than exists', function (): void {
    // Told now rather than at payment, and told how many are actually left.
    try {
        $this->carts->add($this->customer, $this->sku, 9);

        expect(false)->toBeTrue('Stoktan fazlası sepete eklendi.');
    } catch (CartRefused $e) {
        expect($e->status)->toBe(409)
            ->and($e->getMessage())->toContain('5');
    }

    expect(CartItem::query()->count())->toBe(0);
});

it('holds no stock while a basket merely sits there', function (): void {
    $this->carts->add($this->customer, $this->sku, 3);

    /*
     * The decision this phase turns on. Holding stock for an open basket would mean a
     * browser tab left open for a week keeps a sofa off the market, and a marketplace's
     * job is to sell the sofa.
     */
    expect($this->stockItem->fresh()->reserved)->toBe(0)
        ->and($this->stock->sellableFor($this->sku))->toBe(5);
});

it('takes the hold at checkout and not before', function (): void {
    $this->carts->add($this->customer, $this->sku, 3);

    $result = $this->carts->beginCheckout($this->customer);

    expect($result['cart']->status)->toBe(CartStatus::CheckingOut)
        ->and($this->stockItem->fresh()->reserved)->toBe(3)
        // Two left for everybody else, which is the point.
        ->and($this->stock->sellableFor($this->sku))->toBe(2);

    $reservation = StockReservation::query()
        ->where('reference_type', 'cart')
        ->where('reference_id', $result['cart']->getKey())
        ->firstOrFail();

    expect($reservation->status)->toBe(ReservationStatus::Held)
        // A deadline, so an abandoned payment returns the stock without anybody noticing.
        ->and($reservation->expires_at)->not->toBeNull();
});

it('gives the stock back when a customer backs out of payment', function (): void {
    $this->carts->add($this->customer, $this->sku, 3);
    $this->carts->beginCheckout($this->customer);

    $cart = $this->carts->abandonCheckout($this->customer);

    expect($cart->status)->toBe(CartStatus::Open)
        ->and($this->stockItem->fresh()->reserved)->toBe(0)
        /*
         * Released immediately rather than left to expire: fifteen minutes of a sofa
         * being unbuyable for no reason is fifteen minutes of somebody else being told
         * "sold out".
         */
        ->and($this->stock->sellableFor($this->sku))->toBe(5);
});

it('refuses to change a basket that is being paid for', function (): void {
    $this->carts->add($this->customer, $this->sku, 1);
    $this->carts->beginCheckout($this->customer);

    // Otherwise the held quantity and the basket would disagree, and the order would be
    // built from one of them.
    expect(fn () => $this->carts->add($this->customer, $this->sku, 1))
        ->toThrow(CartRefused::class);

    expect(fn () => $this->carts->clear($this->customer))
        ->toThrow(CartRefused::class);
});

it('holds all of a basket or none of it', function (): void {
    $second = makeProduct($this->seller, $this->category, [
        'name' => 'İkinci kanepe',
        'description' => 'Stoğu tükenmek üzere.',
        'price_minor' => 1_000_000,
        'width_mm' => 1_800,
        'stock_quantity' => 1,
    ]);

    $secondSku = $second->fresh(['skus'])->skus->first();
    $secondItem = $this->stock->itemFor($secondSku);
    $this->stock->adjust($secondItem, 1, MovementType::Receipt);

    $this->carts->add($this->customer, $this->sku, 2);
    $this->carts->add($this->customer, $secondSku, 1);

    // The last of the second product goes to somebody else, after the basket was built.
    $this->stock->adjust($secondItem->fresh(), -1, MovementType::Adjustment, reason: 'Test satışı');

    $result = $this->carts->beginCheckout($this->customer);

    /*
     * Revalidation catches it before any hold is taken, so the basket comes back with an
     * explanation rather than half a reservation. A customer told "two of your three items
     * are yours" has been handed a problem rather than an order.
     */
    expect($result['cart']->status)->toBe(CartStatus::Open)
        ->and($result['issues'])->not->toBeEmpty()
        ->and($this->stockItem->fresh()->reserved)->toBe(0);
});

it('refuses checkout on a basket somebody emptied in another tab', function (): void {
    expect(fn () => $this->carts->beginCheckout($this->customer))
        ->toThrow(CartRefused::class);
});

it('takes the row lock before deciding anything', function (): void {
    $locking = [];

    DB::listen(function ($query) use (&$locking): void {
        if (str_contains($query->sql, 'for update') && str_contains($query->sql, 'carts')) {
            $locking[] = $query->sql;
        }
    });

    $this->carts->add($this->customer, $this->sku, 1);

    /*
     * Two tabs adding the same product would otherwise both read "not in the basket" and
     * both insert — and one would lose to the unique index, turning a double click into an
     * error the customer sees.
     */
    expect($locking)->not->toBeEmpty();
});

it('cannot be talked into over-reserving by a stale cart', function (): void {
    $this->carts->add($this->customer, $this->sku, 5);

    // The other customer takes four of the five before this basket reaches checkout.
    $this->carts->add($this->other, $this->sku, 4);
    $this->carts->beginCheckout($this->other);

    $result = $this->carts->beginCheckout($this->customer);

    // One left, five wanted: reduced and reported rather than reserved.
    expect($result['cart']->status)->toBe(CartStatus::Open)
        ->and($result['issues'][0]['issue'])->toBe(LineIssue::QuantityReduced)
        ->and($this->stock->sellableFor($this->sku))->toBe(1);
});

it('never lets two baskets hold more than exists', function (): void {
    $this->carts->add($this->customer, $this->sku, 3);
    $this->carts->add($this->other, $this->sku, 3);

    $this->carts->beginCheckout($this->customer);

    // Three held, two left; the second basket wanted three and is cut to two.
    $second = $this->carts->beginCheckout($this->other);

    $item = $this->stockItem->fresh();

    expect($item->reserved)->toBeLessThanOrEqual($item->on_hand)
        ->and($second['issues'])->not->toBeEmpty();

    $held = (int) StockReservation::query()
        ->where('stock_item_id', $item->getKey())
        ->where('status', ReservationStatus::Held->value)
        ->sum('quantity');

    // The invariant that matters: however the two baskets interleave, the warehouse never
    // promises more than it has.
    expect($held)->toBeLessThanOrEqual(5);
});

it('keeps the seller on every line', function (): void {
    $this->carts->add($this->customer, $this->sku, 1);

    $cart = $this->carts->forUser($this->customer)->fresh(['items']);

    /*
     * A marketplace basket is several parcels from several shops. The seller is recorded on
     * the line rather than looked up through the SKU, so a basket keeps saying who was
     * selling something even after the offer is withdrawn.
     */
    expect($cart->items->first()->seller_id)->toBe($this->seller->getKey())
        ->and($cart->bySeller())->toHaveCount(1);
});

it('counts tax as part of the price rather than on top of it', function (): void {
    $this->carts->add($this->customer, $this->sku, 1);

    $cart = $this->carts->forUser($this->customer)->fresh(['items']);

    /*
     * Turkish prices are quoted inclusive of KDV: a 20.000₺ price at 20% contains
     * 3.333,33₺ of tax, not 4.000₺. Getting this backwards overcharges every customer by
     * a fifth.
     */
    expect($cart->subtotalMinor())->toBe(2_000_000)
        ->and($cart->taxMinor())->toBe(333_333);
});

it('refuses an offer that is not on sale at all', function (): void {
    $hidden = makeProduct($this->seller, $this->category, [
        'name' => 'Yayınlanmamış kanepe',
        'description' => 'Moderasyondan geçmemiş.',
        'price_minor' => 500_000,
        'width_mm' => 1_500,
    ]);

    // Back to where a listing starts: a published product must be approved, and the
    // schema refuses any other combination.
    $hidden->forceFill([
        'moderation_status' => 'pending_review',
        'status' => 'draft',
        'published_at' => null,
    ])->save();

    // A basket that accepts something the catalogue will not show is a basket that fails
    // at payment.
    expect(fn () => $this->carts->add($this->customer, $hidden->fresh(['skus'])->skus->first(), 1))
        ->toThrow(CartRefused::class);
});

it('will not touch another customer line', function (): void {
    $this->carts->add($this->customer, $this->sku, 1);

    $item = CartItem::query()->firstOrFail();

    expect(fn () => $this->carts->setQuantity($this->other, $item, 5))
        ->toThrow(CartRefused::class);

    expect($item->fresh()->quantity)->toBe(1);
});
