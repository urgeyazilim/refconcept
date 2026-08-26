<?php

declare(strict_types=1);

namespace App\Domains\Finance\Models;

use App\Domains\Sellers\Models\Seller;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One account in the chart of accounts.
 *
 * Named `LedgerAccountRow` rather than `LedgerAccount` because that name belongs to the
 * enum of codes, and having a model and an enum with the same name is how somebody
 * type-hints one and passes the other.
 *
 * @property string $id
 * @property string $code
 * @property string $type
 * @property string $name
 * @property string|null $seller_id
 * @property string $currency
 * @property bool $is_active
 * @property-read Seller|null $seller
 */
class LedgerAccountRow extends Model
{
    use HasUuidV7;

    protected $table = 'ledger_accounts';

    /** @var array<string, mixed> */
    protected $attributes = ['currency' => 'TRY', 'is_active' => true];

    /** @var list<string> */
    protected $fillable = ['code', 'type', 'name', 'seller_id', 'currency', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Seller, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /** @return HasMany<LedgerLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(LedgerLine::class, 'account_id');
    }
}
