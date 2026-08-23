<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A shipping/billing address book entry.
 *
 * Orders never point at this row: they snapshot the address at checkout, so editing
 * or deleting an address can never rewrite where a past order was sent.
 *
 * @property string $id
 * @property string $user_id
 * @property string|null $label
 * @property string $recipient_name
 * @property string|null $phone
 * @property string $country_code
 * @property string $city
 * @property string|null $district
 * @property string|null $neighbourhood
 * @property string $address_line1
 * @property string|null $address_line2
 * @property string|null $postal_code
 * @property bool $is_default_shipping
 * @property bool $is_default_billing
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class UserAddress extends Model
{
    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'user_addresses';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'label',
        'recipient_name',
        'phone',
        'country_code',
        'city',
        'district',
        'neighbourhood',
        'address_line1',
        'address_line2',
        'postal_code',
        'is_default_shipping',
        'is_default_billing',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default_shipping' => 'boolean',
            'is_default_billing' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
