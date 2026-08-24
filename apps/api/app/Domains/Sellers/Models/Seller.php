<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An approved seller.
 *
 * Exactly one per organization, which is what makes the organization the tenant
 * boundary for everything a seller owns from Phase 3 onward.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $application_id
 * @property string $seller_code
 * @property string $display_name
 * @property SellerStatus $status
 * @property string $onboarding_status
 * @property string $risk_status
 * @property int|null $default_commission_bps
 * @property string|null $iyzico_submerchant_key
 * @property string|null $qnb_merchant_reference
 * @property Carbon|null $approved_at
 * @property string|null $approved_by
 * @property Carbon|null $suspended_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Seller extends Model
{
    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'sellers';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'active',
        'onboarding_status' => 'completed',
        'risk_status' => 'normal',
    ];

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'application_id',
        'seller_code',
        'display_name',
        'default_commission_bps',
    ];

    /**
     * Gateway identifiers are credentials in effect — they authorise payouts — so
     * they are encrypted at rest and never mass-assignable.
     *
     * @var list<string>
     */
    protected $hidden = [
        'iyzico_submerchant_key',
        'qnb_merchant_reference',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SellerStatus::class,
            'default_commission_bps' => 'integer',
            'iyzico_submerchant_key' => 'encrypted',
            'qnb_merchant_reference' => 'encrypted',
            'approved_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<SellerApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(SellerApplication::class, 'application_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<SellerStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(SellerStatusHistory::class, 'seller_id');
    }

    public function canTrade(): bool
    {
        return $this->status->canTrade();
    }

    /**
     * The commission that applies when nothing more specific does.
     *
     * Returns basis points, never a percentage: the resolver hierarchy in Phase 16
     * compares these values and a float would make two equal rates unequal.
     */
    public function effectiveCommissionBps(): int
    {
        return $this->default_commission_bps
            ?? (int) config('refconcept.commission.platform_default_bps', 1200);
    }
}
