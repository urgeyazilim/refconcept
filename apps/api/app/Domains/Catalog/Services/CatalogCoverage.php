<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Services;

use App\Domains\Products\Enums\ModerationStatus;
use App\Domains\Products\Enums\ProductStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What the shop can actually supply, by category and style.
 *
 * The question every guided design has to answer before it asks anything: is there any
 * point offering a television unit? The catalogue holds twelve products and stocks no
 * television units at all, so a wizard that offers one is a wizard that promises furniture
 * nobody can buy — which is precisely the failure that put a TV unit, curtains, a picture
 * and a plant into a customer's rendered room beside a shopping list of four items.
 *
 * Counted rather than guessed, and counted the same way the shopper's own search counts:
 * active, approved, in a category, with a price. A product that a customer could not buy
 * is a product this must not promise.
 *
 * Also the honest operator's report. "Salon programında 8 sorudan 3'ü karşılanamıyor" is
 * the sentence that tells somebody which sellers to go and find.
 */
final class CatalogCoverage
{
    private const CACHE_TTL_SECONDS = 600;

    public function __construct(private readonly StyleAffinity $affinity) {}

    /**
     * How many buyable products sit in a category.
     *
     * Ignores style entirely. This is the first gate — "do we sell these at all" — and it
     * is the one that decides whether an option is offered or hidden.
     */
    public function inCategory(string $categorySlug): int
    {
        return $this->counts()[$categorySlug]['total'] ?? 0;
    }

    /**
     * How many sit in a category *and* suit the chosen style.
     *
     * Neighbouring styles count, because they are what the customer will be shown: with a
     * thin catalogue a strict count would report zero for a question that will in fact
     * return three perfectly reasonable answers.
     */
    public function inCategoryForStyle(string $categorySlug, ?string $styleCode): int
    {
        if ($styleCode === null) {
            return $this->inCategory($categorySlug);
        }

        $byStyle = $this->counts()[$categorySlug]['by_style'] ?? [];

        $total = 0;

        foreach ($byStyle as $code => $count) {
            if ($this->affinity->between($styleCode, (string) $code) > 0) {
                $total += $count;
            }
        }

        return $total;
    }

    /**
     * What a customer should be told about an option, if anything.
     *
     * Three states rather than a boolean, because "we do not sell these" and "we sell these
     * but not in the style you chose" are different sentences and only the second is worth
     * waiting for.
     *
     * @return array{available: bool, exact: bool, count: int}
     */
    public function verdict(string $categorySlug, ?string $styleCode): array
    {
        $total = $this->inCategory($categorySlug);

        if ($total === 0) {
            return ['available' => false, 'exact' => false, 'count' => 0];
        }

        $forStyle = $this->inCategoryForStyle($categorySlug, $styleCode);

        return ['available' => true, 'exact' => $forStyle > 0, 'count' => $forStyle];
    }

    /**
     * Every category with a buyable product, and how those split by style.
     *
     * One query for the whole catalogue rather than one per option: a room programme asks
     * about a dozen categories and every one of them would otherwise be a round trip on a
     * page load.
     *
     * @return array<string, array{total: int, by_style: array<string, int>}>
     */
    public function counts(): array
    {
        /** @var array<string, array{total: int, by_style: array<string, int>}> $counts */
        $counts = Cache::remember('catalog:coverage', self::CACHE_TTL_SECONDS, function (): array {
            $rows = DB::table('products as p')
                ->join('categories as c', 'c.id', '=', 'p.primary_category_id')
                ->join('product_skus as sk', 'sk.product_id', '=', 'p.id')
                ->leftJoin('product_styles as ps', 'ps.product_id', '=', 'p.id')
                ->leftJoin('styles as s', 's.id', '=', 'ps.style_id')
                ->where('p.status', ProductStatus::Active->value)
                ->where('p.moderation_status', ModerationStatus::Approved->value)
                ->whereNull('p.deleted_at')
                ->where('sk.status', 'active')
                ->groupBy('c.slug', 's.code')
                // Distinct on the product: a listing with three offers is one thing to buy,
                // and counting its SKUs would report a catalogue three times its real depth.
                ->selectRaw('c.slug, s.code as style, count(distinct p.id) as total')
                ->get();

            $built = [];

            foreach ($rows as $row) {
                $slug = (string) $row->slug;
                $built[$slug]['total'] = ($built[$slug]['total'] ?? 0) + (int) $row->total;

                // Untagged products count towards the category but towards no style, so
                // they are offered when the question is "do we sell sofas" and rank last
                // when it is "do we sell modern ones".
                if ($row->style !== null) {
                    $built[$slug]['by_style'][(string) $row->style] = (int) $row->total;
                }
            }

            foreach ($built as $slug => $entry) {
                $built[$slug] = [
                    'total' => $entry['total'],
                    'by_style' => $entry['by_style'] ?? [],
                ];
            }

            return $built;
        });

        return $counts;
    }

    /** Called when a listing is published, unpublished or restyled. */
    public function forget(): void
    {
        Cache::forget('catalog:coverage');
    }
}
