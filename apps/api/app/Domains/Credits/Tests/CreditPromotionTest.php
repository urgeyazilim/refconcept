<?php

declare(strict_types=1);

use App\Domains\Credits\Enums\CreditLotSource;
use App\Domains\Credits\Exceptions\PromotionRefused;
use App\Domains\Credits\Models\CreditPromotion;
use App\Domains\Credits\Models\CreditPromotionRedemption;
use App\Domains\Credits\Services\CreditLedger;
use App\Domains\Credits\Services\PromotionRedeemer;
use App\Domains\Identity\Models\User;

/**
 * Promotion codes.
 *
 * The one part of the credit system a stranger is actively trying to abuse, so the tests
 * are mostly about the abuse: redeeming twice, redeeming a code that has run out,
 * claiming a welcome bonus on an account that is not new, and probing for which codes
 * exist.
 */
beforeEach(function (): void {
    $this->ledger = app(CreditLedger::class);
    $this->redeemer = app(PromotionRedeemer::class);
    $this->user = User::factory()->create();

    $this->promotion = CreditPromotion::query()->create([
        'code' => 'HOSGELDIN',
        'name' => 'Hoş geldin kredisi',
        'credits' => 25,
        'validity_days' => 90,
        'max_per_user' => 1,
    ]);
});

it('grants the credits with the promotion as their source and deadline', function (): void {
    $transaction = $this->redeemer->redeem($this->user, 'HOSGELDIN');

    $wallet = $this->ledger->walletFor($this->user);

    expect($wallet->balance)->toBe(25)
        // Granted, not purchased: this is not money the customer handed over, and the
        // distinction matters the moment somebody asks for a refund.
        ->and($wallet->lifetime_granted)->toBe(25)
        ->and($wallet->lifetime_purchased)->toBe(0);

    $lot = $wallet->lots()->firstOrFail();

    expect($lot->source)->toBe(CreditLotSource::Promotion)
        ->and($lot->expires_at?->toDateString())->toBe(now()->addDays(90)->toDateString())
        ->and($transaction->description)->toBe('Hoş geldin kredisi');
});

it('accepts the code however it was capitalised', function (): void {
    // citext. Somebody typing what they read on a poster should not be told it is wrong
    // because they used lower case.
    $this->redeemer->redeem($this->user, 'hosgeldin');

    expect($this->ledger->walletFor($this->user)->balance)->toBe(25);
});

it('refuses a second redemption by the same person', function (): void {
    $this->redeemer->redeem($this->user, 'HOSGELDIN');

    expect(fn () => $this->redeemer->redeem($this->user, 'HOSGELDIN'))
        ->toThrow(PromotionRefused::class);

    expect($this->ledger->walletFor($this->user)->balance)->toBe(25)
        ->and(CreditPromotionRedemption::query()->count())->toBe(1)
        ->and($this->promotion->fresh()?->redemption_count)->toBe(1);
});

it('says plainly that a code was already used', function (): void {
    $this->redeemer->redeem($this->user, 'HOSGELDIN');

    try {
        $this->redeemer->redeem($this->user, 'HOSGELDIN');
    } catch (PromotionRefused $e) {
        /*
         * Safe to say, because the person asking has already proved they know the code —
         * nothing is disclosed. And being told "you have used this" rather than "invalid
         * code" is the difference between understanding and a support ticket.
         */
        expect($e->kind)->toBe('already_redeemed')
            ->and($e->getMessage())->toBe('Bu kodu zaten kullandınız.');
    }
});

