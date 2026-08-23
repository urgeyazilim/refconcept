<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Organizations\Models\Organization;
use App\Support\Concerns\HasUuidV7;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * A person with access to RefConcept — customer, seller staff or platform operator.
 *
 * Roles decide which. There is no separate "seller user" table: authority comes from
 * organization membership plus a role grant scoped to that organization.
 *
 * @property string $id
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $password_hash
 * @property UserStatus $status
 * @property string $locale
 * @property string $timezone
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $phone_verified_at
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read UserProfile|null $profile
 */
class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuidV7;
    use Notifiable;
    use SoftDeletes;

    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = [
        'email',
        'phone',
        'status',
        'locale',
        'timezone',
    ];

    /**
     * password_hash never leaves the model.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => UserStatus::class,
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * The schema names the column `password_hash` rather than Laravel's default
     * `password`, so the auth contract is pointed at it explicitly.
     */
    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /** @return HasOne<UserProfile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    /** @return HasMany<UserAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(UserAddress::class);
    }

    /** @return HasMany<Consent, $this> */
    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    /** @return HasMany<UserSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    /** @return HasMany<UserRole, $this> */
    public function roleGrants(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    /** @return BelongsToMany<Organization, $this> */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_users')
            ->withPivot(['status', 'joined_at', 'removed_at'])
            ->wherePivot('status', 'active')
            ->withTimestamps();
    }

    public function isVerified(): bool
    {
        return $this->email_verified_at !== null || $this->phone_verified_at !== null;
    }

    /**
     * A human label for audit trails and notifications. Falls back through profile
     * name, e-mail, phone and finally the id, so it is never empty.
     */
    public function displayName(): string
    {
        $profile = $this->profile;

        if ($profile !== null) {
            if ($profile->display_name !== null && $profile->display_name !== '') {
                return $profile->display_name;
            }

            $name = trim(($profile->first_name ?? '').' '.($profile->last_name ?? ''));

            if ($name !== '') {
                return $name;
            }
        }

        return (string) ($this->email ?? $this->phone ?? $this->id);
    }
}
