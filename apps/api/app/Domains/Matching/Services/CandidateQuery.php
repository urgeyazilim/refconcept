<?php

declare(strict_types=1);

namespace App\Domains\Matching\Services;

use App\Domains\Catalog\Models\Category;
use App\Domains\Matching\Enums\EmbeddingSource;
use App\Domains\Matching\Models\ProductEmbedding;
use App\Support\Text\TurkishText;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The narrow, then the near.
 *
 * Everything a customer cannot negotiate is applied in SQL first — the right category, in
 * stock, within budget, narrow enough to fit against the wall — and only what survives is
 * ranked by similarity. That order is the whole design and it is not an optimisation.
 *
 * A language model asked to respect "no wider than 2200mm" will sometimes respect it. A
 * `WHERE width_mm <= 2200` always does. Every constraint that can be expressed as a fact
 * belongs on this side of the line, and what is left for the vector is the part that is
 * genuinely a matter of resemblance: does this look like the thing in the picture.
 *
 * The query returns raw rows rather than models. A similarity search over a catalogue is
 * a reporting query — it reads from four tables and returns a distance — and hydrating
 * Eloquent models to compute a number would be work with nothing to show for it.
 */
final class CandidateQuery
{
    /** How many rows the vector search returns before scoring narrows them further. */
    public const DEFAULT_LIMIT = 40;

    public function __construct(private readonly TurkishText $text) {}

