<?php

declare(strict_types=1);

namespace App\Domains\Finance\Http\Controllers;

use App\Domains\Finance\Enums\LedgerAccount;
use App\Domains\Finance\Models\CommissionRule;
use App\Domains\Finance\Models\LedgerEntry;
use App\Domains\Finance\Models\LedgerLine;
use App\Domains\Finance\Models\Settlement;
use App\Domains\Finance\Services\Ledger;
use App\Domains\Finance\Services\SettlementService;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Services\AccessControl;
use App\Domains\Sellers\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Finance's side: the books, the payouts, and the commission rules behind them.
 *
 * Reading and settling stay the two separate permissions Phase 14 introduced. Approving a
 * payout commits money and marking one paid records that it left; neither is something a
 * person answering "where is my money" should be able to do by accident.
 */
final class AdminFinanceController
{
    public function __construct(
        private readonly SettlementService $settlements,
        private readonly Ledger $ledger,
        private readonly AccessControl $access,
    ) {}

    /**
     * The books at a glance.
     *
     * `is_balanced` first, because if it is ever false nothing else on the page means
     * anything and an operator should stop reading and call somebody.
     */
    public function overview(Request $request): JsonResponse
    {
        $this->authorise($request, Permission::PaymentsView);

        return $this->json([
            'data' => [
                'is_balanced' => $this->ledger->isBalanced(),
                'accounts' => array_map(fn (LedgerAccount $code): array => [
                    'code' => $code->value,
                    'label' => $code->label(),
                    'type' => $code->type(),
                    'balance_minor' => $this->ledger->balanceOf($code),
                ], [
                    LedgerAccount::CashProvider,
                    LedgerAccount::Bank,
                    LedgerAccount::Commission,
                    LedgerAccount::CustomerRefund,
                    LedgerAccount::PaymentClearing,
                    LedgerAccount::PayoutClearing,
                ]),
                /*
                 * Every seller's payable added together.
                 *
                 * The per-seller accounts are the record, but "what do we owe sellers in
                 * total" is the figure an operator actually needs on an overview — and it
                 * is the one that should roughly equal the cash we are holding.
                 */
                'sellers_owed_minor' => (int) DB::table('ledger_lines')
                    ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_lines.account_id')
                    ->where('ledger_accounts.code', LedgerAccount::SellerPayable->value)
                    ->selectRaw('COALESCE(SUM(credit_minor) - SUM(debit_minor), 0) AS owed')
                    ->value('owed'),

                'open_settlements' => Settlement::query()->open()->count(),
                'sellers_owed' => (int) DB::table('seller_balances')
                    ->where('available_minor', '>', 0)
                    ->count(),
            ],
        ]);
    }

    /** The journal, newest first. */
    public function entries(Request $request): JsonResponse
    {
        $this->authorise($request, Permission::PaymentsView);

        $entries = LedgerEntry::query()
            ->with('lines.account')
            ->orderByDesc('posted_at')
            ->limit(100)
            ->get();

        return $this->json([
            'data' => $entries->map(fn (LedgerEntry $entry): array => [
                'id' => $entry->id,
                'type' => $entry->type,
                'description' => $entry->description,
                'currency' => $entry->currency,
                'total_minor' => $entry->totalMinor(),
                'reference_type' => $entry->reference_type,
                'reference_id' => $entry->reference_id,
                'is_reversal' => $entry->reverses_entry_id !== null,
                'posted_at' => $entry->posted_at->toIso8601String(),
                'lines' => $entry->lines->map(fn (LedgerLine $line): array => [
                    'account' => $line->account?->code,
                    'debit_minor' => $line->debit_minor,
                    'credit_minor' => $line->credit_minor,
                    'memo' => $line->memo,
                ])->values()->all(),
            ])->all(),
        ]);
    }

    // --- settlements ------------------------------------------------------------

    public function settlements(Request $request): JsonResponse
    {
        $this->authorise($request, Permission::PaymentsView);

        $status = $request->query('status');

        $query = Settlement::query()->with('seller')->orderByDesc('created_at');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        } else {
            $query->open();
        }

