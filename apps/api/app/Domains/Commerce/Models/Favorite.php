<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Products\Models\Product;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something a customer wanted to remember.
 *
 * Per product rather than per offer, deliberately. Favouriting a sofa means the sofa — not
 * one seller's listing of it — and a favourite that broke when that seller went out of
 * stock would be a promise the feature never made.
 *
 * @property string $id
 * @property string $user_id
 * @property string $product_id
 * @property Carbon $created_at
 */
class Favorite extends Model
{
    use HasUuidV7;

    protected $table = 'favorites';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['user_id', 'product_id', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
