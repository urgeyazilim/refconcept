<?php

declare(strict_types=1);

namespace App\Domains\Credits\Http\Controllers;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Credits\Exceptions\InsufficientCredits;
use App\Domains\Credits\Models\CreditPackage;
use App\Domains\Credits\Models\CreditPromotion;
use App\Domains\Credits\Models\CreditTransaction;
use App\Domains\Credits\Services\CreditLedger;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Services\AccessControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Credit administration.
 *
 * Three powers, in increasing order of how much damage they can do: editing what is on
 * sale, running promotions, and adjusting a specific person's balance by hand. All three
 * are gated on the same permission and every one of them is audited, because the third
 * is indistinguishable from theft without a record of who did it and why.
 *
 * The adjustment endpoint demands a reason and the database refuses an adjustment without
 * one. That is not belt-and-braces for its own sake: this is the one movement that
 * happens because a person decided it should, and "why do I have forty fewer credits than
 * yesterday" needs an answer better than "somebody ran a script".
 */
final class AdminCreditController
{
    public function __construct(
        private readonly CreditLedger $ledger,
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
    ) {}

    // --- packages ------------------------------------------------------------

    public function packages(Request $request): JsonResponse
    {
        $this->authorizeRead($request);

        return response()->json([
            'data' => CreditPackage::query()
                ->orderBy('position')
                ->orderBy('price_minor')
                ->get()
                ->map(fn (CreditPackage $package): array => $this->packagePayload($package))
                ->all(),
        ]);
    }

