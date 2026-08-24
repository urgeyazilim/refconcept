<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Projects\Enums\DesignVersionStatus;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One attempt, and where it came from.
 *
 * `parent_version_id` is the whole design. "Make the sofa darker" produces a *child*
 * of the version being looked at rather than a replacement for it, so a customer can
 * always return to the one they liked. A null parent is a root: the first attempt, or
 * a deliberate fresh start from the original photograph.
 *
 * A version that has reached `ready` never changes again. Re-running produces another
 * version — that is what a tree is for, and it is what makes "I preferred the third
 * one" a thing somebody can act on.
 *
 * @property string $id
 * @property string $design_id
 * @property string|null $parent_version_id
 * @property int $version_number
 * @property DesignVersionStatus $status
 * @property string|null $style_code
 * @property string|null $style_prompt
 * @property string|null $user_prompt
 * @property string|null $ai_job_id
 * @property int $credit_cost
 * @property string|null $failure_reason
 * @property string|null $created_by
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DesignVersion extends Model
{
    use HasUuidV7;

    protected $table = 'design_versions';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'credit_cost' => 0,
    ];

    /** @var list<string> */
    protected $fillable = [
        'design_id',
        'parent_version_id',
        'version_number',
        'style_code',
        'style_prompt',
        'user_prompt',
        'credit_cost',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DesignVersionStatus::class,
            'version_number' => 'integer',
            'credit_cost' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Design, $this> */
    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class);
    }

    /** @return BelongsTo<DesignVersion, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(DesignVersion::class, 'parent_version_id');
    }

    /** @return HasMany<DesignVersion, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(DesignVersion::class, 'parent_version_id')->orderBy('version_number');
    }

    /** @return HasMany<DesignAsset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(DesignAsset::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isRoot(): bool
    {
        return $this->parent_version_id === null;
    }

    /** The finished image, if there is one. */
    public function render(): ?DesignAsset
    {
        $this->loadMissing('assets');

        return $this->assets->firstWhere('type', 'render');
    }

    public function thumbnail(): ?DesignAsset
    {
        $this->loadMissing('assets');

        return $this->assets->firstWhere('type', 'thumbnail');
    }

    /**
     * The chain back to the root, oldest first.
     *
     * What a customer means by "how did I get here": every prompt that shaped this
     * image, in the order they gave them.
     *
     * @return array<int, self>
     */
    public function ancestry(): array
    {
        $chain = [];
        $node = $this;

        // Bounded rather than trusting the data: a cycle would otherwise hang a request
        // rather than merely being wrong, and a schema constraint only rules out the
        // one-step case.
        for ($depth = 0; $depth < 64 && $node !== null; $depth++) {
            array_unshift($chain, $node);
            $node = $node->parent;
        }

        return $chain;
    }

    /** @param  Builder<$this>  $query */
    public function scopeReady(Builder $query): void
    {
        $query->where('status', DesignVersionStatus::Ready->value);
    }
}
