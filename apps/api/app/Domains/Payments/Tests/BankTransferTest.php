<?php

declare(strict_types=1);

use App\Domains\Commerce\Services\CartService;
use App\Domains\Credits\Models\CreditPackage;
use App\Domains\Credits\Services\CreditLedger;
use App\Domains\Identity\Enums\SystemRole;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserAddress;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\ReservationStatus;
use App\Domains\Inventory\Models\StockReservation;
use App\Domains\Inventory\Services\InventoryLedger;
use App\Domains\Payments\Enums\BankTransferStatus;
use App\Domains\Payments\Enums\CheckoutStatus;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Exceptions\CheckoutRefused;
use App\Domains\Payments\Gateways\BankTransferGateway;
use App\Domains\Payments\Models\BankTransfer;
use App\Domains\Payments\Models\PaymentBankAccount;
use App\Domains\Payments\Services\BankTransferService;
use App\Domains\Payments\Services\CheckoutService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;

/**
 * Paying by bank transfer.
 *
 * The gate is duplicate confirmation and amount mismatch, and both are the same underlying
 * problem: the amount is a claim rather than a fact, and confirming one releases goods and
 * cannot be undone. Everything below is about not letting a person, on a stale screen, at
 * the end of a long day, quietly decide that 4.997,50₺ is close enough to 5.000₺.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->carts = app(CartService::class);
    $this->checkout = app(CheckoutService::class);
    $this->transfers = app(BankTransferService::class);
    $this->stock = app(InventoryLedger::class);

    [$this->seller] = makeApprovedSeller('Havale Test A.Ş.', 'havale-test');

    $this->product = makeProduct($this->seller, makeCategory('Masa', 'masa-havale', 'living_room'), [
        'name' => 'Havale test masası',
        'description' => 'Havale testleri için masa.',
        'price_minor' => 500_000,
        'stock_quantity' => 3,
    ]);

    $this->sku = $this->product->skus->first();
    $this->stock->adjust($this->stock->itemFor($this->sku), 3, MovementType::Receipt);

    $this->customer = User::factory()->create();
    $this->customer->forceFill(['email_verified_at' => now()])->save();

    UserAddress::query()->create([
        'user_id' => $this->customer->getKey(),
        'recipient_name' => 'Deniz Yılmaz',
        'city' => 'İstanbul',
        'address_line1' => 'Bağdat Caddesi 100',
        'is_default_shipping' => true,
    ]);

    $this->operator = User::factory()->create();
    $this->operator->forceFill(['email_verified_at' => now()])->save();
    grantPlatformRole($this->operator, SystemRole::Operator);

    $this->account = PaymentBankAccount::query()->create([
        'bank_name' => 'Test Bankası',
        'account_holder' => 'RefConcept A.Ş.',
        'iban' => 'TR330006100519786457841326',
        'currency' => 'TRY',
    ]);

    $this->package = CreditPackage::query()->create([
        'code' => 'havale-paket',
        'name' => 'Havale paketi',
        'credits' => 80,
        'price_minor' => 39_900,
        'currency' => 'TRY',
    ]);
});

/** Opens a basket checkout paid by transfer. */
function startTransfer(int $quantity = 1): BankTransfer
{
    test()->carts->add(test()->customer, test()->sku, $quantity);
    $session = test()->checkout->openCart(test()->customer, []);

    test()->checkout->pay($session, BankTransferGateway::NAME, null, null, null, (string) test()->account->getKey());

    return BankTransfer::query()->latest('created_at')->firstOrFail();
}

// --- the reference -----------------------------------------------------------

it('quotes a reference a person can actually type', function (): void {
    $transfer = startTransfer();

    expect($transfer->reference)->toStartWith('RC-')
        /*
         * No 0/O and no 1/I/L. The reference is copied by eye from a screen into a banking
         * app, and a character pair that is identical in one bank's font is a payment
         * nobody can match to an order.
         */
        ->and($transfer->reference)->not->toMatch('/[01OIL]/')
        ->and($transfer->intent?->external_id)->toBe($transfer->reference)
        ->and($transfer->intent?->status)->toBe(PaymentStatus::RequiresAction);
});

it('quotes the same reference when the page is reloaded', function (): void {
    $transfer = startTransfer();
    $session = $transfer->intent?->session;

    // A second reference would leave the customer with two, and the money matching neither.
    $again = $this->transfers->open($transfer->intent, (string) $this->account->getKey());

    expect($again->getKey())->toBe($transfer->getKey())
        ->and(BankTransfer::query()->count())->toBe(1)
        ->and($session)->not->toBeNull();
});

it('stretches the stock hold to the transfer window', function (): void {
    $transfer = startTransfer(2);

    $reservation = StockReservation::query()
        ->where('reference_type', 'cart')
        ->firstOrFail();

    /*
     * A card checkout holds for fifteen minutes; a transfer takes a day or two. A customer
     * told their goods are reserved and then losing them overnight has been lied to.
     */
    expect($reservation->expires_at?->greaterThan(now()->addHours(24)))->toBeTrue()
        ->and($transfer->expires_at?->greaterThan(now()->addHours(24)))->toBeTrue();
});

it('refuses when there is nowhere to pay into', function (): void {
    PaymentBankAccount::query()->update(['is_active' => false]);

    $this->carts->add($this->customer, $this->sku);
    $session = $this->checkout->openCart($this->customer, []);

    expect(fn () => $this->checkout->pay($session, BankTransferGateway::NAME, null))
        ->toThrow(CheckoutRefused::class);
});

// --- amounts that do not match: the gate -------------------------------------

