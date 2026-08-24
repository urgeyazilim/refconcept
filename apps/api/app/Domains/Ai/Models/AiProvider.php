<?php

declare(strict_types=1);

namespace App\Domains\Ai\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A company RefConcept buys model capacity from.
 *
 * `driver` selects the adapter; everything else is operational detail an operator can
 * change. No credential lives here — see {@see AiProviderCredential} — so this row is
 * safe to load into an admin list without decrypting anything.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $driver
 * @property string|null $base_url
 * @property bool $is_active
 * @property array<string, mixed>|null $config
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiProvider extends Model
{
    use HasUuidV7;

    protected $table = 'ai_providers';

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true];

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'driver', 'base_url', 'is_active', 'config'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'config' => 'array'];
    }

    /** @return HasMany<AiModel, $this> */
    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class, 'provider_id');
    }

    /** @return HasMany<AiProviderCredential, $this> */
    public function credentials(): HasMany
    {
        return $this->hasMany(AiProviderCredential::class, 'provider_id');
    }

    /** The one key calls should use, if there is one. */
    public function activeCredential(): ?AiProviderCredential
    {
        $this->loadMissing('credentials');

        return $this->credentials->firstWhere('is_active', true);
    }

    /** @param  Builder<$this>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
