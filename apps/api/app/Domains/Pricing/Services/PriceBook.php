<?php

declare(strict_types=1);

namespace App\Domains\Pricing\Services;

use App\Domains\Identity\Models\User;
use App\Domains\Pricing\Models\PriceHistory;
use App\Domains\Pricing\Models\PriceList;
use App\Domains\Pricing\Models\PriceListItem;
use App\Domains\Products\Models\ProductSku;
use App\Support\ValueObjects\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only writer of prices, and the single answer to "what does this cost".
 *
 * Two jobs that belong together. Writing a price and recording that it changed cannot
 * be separate operations performed by separate callers, or the history will be missing
 * exactly the changes somebody later wants to explain. So every path that sets a price
 * goes through {@see setPrice()}, and the history row is written in the same
 * transaction as the price itself.
 *
 * Resolution order, when several lists could apply:
 *
 *   1. an effective campaign list (a window that includes now), most recently started;
 *   2. the seller's default list;
 *   3. the price on the SKU itself.
 *
 * The third is not a fallback for missing data — it is where a seller who has never
 * heard of price lists keeps their prices, and it must keep working.
 */
final class PriceBook
{
    /**
     * What this SKU costs right now, and where the figure came from.
     *
     * The source is returned rather than only the amount, because a storefront that
     * shows a campaign price should be able to say it is a campaign price, and a
     * support agent looking at a dispute needs to know which list was in force.
     *
     * @return array{list_price: Money, sale_price: Money|null, effective: Money, source: string, price_list_id: string|null}
     */
    public function resolve(ProductSku $sku, ?Carbon $at = null): array
    {
        $at ??= now();

        $item = $this->applicableItem($sku, $at);

        if ($item !== null) {
            return [
                'list_price' => $item->list_price_minor,
                'sale_price' => $item->sale_price_minor,
                'effective' => $item->effectivePrice(),
                'source' => $item->priceList?->is_default === true ? 'default_list' : 'campaign',
                'price_list_id' => $item->price_list_id,
            ];
        }

        return [
            'list_price' => $sku->list_price_minor,
            'sale_price' => $sku->sale_price_minor,
            'effective' => $sku->effectivePrice(),
            'source' => 'sku',
            'price_list_id' => null,
        ];
    }

    /**
     * Changes a SKU's own price and records why.
     *
     * A no-op when nothing actually changed: writing an unchanged value would fill the
     * history with noise and make a real change harder to find, which defeats the
     * purpose of keeping one.
     */
    public function setPrice(
        ProductSku $sku,
        Money $listPrice,
        ?Money $salePrice = null,
        ?User $actor = null,
        string $source = 'manual',
    ): ProductSku {
        if ($salePrice !== null && $salePrice->greaterThan($listPrice)) {
            throw new InvalidArgumentException('İndirimli fiyat liste fiyatından yüksek olamaz.');
        }

        return DB::transaction(function () use ($sku, $listPrice, $salePrice, $actor, $source): ProductSku {
            $before = [
                'list_price' => $sku->list_price_minor,
                'sale_price' => $sku->sale_price_minor,
            ];

            $sku->list_price_minor = $listPrice;
            $sku->sale_price_minor = $salePrice;
            $sku->save();

            $this->recordChange($sku, 'list_price', $before['list_price'], $listPrice, null, $actor, $source);
            $this->recordChange($sku, 'sale_price', $before['sale_price'], $salePrice, null, $actor, $source);

            return $sku;
        });
    }

    /**
     * Records the price a SKU was created with.
     *
     * {@see setPrice()} deliberately writes nothing when a value has not moved, which
     * is right for edits and wrong for creation: a SKU that arrives already priced
     * would have no history at all, and the origin of its very first price — a
     * spreadsheet, an API call, somebody typing — would be the one thing nobody could
     * look up. The old value is null rather than zero, because it did not used to be
     * free; it did not used to exist.
     */
    public function recordInitialPrice(ProductSku $sku, ?User $actor = null, string $source = 'manual'): void
    {
        $this->recordChange($sku, 'list_price', null, $sku->list_price_minor, null, $actor, $source);

        if ($sku->sale_price_minor !== null) {
            $this->recordChange($sku, 'sale_price', null, $sku->sale_price_minor, null, $actor, $source);
        }
    }

