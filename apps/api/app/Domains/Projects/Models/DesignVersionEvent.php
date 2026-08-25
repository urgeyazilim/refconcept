<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use App\Domains\Projects\Enums\GenerationStage;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of progress, as a customer sees it.
 *
 * A row per step rather than a status column that gets overwritten, because "it has been
 * on 'görsel üretiliyor' for forty seconds" and "it went straight there and stuck" are
 * different problems, and a single column cannot tell them apart.
 *
 * Carries nothing sensitive by construction: a stage, an outcome, and a short message in
 * Turkish. No prompt, no photograph, nothing about the room — this is the one part of the
 * pipeline a client polls constantly, and the cheapest way to keep it safe is to give it
 * nothing to leak.
 *
 * @property string $id
 * @property string $design_version_id
 * @property GenerationStage $stage
 * @property string $status
 * @property string $message
 * @property int|null $duration_ms
 * @property Carbon $created_at
 */
class DesignVersionEvent extends Model
{
    use HasUuidV7;

    protected $table = 'design_version_events';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'design_version_id',
        'stage',
        'status',
        'message',
        'duration_ms',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => GenerationStage::class,
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DesignVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DesignVersion::class, 'design_version_id');
    }
}
