<?php

declare(strict_types=1);

namespace App\Domains\Credits\Services;

use App\Domains\Credits\Enums\CreditLotSource;
use App\Domains\Credits\Exceptions\PromotionRefused;
use App\Domains\Credits\Models\CreditPromotion;
use App\Domains\Credits\Models\CreditPromotionRedemption;
use App\Domains\Credits\Models\CreditTransaction;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Claiming a promotion code.
 *
 * The whole of this class is one careful sequence, because a promotion code is the one
 * thing in the system that a stranger is actively trying to abuse. Two attacks matter and
 * they need different defences:
 *
 *  - **The same person redeeming repeatedly.** Two requests arriving together both count
 *    the existing redemptions, both find room under the limit, and both grant. Defended
 *    by locking the promotion row before counting, so the second waits for the first.
 *  - **Guessing codes.** Every failure returns the same refusal regardless of whether the
 *    code exists, has run out, or was already claimed by this person. Distinguishing them
 *    would turn the endpoint into an oracle that enumerates live campaigns.
 *
 * The lock is on the promotion rather than on the wallet, and that is the correct
 * granularity: the contended resource is the campaign's remaining budget, which everybody
 * redeeming that code shares. It does mean a popular code serialises its redemptions,
 * which is exactly what a limited budget requires.
 */
final class PromotionRedeemer
{
    public function __construct(private readonly CreditLedger $ledger) {}

    /**
     * @throws PromotionRefused
     */
    public function redeem(User $user, string $code): CreditTransaction
    {
        return DB::transaction(function () use ($user, $code): CreditTransaction {
            /*
             * Found and locked in one statement. Looking it up and then locking it would
             * leave a gap in which the row can change, and the gap is precisely where a
             * campaign's last redemption gets handed out twice.
             *
             * The column is citext, so the customer's capitalisation does not matter.
             */
            $promotion = CreditPromotion::query()
                ->where('code', $code)
                ->lockForUpdate()
                ->first();

            if ($promotion === null || ! $promotion->isRunning()) {
                throw PromotionRefused::unusable();
            }

            if ($promotion->new_accounts_only && $this->hasHistory($user)) {
                /*
                 * A welcome bonus any existing customer can also claim is not a welcome
                 * bonus. "Has history" means they have already been given or bought
                 * credits — not merely that the account is old, because somebody who
                 * registered in March and is only now trying the product is exactly who
                 * this is for.
                 */
                throw PromotionRefused::notEligible();
            }

            $alreadyClaimed = CreditPromotionRedemption::query()
                ->where('promotion_id', $promotion->getKey())
                ->where('user_id', $user->getKey())
                ->count();

            if ($alreadyClaimed >= $promotion->max_per_user) {
                throw PromotionRefused::alreadyRedeemed();
            }

            $transaction = $this->ledger->grant(
                user: $user,
                credits: $promotion->credits,
                source: CreditLotSource::Promotion,
                description: $promotion->name,
                // Deterministic, so a retried request finds the grant it already made
                // instead of making a second one. Includes the attempt number, because a
                // promotion allowing three claims per person needs three distinct keys.
                reference: sprintf('promo:%s:%s:%d', $promotion->getKey(), $user->getKey(), $alreadyClaimed + 1),
                expiresAt: $promotion->expiresAt(),
                origin: $promotion,
            );

            CreditPromotionRedemption::query()->create([
                'promotion_id' => $promotion->getKey(),
                'user_id' => $user->getKey(),
                'transaction_id' => $transaction->getKey(),
                'credits' => $promotion->credits,
                'created_at' => now(),
            ]);

            // Incremented under the same lock that read it, so a budget of a hundred
            // hands out a hundred and not a hundred and three.
            $promotion->forceFill(['redemption_count' => $promotion->redemption_count + 1])->save();

            return $transaction;
        });
    }

    /**
     * Whether this account has ever had credits.
     *
     * The test for "new account" that actually matches the intent. Registration date
     * would punish somebody who signed up months ago and is only now trying the product,
     * who is exactly the person a welcome bonus is meant to reach.
     */
    private function hasHistory(User $user): bool
    {
        $wallet = $this->ledger->walletFor($user);

        return $wallet->lifetime_purchased > 0 || $wallet->lifetime_granted > 0;
    }
}
