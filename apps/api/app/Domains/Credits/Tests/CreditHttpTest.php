<?php

declare(strict_types=1);

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Credits\Enums\CreditLotSource;
use App\Domains\Credits\Models\CreditPackage;
use App\Domains\Credits\Models\CreditPromotion;
use App\Domains\Credits\Services\CreditLedger;
use App\Domains\Identity\Enums\SystemRole;
use App\Domains\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The endpoints.
 *
 * The customer routes carry no id — `/credits` is always *your* wallet — so the tests
 * below check the thing that actually could go wrong: that a signed-in person sees their
 * own numbers and never anybody else's, and that the admin routes refuse everybody who
 * is not staff.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ledger = app(CreditLedger::class);

    $this->customer = User::factory()->create();
    $this->customer->forceFill(['email_verified_at' => now()])->save();

    $this->admin = User::factory()->create();
    grantPlatformRole($this->admin, SystemRole::SuperAdmin);

    RateLimiter::clear('credit-promo:'.$this->customer->getKey());
});

it('shows a customer their own balance and what is about to expire', function (): void {
    $this->ledger->grant($this->customer, 100, CreditLotSource::Purchase, 'Paket', expiresAt: now()->addYear());
    $this->ledger->grant($this->customer, 25, CreditLotSource::Promotion, 'Hoş geldin', expiresAt: now()->addDays(10));
    $this->ledger->reserve($this->customer, 30, 'job-1', 'Görsel');

    $response = $this->actingAs($this->customer)
        ->getJson('/api/v1/credits')
        ->assertOk()
        // Never cached: this is the number a customer refreshes to see a render land.
        ->assertHeader('Cache-Control', 'no-store, private');

    expect($response->json('data.balance'))->toBe(125)
        ->and($response->json('data.reserved'))->toBe(30)
        ->and($response->json('data.available'))->toBe(95)
        /*
         * Surfaced without being asked for. A customer who loses twenty-five credits they
         * did not know had a deadline feels cheated, and "it was in the terms" does not
         * help.
         */
        ->and($response->json('data.expiring_total'))->toBe(25)
        ->and($response->json('data.expiring_soon'))->toHaveCount(1);
});

it('keeps holds off the statement', function (): void {
    $this->ledger->grant($this->customer, 100, CreditLotSource::Purchase, 'Paket');

    $reservation = $this->ledger->reserve($this->customer, 30, 'job-1', 'Oda analizi');
    $this->ledger->consume($reservation);

    $response = $this->actingAs($this->customer)
        ->getJson('/api/v1/credits/transactions')
        ->assertOk();

    $types = collect($response->json('data'))->pluck('type');

    /*
     * A reserve followed by a consume is one event to the person who ran the render.
     * Three lines for it is how a statement becomes something nobody checks, which
     * defeats the purpose of keeping one.
     */
    expect($types)->toHaveCount(2)
        ->and($types->all())->toBe(['consume', 'purchase'])
        ->and($response->json('data.0.balance_after'))->toBe(70);
});

it('never shows one customer another customer wallet', function (): void {
    $other = User::factory()->create();
    $this->ledger->grant($other, 500, CreditLotSource::Purchase, 'Paket');
    $this->ledger->grant($this->customer, 10, CreditLotSource::Grant, 'Jest');

    // There is no id in this route to get wrong, which is the strongest form the rule
    // can take — but the assertion is cheap and the consequence of a regression is not.
    $response = $this->actingAs($this->customer)->getJson('/api/v1/credits')->assertOk();

    expect($response->json('data.balance'))->toBe(10);
});

it('lists packages to anybody, signed in or not', function (): void {
    CreditPackage::query()->create([
        'code' => 'home',
        'name' => 'Ev',
        'credits' => 500,
        'bonus_credits' => 50,
        'price_minor' => 89_900,
        'validity_days' => 365,
    ]);

    CreditPackage::query()->create([
        'code' => 'gizli',
        'name' => 'Kapalı paket',
        'credits' => 10,
        'price_minor' => 1_000,
        'is_active' => false,
    ]);

    // A pricing page has to render for somebody who has not signed in yet — that is
    // exactly when they are deciding whether to.
    $response = $this->getJson('/api/v1/credits/packages')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.total_credits'))->toBe(550)
        // Minor units. A client that receives "899.00" will parse it into a float sooner
        // or later; one that receives 89900 cannot.
        ->and($response->json('data.0.price.amount_minor'))->toBe(89_900)
        ->and($response->json('data.0.price.currency'))->toBe('TRY');
});