    /**
     * Puts a price into a named list, creating or updating the entry.
     *
     * The SKU's own price is untouched: that is the whole point of a list. Ending the
     * campaign restores yesterday's prices because nothing overwrote them.
     */
    public function setListPrice(
        PriceList $list,
        ProductSku $sku,
        Money $listPrice,
        ?Money $salePrice = null,
        ?User $actor = null,
        string $source = 'manual',
    ): PriceListItem {
        if ($salePrice !== null && $salePrice->greaterThan($listPrice)) {
            throw new InvalidArgumentException('İndirimli fiyat liste fiyatından yüksek olamaz.');
        }

        if ($sku->seller_id !== $list->seller_id) {
            // A seller pricing another seller's offer would be a tenancy hole, not a
            // validation slip, so it is refused here as well as in the policy.
            throw new InvalidArgumentException('Bu fiyat listesi bu satış seçeneğine uygulanamaz.');
        }

        return DB::transaction(function () use ($list, $sku, $listPrice, $salePrice, $actor, $source): PriceListItem {
            $existing = PriceListItem::query()
                ->where('price_list_id', $list->getKey())
                ->where('sku_id', $sku->getKey())
                ->first();

            $oldList = $existing?->list_price_minor;
            $oldSale = $existing?->sale_price_minor;

            $item = PriceListItem::query()->updateOrCreate(
                ['price_list_id' => $list->getKey(), 'sku_id' => $sku->getKey()],
                [
                    'list_price_minor' => $listPrice,
                    'sale_price_minor' => $salePrice,
                    'currency' => $listPrice->currency,
                ],
            );

            $this->recordChange($sku, 'list_price', $oldList, $listPrice, $list, $actor, $source);
            $this->recordChange($sku, 'sale_price', $oldSale, $salePrice, $list, $actor, $source);

            return $item;
        });
    }

    /**
     * The seller's default list, created on first use.
     *
     * A seller who never thinks about price lists should not have to create one to be
     * able to run a campaign later.
     */
    public function defaultListFor(string $sellerId, string $currency = 'TRY'): PriceList
    {
        $existing = PriceList::query()->forSeller($sellerId)->where('is_default', true)->first();

        if ($existing !== null) {
            return $existing;
        }

        return PriceList::query()->create([
            'seller_id' => $sellerId,
            'code' => 'DEFAULT',
            'name' => 'Varsayılan fiyat listesi',
            'currency' => $currency,
            'is_default' => true,
        ]);
    }

    /**
     * The list entry that applies, if any.
     *
     * Campaign lists win over the default one, and the most recently started campaign
     * wins over an older overlapping one. Two campaigns covering the same SKU at the
     * same time is a seller's mistake rather than an impossible state, so the rule has
     * to be deterministic rather than merely unlikely to matter.
     */
    private function applicableItem(ProductSku $sku, Carbon $at): ?PriceListItem
    {
        return PriceListItem::query()
            ->with('priceList')
            ->where('sku_id', $sku->getKey())
            ->whereHas('priceList', function ($query) use ($at, $sku): void {
                /** @var Builder<PriceList> $query */
                $query->forSeller($sku->seller_id)->effective($at);
            })
            ->join('price_lists', 'price_lists.id', '=', 'price_list_items.price_list_id')
            ->orderBy('price_lists.is_default')
            ->orderByDesc('price_lists.starts_at')
            ->select('price_list_items.*')
            ->first();
    }

    /** Writes one history row, and only when the value actually moved. */
    private function recordChange(
        ProductSku $sku,
        string $field,
        ?Money $old,
        ?Money $new,
        ?PriceList $list,
        ?User $actor,
        string $source,
    ): void {
        $oldMinor = $old?->amountMinor;
        $newMinor = $new?->amountMinor;

        if ($oldMinor === $newMinor) {
            return;
        }

        PriceHistory::query()->create([
            'sku_id' => $sku->getKey(),
            'price_list_id' => $list?->getKey(),
            'field' => $field,
            'old_value_minor' => $oldMinor,
            'new_value_minor' => $newMinor,
            // One of the two is non-null: an unchanged pair returned above.
            'currency' => ($new ?? $old)->currency,
            'source' => $source,
            'changed_by' => $actor?->getKey(),
            'changed_at' => now(),
        ]);
    }
}
