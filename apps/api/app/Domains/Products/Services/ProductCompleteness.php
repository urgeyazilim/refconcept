<?php

declare(strict_types=1);

namespace App\Domains\Products\Services;

use App\Domains\Products\Models\Product;

/**
 * Decides whether a listing is complete enough to be reviewed.
 *
 * Derived from the data, like the seller onboarding checklist and for the same
 * reason: a stored "ready" flag can be set by a partial save, and then a reviewer
 * opens a listing with no price and no photograph.
 *
 * The requirements are the ones a customer needs in order to buy with confidence and
 * the AI needs in order to place the item in a room — not an arbitrary form length.
 */
final class ProductCompleteness
{
    /**
     * @return array<int, string> human-readable descriptions of what is missing
     */
    public function missingRequirements(Product $product): array
    {
        $product->loadMissing([
            'skus.dimensions',
            'media',
            'attributeValues.attribute',
            'primaryCategory.attributes',
        ]);

        $missing = [];

        if (trim((string) $product->description) === '') {
            $missing[] = 'Ürün açıklaması';
        }

        if ($product->media->isEmpty()) {
            // A catalogue entry with no photograph cannot be sold and cannot be matched
            // to a design; it is the one requirement with no workaround.
            $missing[] = 'En az bir ürün görseli';
        }

        if ($product->skus->isEmpty()) {
            $missing[] = 'En az bir satış seçeneği (SKU)';
        }

        foreach ($product->skus as $sku) {
            if ($sku->list_price_minor->isZero()) {
                $missing[] = "Fiyat girilmemiş: {$sku->sku}";
            }

            $dimensions = $sku->dimensions;

            // Width and depth are what decide whether the piece fits the wall the AI
            // wants to put it against. Height is optional; a rug has none worth stating.
            if ($dimensions === null || $dimensions->width_mm === null || $dimensions->depth_mm === null) {
                $missing[] = "Ölçü girilmemiş: {$sku->sku}";
            }
        }

        $missing = [...$missing, ...$this->missingRequiredAttributes($product)];

        return array_values(array_unique($missing));
    }

    public function isComplete(Product $product): bool
    {
        return $this->missingRequirements($product) === [];
    }

    public function completionPercent(Product $product): int
    {
        // Weighted by the number of checks rather than by section, so adding one more
        // optional field does not make every listing look less finished.
        $total = 4 + $product->primaryCategory->attributes->where('pivot.is_required', true)->count();
        $missing = count($this->missingRequirements($product));

        return (int) round((max(0, $total - $missing) / max(1, $total)) * 100);
    }

    /**
     * Attributes the product's category marks as required.
     *
     * @return array<int, string>
     */
    private function missingRequiredAttributes(Product $product): array
    {
        $category = $product->primaryCategory;

        if ($category === null) {
            return ['Kategori seçilmemiş'];
        }

        $provided = $product->attributeValues->pluck('attribute_id')->all();
        $missing = [];

        foreach ($category->attributes as $attribute) {
            // The pivot carries whether this category demands the attribute; it exists
            // only on rows loaded through the relation, which is how they arrive here.
            /** @var object{is_required: bool} $pivot */
            $pivot = $attribute->getRelation('pivot');

            if ($pivot->is_required !== true) {
                continue;
            }

            if (! in_array($attribute->getKey(), $provided, true)) {
                $missing[] = "Zorunlu özellik: {$attribute->name}";
            }
        }

        return $missing;
    }
}