it('releases nothing when too little arrives', function (): void {
    $transfer = startTransfer();

    $short = $this->transfers->confirm(
        $transfer,
        $transfer->expected_minor - 2_500,
        Carbon::parse('2026-08-25'),
        $this->operator,
    );

    /*
     * The tempting mistake is to let this through as "close enough". It is also how a
     * marketplace ships goods for less than they cost, one rounded-down transfer at a
     * time — so the shortfall is stated and nothing moves.
     */
    expect($short->status)->toBe(BankTransferStatus::ShortPaid)
        ->and($short->shortfallMinor())->toBe(2_500)
        ->and($short->intent?->fresh()?->status)->toBe(PaymentStatus::RequiresAction)
        ->and($short->intent?->session?->fresh()?->status)->not->toBe(CheckoutStatus::Paid);
});

it('lets the customer make up a shortfall against the same reference', function (): void {
    $transfer = startTransfer();

    $this->transfers->confirm($transfer, 100_000, Carbon::parse('2026-08-25'), $this->operator);

    $completed = $this->transfers->confirm(
        $transfer->fresh(),
        $transfer->expected_minor,
        Carbon::parse('2026-08-26'),
        $this->operator,
        'Fark gönderildi',
    );

    expect($completed->status)->toBe(BankTransferStatus::Confirmed)
        ->and($completed->intent?->fresh()?->status)->toBe(PaymentStatus::Captured);
});

it('releases the order but records the surplus when too much arrives', function (): void {
    $transfer = startTransfer();

    $over = $this->transfers->confirm(
        $transfer,
        $transfer->expected_minor + 10_000,
        Carbon::parse('2026-08-25'),
        $this->operator,
    );

    expect($over->status)->toBe(BankTransferStatus::OverPaid)
        ->and($over->shortfallMinor())->toBe(-10_000)
        // Captured at what was owed, not at what arrived: the surplus is a refund, not a
        // larger sale, and the CHECK on the intent would refuse it anyway.
        ->and($over->intent?->fresh()?->captured_minor)->toBe($transfer->expected_minor)
        ->and($over->intent?->fresh()?->status)->toBe(PaymentStatus::Captured);
});

it('refuses an arrival of nothing', function (): void {
    $transfer = startTransfer();

    expect(fn () => $this->transfers->confirm($transfer, 0, Carbon::now(), $this->operator))
        ->toThrow(CheckoutRefused::class);
});

// --- duplicate confirmation: the gate ----------------------------------------

it('confirms a transfer exactly once', function (): void {
    $transfer = startTransfer(2);

    $this->transfers->confirm($transfer, $transfer->expected_minor, Carbon::parse('2026-08-25'), $this->operator);

    /*
     * Two operators, two stale screens. The second is refused with a sentence rather than
     * allowed through to release an order twice — the rule from
     * 06_SECURITY_PAYMENT_FINANCE_RULES.md.
     */
    expect(fn () => $this->transfers->confirm(
        $transfer->fresh(),
        $transfer->expected_minor,
        Carbon::parse('2026-08-25'),
        $this->operator,
    ))->toThrow(CheckoutRefused::class);

    $reservations = StockReservation::query()->where('reference_type', 'cart')->get();

    // One consumption, not two: the stock came off the shelf once.
    expect($reservations->where('status', ReservationStatus::Consumed)->count())->toBe(1)
        ->and($this->stock->sellableFor($this->sku))->toBe(1);
});

it('loads credits once for a transfer confirmed once', function (): void {
    $session = $this->checkout->openCredits($this->customer, $this->package);
    $this->checkout->pay($session, BankTransferGateway::NAME, null, null, null, (string) $this->account->getKey());

    $transfer = BankTransfer::query()->latest('created_at')->firstOrFail();

    $this->transfers->confirm($transfer, $transfer->expected_minor, Carbon::now(), $this->operator);

    expect(app(CreditLedger::class)->walletFor($this->customer)->balance)->toBe(80);
});

// --- rejection and expiry -----------------------------------------------------

it('records why a transfer was refused', function (): void {
    $transfer = startTransfer();

    $rejected = $this->transfers->reject($transfer, $this->operator, 'Ekstrede eşleşen bir kayıt yok.');

    expect($rejected->status)->toBe(BankTransferStatus::Rejected)
        ->and($rejected->decision_note)->toBe('Ekstrede eşleşen bir kayıt yok.')
        ->and($rejected->confirmed_by)->toBe($this->operator->getKey())
        // The session survives a refusal, as it does for a declined card: the customer can
        // pay another way rather than starting over at today's prices.
        ->and($rejected->intent?->fresh()?->status)->toBe(PaymentStatus::Failed);
});

it('closes a transfer nobody paid and gives the stock back', function (): void {
    $transfer = startTransfer(3);

    expect($this->stock->sellableFor($this->sku))->toBe(0);

    $transfer->forceFill(['expires_at' => now()->subHour()])->save();

    expect($this->transfers->expireOverdue())->toBe(1)
        ->and($transfer->fresh()?->status)->toBe(BankTransferStatus::Expired);

    /*
     * Released by the transfer's own expiry rather than by the checkout sweeper. The two
     * have separate clocks, and the transfer's is the deadline the customer was told —
     * waiting for a second timer to agree would keep the goods off the market after we
     * had already decided nobody was going to pay for them.
     */
    expect($this->stock->sellableFor($this->sku))->toBe(3)
        ->and($transfer->fresh()?->intent?->session?->status)->toBe(CheckoutStatus::Expired);
});

it('leaves a transfer alone while its window is open', function (): void {
    startTransfer();

    expect($this->transfers->expireOverdue())->toBe(0);
});
