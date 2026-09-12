<?php

declare(strict_types=1);

namespace App\Domains\Products\Http\Resources;

use App\Domains\Products\Models\Product;
use App\Domains\Products\Models\ProductSku;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public shape of a product.
 *
 * Prices are serialised as `{amount_minor, currency, formatted}` rather than as a
 * decimal string. A client that receives "48900.00" will parse it into a float sooner
 * or later; one that receives the integer cannot.
 *
 * @mixin Product
 */
final class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $lowest = $this->relationLoaded('skus') ? $this->lowestActivePrice() : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'product_type' => $this->product_type,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'moderation_status' => $this->moderation_status->value,
            'moderation_status_label' => $this->moderation_status->label(),
            'is_editable' => $this->moderation_status->isEditable(),
            'published_at' => $this->published_at?->toIso8601String(),

            'brand' => $this->whenLoaded('brand', fn (): ?array => $this->brand === null ? null : [
                'id' => $this->brand->id,
                'name' => $this->brand->name,
                'slug' => $this->brand->slug,
            ]),

            'category' => $this->whenLoaded('primaryCategory', fn (): ?array => $this->primaryCategory === null ? null : [
                'id' => $this->primaryCategory->id,
                'name' => $this->primaryCategory->name,
                'slug' => $this->primaryCategory->slug,
                'path' => $this->primaryCategory->path,
                'room_type' => $this->primaryCategory->room_type,
            ]),

            'style' => $this->whenLoaded('style', fn (): ?array => $this->style === null ? null : [
                'id' => $this->style->id,
                'code' => $this->style->code,
                'name' => $this->style->name,
            ]),

            /*
             * Photographs only.
             *
             * The relation also holds the 3D model, which is a media row on the same table but
             * not a gallery entry: a client that maps this list into `<img src>` would draw a
             * broken image for it, and a seller's gallery would offer to reorder it. The model
             * is its own key below.
             */
            'media' => $this->whenLoaded('media', fn (): array => $this->media
                ->where('type', 'image')
                ->map(fn ($item): array => [
                    'id' => $item->id,
                    'type' => $item->type,
                    // Which side of the product this photograph shows, when anybody knows.
                    // The mesh generator is given the four sides and stops inventing a back.
                    'view' => $item->view,
                    'url' => $item->url(),
                    'alt_text' => $item->alt_text,
                    'position' => $item->position,
                    'is_cover' => $item->isCover(),
                ])->values()->all()),

            /*
             * The 3D model, if there is one, and where it came from.
             *
             * A seller's own file is the shape of the thing; one generated from a photograph is
             * a likeness whose far side may never have been photographed, and the planner says
             * so to the customer. Only one is ever offered — the seller's, when both exist.
             */
            'model' => $this->whenLoaded('media', function (): ?array {
                $model = $this->media
                    ->where('type', 'model_3d')
                    ->sortBy(fn ($item): int => $item->source === 'seller' ? 0 : 1)
                    ->first();

                return $model === null ? null : [
                    'id' => $model->id,
                    'url' => $model->url(),
                    'source' => $model->source,
                ];
            }),

            'attributes' => $this->whenLoaded('attributeValues', fn (): array => $this->attributeValues
                ->map(fn ($value): array => [
                    'code' => $value->attribute?->code,
                    'name' => $value->attribute?->name,
                    'unit' => $value->attribute?->unit,

                    // `value` is what a form posts back, `display` is what a customer
                    // reads. For a select they differ ("cream" versus "Krem"), and a
                    // form populated from the label matches none of its own options.
                    'value' => $value->rawValue(),
                    'display' => $value->resolvedValue(),
                ])->all()),

            'skus' => $this->whenLoaded('skus', fn (): array => $this->skus
                ->map(fn (ProductSku $sku): array => [
                    'id' => $sku->id,
                    'sku' => $sku->sku,
                    'variant_label' => $sku->variant_label,
                    'status' => $sku->status->value,
                    'status_label' => $sku->status->label(),

                    'list_price' => $sku->list_price_minor->jsonSerialize(),
                    'sale_price' => $sku->sale_price_minor?->jsonSerialize(),
                    'effective_price' => $sku->effectivePrice()->jsonSerialize(),
                    'discount_bps' => $sku->discountBps(),
                    'tax_rate_bps' => $sku->tax_rate_bps,

                    'stock_policy' => $sku->stock_policy->value,
                    'stock_quantity' => $sku->stock_policy->tracksQuantity() ? $sku->stock_quantity : null,
                    'lead_time_days' => $sku->lead_time_days,
                    'is_available' => $sku->isAvailable(),

                    'dimensions' => $sku->relationLoaded('dimensions') && $sku->dimensions !== null ? [
                        'width_mm' => $sku->dimensions->width_mm,
                        'height_mm' => $sku->dimensions->height_mm,
                        'depth_mm' => $sku->dimensions->depth_mm,
                        'weight_g' => $sku->dimensions->weight_g,
                        'display' => $sku->dimensions->displaySize(),
                        'assembly_required' => $sku->dimensions->assembly_required,
                    ] : null,

                    'seller' => $sku->relationLoaded('seller') && $sku->seller !== null ? [
                        'id' => $sku->seller->id,
                        'display_name' => $sku->seller->display_name,
                        'seller_code' => $sku->seller->seller_code,
                    ] : null,
                ])->all()),

            // The "from" figure on a listing card, so the storefront does not have to
            // reduce the SKU list itself and risk a different answer per screen.
            'from_price' => $lowest?->effectivePrice()->jsonSerialize(),

            // The same figure ignoring availability, for the seller's own list and the
            // moderation queue — both of which look at listings that are not on sale yet.
            'lowest_price' => $this->relationLoaded('skus')
                ? $this->lowestPrice()?->effectivePrice()->jsonSerialize()
                : null,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
