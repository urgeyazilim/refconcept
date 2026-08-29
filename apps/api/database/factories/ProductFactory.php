<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Style;
use App\Domains\Products\Enums\ModerationStatus;
use App\Domains\Products\Enums\ProductStatus;
use App\Domains\Products\Enums\SkuStatus;
use App\Domains\Products\Models\Product;
use App\Domains\Sellers\Models\Seller;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
final class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst($this->faker->words(3, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'description' => $this->faker->paragraph(),
            // Categories are seeded reference data, not fabricated: a product filed
            // under an invented category could never be matched or filtered.
            'primary_category_id' => Category::query()
                ->whereNotNull('parent_id')
                ->inRandomOrder()
                ->value('id'),
        ];
    }

    /** Attaches the product to a seller's organization. */
    public function forSeller(Seller $seller): static
    {
        return $this->state(fn (): array => ['organization_id' => $seller->organization_id]);
    }

    /**
     * Fills in everything the completeness check requires.
     *
     * Used by tests that care about what happens *after* a complete listing —
     * repeating this setup inline would bury the behaviour under fixtures.
     */
    public function complete(?Seller $seller = null): static
    {
        return $this->afterCreating(function (Product $product) use ($seller): void {
            $seller ??= Seller::query()->where('organization_id', $product->organization_id)->first();

            /*
             * A style, because a listing is no longer complete without one.
             *
             * The customer chooses a style from a row of pictures now, and a catalogue full
             * of untagged products answers that choice with nothing — which reads to them as
             * an empty shop rather than as an untagged one. "Complete" has to mean findable.
             */
            $style = Style::query()->inRandomOrder()->first();

            if ($style !== null) {
                $product->styles()->syncWithoutDetaching([
                    $style->getKey() => ['strength_bps' => 10_000, 'is_primary' => true],
                ]);

                $product->forceFill(['style_id' => $style->getKey()])->save();
            }

            $product->media()->create([
                'type' => 'image',
                'disk' => 'public',
                'storage_path' => 'products/'.$product->getKey().'/cover.webp',
                'original_name' => 'cover.webp',
                'mime_type' => 'image/webp',
                'size_bytes' => 24_000,
                'width' => 1200,
                'height' => 900,
                'alt_text' => $product->name,
                'position' => 0,
            ]);

            if ($seller !== null) {
                $sku = $product->skus()->create([
                    'seller_id' => $seller->getKey(),
                    'sku' => 'SKU-'.Str::upper(Str::random(8)),
                    'currency' => 'TRY',
                    'list_price_minor' => 4_890_000,
                    'tax_rate_bps' => 2000,
                    'stock_policy' => 'track',
                    'stock_quantity' => 10,
                    'lead_time_days' => 3,
                ]);

                // status is not mass-assignable: an offer going on sale is a decision,
                // not a form field. The factory takes the same route the workflow does.
                $sku->forceFill(['status' => SkuStatus::Active])->save();

                $sku->dimensions()->create([
                    'width_mm' => 2200,
                    'height_mm' => 780,
                    'depth_mm' => 950,
                    'weight_g' => 62_000,
                    'package_count' => 2,
                    'assembly_required' => true,
                ]);
            }

            // Whatever the product's category marks required.
            foreach ($product->primaryCategory->attributes as $attribute) {
                /** @var object{is_required: bool} $pivot */
                $pivot = $attribute->getRelation('pivot');

                if ($pivot->is_required !== true) {
                    continue;
                }

                $payload = [
                    'attribute_id' => $attribute->getKey(),
                ];

                if ($attribute->isSelectable()) {
                    $payload['attribute_value_id'] = $attribute->values()->value('id');
                } else {
                    $payload['value_text'] = 'Test';
                }

                $product->attributeValues()->create($payload);
            }
        });
    }

    public function pendingReview(): static
    {
        return $this->afterCreating(function (Product $product): void {
            $product->forceFill(['moderation_status' => ModerationStatus::PendingReview])->save();
        });
    }

    public function published(): static
    {
        return $this->afterCreating(function (Product $product): void {
            $product->forceFill([
                'moderation_status' => ModerationStatus::Approved,
                'status' => ProductStatus::Active,
                'published_at' => now(),
            ])->save();
        });
    }
}
