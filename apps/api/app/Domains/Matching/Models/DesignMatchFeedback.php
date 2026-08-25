<?php

declare(strict_types=1);

namespace App\Domains\Matching\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Matching\Enums\FeedbackVerdict;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A customer's verdict on one suggestion.
 *
 * The only honest measure of whether matching works. Similarity scores and rerank
 * confidence are the system marking its own homework; this is somebody looking at a sofa
 * and saying "not that one".
 *
 * Append-only, and every verdict is kept rather than the latest overwriting the last: a
 * customer who says "too expensive" and then "wrong style" has said two things, and only
 * one of them is about the price.
 *
 * @property string $id
 * @property string $match_id
 * @property string|null $user_id
 * @property FeedbackVerdict $verdict
 * @property string|null $reason_code
 * @property string|null $note
 * @property Carbon $created_at
 */
class DesignMatchFeedback extends Model
{
    use HasUuidV7;

    protected $table = 'design_match_feedback';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['match_id', 'user_id', 'verdict', 'reason_code', 'note', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['verdict' => FeedbackVerdict::class, 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<DesignMatch, $this> */
    public function match(): BelongsTo
    {
        return $this->belongsTo(DesignMatch::class, 'match_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  Builder<$this>  $query */
    public function scopeSince(Builder $query, Carbon $from): void
    {
        $query->where('created_at', '>=', $from);
    }
}
