<?php

declare(strict_types=1);

namespace App\Domains\Partners\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One partner API request.
 *
 * Enough to answer "is our integration working" without keeping anything sensitive:
 * the path but not the query string, the status but not the body. A seller debugging
 * a nightly sync needs to see that it ran and what it got back; nobody needs a copy
 * of what was in it.
 *
 * @property string $id
 * @property string|null $credential_id
 * @property string $method
 * @property string $path
 * @property int $status
 * @property int $duration_ms
 * @property string|null $ip
 * @property Carbon $created_at
 */
class ApiRequestLog extends Model
{
    use HasUuidV7;

    protected $table = 'api_request_logs';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'credential_id',
        'method',
        'path',
        'status',
        'duration_ms',
        'ip',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ApiCredential, $this> */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(ApiCredential::class, 'credential_id');
    }

    public function wasSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
