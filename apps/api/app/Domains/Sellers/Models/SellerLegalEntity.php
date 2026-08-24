<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The registered legal identity behind an application.
 *
 * @property string $id
 * @property string $application_id
 * @property string $legal_name
 * @property string|null $tax_office
 * @property string|null $tax_number
 * @property string|null $national_id
 * @property string|null $mersis_number
 * @property string|null $trade_registry_number
 * @property string|null $kep_address
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SellerLegalEntity extends Model
{
    use HasUuidV7;

    protected $table = 'seller_legal_entities';

    /** @var list<string> */
    protected $fillable = [
        'application_id',
        'legal_name',
        'tax_office',
        'tax_number',
        'national_id',
        'mersis_number',
        'trade_registry_number',
        'kep_address',
    ];

    /** @return BelongsTo<SellerApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(SellerApplication::class, 'application_id');
    }
}
