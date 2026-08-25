<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Http\Controllers;

use App\Domains\Commerce\Models\Cart;
use App\Domains\Commerce\Models\CartItem;
use App\Domains\Commerce\Services\CartService;
use App\Domains\Identity\Models\User;
use App\Domains\Products\Models\ProductSku;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * A customer's basket.
 *
 * No cart id appears in any route: `/cart` is always *your* cart. That is the strongest
 * form the ownership rule can take — a forgotten check cannot expose somebody else's
 * basket when there is no way to name one — and the only id that does appear, a line's,
 * is verified against the caller's own cart before anything happens to it.
 *
 * Every response revalidates. A basket is a promise made at a moment and honoured later,
 * so the moment somebody looks at it is the right moment to check whether the world still
 * agrees — and telling them at payment would be the worst possible time.
 */
final class CartController
{
    public function __construct(private readonly CartService $carts) {}

    public function show(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $cart = $this->carts->forUser($user);

        $issues = $this->carts->revalidate($cart);

        return $this->cartResponse($cart->fresh(['items']) ?? $cart, $issues);
    }

    public function add(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'sku_id' => ['required', 'uuid'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'design_match_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $sku = ProductSku::query()->with(['product', 'seller'])->findOrFail($validated['sku_id']);

        $this->carts->add(
            $user,
            $sku,
            (int) ($validated['quantity'] ?? 1),
            $validated['design_match_id'] ?? null,
        );

        $cart = $this->carts->forUser($user);

        return $this->cartResponse($cart->fresh(['items']) ?? $cart, [], 201);
    }

    public function updateItem(Request $request, CartItem $item): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:0', 'max:99'],
        ]);

        $this->carts->setQuantity($user, $item, (int) $validated['quantity']);

        $cart = $this->carts->forUser($user);

        return $this->cartResponse($cart->fresh(['items']) ?? $cart, []);
    }

    public function removeItem(Request $request, CartItem $item): JsonResponse
    {
        $user = $this->user($request);

        $this->carts->remove($user, $item);

        $cart = $this->carts->forUser($user);

        return $this->cartResponse($cart->fresh(['items']) ?? $cart, []);
    }

    public function clear(Request $request): JsonResponse
    {
        $cart = $this->carts->clear($this->user($request));

        return $this->cartResponse($cart->fresh(['items']) ?? $cart, []);
    }

    /**
     * Accepts prices that have moved.
     *
     * An explicit act, so a higher figure is something the customer agreed to rather than
     * something that happened to them while they were not looking.
     */
    public function acceptPrices(Request $request): JsonResponse
    {
        $cart = $this->carts->acceptPriceChanges($this->user($request));

        return $this->cartResponse($cart->fresh(['items']) ?? $cart, []);
    }

    /**
     * Moves to checkout and takes the stock hold.
     *
     * Returns the issues rather than refusing outright when something moved: the customer
     * is shown what changed and asked again. Refusing silently, or proceeding anyway, are
     * the two ways to lose their trust.
     */
    public function beginCheckout(Request $request): JsonResponse
    {
        $result = $this->carts->beginCheckout($this->user($request));

        /** @var Cart $cart */
        $cart = $result['cart'];

        return $this->cartResponse($cart, $result['issues']);
    }

    public function abandonCheckout(Request $request): JsonResponse
    {
        $cart = $this->carts->abandonCheckout($this->user($request));

        return $this->cartResponse($cart->fresh(['items']) ?? $cart, []);
    }

    // --- payloads ------------------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $issues
     */
    private function cartResponse(Cart $cart, array $issues, int $status = 200): JsonResponse
    {
        $cart->loadMissing(['items.product.media', 'items.sku.dimensions', 'items.seller']);

        return response()->json([
            'data' => [
                'id' => $cart->id,
                'status' => $cart->status->value,
                'status_label' => $cart->status->label(),
                'is_editable' => $cart->status->isEditable(),
                'currency' => $cart->currency,
                'item_count' => $cart->itemCount(),
                'subtotal_minor' => $cart->subtotalMinor(),

                /*
                 * Tax is the part *inside* the subtotal, not an addition to it: Turkish
                 * prices are quoted inclusive of KDV. Showing it as an extra would inflate
                 * every total by twenty per cent.
                 */
                'tax_minor' => $cart->taxMinor(),

                // Grouped by seller because that is what a marketplace basket is: several
                // parcels from several shops, arriving on different days.
                'sellers' => $this->sellerGroups($cart),
            ],

            'issues' => array_map(static fn (array $issue): array => [
                'item_id' => $issue['item_id'],
                'product' => $issue['product'],
                'issue' => $issue['issue']->value,
                'label' => $issue['issue']->label(),
                'blocks_checkout' => $issue['issue']->blocksCheckout(),
                'from' => $issue['from'],
                'to' => $issue['to'],
            ], $issues),
        ], $status)->header('Cache-Control', 'no-store, private');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sellerGroups(Cart $cart): array
    {
        return $cart->bySeller()
            ->map(function ($items): array {
                /** @var Collection<int, CartItem> $items */
                $first = $items->first();

                return [
                    'seller_id' => $first?->seller_id,
                    'seller_name' => $first?->seller?->display_name,
                    'subtotal_minor' => (int) $items->sum(
                        static fn (CartItem $item): int => $item->lineTotalMinor(),
                    ),
                    'items' => $items->map(static fn (CartItem $item): array => [
                        'id' => $item->id,
                        'sku_id' => $item->sku_id,
                        'product' => [
                            'id' => $item->product_id,
                            'name' => $item->product?->name,
                            'slug' => $item->product?->slug,
                            'image_url' => ($item->product?->media?->firstWhere('is_cover', true)
                                ?? $item->product?->media?->first())?->url(),
                        ],
                        'variant' => $item->sku?->variant_label,
                        'quantity' => $item->quantity,
                        'unit_price_minor' => $item->unit_price_minor,
                        'list_price_minor' => $item->list_price_minor,
                        'line_total_minor' => $item->lineTotalMinor(),
                        // Set by revalidation, so a customer sees it exactly once rather
                        // than as a comparison recomputed on every render.
                        'price_changed' => $item->price_changed_at !== null,
                        'current_price_minor' => $item->sku?->effectivePrice()->amountMinor,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
