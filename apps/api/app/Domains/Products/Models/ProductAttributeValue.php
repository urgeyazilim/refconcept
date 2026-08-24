<?php

declare(strict_types=1);

namespace App\Domains\Products\Models;

use App\Domains\Catalog\Models\Attribute;
use App\Domains\Catalog\Models\AttributeValue;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attribute value on a product.
 *
 * Typed columns rather than a single text field: the matching engine filters on
 * numbers ("under 200 cm wide") and the storefront facets on selections, and neither
 * works if every value is a string that has to be parsed at query time.
 *
 * @property string $id
 * @property string $product_id
 * @property string $attribute_id
 * @property string|null $attribute_value_id
 * @property string|null $value_text
 * @property int|null $value_integer
 * @property string|null $value_decimal
 * @property bool|null $value_boolean
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ProductAttributeValue extends Model
{
    use HasUuidV7;

    protected $table = 'product_attributes';

    /** @var list<string> */
    protected $fillable = [
        'product_id',
        'attribute_id',
        'attribute_value_id',
        'value_text',
        'value_integer',
        'value_decimal',
        'value_boolean',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_integer' => 'integer',
            'value_boolean' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Attribute, $this> */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    /** @return BelongsTo<AttributeValue, $this> */
    public function attributeValue(): BelongsTo
    {
        return $this->belongsTo(AttributeValue::class, 'attribute_value_id');
    }

    /** The value in whichever column actually holds it. */
    public function resolvedValue(): string|int|float|bool|null
    {
        $selected = $this->attributeValue;

        return ($selected instanceof AttributeValue ? $selected->label : null)
            ?? $this->value_text
            ?? $this->value_integer
            ?? ($this->value_decimal === null ? null : (float) $this->value_decimal)
            ?? $this->value_boolean;
    }

    /**
     * The value as it goes back over the wire, rather than as it is displayed.
     *
     * For a selectable attribute these differ: the label is "Krem", the value is
     * "cream". A form that is populated from the label cannot match its own options,
     * so the seller's editor silently shows every attribute as unset and wipes them on
     * the next save. Both are serialised, and each screen takes the one it needs.
     */
    public function rawValue(): string|int|float|bool|null
    {
        $selected = $this->attributeValue;

        return ($selected instanceof AttributeValue ? $selected->value : null)
            ?? $this->value_text
            ?? $this->value_integer
            ?? ($this->value_decimal === null ? null : (float) $this->value_decimal)
            ?? $this->value_boolean;
    }
}
