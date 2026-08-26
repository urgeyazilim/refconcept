<?php

declare(strict_types=1);

namespace App\Domains\Administration\Models;

use App\Domains\Identity\Models\User;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * On, off, or on for some.
 *
 * @property string $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property bool $is_enabled
 * @property int $rollout_percentage
 * @property string|null $updated_by
 */
class FeatureFlag extends Model
{
    use HasUuidV7;

    protected $table = 'feature_flags';

    /** @var array<string, mixed> */
    protected $attributes = ['is_enabled' => false, 'rollout_percentage' => 100];

    /** @var list<string> */
    protected $fillable = ['key', 'name', 'description', 'is_enabled', 'rollout_percentage', 'updated_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'rollout_percentage' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Whether this flag is on for one person.
     *
     * {@see AppDomainsAdministrationServicesFeatures} answers the same question from
     * cached scalars rather than from a loaded model, and mirrors this arithmetic. A test
     * asserts the two agree, because a rollout that disagreed with itself would move people
     * in and out of a feature depending on which code path asked.
     *
     * The bucket comes from a stable hash of the flag key and the user id, so somebody who
     * has the feature keeps it — a percentage that re-rolled on every request would show a
     * user a feature and then take it away mid-journey, which is worse than not shipping
     * it. Hashing the key in as well means two flags at 50% do not select the same half.
     */
    public function isOnFor(?string $userId): bool
    {
        if (! $this->is_enabled) {
            return false;
        }

        if ($this->rollout_percentage >= 100) {
            return true;
        }

        if ($this->rollout_percentage <= 0 || $userId === null) {
            return false;
        }

        $bucket = hexdec(substr(hash('sha256', $this->key.':'.$userId), 0, 8)) % 100;

        return $bucket < $this->rollout_percentage;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'is_enabled' => $this->is_enabled,
            'rollout_percentage' => $this->rollout_percentage,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
