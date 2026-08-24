<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Models;

use App\Domains\Sellers\Enums\TaxpayerType;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tax treatment for a seller.
 *
 * The VAT rate is basis points, not a percentage: 2000 = 20%. Order totals are
 * computed from it in integer minor units, and a float here would reintroduce
 * rounding error at the one place the platform cannot afford it.
 *
 * @property string $id
 * @property string $application_id
 * @property TaxpayerType $taxpayer_type
 * @property int $default_vat_rate_bps
 * @property bool $e_invoice_enabled
 * @property bool $e_archive_enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SellerTaxProfile extends Model
{
    use HasUuidV7;

    protected $table = 'seller_tax_profiles';

    /** @var list<string> */
    protected $fillable = [
        'application_id',
        'taxpayer_type',
        'default_vat_rate_bps',
        'e_invoice_enabled',
        'e_archive_enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'taxpayer_type' => TaxpayerType::class,
            'default_vat_rate_bps' => 'integer',
            'e_invoice_enabled' => 'boolean',
            'e_archive_enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<SellerApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(SellerApplication::class, 'application_id');
    }

    /** Human-readable rate for display only; never used in arithmetic. */
    public function vatRatePercent(): float
    {
        return $this->default_vat_rate_bps / 100;
    }
}
