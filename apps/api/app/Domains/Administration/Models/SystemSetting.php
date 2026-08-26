<?php

declare(strict_types=1);

namespace App\Domains\Administration\Models;

use App\Domains\Identity\Models\User;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A value the platform runs on.
 *
 * Typed, because "14" and "true" and an e-mail address are not the same kind of thing, and
 * a settings screen that accepts any string into any field is a screen that will one day
 * set a hold period to "yes".
 *
 * @property string $id
 * @property string $key
 * @property string $group
 * @property string $label
 * @property string|null $description
 * @property string $type
 * @property string|null $value
 * @property bool $is_secret
 * @property string|null $updated_by
 */
class SystemSetting extends Model
{
    use HasUuidV7;

    protected $table = 'system_settings';

    /** @var array<string, mixed> */
    protected $attributes = ['group' => 'general', 'type' => 'string', 'is_secret' => false];

    /** @var list<string> */
    protected $fillable = ['key', 'group', 'label', 'description', 'type', 'value', 'is_secret', 'updated_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_secret' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The value in the shape its type promises.
     *
     * @return string|int|bool|array<mixed>|null
     */
    public function typed(): string|int|bool|array|null
    {
        if ($this->value === null) {
            return null;
        }

        return match ($this->type) {
            'integer' => (int) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($this->value, true),
            default => $this->value,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'group' => $this->group,
            'label' => $this->label,
            'description' => $this->description,
            'type' => $this->type,
            /*
             * A secret is never echoed back, not even to whoever set it. A settings screen
             * that shows an API token has published it to everybody who can open the page,
             * and "it was already stored" is no comfort afterwards.
             */
            'value' => $this->is_secret ? null : $this->value,
            'is_secret' => $this->is_secret,
            'is_set' => $this->value !== null && $this->value !== '',
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
