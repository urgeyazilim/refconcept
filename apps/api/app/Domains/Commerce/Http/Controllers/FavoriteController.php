<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Http\Controllers;

use App\Domains\Commerce\Models\Favorite;
use App\Domains\Identity\Models\User;
use App\Domains\Products\Http\Resources\ProductResource;
use App\Domains\Products\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Things a customer wanted to remember.
 *
 * Per product, not per offer: favouriting a sofa means the sofa, and a favourite that
 * broke when one seller went out of stock would be a promise the feature never made.
 *
 * Like the cart, no favourite id appears in a route — a product id and the signed-in user
 * identify the row completely, so there is nothing to get wrong.
 */
final class FavoriteController
{
    /**
     * The list.
     *
     * Products that have since been withdrawn are dropped from the response rather than
     * shown greyed out. A favourites page is a shortlist somebody is shopping from, and
     * filling it with things nobody can buy makes it a worse shortlist.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);

        $products = Product::query()
            ->publiclyVisible()
            ->with(['brand', 'primaryCategory', 'media', 'skus.seller'])
            ->whereIn('id', Favorite::query()->where('user_id', $user->getKey())->select('product_id'))
            ->orderByDesc('published_at')
            ->paginate(24);

        return ProductResource::collection($products);
    }

    /**
     * Adds one.
     *
     * Idempotent: favouriting twice is the same as favouriting once, so a double tap is a
     * no-op rather than an error the customer has to understand.
     */
    public function store(Request $request, Product $product): JsonResponse
    {
        $user = $this->user($request);

        abort_unless($product->isPubliclyVisible(), 404);

        Favorite::query()->firstOrCreate(
            ['user_id' => $user->getKey(), 'product_id' => $product->getKey()],
            ['created_at' => now()],
        );

        return response()->json(['data' => ['product_id' => $product->id, 'is_favorite' => true]], 201);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $user = $this->user($request);

        Favorite::query()
            ->where('user_id', $user->getKey())
            ->where('product_id', $product->getKey())
            ->delete();

        return response()->json(['data' => ['product_id' => $product->id, 'is_favorite' => false]]);
    }

    /**
     * Which of a set are favourited.
     *
     * One request for a whole results page. The alternative — a flag on every product in
     * every catalogue response — would mean a join on a listing that anonymous visitors
     * also read, for a field only signed-in ones can use.
     */
    public function check(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'product_ids' => ['required', 'array', 'max:100'],
            'product_ids.*' => ['uuid'],
        ]);

        $favourited = Favorite::query()
            ->where('user_id', $user->getKey())
            ->whereIn('product_id', $validated['product_ids'])
            ->pluck('product_id');

        return response()->json(['data' => $favourited->all()]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
