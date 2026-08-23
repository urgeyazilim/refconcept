<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use App\Domains\Identity\Enums\ConsentType;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An append-only consent record.
 *
 * Withdrawing consent inserts a new row with `granted = false`; the original
 * acceptance stays intact because it is the evidence that consent was given at all.
 *
 * @property string $id
 * @property string $user_id
 * @property ConsentType $type
 * @property string $version
 * @property bool $granted
 * @property Carbon $recorded_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Consent extends Model
{
    use HasUuidV7;

    protected $table = 'consents';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'type',
        'version',
        'granted',
        'recorded_at',
        'ip_address',
        'user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ConsentType::class,
            'granted' => 'boolean',
            'recorded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
