<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A versioned agreement a seller must accept.
 *
 * Text is never edited in place. Changing terms means publishing a new version, which
 * is what makes a past acceptance provable.
 *
 * @property string $id
 * @property string $code
 * @property string $version
 * @property string $title
 * @property string $body
 * @property Carbon $effective_from
 * @property bool $is_mandatory
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SellerAgreement extends Model
{
    use HasUuidV7;

    protected $table = 'seller_agreements';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'version',
        'title',
        'body',
        'effective_from',
        'is_mandatory',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
            'is_mandatory' => 'boolean',
        ];
    }

    /** @return HasMany<SellerAgreementAcceptance, $this> */
    public function acceptances(): HasMany
    {
        return $this->hasMany(SellerAgreementAcceptance::class, 'agreement_id');
    }

    /**
     * Agreements in force right now.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeEffective(Builder $query): void
    {
        $query->where('effective_from', '<=', now());
    }

    /**
     * The hash recorded alongside an acceptance, so the text can be proven later.
     */
    public function bodyChecksum(): string
    {
        return hash('sha256', $this->body);
    }
}
