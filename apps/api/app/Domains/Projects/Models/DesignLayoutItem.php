<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use App\Domains\Products\Models\Product;
use App\Domains\Products\Models\ProductSku;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A product, somewhere in a room.
 *
 * Millimetres from the room's origin corner and whole degrees about the vertical axis, all
 * integers, for the same reason money is in minor units here: a coordinate that drifts by a
 * float's last bit between save and load is a sofa that has moved slightly every time the
 * customer opens the page, and nobody would ever be able to say why.
 *
 * Only one axis of rotation. Furniture turns; it does not tumble, and offering three axes is
 * offering two ways to end up with a sideboard lying on its face.
 *
 * The variant is required rather than optional. A sofa is 2200 mm or 2600 mm depending on
 * which one was chosen, so a layout that knows only the product knows neither — and the
 * footprint this item occupies in the scene is read from the SKU.
 *
 * @property string $id
 * @property string $layout_id
 * @property string $product_id
 * @property string $sku_id
 * @property int $position_x_mm
 * @property int $position_y_mm
 * @property int $position_z_mm
 * @property int $rotation_y_deg
 * @property bool $locked
 * @property string $collision_state
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DesignLayoutItem extends Model
{
    use HasUuidV7;

    protected $table = 'design_layout_items';

    /** @var array<string, mixed> */
    protected $attributes = [
        'position_y_mm' => 0,
        'rotation_y_deg' => 0,
        'locked' => false,
        'collision_state' => 'ok',
    ];

    /** @var list<string> */
    protected $fillable = [
        'layout_id',
        'product_id',
        'sku_id',
        'position_x_mm',
        'position_y_mm',
        'position_z_mm',
        'rotation_y_deg',
        'locked',
        'collision_state',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position_x_mm' => 'integer',
            'position_y_mm' => 'integer',
            'position_z_mm' => 'integer',
            'rotation_y_deg' => 'integer',
            'locked' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<DesignLayout, $this> */
    public function layout(): BelongsTo
    {
        return $this->belongsTo(DesignLayout::class, 'layout_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductSku, $this> */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(ProductSku::class, 'sku_id');
    }

    /**
     * The space this occupies, in millimetres, with rotation applied.
     *
     * Only right angles change the footprint — at 45° a rotated rectangle needs more room
     * than either dimension, and pretending otherwise would let two pieces pass through each
     * other. Snapping keeps furniture on right angles in practice; the diagonal case is
     * handled by taking the bounding square, which is conservative rather than exact and
     * errs towards refusing a position rather than allowing an overlap.
     *
     * @return array{width: int, depth: int}
     */
    public function footprintMm(): array
    {
        // Dimensions hang off the SKU in their own table rather than on it: not every
        // variant has been measured, and a nullable column per axis on a hot table is three
        // nulls somebody eventually treats as zero.
        $dimensions = $this->sku?->dimensions;

        $width = (int) ($dimensions?->width_mm ?? 0);
        $depth = (int) ($dimensions?->depth_mm ?? 0);

        $angle = ((int) $this->rotation_y_deg % 360 + 360) % 360;

        if ($angle === 90 || $angle === 270) {
            return ['width' => $depth, 'depth' => $width];
        }

        if ($angle === 0 || $angle === 180) {
            return ['width' => $width, 'depth' => $depth];
        }

        // Anything off a right angle: the square that certainly contains it.
        $side = max($width, $depth);

        return ['width' => $side, 'depth' => $side];
    }

    /** Whether the layout engine may move this. */
    public function isMovable(): bool
    {
        return ! $this->locked;
    }
}
