<?php

declare(strict_types=1);

namespace App\Domains\Credits\Http\Controllers;

use App\Domains\Credits\Exceptions\PromotionRefused;
use App\Domains\Credits\Models\CreditLot;
use App\Domains\Credits\Models\CreditPackage;
use App\Domains\Credits\Models\CreditTransaction;
use App\Domains\Credits\Services\CreditLedger;
use App\Domains\Credits\Services\PromotionRedeemer;
use App\Domains\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A customer's own wallet.
 *
 * Everything here is scoped to the signed-in user by construction rather than by a
 * policy check — there is no id in any of these routes to get wrong. That is the
 * strongest form the rule can take: a missing authorization check cannot expose somebody
 * else's balance if there is no way to name somebody else.
 */
final class CreditWalletController
{
    public function __construct(
        private readonly CreditLedger $ledger,
        private readonly PromotionRedeemer $promotions,
    ) {}

    /**
     * The balance, and what is about to disappear from it.
     *
     * Expiring credits are surfaced without being asked for. A customer who loses fifty
     * credits they did not know had a deadline is a customer who feels cheated, and the
     * fact that it was in the terms when they bought them does not help.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $wallet = $this->ledger->walletFor($user);

        $expiringSoon = CreditLot::query()
            ->where('wallet_id', $wallet->getKey())
            ->expiring(now()->addDays(30))
            ->orderBy('expires_at')
            ->get();

        return response()->json([
            'data' => [
                'balance' => $wallet->balance,
                'reserved' => $wallet->reserved,
                'available' => $wallet->available(),

                'lifetime' => [
                    'purchased' => $wallet->lifetime_purchased,
                    'granted' => $wallet->lifetime_granted,
                    'consumed' => $wallet->lifetime_consumed,
                    'expired' => $wallet->lifetime_expired,
                ],

                'expiring_soon' => $expiringSoon->map(fn (CreditLot $lot): array => [
                    'credits' => $lot->remaining,
                    'expires_at' => $lot->expires_at?->toIso8601String(),
                    'source' => $lot->source->value,
                    'source_label' => $lot->source->label(),
                ])->all(),

                'expiring_total' => (int) $expiringSoon->sum('remaining'),
                'last_movement_at' => $wallet->last_movement_at?->toIso8601String(),
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * The statement.
     *
     * Holds are filtered out. A reserve followed by a consume is one event to the person
     * who ran a render; three lines for it is how a statement becomes something nobody
     * ever checks, which defeats the purpose of keeping one.
     */
    public function transactions(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $wallet = $this->ledger->walletFor($user);

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $transactions = CreditTransaction::query()
            ->where('wallet_id', $wallet->getKey())
            ->visible()
            // UUIDv7 is time-ordered, so this is newest-first without the tie-breaking
            // problem `created_at` has inside one second.
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'data' => array_map(
                static fn (CreditTransaction $entry): array => [
                    'id' => $entry->id,
                    'type' => $entry->type->value,
                    'type_label' => $entry->type->label(),
                    'amount' => $entry->amount,
                    'balance_after' => $entry->balance_after,
                    'description' => $entry->description,
                    // The staff member's reason, when there was one. A customer is
                    // entitled to know why their balance was corrected by hand.
                    'reason' => $entry->reason,
                    'created_at' => $entry->created_at->toIso8601String(),
                ],
                $transactions->items(),
            ),
            'meta' => [
                'total' => $transactions->total(),
                'per_page' => $transactions->perPage(),
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    /** What is on sale. Public information; no wallet is touched. */
    public function packages(): JsonResponse
    {
        $packages = CreditPackage::query()->purchasable()->get();

        return response()->json([
            'data' => $packages->map(static fn (CreditPackage $package): array => [
                'id' => $package->id,
                'code' => $package->code,
                'name' => $package->name,
                'description' => $package->description,
                'credits' => $package->credits,
                'bonus_credits' => $package->bonus_credits,
                'total_credits' => $package->totalCredits(),
                // Minor units, as everywhere else. A client that receives "499.00" will
                // parse it into a float sooner or later; one that receives 49900 cannot.
                'price' => [
                    'amount_minor' => $package->price_minor,
                    'currency' => $package->currency,
                ],
                'unit_price_scaled' => $package->unitPriceScaled(),
                'validity_days' => $package->validity_days,
                'is_featured' => $package->is_featured,
            ])->all(),
        ]);
    }

    /**
     * Redeeming a promotion code.
     *
     * Rate-limited per user rather than per IP, and deliberately tight. A code is a short
     * string somebody can guess, and without a limit this endpoint is an oracle that will
     * happily be asked a thousand dictionary words a minute.
     */
    public function redeem(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40'],
        ]);

        $key = 'credit-promo:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'message' => sprintf(
                    'Çok fazla deneme yaptınız. %d saniye sonra tekrar deneyin.',
                    RateLimiter::availableIn($key),
                ),
            ], 429);
        }

        RateLimiter::hit($key, 600);

        try {
            $transaction = $this->promotions->redeem($user, $validated['code']);
        } catch (PromotionRefused $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->kind], 422);
        }

        // A success clears the counter: somebody who found a real code is not the person
        // this limit is aimed at.
        RateLimiter::clear($key);

        $wallet = $this->ledger->walletFor($user);

        return response()->json([
            'message' => sprintf('%d kredi hesabınıza tanımlandı.', $transaction->amount),
            'data' => [
                'credits' => $transaction->amount,
                'balance' => $wallet->balance,
                'available' => $wallet->available(),
            ],
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
