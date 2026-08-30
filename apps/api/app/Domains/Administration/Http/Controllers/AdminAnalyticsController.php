<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Controllers;

use App\Domains\Ai\Models\AiJob;
use App\Domains\Catalog\Services\CatalogCoverage;
use App\Domains\Catalog\Services\ProgrammeCoverageReport;
use App\Domains\Finance\Enums\LedgerAccount;
use App\Domains\Finance\Services\Ledger;
use App\Domains\Identity\Models\User;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\SellerOrder;
use App\Domains\Products\Models\Product;
use App\Domains\Sellers\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What the platform did, in numbers.
 *
 * Deliberately small. A dashboard's job is to answer "is anything wrong" in five seconds,
 * and a screen with forty figures answers nothing — so this returns the handful an
 * operator would actually act on, and the money comes from the ledger rather than from
 * summing order tables, because the ledger is the authority and the two disagreeing is
 * exactly what a dashboard should surface.
 */
final class AdminAnalyticsController
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly ProgrammeCoverageReport $coverage,
        private readonly CatalogCoverage $catalogue,
    ) {}

    /**
     * Which of the design questions the shop can actually answer, room by room.
     *
     * A commercial number rather than a technical one. Every category missing here is a
     * question a customer is shown greyed out with "bu ürün grubunda henüz satıcımız yok" —
     * so this is a list of sentences the product is currently having to say, ordered by how
     * often it has to say them, which is the same thing as a list of sellers worth signing.
     */
    public function catalogueCoverage(Request $request): JsonResponse
    {
        // Read fresh. Somebody opening this straight after approving a listing wants to see
        // the listing, not a ten-minute-old count.
        $this->catalogue->forget();

        $style = $request->string('style')->toString();

        return response()->json([
            'data' => $this->coverage->all($style === '' ? null : $style),
        ]);
    }

    public function overview(Request $request): JsonResponse
    {
        $days = min(90, max(1, (int) $request->query('days', 30)));
        $since = now()->subDays($days);

        $orders = Order::query()->where('placed_at', '>=', $since);

        return $this->json([
            'data' => [
                'period_days' => $days,

                'orders' => [
                    'count' => (clone $orders)->count(),
                    'gross_minor' => (int) (clone $orders)->sum('grand_total_minor'),
                    'average_minor' => (int) round((float) (clone $orders)->avg('grand_total_minor') ?: 0),
                    'by_status' => (clone $orders)
                        ->toBase()
                        ->select('status', DB::raw('count(*) as total'))
                        ->groupBy('status')
                        ->pluck('total', 'status')
                        ->all(),
                ],

                /*
                 * From the ledger, not from the orders.
                 *
                 * The two disagreeing is precisely the thing a dashboard exists to
                 * surface, so taking both from the same place would hide it.
                 */
                'money' => [
                    'is_balanced' => $this->ledger->isBalanced(),
                    'cash_minor' => $this->ledger->balanceOf(LedgerAccount::CashProvider),
                    'commission_minor' => $this->ledger->balanceOf(LedgerAccount::Commission),
                    'refunds_owed_minor' => $this->ledger->balanceOf(LedgerAccount::CustomerRefund),
                    'sellers_owed_minor' => (int) DB::table('ledger_lines')
                        ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_lines.account_id')
                        ->where('ledger_accounts.code', LedgerAccount::SellerPayable->value)
                        ->selectRaw('COALESCE(SUM(credit_minor) - SUM(debit_minor), 0) AS owed')
                        ->value('owed'),
                ],

                'marketplace' => [
                    'active_sellers' => Seller::query()->where('status', 'active')->count(),
                    'live_products' => Product::query()->where('status', 'active')
                        ->where('moderation_status', 'approved')->count(),
                    'pending_moderation' => Product::query()->where('moderation_status', 'pending')->count(),
                    'new_customers' => User::query()->where('created_at', '>=', $since)->count(),
                ],

                /*
                 * The queue an operator should clear, not a count of everything.
                 *
                 * A dashboard that says "412 orders" tells somebody nothing; one that says
                 * "6 waiting on you" tells them what to do next.
                 */
                'waiting' => [
                    'seller_orders_unconfirmed' => SellerOrder::query()
                        ->where('status', 'awaiting_confirmation')->count(),
                    'open_returns' => DB::table('returns')
                        ->whereIn('status', ['requested', 'approved', 'in_transit', 'received'])->count(),
                    'transfers_to_check' => DB::table('bank_transfers')
                        ->whereIn('status', ['awaiting_transfer', 'under_review', 'short_paid'])->count(),
                    'settlements_open' => DB::table('settlements')
                        ->whereIn('status', ['draft', 'approved'])->count(),
                    'failed_refunds' => DB::table('refunds')->where('status', 'failed')->count(),
                    'failed_jobs' => DB::table('failed_jobs')->count(),
                ],

                'ai' => [
                    'jobs' => AiJob::query()->where('created_at', '>=', $since)->count(),
                    'failed' => AiJob::query()->where('created_at', '>=', $since)
                        ->where('status', 'failed')->count(),
                ],
            ],
        ]);
    }

    /**
     * Orders a day, for the shape rather than the total.
     *
     * A single figure for a month hides the Tuesday nothing sold; a series does not.
     */
    public function orderSeries(Request $request): JsonResponse
    {
        $days = min(90, max(7, (int) $request->query('days', 30)));

        $rows = Order::query()
            ->where('placed_at', '>=', now()->subDays($days))
            ->toBase()
            ->selectRaw('DATE(placed_at) AS day, count(*) AS orders, COALESCE(SUM(grand_total_minor), 0) AS gross')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        return $this->json([
            'data' => $rows->map(static fn (object $row): array => [
                'day' => (string) $row->day,
                'orders' => (int) $row->orders,
                'gross_minor' => (int) $row->gross,
            ])->all(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload): JsonResponse
    {
        return response()->json($payload)->header('Cache-Control', 'no-store, private');
    }
}
