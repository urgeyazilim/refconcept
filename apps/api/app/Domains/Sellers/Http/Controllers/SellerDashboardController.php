<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Http\Controllers;

use App\Domains\Fulfilment\Enums\ReturnStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Orders\Enums\SellerOrderStatus;
use App\Domains\Orders\Models\SellerOrder;
use App\Domains\Organizations\Enums\MembershipStatus;
use App\Domains\Products\Models\Product;
use App\Domains\Sellers\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What a seller needs to know before they start work.
 *
 * The queue first, then the money, then the catalogue — the order somebody actually works
 * a morning in. A dashboard that leads with a revenue figure looks impressive and answers
 * nothing: the seller already knows roughly what they sold, and does not know that four
 * orders have been sitting unconfirmed since Friday.
 *
 * Every figure is scoped to the caller's own seller. There is no id in the path, so the
 * question "whose dashboard" cannot be asked at all.
 */
final class SellerDashboardController
{
    public function show(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        $days = min(90, max(7, (int) $request->query('days', 30)));
        $since = now()->subDays($days);

        $orders = SellerOrder::query()->where('seller_id', $seller->getKey());

        $inPeriod = (clone $orders)->whereHas(
            'order',
            fn ($query) => $query->where('placed_at', '>=', $since),
        );

        $balance = DB::table('seller_balances')
            ->where('seller_id', $seller->getKey())
            ->where('currency', 'TRY')
            ->first();

        return response()->json([
            'data' => [
                /*
                 * The half a seller opens this page for. Each of these is somebody waiting
                 * — a customer whose order has not been acknowledged, a parcel that has not
                 * gone, a return nobody has answered.
                 */
                'waiting' => [
                    'unconfirmed_orders' => (clone $orders)
                        ->where('status', SellerOrderStatus::AwaitingConfirmation->value)->count(),
                    'to_ship' => (clone $orders)
                        ->whereIn('status', [
                            SellerOrderStatus::Confirmed->value,
                            SellerOrderStatus::Preparing->value,
                        ])->count(),
                    'open_returns' => DB::table('returns')
                        ->join('seller_orders', 'seller_orders.id', '=', 'returns.seller_order_id')
                        ->where('seller_orders.seller_id', $seller->getKey())
                        ->whereIn('returns.status', [
                            ReturnStatus::Requested->value,
                            ReturnStatus::Approved->value,
                            ReturnStatus::Received->value,
                        ])->count(),
                    'low_stock' => $this->lowStock($seller),
                    'pending_moderation' => Product::query()
                        ->where('organization_id', $seller->organization_id)
                        ->where('moderation_status', 'pending')->count(),
                ],

                'sales' => [
                    'period_days' => $days,
                    'orders' => (clone $inPeriod)->count(),
                    'gross_minor' => (int) (clone $inPeriod)->sum('total_minor'),
                    'commission_minor' => (int) (clone $inPeriod)->sum('commission_minor'),
                    // What is actually theirs. A gross figure with the commission left in
                    // is the number a seller plans around and then does not receive.
                    'payable_minor' => (int) (clone $inPeriod)->sum('total_minor')
                        - (int) (clone $inPeriod)->sum('commission_minor'),
                ],

                // From the ledger's projection rather than from the orders, for the same
                // reason the admin dashboard does: the two disagreeing is worth seeing.
                'earnings' => [
                    'currency' => 'TRY',
                    'available_minor' => (int) ($balance->available_minor ?? 0),
                    'pending_minor' => (int) ($balance->pending_minor ?? 0),
                    'in_settlement_minor' => (int) ($balance->reserved_minor ?? 0),
                    'paid_minor' => (int) ($balance->paid_out_minor ?? 0),
                ],

                'catalogue' => [
                    'live' => Product::query()->where('organization_id', $seller->organization_id)
                        ->where('status', 'active')->where('moderation_status', 'approved')->count(),
                    'draft' => Product::query()->where('organization_id', $seller->organization_id)
                        ->where('status', 'draft')->count(),
                    'out_of_stock' => $this->outOfStock($seller),
                ],
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    // --- internals -----------------------------------------------------------

    /**
     * Listings with stock but not much of it.
     *
     * A threshold rather than a per-product reorder point, which is a warehouse feature and
     * belongs with one. Five is low enough to be worth a look and high enough to arrive
     * before the listing goes dark.
     */
    private function lowStock(Seller $seller): int
    {
        return DB::table('product_skus')
            ->join('products', 'products.id', '=', 'product_skus.product_id')
            ->where('products.organization_id', $seller->organization_id)
            ->where('products.status', 'active')
            ->whereBetween('product_skus.stock_quantity', [1, 5])
            ->count();
    }

    private function outOfStock(Seller $seller): int
    {
        return DB::table('product_skus')
            ->join('products', 'products.id', '=', 'product_skus.product_id')
            ->where('products.organization_id', $seller->organization_id)
            ->where('products.status', 'active')
            ->where('product_skus.stock_quantity', '<=', 0)
            ->count();
    }

    private function seller(Request $request): Seller
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $seller = Seller::query()
            ->whereHas(
                'organization.memberships',
                fn ($query) => $query->where('user_id', $user->getKey())
                    ->where('status', MembershipStatus::Active->value),
            )
            ->first();

        abort_if($seller === null, 404, 'Bu hesaba bağlı bir satıcı kaydı yok.');

        return $seller;
    }
}
