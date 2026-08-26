<?php

declare(strict_types=1);

namespace App\Domains\Finance\Http\Controllers;

use App\Domains\Finance\Models\Settlement;
use App\Domains\Finance\Services\SettlementEligibility;
use App\Domains\Identity\Services\AccessControl;
use App\Domains\Orders\Models\SellerOrder;
use App\Domains\Sellers\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What a seller is owed, and when they will get it.
 *
 * Built around the two questions a seller actually asks: how much, and why not yet. The
 * second is why every order carries a sentence rather than a status code — "12.09.2026
 * tarihinde hakedişe girer" is something a seller can plan around, and "pending" is not.
 */
final class SellerEarningsController
{
    public function __construct(
        private readonly SettlementEligibility $eligibility,
        private readonly AccessControl $access,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        $currency = 'TRY';

        $balance = DB::table('seller_balances')
            ->where('seller_id', $seller->getKey())
            ->where('currency', $currency)
            ->first();

        return $this->json([
            'data' => [
                'currency' => $currency,
                'pending_minor' => (int) ($balance->pending_minor ?? 0),
                'available_minor' => (int) ($balance->available_minor ?? 0),
                'reserved_minor' => (int) ($balance->reserved_minor ?? 0),
                'paid_out_minor' => (int) ($balance->paid_out_minor ?? 0),
                'lifetime_commission_minor' => (int) ($balance->lifetime_commission_minor ?? 0),
                'hold_days' => $this->eligibility->holdDays(),
            ],
        ]);
    }

    /**
     * Every order that is owed money, with a sentence about each.
     *
     * Delivered orders first because those are the ones a seller is waiting on; the rest
     * is what is still in flight.
     */
    public function orders(Request $request): JsonResponse
    {
        $seller = $this->seller($request);

        $orders = SellerOrder::query()
            ->where('seller_id', $seller->getKey())
            ->whereNotIn('status', ['cancelled'])
            ->with(['order.payment', 'seller'])
            ->orderByRaw("CASE WHEN status = 'delivered' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return $this->json([
            'data' => $orders->map(fn (SellerOrder $sellerOrder): array => [
                'seller_order_number' => $sellerOrder->seller_order_number,
                'status' => $sellerOrder->status->value,
                'status_label' => $sellerOrder->status->label(),
                'total_minor' => $sellerOrder->total_minor,
                'commission_minor' => $sellerOrder->commission_minor,
                'payable_minor' => $sellerOrder->payableMinor(),
                'delivered_at' => $sellerOrder->delivered_at?->toIso8601String(),
                // The sentence. Sellers ask, and silence is a support ticket.
                'settlement_note' => $this->eligibility->explain($sellerOrder),
            ])->all(),
        ]);
    }

    public function settlements(Request $request): JsonResponse
    {
        $seller = $this->seller($request);

        $settlements = Settlement::query()
            ->where('seller_id', $seller->getKey())
            ->with('seller')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return $this->json(['data' => $settlements->map->toArray()->all()]);
    }

    private function seller(Request $request): Seller
    {
        $organizationIds = $this->access->organizationIds($request->user());

        abort_if($organizationIds === [], 403, 'Satıcı hesabınız bulunmuyor.');

        $seller = Seller::query()->whereIn('organization_id', $organizationIds)->first();

        abort_if($seller === null, 403, 'Onaylı satıcı hesabınız bulunmuyor.');

        return $seller;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload): JsonResponse
    {
        return response()->json($payload)->header('Cache-Control', 'no-store, private');
    }
}
