<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A single fine-grained capability, e.g. `orders.refund`.
 *
 * Permissions are seeded from code rather than created at runtime: an authorization
 * check that references a permission nobody defined must fail loudly during
 * development, not silently grant or deny in production.
 *
 * @property string $id
 * @property string $name
 * @property string $group
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Permission extends Model
{
    use HasUuidV7;

    protected $table = 'permissions';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'group',
        'description',
    ];

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }
}
