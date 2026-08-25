<?php

declare(strict_types=1);

namespace App\Domains\Credits\Models;

use App\Domains\Identity\Models\User;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person claiming one promotion, once.
 *
 * The row that makes a per-user limit enforceable. Counting redemptions out of the
 * ledger would work right up until two requests arrive together, both read the same
 * count and both decide they are within the limit.
 *
 * @property string $id
 * @property string $promotion_id
 * @property string $user_id
 * @property string|null $transaction_id
 * @property int $credits
 * @property Carbon $created_at
 */
class CreditPromotionRedemption extends Model
{
    use HasUuidV7;

    protected $table = 'credit_promotion_redemptions';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['promotion_id', 'user_id', 'transaction_id', 'credits', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['credits' => 'integer', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<CreditPromotion, $this> */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(CreditPromotion::class, 'promotion_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<CreditTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(CreditTransaction::class, 'transaction_id');
    }
}
