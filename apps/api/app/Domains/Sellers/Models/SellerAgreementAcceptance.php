<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Models;

use App\Domains\Identity\Models\User;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Evidence that a specific person accepted a specific agreement version.
 *
 * Immutable, enforced by a database trigger as well as here: this row is what the
 * platform would produce in a dispute, and a record that can be edited proves
 * nothing.
 *
 * @property string $id
 * @property string $application_id
 * @property string $agreement_id
 * @property string $accepted_by
 * @property Carbon $accepted_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string $body_checksum
 */
class SellerAgreementAcceptance extends Model
{
    use HasUuidV7;

    protected $table = 'seller_agreement_acceptances';

    /** @var list<string> */
    protected $fillable = [
        'application_id',
        'agreement_id',
        'accepted_by',
        'accepted_at',
        'ip_address',
        'user_agent',
        'body_checksum',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    /** @return BelongsTo<SellerApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(SellerApplication::class, 'application_id');
    }

    /** @return BelongsTo<SellerAgreement, $this> */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(SellerAgreement::class, 'agreement_id');
    }

    /** @return BelongsTo<User, $this> */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new RuntimeException(
            'Agreement acceptances are immutable. Publish a new agreement version and record a new acceptance.'
        );
    }

    public function delete(): bool
    {
        throw new RuntimeException('Agreement acceptances are immutable and cannot be deleted.');
    }
}