    public function savePackage(Request $request, ?CreditPackage $package = null): JsonResponse
    {
        $this->authorizeWrite($request);

        $validated = $request->validate([
            'code' => [
                $package === null ? 'required' : 'sometimes',
                'string', 'max:40', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('credit_packages', 'code')->ignore($package?->getKey()),
            ],
            'name' => [$package === null ? 'required' : 'sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'credits' => [$package === null ? 'required' : 'sometimes', 'integer', 'min:1'],
            'bonus_credits' => ['sometimes', 'integer', 'min:0'],
            'price_minor' => [$package === null ? 'required' : 'sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'validity_days' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);

        if ($package === null) {
            $package = CreditPackage::query()->create($validated);
            $this->audit->record('credit.package.created', $package, $validated);

            return response()->json(['data' => $this->packagePayload($package)], 201);
        }

        /*
         * A price change edits the row rather than versioning it, unlike an AI cost rate.
         * The difference is who the number is for: a rate is what *we* paid and has to be
         * reconstructable per historical job, while a package price is a shop-window
         * figure. What a customer actually paid is captured on their purchase, which is
         * Phase 11's job, and that snapshot is what a receipt is built from.
         */
        $package->update($validated);
        $this->audit->recordChange('credit.package.updated', $package);

        return response()->json(['data' => $this->packagePayload($package->fresh() ?? $package)]);
    }

    // --- promotions ----------------------------------------------------------

    public function promotions(Request $request): JsonResponse
    {
        $this->authorizeRead($request);

        return response()->json([
            'data' => CreditPromotion::query()
                ->orderByDesc('id')
                ->get()
                ->map(fn (CreditPromotion $promotion): array => $this->promotionPayload($promotion))
                ->all(),
        ]);
    }

    public function savePromotion(Request $request, ?CreditPromotion $promotion = null): JsonResponse
    {
        $this->authorizeWrite($request);

        $validated = $request->validate([
            'code' => [
                $promotion === null ? 'required' : 'sometimes',
                'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('credit_promotions', 'code')->ignore($promotion?->getKey()),
            ],
            'name' => [$promotion === null ? 'required' : 'sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'credits' => [$promotion === null ? 'required' : 'sometimes', 'integer', 'min:1', 'max:100000'],
            'validity_days' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_redemptions' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_per_user' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'new_accounts_only' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($promotion === null) {
            $promotion = CreditPromotion::query()->create($validated);
            $this->audit->record('credit.promotion.created', $promotion, $validated);

            return response()->json(['data' => $this->promotionPayload($promotion)], 201);
        }

        /*
         * `credits` and `code` stay editable while a promotion is live, which is a
         * deliberate choice rather than an oversight: a campaign with a typo in it is
         * worth fixing. What is *not* editable is anything already handed out — every
         * redemption recorded the amount it granted, so raising the figure tomorrow does
         * not retroactively enrich yesterday's claimants.
         */
        $promotion->update($validated);
        $this->audit->recordChange('credit.promotion.updated', $promotion);

        return response()->json(['data' => $this->promotionPayload($promotion->fresh() ?? $promotion)]);
    }

    // --- wallets -------------------------------------------------------------

    /**
     * One customer's wallet, for support.
     *
     * The balance, the holds and the statement — no more. There is nothing here about
     * what the credits were spent *on*: "Salon tasarımı" is a description a customer
     * wrote about their own home, and support answering a billing question does not need
     * to read it.
     */
    public function wallet(Request $request, User $user): JsonResponse
    {
        $this->authorizeRead($request);

        $wallet = $this->ledger->walletFor($user);

        $transactions = CreditTransaction::query()
            ->where('wallet_id', $wallet->getKey())
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $user->getKey(),
                    'email' => $user->email,
                    'name' => $user->displayName(),
                ],
                'balance' => $wallet->balance,
                'reserved' => $wallet->reserved,
                'available' => $wallet->available(),
                'lifetime' => [
                    'purchased' => $wallet->lifetime_purchased,
                    'granted' => $wallet->lifetime_granted,
                    'consumed' => $wallet->lifetime_consumed,
                    'expired' => $wallet->lifetime_expired,
                ],

                /*
                 * The reconciliation figure, on the screen rather than in a report nobody
                 * runs. If the lots and the wallet ever disagree, the person looking at
                 * this customer's balance is the one who most needs to know.
                 */
                'lot_total' => $this->ledger->reconcile($wallet),

                'transactions' => $transactions->map(static fn (CreditTransaction $entry): array => [
                    'id' => $entry->id,
                    'type' => $entry->type->value,
                    'type_label' => $entry->type->label(),
                    'amount' => $entry->amount,
                    'balance_after' => $entry->balance_after,
                    'reserved_after' => $entry->reserved_after,
                    'reason' => $entry->reason,
                    'actor_id' => $entry->actor_id,
                    'created_at' => $entry->created_at->toIso8601String(),
                ])->all(),
            ],
        ]);
    }

    /**
     * A manual correction.
     *
     * Both directions, one endpoint, and always a reason. A negative adjustment that
     * would drive the balance below zero is refused rather than clamped: silently taking
     * less than was asked for would leave the member of staff believing they had made a
     * correction they had not.
     */
    public function adjust(Request $request, User $user): JsonResponse
    {
        $this->authorizeWrite($request);

        $actor = $request->user();

        abort_unless($actor instanceof User, 401);

        $validated = $request->validate([
            'delta' => ['required', 'integer', 'not_in:0', 'min:-100000', 'max:100000'],
            'reason' => ['required', 'string', 'min:8', 'max:400'],
        ]);

        try {
            $transaction = $this->ledger->adjust(
                user: $user,
                delta: (int) $validated['delta'],
                reason: $validated['reason'],
                actor: $actor,
            );
        } catch (InsufficientCredits $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Audited in addition to the ledger entry. The ledger says what happened to the
        // money; the audit log is where "which member of staff touched this account" is
        // looked up, and support reads that far more often than it reads a wallet.
        $this->audit->record('credit.wallet.adjusted', $user, [
            'delta' => $validated['delta'],
            'balance_after' => $transaction->balance_after,
        ], reason: $validated['reason']);

        return response()->json([
            'message' => 'Bakiye güncellendi.',
            'data' => [
                'delta' => $transaction->amount,
                'balance' => $transaction->balance_after,
            ],
        ]);
    }

    // --- payloads ------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function packagePayload(CreditPackage $package): array
    {
        return [
            'id' => $package->id,
            'code' => $package->code,
            'name' => $package->name,
            'description' => $package->description,
            'credits' => $package->credits,
            'bonus_credits' => $package->bonus_credits,
            'total_credits' => $package->totalCredits(),
            'price_minor' => $package->price_minor,
            'currency' => $package->currency,
            'unit_price_scaled' => $package->unitPriceScaled(),
            'validity_days' => $package->validity_days,
            'is_active' => $package->is_active,
            'is_featured' => $package->is_featured,
            'position' => $package->position,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function promotionPayload(CreditPromotion $promotion): array
    {
        return [
            'id' => $promotion->id,
            'code' => $promotion->code,
            'name' => $promotion->name,
            'description' => $promotion->description,
            'credits' => $promotion->credits,
            'validity_days' => $promotion->validity_days,
            'max_redemptions' => $promotion->max_redemptions,
            'max_per_user' => $promotion->max_per_user,
            'redemption_count' => $promotion->redemption_count,
            'remaining_redemptions' => $promotion->remainingRedemptions(),
            'starts_at' => $promotion->starts_at?->toIso8601String(),
            'ends_at' => $promotion->ends_at?->toIso8601String(),
            'new_accounts_only' => $promotion->new_accounts_only,
            'is_active' => $promotion->is_active,
            'is_running' => $promotion->isRunning(),
        ];
    }

    private function authorizeRead(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && (
                $this->access->hasPermission($user, Permission::SystemSettingsManage)
                || $this->access->hasPermission($user, Permission::AuditView)
            ),
            403,
        );
    }

    private function authorizeWrite(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && $this->access->hasPermission($user, Permission::SystemSettingsManage),
            403,
        );
    }
}
