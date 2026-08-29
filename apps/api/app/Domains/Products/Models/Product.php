<?php

declare(strict_types=1);

namespace App\Domains\Products\Models;

use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Style;
use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Products\Enums\ModerationStatus;
use App\Domains\Products\Enums\ProductStatus;
use App\Support\Concerns\HasUuidV7;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A catalogue entry: what the thing *is*.
 *
 * Commercial terms live on {@see ProductSku}, one per seller offer. Keeping the two
 * apart is what lets two sellers list the same sofa without the matching engine
 * seeing two different sofas.
 *
 * Visibility takes two independent conditions — approved by moderation *and* set
 * active by the seller. Neither alone is enough, which is why they are separate
 * columns rather than one status.
 *
 * @property string $id
 * @property string|null $organization_id
 * @property string|null $brand_id
 * @property string $primary_category_id
 * @property string|null $style_id
 * @property string $name
 * @property string $slug
 * @property string $product_type
 * @property string|null $description
 * @property ProductStatus $status
 * @property ModerationStatus $moderation_status
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property string|null $created_by
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'products';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'moderation_status' => 'draft',
        'product_type' => 'simple',
    ];

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'brand_id',
        'primary_category_id',
        'style_id',
        'name',
        'slug',
        'product_type',
        'description',
        'seo_title',
        'seo_description',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'moderation_status' => ModerationStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function primaryCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'primary_category_id');
    }

    /**
     * The one style this is, mainly.
     *
     * Kept in step with the primary row in {@see styles()} rather than being the truth. The
     * public catalogue and search still read it; they move over separately, and a column
     * disappearing under running code is a worse morning than one deploy of duplication.
     *
     * @return BelongsTo<Style, $this>
     */
    public function style(): BelongsTo
    {
        return $this->belongsTo(Style::class);
    }

    /**
     * Every style this belongs to, primary first.
     *
     * A product is rarely one style. A plain oak sideboard is credibly scandinavian and
     * minimal, and a seller forced to choose loses half of what makes it findable — which
     * mattered the moment customers began choosing a style rather than typing a sentence.
     *
     * @return BelongsToMany<Style, $this>
     */
    public function styles(): BelongsToMany
    {
        return $this->belongsToMany(Style::class, 'product_styles')
            ->withPivot(['strength_bps', 'is_primary'])
            ->withTimestamps()
            ->orderByPivot('strength_bps', 'desc');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'product_categories');
    }

    /** @return HasMany<ProductSku, $this> */
    public function skus(): HasMany
    {
        return $this->hasMany(ProductSku::class);
    }

    /** @return HasMany<ProductAttributeValue, $this> */
    public function attributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    /** @return HasMany<ProductMedia, $this> */
    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class)->orderBy('position');
    }

    /** @return HasMany<ProductModeration, $this> */
    public function moderationDecisions(): HasMany
    {
        return $this->hasMany(ProductModeration::class)->orderByDesc('decided_at');
    }

    /**
     * Publicly visible listings.
     *
     * Three conditions, all necessary: approved by moderation, set active by the
     * seller, and carrying at least one *purchasable* offer. A product with no
     * purchasable SKU is a catalogue page that cannot be bought from, which is worse
     * than not listing it.
     *
     * "Purchasable" deliberately delegates to the SKU scope, which also requires the
     * offering seller to be trading. Repeating a simpler condition here is how a
     * suspended seller's listings stay on sale.
     *
     * @param  Builder<$this>  $query
     */
    public function scopePubliclyVisible(Builder $query): void
    {
        $query
            ->where('moderation_status', ModerationStatus::Approved->value)
            ->where('status', ProductStatus::Active->value)
            // The closure is typed to the SKU builder so the scope resolves for static
            // analysis as well as at runtime.
            ->whereHas('skus', function (Builder $skus): void {
                /** @var Builder<ProductSku> $skus */
                $skus->purchasable();
            });
    }

    /** @param  Builder<$this>  $query */
    public function scopeForOrganization(Builder $query, string $organizationId): void
    {
        $query->where('organization_id', $organizationId);
    }

    public function isEditable(): bool
    {
        return $this->moderation_status->isEditable();
    }

    public function isPubliclyVisible(): bool
    {
        return $this->moderation_status === ModerationStatus::Approved
            && $this->status === ProductStatus::Active
            // isAvailable() covers stock and the seller's trading status too, matching
            // what publiclyVisible() asks of the database.
            && $this->skus->contains(fn (ProductSku $sku): bool => $sku->isAvailable());
    }

    /**
     * Whether this listing exists, ignoring how many are left.
     *
     * The difference from {@see isPubliclyVisible()} is stock, and it matters wherever the
     * ledger is the authority on quantity — a basket revalidating its own lines, most of
     * all. A customer holding the last unit at checkout has not had the listing withdrawn
     * from under them, and treating "none left" as "no longer sold" would empty their
     * basket while they were paying for it.
     */
    public function isListable(): bool
    {
        return $this->moderation_status === ModerationStatus::Approved
            && $this->status === ProductStatus::Active
            && $this->skus->contains(fn (ProductSku $sku): bool => $sku->isOffered());
    }

    /**
     * The lowest purchasable price across sellers — the "from" figure on a listing.
     */
    public function lowestActivePrice(): ?ProductSku
    {
        return $this->skus
            ->filter(fn (ProductSku $sku): bool => $sku->isAvailable())
            ->sortBy(fn (ProductSku $sku): int => $sku->effectivePrice()->amountMinor)
            ->first();
    }

    /**
     * The lowest price across *every* offer, whether it is on sale or not.
     *
     * The seller's own list and the moderation queue both need this: a draft listing
     * has no purchasable offer by definition, and showing a reviewer "—" where the
     * price should be hides the single figure they most need in order to judge it.
     * Never used on the storefront, where an unpurchasable price is a lie.
     */
    public function lowestPrice(): ?ProductSku
    {
        return $this->skus
            ->sortBy(fn (ProductSku $sku): int => $sku->effectivePrice()->amountMinor)
            ->first();
    }
}