it('gives the same answer for a code that does not exist and one that has ended', function (): void {
    $ended = CreditPromotion::query()->create([
        'code' => 'BITMIS',
        'name' => 'Süresi geçmiş kampanya',
        'credits' => 100,
        'starts_at' => now()->subMonths(2),
        'ends_at' => now()->subMonth(),
    ]);

    $messages = [];

    foreach (['BITMIS', 'HICBIRZAMANVAROLMADI'] as $code) {
        try {
            $this->redeemer->redeem($this->user, $code);
        } catch (PromotionRefused $e) {
            $messages[] = $e->getMessage();
        }
    }

    /*
     * Identical on purpose. Telling them apart would turn this into an oracle: somebody
     * trying dictionary words would learn which are real campaigns and could then watch
     * for one to open.
     */
    expect($messages)->toHaveCount(2)
        ->and($messages[0])->toBe($messages[1])
        ->and($ended->fresh()?->redemption_count)->toBe(0);
});

it('stops handing out credits once the budget is spent', function (): void {
    $limited = CreditPromotion::query()->create([
        'code' => 'ILK2',
        'name' => 'İlk iki kişi',
        'credits' => 10,
        'max_redemptions' => 2,
    ]);

    $claimed = 0;

    foreach (range(1, 4) as $i) {
        try {
            $this->redeemer->redeem(User::factory()->create(), 'ILK2');
            $claimed++;
        } catch (PromotionRefused) {
            // Expected once the budget is gone.
        }
    }

    // A code that reaches a public forum must not be able to give away an unbounded
    // number of credits.
    expect($claimed)->toBe(2)
        ->and($limited->fresh()?->redemption_count)->toBe(2)
        ->and($limited->fresh()?->remainingRedemptions())->toBe(0);
});

it('honours a per-user limit above one', function (): void {
    $thrice = CreditPromotion::query()->create([
        'code' => 'UCKERE',
        'name' => 'Üç kere kullanılabilir',
        'credits' => 5,
        'max_per_user' => 3,
    ]);

    foreach (range(1, 4) as $i) {
        try {
            $this->redeemer->redeem($this->user, 'UCKERE');
        } catch (PromotionRefused) {
            break;
        }
    }

    // Each redemption gets its own idempotency key, so three distinct grants land rather
    // than the second silently returning the first.
    expect($this->ledger->walletFor($this->user)->balance)->toBe(15)
        ->and($thrice->fresh()?->redemption_count)->toBe(3);
});

it('keeps a welcome bonus for accounts that have never had credits', function (): void {
    $welcome = CreditPromotion::query()->create([
        'code' => 'YENIUYE',
        'name' => 'Yeni üye',
        'credits' => 50,
        'new_accounts_only' => true,
    ]);

    $existing = User::factory()->create();
    $this->ledger->grant($existing, 100, CreditLotSource::Purchase, 'Paket');

    expect(fn () => $this->redeemer->redeem($existing, 'YENIUYE'))
        ->toThrow(PromotionRefused::class);

    /*
     * The test is "has this account ever had credits", not "when did it register".
     * Somebody who signed up in March and is only now trying the product is exactly who
     * a welcome bonus is for.
     */
    $newcomer = User::factory()->create();
    $newcomer->forceFill(['created_at' => now()->subYear()])->save();

    $this->redeemer->redeem($newcomer, 'YENIUYE');

    expect($this->ledger->walletFor($newcomer)->balance)->toBe(50)
        ->and($welcome->fresh()?->redemption_count)->toBe(1);
});

it('refuses a campaign that has not started yet', function (): void {
    CreditPromotion::query()->create([
        'code' => 'YAKINDA',
        'name' => 'Yakında',
        'credits' => 30,
        'starts_at' => now()->addWeek(),
    ]);

    expect(fn () => $this->redeemer->redeem($this->user, 'YAKINDA'))
        ->toThrow(PromotionRefused::class);
});

it('refuses a campaign somebody has switched off', function (): void {
    $this->promotion->forceFill(['is_active' => false])->save();

    // The kill switch for a code that turned out to be too generous, or leaked.
    expect(fn () => $this->redeemer->redeem($this->user, 'HOSGELDIN'))
        ->toThrow(PromotionRefused::class);

    expect($this->ledger->walletFor($this->user)->balance)->toBe(0);
});
