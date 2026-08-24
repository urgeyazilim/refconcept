<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A named person at the seller. Finance and logistics contacts matter later:
 * settlement notices and shipping escalations go to a person, not a company inbox.
 *
 * @property string $id
 * @property string $application_id
 * @property string $type
 * @property string $full_name
 * @property string $email
 * @property string|null $phone
 * @property string|null $title
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SellerContact extends Model
{
    use HasUuidV7;

    protected $table = 'seller_contacts';

    /** @var list<string> */
    protected $fillable = [
        'application_id',
        'type',
        'full_name',
        'email',
        'phone',
        'title',
    ];

    /** @return BelongsTo<SellerApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(SellerApplication::class, 'application_id');
    }
}
