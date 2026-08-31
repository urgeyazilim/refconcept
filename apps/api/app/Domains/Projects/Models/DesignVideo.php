<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use App\Domains\Ai\Models\AiJob;
use App\Domains\Credits\Models\CreditReservation;
use App\Domains\Identity\Models\User;
use App\Domains\Projects\Enums\DesignVersionStatus;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One film of one design, and everything that happened while it was being made.
 *
 * Separate from the file it produces for the same reason {@see DesignVersion} is separate
 * from {@see DesignAsset}: the file is what exists at the end, and the customer is looking
 * at the two minutes before that. A row here exists from the moment the button is pressed,
 * so "üretiliyor" is something a client can poll rather than an absence it has to guess at.
 *
 * The status enum is shared with a design version rather than copied. The states are the
 * same four states and they mean the same four things — accepted, running, done, failed —
 * and two enums with identical cases would be two places to translate the same word.
 *
 * @property string $id
 * @property string $design_version_id
 * @property DesignVersionStatus $status
 * @property string|null $requested_by
 * @property int $duration_seconds
 * @property int $credit_cost
 * @property string|null $credit_reservation_id
 * @property string|null $ai_job_id
 * @property string|null $asset_id
 * @property string|null $failure_reason
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DesignVideo extends Model
{
    use HasUuidV7;

    protected $table = 'design_videos';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'duration_seconds' => 8,
    ];

    /** @var list<string> */
    protected $fillable = [
        'design_version_id',
        'status',
        'requested_by',
        'duration_seconds',
        'credit_cost',
        'credit_reservation_id',
        'ai_job_id',
        'asset_id',
        'failure_reason',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DesignVersionStatus::class,
            'duration_seconds' => 'integer',
            'credit_cost' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DesignVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DesignVersion::class, 'design_version_id');
    }

    /** @return BelongsTo<DesignAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(DesignAsset::class, 'asset_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<CreditReservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(CreditReservation::class, 'credit_reservation_id');
    }

    /** @return BelongsTo<AiJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(AiJob::class, 'ai_job_id');
    }

    /** Whether another film may be started for this design. */
    public function isInFlight(): bool
    {
        return ! $this->status->isTerminal();
    }
}
