<?php

declare(strict_types=1);

namespace App\Domains\Finance\Models;

use App\Domains\Identity\Models\User;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One financial event, and its lines.
 *
 * Append-only in the database. `UPDATED_AT` is off because a model that tried to touch it
 * would hit the trigger — and a ledger that can be touched is not a ledger.
 *
 * @property string $id
 * @property string $type
 * @property string $description
 * @property string|null $reference_type
 * @property string|null $reference_id
 * @property string|null $reverses_entry_id
 * @property string $currency
 * @property string|null $idempotency_key
 * @property string|null $created_by
 * @property Carbon $posted_at
 * @property-read Collection<int, LedgerLine> $lines
 * @property-read User|null $author
 */
class LedgerEntry extends Model
{
    use HasUuidV7;

    protected $table = 'ledger_entries';

    public const UPDATED_AT = null;

    /** @var array<string, mixed> */
    protected $attributes = ['currency' => 'TRY'];

    /** @var list<string> */
    protected $fillable = [
        'type',
        'description',
        'reference_type',
        'reference_id',
        'reverses_entry_id',
        'currency',
        'idempotency_key',
        'created_by',
        'posted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['posted_at' => 'datetime'];
    }

    /** @return HasMany<LedgerLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(LedgerLine::class, 'entry_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<self, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function totalMinor(): int
    {
        $this->loadMissing('lines');

        return (int) $this->lines->sum('debit_minor');
    }
}
