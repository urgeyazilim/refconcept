<?php

declare(strict_types=1);

namespace App\Domains\Imports\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Imports\Enums\ImportStatus;
use App\Domains\Imports\Enums\RowStatus;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Sellers\Models\Seller;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One uploaded spreadsheet and everything that happened to it.
 *
 * The counts are stored rather than derived. They are read on every poll of a progress
 * screen, and counting four thousand rows five times a second to render a progress bar
 * is not a trade worth making — they are written inside the same transaction as the
 * rows they count, so they cannot drift from them.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $seller_id
 * @property string $type
 * @property ImportStatus $status
 * @property string $original_name
 * @property string $disk
 * @property string $storage_path
 * @property int $size_bytes
 * @property array<int, string>|null $detected_headers
 * @property array<string, string>|null $mapping
 * @property int $total_rows
 * @property int $valid_rows
 * @property int $error_rows
 * @property int $created_rows
 * @property int $updated_rows
 * @property string|null $failure_reason
 * @property string|null $created_by
 * @property Carbon|null $analysed_at
 * @property Carbon|null $dry_run_at
 * @property Carbon|null $committed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class ImportBatch extends Model
{
    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'import_batches';

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => 'products',
        'status' => 'uploaded',
    ];

    /** The object key is infrastructure detail, like every other storage path. */
    protected $hidden = ['storage_path'];

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'seller_id',
        'type',
        'original_name',
        'disk',
        'storage_path',
        'size_bytes',
        'detected_headers',
        'mapping',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'detected_headers' => 'array',
            'mapping' => 'array',
            'size_bytes' => 'integer',
            'total_rows' => 'integer',
            'valid_rows' => 'integer',
            'error_rows' => 'integer',
            'created_rows' => 'integer',
            'updated_rows' => 'integer',
            'analysed_at' => 'datetime',
            'dry_run_at' => 'datetime',
            'committed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Seller, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ImportRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class, 'batch_id')->orderBy('line_number');
    }

    /** @return HasMany<ImportRow, $this> */
    public function invalidRows(): HasMany
    {
        return $this->rows()->where('status', RowStatus::Invalid->value);
    }

    /** Progress as a percentage, for a bar that has to show something sensible at zero. */
    public function progressPercent(): int
    {
        if ($this->total_rows === 0) {
            return $this->status->isTerminal() ? 100 : 0;
        }

        $done = $this->valid_rows + $this->error_rows;

        return (int) min(100, round(($done / $this->total_rows) * 100));
    }

    /** @param  Builder<$this>  $query */
    public function scopeForOrganization(Builder $query, string $organizationId): void
    {
        $query->where('organization_id', $organizationId);
    }
}
