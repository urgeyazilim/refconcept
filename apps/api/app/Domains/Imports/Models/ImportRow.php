<?php

declare(strict_types=1);

namespace App\Domains\Imports\Models;

use App\Domains\Imports\Enums\RowStatus;
use App\Domains\Products\Models\Product;
use App\Domains\Products\Models\ProductSku;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of a seller's spreadsheet.
 *
 * The raw cells are kept alongside the normalised values, which looks like duplication
 * and is not: when a seller asks in March why the price on line 251 came out as 4.899
 * instead of 48.990, the answer is in what their file actually said, not in what the
 * importer made of it.
 *
 * `line_number` counts the file's own lines, header included, so an error message can
 * name a line the seller can find in Excel.
 *
 * @property string $id
 * @property string $batch_id
 * @property int $line_number
 * @property array<string, mixed> $raw
 * @property array<string, mixed>|null $normalised
 * @property RowStatus $status
 * @property array<string, array<int, string>>|null $errors
 * @property string|null $action
 * @property string|null $product_id
 * @property string|null $sku_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ImportRow extends Model
{
    use HasUuidV7;

    protected $table = 'import_rows';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
    ];

    /** @var list<string> */
    protected $fillable = [
        'batch_id',
        'line_number',
        'raw',
        'normalised',
        'status',
        'errors',
        'action',
        'product_id',
        'sku_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RowStatus::class,
            'raw' => 'array',
            'normalised' => 'array',
            'errors' => 'array',
            'line_number' => 'integer',
        ];
    }

    /** @return BelongsTo<ImportBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'batch_id');
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
     * Every error on this row as flat sentences.
     *
     * A seller reading a four-hundred-line report does not want a field-keyed
     * structure; they want to know what is wrong with the line in front of them.
     *
     * @return array<int, string>
     */
    public function errorMessages(): array
    {
        $messages = [];

        foreach ($this->errors ?? [] as $field => $fieldErrors) {
            foreach ((array) $fieldErrors as $message) {
                $messages[] = is_string($field) && $field !== ''
                    ? $field.': '.$message
                    : (string) $message;
            }
        }

        return $messages;
    }

    /** @param  Builder<$this>  $query */
    public function scopeValid(Builder $query): void
    {
        $query->where('status', RowStatus::Valid->value);
    }
}
