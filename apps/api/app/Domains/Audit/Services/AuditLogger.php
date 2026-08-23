<?php

declare(strict_types=1);

namespace App\Domains\Audit\Services;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the audit trail.
 *
 * Two rules shape this class:
 *
 * 1. **Auditing must never break the action it observes.** A failure to write the
 *    trail is logged and swallowed, because losing an audit row is bad but rolling
 *    back a completed refund because the audit insert failed is worse.
 * 2. **Secrets never reach the trail.** Password hashes, tokens and card data are
 *    redacted before the change set is persisted.
 */
final class AuditLogger
{
    /** Attribute names whose values are replaced with a placeholder. */
    private const REDACTED = [
        'password',
        'password_hash',
        'password_confirmation',
        'token',
        'token_hash',
        'secret',
        'api_key',
        'secret_key',
        'card_number',
        'pan',
        'cvv',
        'cvc',
        'iban',
    ];

    private const PLACEHOLDER = '[redacted]';

    public function __construct(private readonly ?Request $request = null) {}

    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $action,
        ?Model $subject = null,
        array $changes = [],
        array $context = [],
        ?string $reason = null,
        ?User $actor = null,
        ?string $organizationId = null,
        string $actorType = 'user',
    ): ?AuditLog {
        try {
            $actor ??= $this->resolveActor();

            return AuditLog::query()->create([
                'actor_id' => $actor?->getKey(),
                'actor_type' => $actor !== null ? 'user' : $actorType,
                'actor_label' => $actor?->displayName(),
                'action' => $action,
                'auditable_type' => $subject !== null ? $subject::class : null,
                'auditable_id' => $subject?->getKey(),
                'organization_id' => $organizationId,
                'changes' => $changes === [] ? null : $this->redact($changes),
                'context' => $context === [] ? null : $this->redact($context),
                'reason' => $reason,
                'ip_address' => $this->request?->ip(),
                'user_agent' => $this->truncate($this->request?->userAgent()),
                'request_id' => $this->request?->header('X-Request-Id'),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // The audited action has already succeeded; do not undo it over a trail write.
            Log::error('Failed to write audit log entry', [
                'action' => $action,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Records a model change as before/after pairs, skipping attributes that did not
     * actually change — a diff full of unchanged values hides the one that matters.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordChange(
        string $action,
        Model $subject,
        ?string $reason = null,
        array $context = [],
        ?string $organizationId = null,
    ): ?AuditLog {
        $changes = [];

        foreach ($subject->getChanges() as $attribute => $newValue) {
            $changes[$attribute] = [
                'from' => $subject->getOriginal($attribute),
                'to' => $newValue,
            ];
        }

        return $this->record(
            action: $action,
            subject: $subject,
            changes: $changes,
            context: $context,
            reason: $reason,
            organizationId: $organizationId,
        );
    }

    private function resolveActor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function redact(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACTED, true)) {
                $result[$key] = self::PLACEHOLDER;

                continue;
            }

            $result[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $result;
    }

    private function truncate(?string $value, int $length = 512): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $length);
    }
}
