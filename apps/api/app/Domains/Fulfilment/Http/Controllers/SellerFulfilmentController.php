<?php

declare(strict_types=1);

namespace App\Domains\Fulfilment\Http\Controllers;

use App\Domains\Fulfilment\Enums\ReturnStatus;
use App\Domains\Fulfilment\Exceptions\FulfilmentRefused;
use App\Domains\Fulfilment\Models\ReturnRequest;
use App\Domains\Fulfilment\Models\Shipment;
use App\Domains\Fulfilment\Services\ReturnService;
use App\Domains\Fulfilment\Services\ShipmentService;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Services\AccessControl;
use App\Domains\Orders\Models\SellerOrder;
use App\Domains\Sellers\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A seller's parcels and the returns coming back to them.
 *
 * Everything is scoped to the seller the caller belongs to, and anything that is not
 * theirs is a 404 — whether a competitor has a return is not something to confirm.
 */
final class SellerFulfilmentController
{
    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly ReturnService $returns,
        private readonly AccessControl $access,
    ) {}

    // --- shipments ---------------------------------------------------------------

    /**
     * Records a parcel.
     *
     * Partial is normal: three of four chairs today and the fourth on Thursday, so the
     * lines and their quantities come from the seller.
     */
    public function ship(Request $request, string $number): JsonResponse
    {
        $sellerOrder = $this->ownedOrder($request, $number);

        $validated = $request->validate([
            'carrier' => ['sometimes', 'nullable', 'string', 'max:80'],
            'tracking_number' => ['sometimes', 'nullable', 'string', 'max:120'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        $shipment = $this->shipments->ship(
            $sellerOrder,
            $validated['items'],
            $validated['carrier'] ?? null,
            $validated['tracking_number'] ?? null,
            $this->user($request),
        );

        return $this->json([
            'data' => [
                'shipment' => $shipment->toArray(),
                'seller_order' => $sellerOrder->fresh()?->toSellerArray(),
            ],
        ], 201);
    }

    public function shipments(Request $request, string $number): JsonResponse
    {
        $sellerOrder = $this->ownedOrder($request, $number);

        $shipments = Shipment::query()
            ->where('seller_order_id', $sellerOrder->getKey())
            ->with('items')
            ->orderBy('created_at')
            ->get();

        return $this->json(['data' => $shipments->map->toArray()->all()]);
    }

    public function markDelivered(Request $request, string $number, Shipment $shipment): JsonResponse
    {
        $sellerOrder = $this->ownedOrder($request, $number);

        abort_unless($shipment->seller_order_id === $sellerOrder->getKey(), 404);

        $delivered = $this->shipments->markDelivered($shipment, $this->user($request));

        return $this->json(['data' => $delivered->toArray()]);
    }

    // --- returns ------------------------------------------------------------------

    public function returns(Request $request): JsonResponse
    {
        $seller = $this->seller($request);

        $status = $request->query('status');

        $query = ReturnRequest::query()
            ->whereHas('sellerOrder', fn ($q) => $q->where('seller_id', $seller->getKey()))
            ->with(['items.orderItem', 'sellerOrder', 'refunds'])
            ->orderByDesc('created_at');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        } else {
            // The default is work to do, not an archive.
            $query->blocking();
        }

        return $this->json(['data' => $query->limit(200)->get()->map->toArray()->all()]);
    }

    /**
     * The seller accepts or refuses, line by line.
     *
     * Accepting some of a request is normal — three chairs sent back, one arrived
     * scratched — so the approved quantities come per line rather than as a yes or no.
     */
    public function decideReturn(Request $request, string $reference): JsonResponse
    {
        $return = $this->ownedReturn($request, $reference);

        $validated = $request->validate([
            'accept' => ['required', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'approved' => ['sometimes', 'array'],
            'approved.*' => ['integer', 'min:0', 'max:99'],
        ]);

        $decided = $this->returns->decide(
            $return,
            (bool) $validated['accept'],
            $validated['approved'] ?? [],
            $this->user($request),
            $validated['note'] ?? null,
        );

        return $this->json(['data' => $decided->fresh(['items.orderItem', 'refunds'])?->toArray()]);
    }

    /**
     * The seller has the parcel, or has finished with it.
     *
     * `received` and `completed` are separate because opening the box is where a return is
     * actually decided — and completing one is what releases the money.
     */
    public function advanceReturn(Request $request, string $reference): JsonResponse
    {
        $return = $this->ownedReturn($request, $reference);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:received,completed,rejected'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $next = ReturnStatus::from($validated['status']);

        if ($next === ReturnStatus::Rejected) {
            // Refusing after inspection costs the customer something, so it needs saying.
            $decided = $this->returns->decide($return, false, [], $this->user($request), $validated['note'] ?? null);

            return $this->json(['data' => $decided->fresh(['items.orderItem', 'refunds'])?->toArray()]);
        }

        $updated = $this->returns->advance($return, $next, $this->user($request));

        return $this->json(['data' => $updated->fresh(['items.orderItem', 'refunds'])?->toArray()]);
    }

    // --- internals -----------------------------------------------------------

    private function ownedOrder(Request $request, string $number): SellerOrder
    {
        $seller = $this->seller($request);

        $sellerOrder = SellerOrder::query()
            ->where('seller_order_number', $number)
            ->with(['items', 'order'])
            ->first();

        if ($sellerOrder === null || $sellerOrder->seller_id !== $seller->getKey()) {
            throw FulfilmentRefused::notYours();
        }

        return $sellerOrder;
    }

    private function ownedReturn(Request $request, string $reference): ReturnRequest
    {
        $seller = $this->seller($request);

        $return = ReturnRequest::query()
            ->where('reference', $reference)
            ->with(['items.orderItem', 'sellerOrder'])
            ->first();

        if ($return === null || $return->sellerOrder?->seller_id !== $seller->getKey()) {
            throw FulfilmentRefused::notYours();
        }

        return $return;
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
