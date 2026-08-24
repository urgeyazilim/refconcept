<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Sellers\Enums\ApplicationStatus;
use App\Support\Concerns\HasUuidV7;
use Database\Factories\SellerApplicationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A prospective seller's onboarding file.
 *
 * Separate from `sellers` on purpose: a rejected application stays on record with its
 * reason, and an approved one preserves exactly what was reviewed at the time.
 *
 * @property string $id
 * @property string $applicant_user_id
 * @property string|null $organization_id
 * @property string $company_name
 * @property string $display_name
 * @property string $legal_form
 * @property string $contact_email
 * @property string $contact_phone
 * @property string|null $website
 * @property string|null $product_categories
 * @property ApplicationStatus $status
 * @property Carbon|null $submitted_at
 * @property Carbon|null $reviewed_at
 * @property string|null $reviewed_by
 * @property string|null $decision_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SellerLegalEntity|null $legalEntity
 * @property-read SellerTaxProfile|null $taxProfile
 */
class SellerApplication extends Model
{
    /** @use HasFactory<SellerApplicationFactory> */
    use HasFactory;

    use HasUuidV7;

    protected $table = 'seller_applications';

    /**
     * A database default is not reflected on a freshly created instance, so the
     * model would report a null status until it was reloaded. Declaring it here keeps
     * the in-memory object and the row in agreement from the first moment.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    /** @var list<string> */
    protected $fillable = [
        'applicant_user_id',
        'company_name',
        'display_name',
        'legal_form',
        'contact_email',
        'contact_phone',
        'website',
        'product_categories',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasOne<SellerLegalEntity, $this> */
    public function legalEntity(): HasOne
    {
        return $this->hasOne(SellerLegalEntity::class, 'application_id');
    }

    /** @return HasOne<SellerTaxProfile, $this> */
    public function taxProfile(): HasOne
    {
        return $this->hasOne(SellerTaxProfile::class, 'application_id');
    }

    /** @return HasMany<SellerContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(SellerContact::class, 'application_id');
    }

    /** @return HasMany<SellerAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(SellerAddress::class, 'application_id');
    }

    /** @return HasMany<SellerBankAccount, $this> */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(SellerBankAccount::class, 'application_id');
    }

    /** @return HasMany<SellerDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(SellerDocument::class, 'application_id');
    }

    /** @return HasMany<SellerAgreementAcceptance, $this> */
    public function acceptances(): HasMany
    {
        return $this->hasMany(SellerAgreementAcceptance::class, 'application_id');
    }

    /** @return HasOne<Seller, $this> */
    public function seller(): HasOne
    {
        return $this->hasOne(Seller::class, 'application_id');
    }

    /**
     * Applications an operator still has to act on.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeAwaitingReview(Builder $query): void
    {
        $query->whereIn('status', [
            ApplicationStatus::Submitted->value,
            ApplicationStatus::InReview->value,
        ]);
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }
}
