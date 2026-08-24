<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A property a product can be described by.
 *
 * `is_variant_defining` decides whether a different value means a different SKU.
 * Colour usually does; care instructions never do. Getting that wrong produces either
 * duplicate SKUs nobody can tell apart or variants nobody can buy.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $data_type
 * @property string|null $unit
 * @property bool $is_variant_defining
 * @property bool $is_filterable
 * @property bool $is_required
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Attribute extends Model
{
    use HasUuidV7;

    protected $table = 'attributes';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'data_type',
        'unit',
        'is_variant_defining',
        'is_filterable',
        'is_required',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_variant_defining' => 'boolean',
            'is_filterable' => 'boolean',
            'is_required' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** @return HasMany<AttributeValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class)->orderBy('position');
    }

    /** Whether this attribute takes a value from a fixed list. */
    public function isSelectable(): bool
    {
        return in_array($this->data_type, ['select', 'multiselect'], true);
    }

    /** @param  Builder<$this>  $query */
    public function scopeFilterable(Builder $query): void
    {
        $query->where('is_filterable', true);
    }
}
