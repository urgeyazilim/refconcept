<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use App\Domains\Identity\Enums\RoleScope;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A named bundle of permissions.
 *
 * Platform roles apply everywhere; organization roles apply only within the
 * organization they were granted in, which is what keeps seller staff confined to
 * their own seller.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property RoleScope $scope
 * @property string|null $description
 * @property bool $is_system
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Role extends Model
{
    use HasUuidV7;

    protected $table = 'roles';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'scope',
        'description',
        'is_system',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => RoleScope::class,
            'is_system' => 'boolean',
        ];
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    /** @return HasMany<UserRole, $this> */
    public function grants(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }
}