        return $this->json(['data' => $query->limit(200)->get()->map->toArray()->all()]);
    }

    /** Builds a draft for every seller with something eligible. */
    public function buildSettlements(Request $request): JsonResponse
    {
        $this->authorise($request, Permission::PaymentsSettle);

        $built = $this->settlements->buildAll();

        return $this->json([
            'data' => array_map(static fn (Settlement $settlement): array => $settlement->toArray(), $built),
            'message' => $built === []
                ? 'Hakedişe hazır sipariş bulunamadı.'
                : sprintf('%d hakediş taslağı hazırlandı.', count($built)),
        ]);
    }

    public function approve(Request $request, Settlement $settlement): JsonResponse
    {
        $this->authorise($request, Permission::PaymentsSettle);

        $validated = $request->validate([
            'note' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);

        $approved = $this->settlements->approve($settlement, $this->user($request), $validated['note'] ?? null);

        return $this->json(['data' => $approved->toArray()]);
    }

    public function markPaid(Request $request, Settlement $settlement): JsonResponse
    {
        $this->authorise($request, Permission::PaymentsSettle);

        // The bank's own reference, required: a settlement marked paid with nothing to
        // look up is a seller asking where their money is and nobody able to answer.
        $validated = $request->validate([
            'payout_reference' => ['required', 'string', 'min:3', 'max:191'],
        ]);

        $paid = $this->settlements->markPaid($settlement, $this->user($request), $validated['payout_reference']);

        return $this->json(['data' => $paid->toArray()]);
    }

    public function cancel(Request $request, Settlement $settlement): JsonResponse
    {
        $this->authorise($request, Permission::PaymentsSettle);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:300'],
        ]);

        $cancelled = $this->settlements->cancel($settlement, $this->user($request), $validated['reason']);

        return $this->json(['data' => $cancelled->toArray()]);
    }

    // --- commission rules --------------------------------------------------------

    public function commissionRules(Request $request): JsonResponse
    {
        $this->authorise($request, Permission::PaymentsView);

        $rules = CommissionRule::query()
            ->with(['seller', 'category'])
            ->orderBy('scope')
            ->orderBy('priority')
            ->get();

        return $this->json([
            'data' => $rules->map(fn (CommissionRule $rule): array => [
                'id' => $rule->id,
                'scope' => $rule->scope,
                'rate_bps' => $rule->rate_bps,
                'priority' => $rule->priority,
                'label' => $rule->label,
                'seller_name' => $rule->seller?->display_name,
                'category_name' => $rule->category?->name,
                'starts_at' => $rule->starts_at?->toIso8601String(),
                'ends_at' => $rule->ends_at?->toIso8601String(),
                'is_active' => $rule->is_active,
            ])->all(),
        ]);
    }

    public function saveCommissionRule(Request $request, ?CommissionRule $rule = null): JsonResponse
    {
        $this->authorise($request, Permission::PaymentsSettle);

        $validated = $request->validate([
            'scope' => ['required', 'string', 'in:platform,category,seller,seller_category,campaign'],
            'seller_id' => ['sometimes', 'nullable', 'uuid'],
            'category_id' => ['sometimes', 'nullable', 'uuid'],
            'rate_bps' => ['required', 'integer', 'min:0', 'max:10000'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'label' => ['sometimes', 'nullable', 'string', 'max:160'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $rule ??= new CommissionRule;

        $rule->fill($validated)->save();

        return $this->json(['data' => ['id' => $rule->id]], $rule->wasRecentlyCreated ? 201 : 200);
    }

    /** One seller's balance, for the question "what do we owe them". */
    public function sellerBalance(Request $request, Seller $seller): JsonResponse
    {
        $this->authorise($request, Permission::PaymentsView);

        $balance = DB::table('seller_balances')->where('seller_id', $seller->getKey())->first();

        return $this->json([
            'data' => [
                'seller_name' => $seller->display_name,
                'pending_minor' => (int) ($balance->pending_minor ?? 0),
                'available_minor' => (int) ($balance->available_minor ?? 0),
                'reserved_minor' => (int) ($balance->reserved_minor ?? 0),
                'paid_out_minor' => (int) ($balance->paid_out_minor ?? 0),
                'ledger_owed_minor' => $this->ledger->balanceOf(
                    LedgerAccount::SellerPayable,
                    (string) $seller->getKey(),
                ),
            ],
        ]);
    }

    // --- internals -----------------------------------------------------------

    private function authorise(Request $request, Permission $permission): void
    {
        abort_unless($this->access->hasPermission($this->user($request), $permission), 403);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'no-store, private');
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
