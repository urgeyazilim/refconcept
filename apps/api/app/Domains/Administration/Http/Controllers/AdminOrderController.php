<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Controllers;

use App\Domains\Orders\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every order, for the person answering the phone.
 *
 * Read-only, and that is the design rather than an omission. Support needs to *see* an
 * order to answer a question; changing one belongs to the seller who is packing it or to
 * finance who is refunding it, both of which are audited paths with their own rules. An
 * "edit order" button here would be a way around both.
 */
final class AdminOrderController
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $query = Order::query()
            ->with(['customer', 'sellerOrders.seller'])
            ->orderByDesc('placed_at');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['search'])) {
            $term = trim($validated['search']);

            /*
             * The order number or the customer's address, because those are the two things
             * a person on the phone actually has. Searching by id would require them to
             * read out a UUID.
             */
            $query->where(function ($inner) use ($term): void {
                $inner->where('order_number', 'ilike', '%'.$term.'%')
                    ->orWhere('customer_email', 'ilike', '%'.$term.'%');
            });
        }

        return $this->json([
            'data' => $query->limit(100)->get()->map(fn (Order $order): array => $order->toCustomerArray() + [
                'customer_email' => $order->customer_email,
                'seller_count' => $order->sellerOrders->count(),
            ])->all(),
        ]);
    }

    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $order = Order::query()
            ->where('order_number', $orderNumber)
            ->with(['customer', 'items', 'sellerOrders.seller', 'history.actor', 'payment'])
            ->firstOrFail();

        return $this->json([
            'data' => $order->toCustomerArray(withItems: true) + [
                'customer_email' => $order->customer_email,
                'payment' => $order->payment?->toCustomerArray(),
                // The history is why support can answer "when did this ship" without
                // guessing from timestamps on three different tables.
                'history' => $order->history->map(static fn ($entry): array => [
                    'from' => $entry->from_status,
                    'to' => $entry->to_status,
                    'actor' => $entry->actor?->email,
                    'actor_role' => $entry->actor_role,
                    'reason' => $entry->reason,
                    'at' => $entry->created_at?->toIso8601String(),
                ])->all(),
            ],
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
