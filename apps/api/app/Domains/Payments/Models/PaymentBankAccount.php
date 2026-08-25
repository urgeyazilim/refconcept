<?php

declare(strict_types=1);

namespace App\Domains\Payments\Models;

use App\Support\Concerns\HasUuidV7;
use App\Support\ValueObjects\Iban;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An account customers pay into.
 *
 * The IBAN is stored and returned in full, which is the opposite of the rule for seller
 * payout accounts — and the difference is the point. A seller's IBAN is personal data that
 * only finance should ever see. This one is printed on the checkout page for every
 * customer to copy into their banking app. Masking it would break the feature; encrypting
 * it would protect a number we publish.
 *
 * @property string $id
 * @property string $bank_name
 * @property string|null $branch
 * @property string $account_holder
 * @property string $iban
 * @property string $currency
 * @property string|null $note
 * @property bool $is_active
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PaymentBankAccount extends Model
{
    use HasUuidV7;

    protected $table = 'payment_bank_accounts';

    /** @var array<string, mixed> */
    protected $attributes = [
        'currency' => 'TRY',
        'is_active' => true,
        'position' => 0,
    ];

    /** @var list<string> */
    protected $fillable = [
        'bank_name',
        'branch',
        'account_holder',
        'iban',
        'currency',
        'note',
        'is_active',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** @param  Builder<$this>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('position');
    }

    /**
     * The IBAN grouped in fours.
     *
     * Not decoration: the number is copied by eye from a screen into a phone, and an
     * unbroken run of twenty-six characters is how a digit gets dropped.
     */
    public function formattedIban(): string
    {
        return Iban::fromString($this->iban)->formatted();
    }

    /**
     * @return array<string, mixed>
     */
    public function toCustomerArray(): array
    {
        return [
            'id' => $this->id,
            'bank_name' => $this->bank_name,
            'branch' => $this->branch,
            'account_holder' => $this->account_holder,
            'iban' => $this->formattedIban(),
            'currency' => $this->currency,
            'note' => $this->note,
        ];
    }
}