    /**
     * Finds purchasable products near a vector, subject to hard filters.
     *
     * @param  array<int, float>  $vector
     * @param  array<string, mixed>  $filters
     * @return Collection<int, stdClass>
     */
    public function nearest(array $vector, array $filters = [], int $limit = self::DEFAULT_LIMIT): Collection
    {
        $literal = ProductEmbedding::toVectorLiteral($vector);

        $query = DB::table('product_embeddings as pe')
            ->join('products as p', 'p.id', '=', 'pe.product_id')
            ->join('product_skus as s', 's.product_id', '=', 'p.id')
            ->join('sellers as sel', 'sel.id', '=', 's.seller_id')
            ->leftJoin('product_dimensions as d', 'd.sku_id', '=', 's.id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.primary_category_id')
            /*
             * The style this product is, mainly.
             *
             * Joined rather than filtered on. With a thin catalogue a strict style filter
             * empties the room — which a customer reads as a broken page rather than as a
             * shop that has not stocked their taste yet. The code comes back on the row and
             * the ranking above decides what it is worth.
             */
            ->leftJoin('product_styles as pst', function ($join): void {
                $join->on('pst.product_id', '=', 'p.id')->where('pst.is_primary', true);
            })
            ->leftJoin('styles as st', 'st.id', '=', 'pst.style_id')
            ->where('pe.source', EmbeddingSource::Text->value)

            /*
             * Everything `Product::publiclyVisible()` asks of the database, restated here.
             * Not a duplication worth removing: this query joins the SKU it is going to
             * recommend, and "the product has *some* purchasable offer" is a weaker
             * statement than "this offer is purchasable". Recommending an out-of-stock
             * variant of an in-stock product is exactly the mistake the weaker form makes.
             */
            ->where('p.moderation_status', 'approved')
            ->where('p.status', 'active')
            ->whereNull('p.deleted_at')
            ->where('s.status', 'active')
            ->whereNull('s.deleted_at')
            ->where('sel.status', 'active')
            ->where(function ($stock): void {
                $stock->where('s.stock_policy', '!=', 'track')
                    ->orWhere('s.stock_quantity', '>', 0);
            });

        $this->applyCategory($query, $filters);
        $this->applyBudget($query, $filters);
        $this->applySize($query, $filters);
        $this->applyExclusions($query, $filters);

        /*
         * `<=>` is cosine distance: 0 identical, 2 opposite.
         *
         * `DISTINCT ON (p.id)` keeps one row per product rather than one per offer: a
         * product sold by six sellers would otherwise fill the whole shortlist with the
         * same sofa. PostgreSQL requires the distinct key to lead the ORDER BY, so the
         * inner query is sorted by product and the outer one puts it back in distance
         * order — which is why this is a subquery rather than one statement.
         */
        $query->selectRaw('
            DISTINCT ON (p.id)
            p.id as product_id,
            p.name as product_name,
            p.slug as product_slug,
            s.id as sku_id,
            s.variant_label,
            s.currency,
            COALESCE(s.sale_price_minor, s.list_price_minor) as price_minor,
            d.width_mm,
            d.height_mm,
            d.depth_mm,
            c.name as category_name,
            c.room_type,
            st.code as style_code,
            (pe.embedding <=> ?::vector) as distance
        ', [$literal])
            // Within one product, the nearest offer wins and price breaks the tie — so the
            // row that survives is the cheapest purchasable one.
            ->orderByRaw('p.id, (pe.embedding <=> ?::vector) asc, COALESCE(s.sale_price_minor, s.list_price_minor) asc', [$literal]);

        return collect(
            DB::query()
                ->fromSub($query, 'ranked')
                ->orderBy('distance')
                ->orderBy('price_minor')
                ->limit($limit)
                ->get()
        );
    }

    /**
     * Cosine distance as a similarity, in basis points.
     *
     * Distance runs 0 (identical) to 2 (opposite); a customer-facing "how close is this"
     * runs the other way and stops at zero. Clamped rather than allowed negative, because
     * a similarity below nothing is not a thing anybody can read.
     */
    public function similarityBps(float $distance): int
    {
        return max(0, min(10_000, (int) round((1.0 - $distance) * 10_000)));
    }

    // --- filters ---------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyCategory(Builder $query, array $filters): void
    {
        $category = $filters['category'] ?? null;

        if (! is_string($category) || $category === '') {
            return;
        }

        /*
         * The planner writes a category in Turkish — "kanepe", "yemek masası" — and the
         * catalogue holds a tree with slugs and materialised paths. Matched on the folded
         * name so a plan saying "Sehpa" finds the "sehpa" branch, and on the path so a
         * request for "kanepe" also finds "üçlü kanepe" beneath it.
         */
        $folded = $this->text->fold($category);

        $matched = Category::query()
            ->where('is_active', true)
            ->get(['id', 'name', 'slug', 'path'])
            ->filter(fn (Category $row): bool => $this->text->fold($row->name) === $folded
                || $this->text->fold($row->slug) === $folded);

        if ($matched->isEmpty()) {
            /*
             * No such category. Deliberately *not* silently ignored: without this the
             * filter would fall away and the search would return the nearest products in
             * the whole catalogue, which is how a plan asking for a rug ends up
             * recommending a wardrobe.
             */
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($outer) use ($matched): void {
            foreach ($matched as $category) {
                // The branch, not only the node: a plan asking for a sofa should reach
                // every kind of sofa beneath that category.
                $outer->orWhere('c.path', 'like', $category->path.'%');
            }
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyBudget(Builder $query, array $filters): void
    {
        $ceiling = $filters['max_price_minor'] ?? null;

        if (is_int($ceiling) && $ceiling > 0) {
            $query->whereRaw('COALESCE(s.sale_price_minor, s.list_price_minor) <= ?', [$ceiling]);
        }

        $floor = $filters['min_price_minor'] ?? null;

        if (is_int($floor) && $floor > 0) {
            $query->whereRaw('COALESCE(s.sale_price_minor, s.list_price_minor) >= ?', [$floor]);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applySize(Builder $query, array $filters): void
    {
        $maxWidth = $filters['max_width_mm'] ?? null;

        if (! is_int($maxWidth) || $maxWidth <= 0) {
            return;
        }

        /*
         * A product with no measurements is allowed through, and that is a judgement
         * rather than an oversight. Most of the catalogue will lack dimensions for a long
         * time, and excluding everything unmeasured would empty the results for the exact
         * customer who took the trouble to measure their room. It is flagged in the match
         * reason instead, so somebody can see which suggestions were not checked.
         */
        $query->where(function ($width) use ($maxWidth): void {
            $width->whereNull('d.width_mm')->orWhere('d.width_mm', '<=', $maxWidth);
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyExclusions(Builder $query, array $filters): void
    {
        /** @var array<int, string> $excluded */
        $excluded = (array) ($filters['exclude_product_ids'] ?? []);

        if ($excluded !== []) {
            // What the customer has already rejected, and what is already on the list for
            // another placement — nobody wants the same sofa suggested twice.
            $query->whereNotIn('p.id', $excluded);
        }

        $roomType = $filters['room_type'] ?? null;

        if (is_string($roomType) && $roomType !== '') {
            /*
             * A category may be tied to a room type, and most are not. `null` means "fits
             * anywhere" rather than "fits nowhere" — a floor lamp has no room type and
             * belongs in every room.
             */
            $query->where(function ($room) use ($roomType): void {
                $room->whereNull('c.room_type')->orWhere('c.room_type', $roomType);
            });
        }
    }
}