it('redeems a code and reports the new balance', function (): void {
    CreditPromotion::query()->create([
        'code' => 'HOSGELDIN',
        'name' => 'Hoş geldin kredisi',
        'credits' => 25,
    ]);

    $this->actingAs($this->customer)
        ->postJson('/api/v1/credits/redeem', ['code' => 'HOSGELDIN'])
        ->assertOk()
        ->assertJsonPath('data.credits', 25)
        ->assertJsonPath('data.balance', 25);

    $this->actingAs($this->customer)
        ->postJson('/api/v1/credits/redeem', ['code' => 'HOSGELDIN'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'already_redeemed');
});

it('will not let an unverified account redeem anything', function (): void {
    CreditPromotion::query()->create(['code' => 'HOSGELDIN', 'name' => 'Hoş geldin', 'credits' => 25]);

    $unverified = User::factory()->create();
    $unverified->forceFill(['email_verified_at' => null])->save();

    /*
     * Without this a promotion is a free-credit machine for anybody willing to type a
     * different address each time — and the address never has to work.
     */
    $this->actingAs($unverified)
        ->postJson('/api/v1/credits/redeem', ['code' => 'HOSGELDIN'])
        ->assertForbidden();

    expect($this->ledger->walletFor($unverified)->balance)->toBe(0);
});

it('stops somebody guessing codes', function (): void {
    $statuses = [];

    foreach (range(1, 7) as $attempt) {
        $statuses[] = $this->actingAs($this->customer)
            ->postJson('/api/v1/credits/redeem', ['code' => 'TAHMIN'.$attempt])
            ->status();
    }

    // Five tries, then the door closes. A code is a short string somebody can guess, and
    // without a limit this endpoint answers a thousand dictionary words a minute.
    expect(array_slice($statuses, 0, 5))->toBe([422, 422, 422, 422, 422])
        ->and(array_slice($statuses, 5))->toBe([429, 429]);
});

it('refuses the admin routes to a customer', function (): void {
    foreach ([
        '/api/v1/admin/credits/packages',
        '/api/v1/admin/credits/promotions',
        '/api/v1/admin/credits/wallets/'.$this->customer->getKey(),
    ] as $path) {
        $this->actingAs($this->customer)->getJson($path)->assertForbidden();
    }

    $this->actingAs($this->customer)
        ->postJson('/api/v1/admin/credits/wallets/'.$this->customer->getKey().'/adjust', [
            'delta' => 1000,
            'reason' => 'Kendime kredi yazıyorum.',
        ])
        ->assertForbidden();

    expect($this->ledger->walletFor($this->customer)->balance)->toBe(0);
});

it('lets staff correct a balance, with a reason, and records who did it', function (): void {
    $this->ledger->grant($this->customer, 50, CreditLotSource::Purchase, 'Paket');

    // A reason is mandatory. This is the one movement indistinguishable from theft
    // without a record of who made it and why.
    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/credits/wallets/'.$this->customer->getKey().'/adjust', [
            'delta' => 20,
            'reason' => 'kısa',
        ])
        ->assertStatus(422);

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/credits/wallets/'.$this->customer->getKey().'/adjust', [
            'delta' => 20,
            'reason' => 'Kesintiden etkilenen müşteriye telafi.',
        ])
        ->assertOk()
        ->assertJsonPath('data.balance', 70);

    $entry = AuditLog::query()->where('action', 'credit.wallet.adjusted')->firstOrFail();

    expect($entry->actor_id)->toBe($this->admin->getKey())
        ->and($entry->reason)->toBe('Kesintiden etkilenen müşteriye telafi.');
});

it('refuses a correction that would drive a balance below zero', function (): void {
    $this->ledger->grant($this->customer, 10, CreditLotSource::Purchase, 'Paket');

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/credits/wallets/'.$this->customer->getKey().'/adjust', [
            'delta' => -100,
            'reason' => 'Yanlış hesaba tanımlandı.',
        ])
        ->assertStatus(422);

    expect($this->ledger->walletFor($this->customer)->balance)->toBe(10);
});

it('shows staff the reconciliation figure next to the balance', function (): void {
    $this->ledger->grant($this->customer, 100, CreditLotSource::Purchase, 'Paket');
    $this->ledger->spend($this->customer, 30, 'Görsel', 'job-1');

    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/credits/wallets/'.$this->customer->getKey())
        ->assertOk();

    /*
     * On the screen rather than in a report nobody runs. If the lots and the wallet ever
     * disagree, the person looking at this customer's balance is the one who most needs
     * to know.
     */
    expect($response->json('data.balance'))->toBe(70)
        ->and($response->json('data.lot_total'))->toBe(70);
});
