<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A node in the platform category tree.
 *
 * Carries a materialised path as well as a parent, so "everything under Mobilya" is a
 * single prefix scan rather than one query per level. The path is derived on save,
 * never set by hand — a path that disagrees with the parent chain silently breaks
 * every category page beneath it.
 *
 * @property string $id
 * @property string|null $parent_id
 * @property string $name
 * @property string $slug
 * @property string $path
 * @property int $depth
 * @property int $position
 * @property string|null $description
 * @property bool $is_active
 * @property string|null $room_type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Category extends Model
{
    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'categories';

    /** @var list<string> */
    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'description',
        'position',
        'is_active',
        'room_type',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'depth' => 'integer',
            'position' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Path and depth follow from the parent, so they are computed rather than
        // trusted to whoever is saving the row.
        static::saving(function (self $category): void {
            $parent = $category->parent_id === null
                ? null
                : self::query()->find($category->parent_id);

            $category->path = $parent === null
                ? $category->slug
                : $parent->path.'/'.$category->slug;

            $category->depth = $parent === null ? 0 : $parent->depth + 1;
        });
    }

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Category, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    /** @return BelongsToMany<Attribute, $this> */
    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'category_attributes')
            ->withPivot(['is_required', 'position'])
            ->orderBy('category_attributes.position');
    }

    /** @param  Builder<$this>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * This category and everything beneath it, in one query.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeUnderPath(Builder $query, string $path): void
    {
        $query->where(function (Builder $inner) use ($path): void {
            $inner->where('path', $path)->orWhere('path', 'like', $path.'/%');
        });
    }

    public static function makeSlug(string $name): string
    {
        return Str::slug($name);
    }
}
