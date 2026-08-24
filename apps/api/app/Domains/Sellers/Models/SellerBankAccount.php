<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Models;

use App\Support\Concerns\HasUuidV7;
use App\Support\ValueObjects\Iban;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Where a seller's payouts go.
 *
 * The IBAN never exists in the database as plaintext. Three columns cooperate:
 *
 *   iban_encrypted   the value itself, encrypted with the application key
 *   iban_last4       a masked display value, so the UI never needs to decrypt
 *   iban_fingerprint a keyed hash, so duplicates can be detected without decrypting
 *
 * A leaked table is therefore not a list of payout destinations, and routine screens
 * never touch the ciphertext at all.
 *
 * @property string $id
 * @property string $application_id
 * @property string $account_holder
 * @property string|null $bank_name
 * @property string|null $iban_encrypted
 * @property string $iban_last4
 * @property string $iban_fingerprint
 * @property string $currency
 * @property bool $is_primary
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SellerBankAccount extends Model
{
    use HasUuidV7;

    protected $table = 'seller_bank_accounts';

    /** @var list<string> */
    protected $fillable = [
        'application_id',
        'account_holder',
        'bank_name',
        'currency',
        'is_primary',
    ];

    /** @var list<string> */
    protected $hidden = [
        'iban_encrypted',
        'iban_fingerprint',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'iban_encrypted' => 'encrypted',
            'is_primary' => 'boolean',
        ];
    }

    /** @return BelongsTo<SellerApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(SellerApplication::class, 'application_id');
    }

    /**
     * Sets every IBAN-derived column from one validated value, so the ciphertext,
     * the mask and the fingerprint can never drift apart.
     */
    public function setIban(Iban $iban): void
    {
        $this->iban_encrypted = $iban->value();
        $this->iban_last4 = $iban->last4();
        $this->iban_fingerprint = $iban->fingerprint();
    }

    public function iban(): ?Iban
    {
        return $this->iban_encrypted === null ? null : Iban::fromStored($this->iban_encrypted);
    }

    /** The only form of the IBAN that belongs in a response or a log line. */
    public function maskedIban(): string
    {
        return '**** **** **** '.$this->iban_last4;
    }
}
