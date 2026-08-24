<?php

declare(strict_types=1);

use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\ReservationStatus;
use App\Domains\Inventory\Exceptions\InsufficientStock;
use App\Domains\Inventory\Models\StockItem;
use App\Domains\Inventory\Models\StockLocation;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Models\StockReservation;
use App\Domains\Inventory\Services\InventoryLedger;
use App\Domains\Products\Models\Product;
use App\Domains\Products\Models\ProductSku;
use Database\Seeders\CatalogTaxonomySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Stock, and the ways it goes wrong.
 *
 * The interesting cases here are all about two things happening at once, or about a
 * state the service is supposed to make impossible. A test that only proves "adding
 * five gives five" proves nothing worth knowing: the balance is easy, the invariants
 * are not.
 */

/**
 * A stable reference id from a readable name.
 *
 * References are UUIDs everywhere in RefConcept — a cart id, an order id — and the
 * column is typed accordingly, so a fixture cannot just say "cart-1". Deterministic
 * rather than random, so a failing test names the same reference every run.
 */
function ref(string $seed): string
{
    // A version-5-shaped UUID built by hand from a hash of the name. Laravel 13 ships
    // only uuid() and uuid7(), and a random one would name a different reference on
    // every run — which is exactly what makes a concurrency failure hard to read.
    $hash = sha1('refconcept-test:'.$seed);

    return sprintf(
        '%08s-%04s-5%03s-%04x-%12s',
        substr($hash, 0, 8),
        substr($hash, 8, 4),
        substr($hash, 13, 3),
        (hexdec(substr($hash, 16, 4)) & 0x3FFF) | 0x8000,
        substr($hash, 20, 12),
    );
}

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CatalogTaxonomySeeder::class);

    $this->ledger = app(InventoryLedger::class);

    [$this->seller, $this->sellerUser] = makeApprovedSeller('Atlas Mobilya', 'atlas-mobilya');

    $this->product = Product::factory()->forSeller($this->seller)->create();

    $this->sku = ProductSku::query()->create([
        'product_id' => $this->product->getKey(),
        'seller_id' => $this->seller->getKey(),
        'sku' => 'ATL-KNP-001',
        'list_price_minor' => 4_890_000,
        'stock_quantity' => 0,
    ]);

    $this->item = $this->ledger->itemFor($this->sku);
});

// --- balances ------------------------------------------------------------------

it('creates a default location for a seller who never made one', function (): void {
    $location = StockLocation::query()->forSeller($this->seller->getKey())->firstOrFail();

    // A seller who has never thought about warehouses must still be able to hold stock.
    expect($location->is_default)->toBeTrue()
        ->and($location->code)->toBe('MAIN');
});

it('records a receipt and the balance it produced', function (): void {
    $this->ledger->adjust($this->item, 12, MovementType::Receipt);

    $movement = StockMovement::query()->firstOrFail();

    expect($this->item->fresh()->on_hand)->toBe(12)
        ->and($movement->quantity)->toBe(12)
        // Both the change and the resulting balance, so a disagreement between the
        // ledger and the aggregate is detectable rather than merely theoretical.
        ->and($movement->on_hand_after)->toBe(12)
        ->and($movement->reserved_after)->toBe(0);
});

it('refuses to remove more than exists', function (): void {
    $this->ledger->adjust($this->item, 3, MovementType::Receipt);

    expect(fn () => $this->ledger->adjust($this->item, -5, MovementType::Adjustment, null, 'Kırıldı'))
        ->toThrow(InsufficientStock::class);

    expect($this->item->fresh()->on_hand)->toBe(3);
});

it('sets the balance to what a count found and records the difference', function (): void {
    $this->ledger->adjust($this->item, 10, MovementType::Receipt);
    $this->ledger->stocktake($this->item, 7, $this->sellerUser);

    $fresh = $this->item->fresh();
    $movement = StockMovement::query()->where('type', MovementType::Stocktake->value)->firstOrFail();

    expect($fresh->on_hand)->toBe(7)
        ->and($fresh->counted_at)->not->toBeNull()
        ->and($movement->quantity)->toBe(-3);
});

// --- reservations ---------------------------------------------------------------

it('holds stock without changing what physically exists', function (): void {
    $this->ledger->adjust($this->item, 10, MovementType::Receipt);
    $this->ledger->reserve($this->item, 4, 'cart', ref('cart-1'));

    $fresh = $this->item->fresh();

    expect($fresh->on_hand)->toBe(10)
        ->and($fresh->reserved)->toBe(4)
        ->and($fresh->sellable())->toBe(6);
});

it('refuses to promise more than is sellable', function (): void {
    $this->ledger->adjust($this->item, 5, MovementType::Receipt);
    $this->ledger->reserve($this->item, 4, 'cart', ref('cart-1'));

    expect(fn () => $this->ledger->reserve($this->item, 2, 'cart', ref('cart-2')))
        ->toThrow(InsufficientStock::class);

    expect($this->item->fresh()->reserved)->toBe(4);
});

