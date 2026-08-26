<?php

declare(strict_types=1);

namespace App\Domains\Finance\Models;

use App\Domains\Sellers\Models\Seller;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One side of one entry. Append-only.
 *
 * @property string $id
 * @property string $entry_id
 * @property string $account_id
 * @property int $debit_minor
 * @property int $credit_minor
 * @property string $currency
 * @property string|null $seller_id
 * @property string|null $memo
 * @property Carbon|null $created_at
 * @property-read LedgerEntry|null $entry
 * @property-read LedgerAccountRow|null $account
 * @property-read Seller|null $seller
 */
class LedgerLine extends Model
{
    use HasUuidV7;

    protected $table = 'ledger_lines';

    public const UPDATED_AT = null;

    /** @var array<string, mixed> */
    protected $attributes = ['currency' => 'TRY', 'debit_minor' => 0, 'credit_minor' => 0];

    /** @var list<string> */
    protected $fillable = [
        'entry_id',
        'account_id',
        'debit_minor',
        'credit_minor',
        'currency',
        'seller_id',
        'memo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['debit_minor' => 'integer', 'credit_minor' => 'integer'];
    }

    /** @return BelongsTo<LedgerEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'entry_id');
    }

    /** @return BelongsTo<LedgerAccountRow, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccountRow::class, 'account_id');
    }

    /** @return BelongsTo<Seller, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /** @param  Builder<$this>  $query */
    public function scopeForSeller(Builder $query, string $sellerId): void
    {
        $query->where('seller_id', $sellerId)->orderBy('created_at');
    }
}
