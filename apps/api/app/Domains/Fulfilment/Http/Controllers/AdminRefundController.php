<?php

declare(strict_types=1);

namespace App\Domains\Fulfilment\Http\Controllers;

use App\Domains\Fulfilment\Models\Refund;
use App\Domains\Fulfilment\Services\RefundService;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Services\AccessControl;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\SellerOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Finance's side of a refund.
 *
 * Two jobs: issuing one that has no return behind it — goodwill, a mis-shipment — and
 * retrying the ones a provider refused. The second is why `failed` is not a terminal
 * state: an outage is the commonest cause and the customer is owed the money either way.
 *
 * Both need the settle permission, not the read one. Sending money back is still sending
 * money.
 */
final class AdminRefundController
{
    public function __construct(
        private readonly RefundService $refunds,
        private readonly AccessControl $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorise($request, Permission::PaymentsView);

        $status = $request->query('status');

        $query = Refund::query()
            ->with(['order', 'sellerOrder.seller', 'returnRequest'])
            ->orderByDesc('created_at');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        } else {
            // The default is what still needs somebody: pending, processing or failed.
            $query->open();
        }

        return $this->json([
            'data' => $query->limit(200)->get()->map(fn (Refund $refund): array => $refund->toArray() + [
                'order_number' => $refund->order?->order_number,
                'seller_name' => $refund->sellerOrder?->seller?->display_name,
                'return_reference' => $refund->returnRequest?->reference,
            ])->all(),
        ]);
    }

    /**
     * A refund with no return behind it.
     *
     * The reason is required: an unexplained payment out is indistinguishable from a
     * mistake when somebody reads it back six months later.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorise($request, Permission::PaymentsSettle);

        $validated = $request->validate([
            'order_number' => ['required', 'string', 'max:24'],
            'seller_order_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:300'],
        ]);

        $order = Order::query()->where('order_number', $validated['order_number'])->firstOrFail();

        $sellerOrder = isset($validated['seller_order_number'])
            ? SellerOrder::query()
                ->where('order_id', $order->getKey())
                ->where('seller_order_number', $validated['seller_order_number'])
                ->first()
            : null;

        $refund = $this->refunds->openManual(
            $order,
            (int) $validated['amount_minor'],
            $validated['reason'],
            $this->user($request),
            $sellerOrder,
        );

        return $this->json(['data' => $refund->toArray()], 201);
    }

    /** Tries a refused refund again. */
    public function retry(Request $request, Refund $refund): JsonResponse
    {
        $this->authorise($request, Permission::PaymentsSettle);

        $retried = $this->refunds->process($refund, $this->user($request));

        return $this->json(['data' => $retried->toArray()]);
    }

    /** What is still refundable on an order, so an operator is not guessing. */
    public function refundable(Request $request, string $orderNumber): JsonResponse
    {
        $this->authorise($request, Permission::PaymentsView);

        $order = Order::query()->where('order_number', $orderNumber)->firstOrFail();

        return $this->json([
            'data' => [
                'order_number' => $order->order_number,
                'grand_total_minor' => $order->grand_total_minor,
                'refundable_minor' => $this->refunds->refundableMinor($order),
                'currency' => $order->currency,
            ],
        ]);
    }

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
