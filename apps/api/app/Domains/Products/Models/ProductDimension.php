<?php

declare(strict_types=1);

namespace App\Domains\Products\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Physical properties of one SKU.
 *
 * Millimetres and grams as integers — the units the AI reasons in when it decides
 * whether a sofa fits a wall, and no rounding drift when converting for display.
 *
 * @property string $id
 * @property string $sku_id
 * @property int|null $width_mm
 * @property int|null $height_mm
 * @property int|null $depth_mm
 * @property int|null $weight_g
 * @property int $package_count
 * @property bool $assembly_required
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ProductDimension extends Model
{
    use HasUuidV7;

    protected $table = 'product_dimensions';

    /** @var list<string> */
    protected $fillable = [
        'sku_id',
        'width_mm',
        'height_mm',
        'depth_mm',
        'weight_g',
        'package_count',
        'assembly_required',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'width_mm' => 'integer',
            'height_mm' => 'integer',
            'depth_mm' => 'integer',
            'weight_g' => 'integer',
            'package_count' => 'integer',
            'assembly_required' => 'boolean',
        ];
    }

    /** @return BelongsTo<ProductSku, $this> */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(ProductSku::class, 'sku_id');
    }

    /** Centimetres for display; never used in placement arithmetic. */
    public function displaySize(): ?string
    {
        if ($this->width_mm === null || $this->depth_mm === null) {
            return null;
        }

        $parts = [$this->width_mm, $this->depth_mm];

        if ($this->height_mm !== null) {
            $parts[] = $this->height_mm;
        }

        return implode('×', array_map(
            static fn (int $mm): string => rtrim(rtrim(number_format($mm / 10, 1, ',', ''), '0'), ','),
            $parts,
        )).' cm';
    }
}
