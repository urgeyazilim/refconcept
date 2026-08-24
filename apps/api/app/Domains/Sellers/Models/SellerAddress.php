<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $application_id
 * @property string $type
 * @property string $country_code
 * @property string $city
 * @property string|null $district
 * @property string $address_line1
 * @property string|null $address_line2
 * @property string|null $postal_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SellerAddress extends Model
{
    use HasUuidV7;

    protected $table = 'seller_addresses';

    /** @var list<string> */
    protected $fillable = [
        'application_id',
        'type',
        'country_code',
        'city',
        'district',
        'address_line1',
        'address_line2',
        'postal_code',
    ];

    /** @return BelongsTo<SellerApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(SellerApplication::class, 'application_id');
    }
}