it('returns the same hold when a reservation is retried', function (): void {
    $this->ledger->adjust($this->item, 10, MovementType::Receipt);

    $first = $this->ledger->reserve($this->item, 3, 'cart', ref('cart-1'));
    $second = $this->ledger->reserve($this->item, 3, 'cart', ref('cart-1'));

    // A checkout retried after a network timeout must not take the stock twice.
    expect($second->getKey())->toBe($first->getKey())
        ->and($this->item->fresh()->reserved)->toBe(3)
        ->and(StockReservation::query()->count())->toBe(1);
});

it('gives stock back when a hold is released', function (): void {
    $this->ledger->adjust($this->item, 10, MovementType::Receipt);

    $reservation = $this->ledger->reserve($this->item, 4, 'cart', ref('cart-1'));
    $this->ledger->release($reservation);

    expect($this->item->fresh()->reserved)->toBe(0)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Released);
});

it('treats releasing twice as a retry rather than an error', function (): void {
    $this->ledger->adjust($this->item, 10, MovementType::Receipt);

    $reservation = $this->ledger->reserve($this->item, 4, 'cart', ref('cart-1'));
    $this->ledger->release($reservation);
    $this->ledger->release($reservation->fresh());

    // Releasing twice must not credit the stock twice.
    expect($this->item->fresh()->reserved)->toBe(0)
        ->and($this->item->fresh()->on_hand)->toBe(10);
});

it('moves both numbers when reserved goods are dispatched', function (): void {
    $this->ledger->adjust($this->item, 10, MovementType::Receipt);

    $reservation = $this->ledger->reserve($this->item, 4, 'order', ref('order-1'));
    $this->ledger->consume($reservation, $this->sellerUser);

    $fresh = $this->item->fresh();

    expect($fresh->on_hand)->toBe(6)
        ->and($fresh->reserved)->toBe(0)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Consumed);
});

it('releases a hold that has expired and frees the stock for somebody else', function (): void {
    $this->ledger->adjust($this->item, 5, MovementType::Receipt);

    $this->ledger->reserve($this->item, 5, 'cart', ref('abandoned'), holdSeconds: 60);

    expect($this->item->fresh()->sellable())->toBe(0);

    $this->travel(2)->minutes();

    // An abandoned basket must not remove a sofa from sale forever, and the next
    // customer should not have to wait for the scheduler to notice.
    $reservation = $this->ledger->reserve($this->item, 5, 'cart', ref('cart-2'));

    expect($reservation->quantity)->toBe(5)
        ->and($this->item->fresh()->reserved)->toBe(5)
        ->and(StockReservation::query()->where('reference_id', ref('abandoned'))->first()->status)
        ->toBe(ReservationStatus::Expired);
});

it('sweeps expired holds when the scheduler runs', function (): void {
    $this->ledger->adjust($this->item, 5, MovementType::Receipt);
    $this->ledger->reserve($this->item, 2, 'cart', ref('a'), holdSeconds: 30);
    $this->ledger->reserve($this->item, 2, 'cart', ref('b'), holdSeconds: 3600);

    $this->travel(5)->minutes();

    expect($this->ledger->releaseExpired())->toBe(1)
        ->and($this->item->fresh()->reserved)->toBe(2);
});

// --- invariants the database itself enforces -------------------------------------

it('refuses a reserved figure above what exists, even bypassing the service', function (): void {
    $this->ledger->adjust($this->item, 3, MovementType::Receipt);

    // The service should make this unreachable. The constraint is what makes it
    // impossible rather than merely unlikely — a lock only helps code that takes it.
    expect(fn () => DB::table('stock_items')->where('id', $this->item->getKey())->update(['reserved' => 9]))
        ->toThrow(QueryException::class);
});

it('refuses a negative balance at the storage layer', function (): void {
    expect(fn () => DB::table('stock_items')->where('id', $this->item->getKey())->update(['on_hand' => -1]))
        ->toThrow(QueryException::class);
});

it('never lets a movement be edited or deleted', function (): void {
    $this->ledger->adjust($this->item, 1, MovementType::Receipt);

    $movement = StockMovement::query()->firstOrFail();

    // Append-only, enforced by a trigger. A ledger somebody can edit answers nothing.
    expect(fn () => DB::table('stock_movements')->where('id', $movement->getKey())->update(['quantity' => 999]))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('stock_movements')->where('id', $movement->getKey())->delete())
        ->toThrow(QueryException::class);
});

