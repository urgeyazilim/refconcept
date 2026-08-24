<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Projects\Enums\DesignStatus;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One customer's ongoing attempt at one room.
 *
 * The design is the container; the versions are the work. `current_version_id` is
 * whichever version the customer is looking at, which is deliberately *not* the same
 * as the newest — the point of keeping a tree is being able to go back to the one you
 * liked and carry on from there.
 *
 * @property string $id
 * @property string $room_id
 * @property string $name
 * @property DesignStatus $status
 * @property string|null $current_version_id
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Design extends Model
{
    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'designs';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
    ];

    /** @var list<string> */
    protected $fillable = [
        'room_id',
        'name',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DesignStatus::class,
        ];
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<DesignVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(DesignVersion::class)->orderBy('version_number');
    }

    /** @return BelongsTo<DesignVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DesignVersion::class, 'current_version_id');
    }

    /**
     * The version numbers already used, so the next one can be chosen safely.
     *
     * Numbering is per design and never reused, even after a version fails: "v4"
     * should mean the same thing to the customer tomorrow as it does today.
     */
    public function nextVersionNumber(): int
    {
        return ((int) $this->versions()->max('version_number')) + 1;
    }

    /** How many credits this design has cost across every attempt. */
    public function totalCreditCost(): int
    {
        return (int) $this->versions()->sum('credit_cost');
    }
}
