<?php

declare(strict_types=1);

namespace App\Domains\Orders\Http\Controllers;

use App\Domains\Identity\Models\User;
use App\Domains\Orders\Exceptions\OrderRefused;
use App\Domains\Orders\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's orders.
 *
 * Addressed by order number rather than id — it is what the customer has in their hand,
 * from an e-mail or a phone call — and checked against the caller before anything is said
 * about it.
 */
final class OrderController
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $orders = Order::query()
            ->forCustomer((string) $user->getKey())
            ->with(['items', 'sellerOrders.seller'])
            ->limit(50)
            ->get();

        return $this->json([
            'data' => $orders->map(fn (Order $order): array => $order->toCustomerArray())->all(),
        ]);
    }

    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $order = $this->ownedOrder($request, $orderNumber);

        return $this->json(['data' => $order->toCustomerArray(withItems: true)]);
    }

    private function ownedOrder(Request $request, string $orderNumber): Order
    {
        $user = $this->user($request);

        $order = Order::query()
            ->where('order_number', $orderNumber)
            ->with(['items', 'sellerOrders.seller'])
            ->first();

        // 404 either way: whether somebody else's order number exists is not something to
        // confirm to a stranger working through a sequence.
        if ($order === null || $order->user_id !== $user->getKey()) {
            throw OrderRefused::notYours();
        }

        return $order;
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
