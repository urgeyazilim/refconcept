<?php

declare(strict_types=1);

namespace App\Domains\Orders\Http\Controllers;

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Services\AccessControl;
use App\Domains\Orders\Enums\SellerOrderStatus;
use App\Domains\Orders\Exceptions\OrderRefused;
use App\Domains\Orders\Models\SellerOrder;
use App\Domains\Orders\Services\OrderStatusService;
use App\Domains\Sellers\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A seller's own orders.
 *
 * Every read and every write is scoped to the seller the caller belongs to, and a
 * seller order that is not theirs is a 404 — whether a competitor has an order is not
 * something to confirm.
 *
 * The response carries the delivery address, because a courier label needs it, and
 * nothing at all about the rest of the customer's basket.
 */
final class SellerOrderController
{
    public function __construct(
        private readonly OrderStatusService $statuses,
        private readonly AccessControl $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $seller = $this->seller($request);

        $status = $request->query('status');

        $query = SellerOrder::query()
            ->where('seller_id', $seller->getKey())
            ->with(['order', 'items'])
            ->orderByDesc('created_at');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        } elseif ($request->query('scope') !== 'all') {
            // The default is work to do, not an archive. A screen that opens on everything
            // ever is a screen nobody uses to work.
            $query->open();
        }

        return $this->json([
            'data' => $query->limit(200)->get()
                ->map(fn (SellerOrder $sellerOrder): array => $sellerOrder->toSellerArray())
                ->all(),
        ]);
    }

    public function show(Request $request, string $number): JsonResponse
    {
        $sellerOrder = $this->ownedOrder($request, $number);

        return $this->json(['data' => $sellerOrder->toSellerArray(withItems: true)]);
    }

    /**
     * Moves the order along.
     *
     * One endpoint for every transition rather than four verbs, because the rules about
     * which move is legal live in the status machine and splitting them across routes
     * would put half of them in the routing table.
     */
    public function advance(Request $request, string $number): JsonResponse
    {
        $sellerOrder = $this->ownedOrder($request, $number);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:confirmed,preparing,shipped,delivered,cancelled'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);

        $updated = $this->statuses->advance(
            $sellerOrder,
            SellerOrderStatus::from($validated['status']),
            $this->user($request),
            'seller',
            $validated['reason'] ?? null,
        );

        return $this->json(['data' => $updated->toSellerArray(withItems: true)]);
    }

    // --- internals -----------------------------------------------------------

    private function ownedOrder(Request $request, string $number): SellerOrder
    {
        $seller = $this->seller($request);

        $sellerOrder = SellerOrder::query()
            ->where('seller_order_number', $number)
            ->with(['order', 'items'])
            ->first();

        if ($sellerOrder === null || $sellerOrder->seller_id !== $seller->getKey()) {
            throw OrderRefused::notYours();
        }

        return $sellerOrder;
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
