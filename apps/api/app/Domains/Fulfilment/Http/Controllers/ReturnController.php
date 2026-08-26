<?php

declare(strict_types=1);

namespace App\Domains\Fulfilment\Http\Controllers;

use App\Domains\Fulfilment\Enums\ReturnStatus;
use App\Domains\Fulfilment\Exceptions\FulfilmentRefused;
use App\Domains\Fulfilment\Models\ReturnRequest;
use App\Domains\Fulfilment\Services\ReturnService;
use App\Domains\Identity\Models\User;
use App\Domains\Orders\Models\SellerOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's side of a return.
 *
 * Addressed by reference, checked against the caller first. A return is opened against a
 * *seller order* rather than the whole order, because that is who receives the parcel —
 * three sellers means three returns however it looked when the button was pressed.
 */
final class ReturnController
{
    public function __construct(private readonly ReturnService $returns) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $returns = ReturnRequest::query()
            ->whereHas('order', fn ($query) => $query->where('user_id', $user->getKey()))
            ->with(['items.orderItem', 'sellerOrder.seller', 'refunds'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return $this->json(['data' => $returns->map->toArray()->all()]);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        return $this->json(['data' => $this->owned($request, $reference)->toArray()]);
    }

    /**
     * Opens a request against one seller's part of an order.
     *
     * The lines and quantities come from the customer, because returning one of four
     * chairs is the ordinary case.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'seller_order_number' => ['required', 'string', 'max:32'],
            'reason_code' => ['required', 'string', 'max:40'],
            'reason_note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        $sellerOrder = SellerOrder::query()
            ->where('seller_order_number', $validated['seller_order_number'])
            ->with('order')
            ->first();

        // 404 either way: whether somebody else's order exists is not a thing to confirm.
        if ($sellerOrder === null || $sellerOrder->order?->user_id !== $user->getKey()) {
            throw FulfilmentRefused::notYours();
        }

        $return = $this->returns->open(
            $sellerOrder,
            $validated['items'],
            $validated['reason_code'],
            $validated['reason_note'] ?? null,
            $user,
        );

        return $this->json(['data' => $return->toArray()], 201);
    }

    /** The customer changes their mind before anything has moved. */
    public function cancel(Request $request, string $reference): JsonResponse
    {
        $return = $this->owned($request, $reference);

        $cancelled = $this->returns->advance(
            $return,
            ReturnStatus::Cancelled,
            $this->user($request),
        );

        return $this->json(['data' => $cancelled->toArray()]);
    }

    /** The customer says the parcel is on its way back. */
    public function markSent(Request $request, string $reference): JsonResponse
    {
        $return = $this->owned($request, $reference);

        $updated = $this->returns->advance(
            $return,
            ReturnStatus::InTransit,
            $this->user($request),
        );

        return $this->json(['data' => $updated->toArray()]);
    }

    /** Which reasons a customer may choose, in their own words. */
    public function reasons(): JsonResponse
    {
        return $this->json([
            'data' => [
                ['code' => 'damaged', 'label' => 'Hasarlı geldi'],
                ['code' => 'wrong_item', 'label' => 'Yanlış ürün gönderildi'],
                ['code' => 'not_as_described', 'label' => 'Açıklamayla uyuşmuyor'],
                ['code' => 'changed_mind', 'label' => 'Vazgeçtim'],
                ['code' => 'other', 'label' => 'Diğer'],
            ],
        ]);
    }

    // --- internals -----------------------------------------------------------

    private function owned(Request $request, string $reference): ReturnRequest
    {
        $user = $this->user($request);

        $return = ReturnRequest::query()
            ->where('reference', $reference)
            ->with(['items.orderItem', 'sellerOrder.seller', 'order', 'refunds'])
            ->first();

        if ($return === null || $return->order?->user_id !== $user->getKey()) {
            throw FulfilmentRefused::notYours();
        }

        return $return;
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