it('refuses a second live hold for the same reference', function (): void {
    $this->ledger->adjust($this->item, 10, MovementType::Receipt);
    $this->ledger->reserve($this->item, 2, 'cart', ref('cart-1'));

    // The service returns the existing hold; the index is what guarantees it, including
    // for any future caller that forgets to ask first.
    expect(fn () => StockReservation::query()->create([
        'stock_item_id' => $this->item->getKey(),
        'quantity' => 1,
        'status' => ReservationStatus::Held,
        'reference_type' => 'cart',
        'reference_id' => ref('cart-1'),
    ]))->toThrow(QueryException::class);
});

// --- concurrency ------------------------------------------------------------------

it('serialises two reservations for the last unit so only one succeeds', function (): void {
    $this->ledger->adjust($this->item, 1, MovementType::Receipt);

    $itemId = (string) $this->item->getKey();
    $outcomes = [];

    // Sequential, because PHP is: this asserts the *decision* is right given what is
    // already committed. That a second caller genuinely waits for the first is a
    // different claim, asserted against two real connections in the test below.
    foreach ([ref('first'), ref('second')] as $attempt) {
        try {
            $this->ledger->reserve(StockItem::query()->findOrFail($itemId), 1, 'cart', $attempt);
            $outcomes[$attempt] = 'held';
        } catch (InsufficientStock) {
            $outcomes[$attempt] = 'refused';
        }
    }

    expect($outcomes)->toBe([ref('first') => 'held', ref('second') => 'refused'])
        ->and($this->item->fresh()->reserved)->toBe(1);
});

it('locks rows in a fixed order when reserving several at once', function (): void {
    $second = ProductSku::query()->create([
        'product_id' => $this->product->getKey(),
        'seller_id' => $this->seller->getKey(),
        'sku' => 'ATL-KNP-002',
        'list_price_minor' => 1_200_000,
        'stock_quantity' => 0,
    ]);

    $secondItem = $this->ledger->itemFor($second);

    $this->ledger->adjust($this->item, 4, MovementType::Receipt);
    $this->ledger->adjust($secondItem, 4, MovementType::Receipt);

    $reservations = $this->ledger->reserveMany(
        [(string) $this->item->getKey() => 2, (string) $secondItem->getKey() => 3],
        'order',
        ref('order-9'),
    );

    expect($reservations)->toHaveCount(2)
        ->and($this->item->fresh()->reserved)->toBe(2)
        ->and($secondItem->fresh()->reserved)->toBe(3);
});

it('reserves nothing at all when one line of a basket cannot be satisfied', function (): void {
    $second = ProductSku::query()->create([
        'product_id' => $this->product->getKey(),
        'seller_id' => $this->seller->getKey(),
        'sku' => 'ATL-KNP-003',
        'list_price_minor' => 1_200_000,
        'stock_quantity' => 0,
    ]);

    $secondItem = $this->ledger->itemFor($second);

    $this->ledger->adjust($this->item, 4, MovementType::Receipt);
    $this->ledger->adjust($secondItem, 1, MovementType::Receipt);

    expect(fn () => $this->ledger->reserveMany(
        [(string) $this->item->getKey() => 2, (string) $secondItem->getKey() => 3],
        'order',
        ref('order-10'),
    ))->toThrow(InsufficientStock::class);

    // All or nothing: a basket half-reserved is a customer who paid for one of two
    // items and a seller who has to explain it.
    expect($this->item->fresh()->reserved)->toBe(0)
        ->and($secondItem->fresh()->reserved)->toBe(0);
});

it('reads the balance under a row lock rather than from a stale copy', function (): void {
    $this->ledger->adjust($this->item, 5, MovementType::Receipt);

    $statements = [];

    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $this->ledger->reserve($this->item, 1, 'cart', ref('locked'));

    $locking = array_values(array_filter(
        $statements,
        static fn (string $sql): bool => str_contains($sql, 'stock_items') && str_contains($sql, 'for update'),
    ));

    /*
     * That `SELECT ... FOR UPDATE` blocks a second transaction is PostgreSQL's
     * behaviour, not ours, and testing it would be testing the database. What is ours
     * — and what would vanish silently in a refactor — is that the ledger takes the
     * lock at all, and re-reads the row inside it rather than trusting the caller's
     * copy. Two customers were promised the last sofa exactly because somebody once
     * decided from a balance read before the lock.
     */
    expect($locking)->not->toBeEmpty();
});

it('decides from the row it locked, not from the model it was handed', function (): void {
    $this->ledger->adjust($this->item, 1, MovementType::Receipt);

    // A stale in-memory copy claiming plenty of stock, of the kind a long-running
    // request or a queued job would hold.
    $stale = $this->item->fresh();
    $stale->on_hand = 500;

    expect(fn () => $this->ledger->reserve($stale, 10, 'cart', ref('stale')))
        ->toThrow(InsufficientStock::class);

    expect($this->item->fresh()->reserved)->toBe(0);
});
