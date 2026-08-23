<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Optional personal details, kept out of `users` so authentication reads stay narrow
 * and so profile data can be purged on a KVKK erasure request without destroying the
 * account rows that financial history references.
 *
 * @property string $user_id
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $display_name
 * @property string|null $avatar_path
 * @property Carbon|null $birth_date
 * @property bool $marketing_opt_in
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UserProfile extends Model
{
    protected $table = 'user_profiles';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'display_name',
        'avatar_path',
        'birth_date',
        'marketing_opt_in',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'marketing_opt_in' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
