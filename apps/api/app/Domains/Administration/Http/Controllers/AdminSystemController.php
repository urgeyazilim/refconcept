<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Controllers;

use App\Domains\Administration\Models\FeatureFlag;
use App\Domains\Administration\Models\SystemSetting;
use App\Domains\Administration\Services\Features;
use App\Domains\Administration\Services\PlatformSettings;
use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Identity\Models\User;
use App\Domains\Payments\Models\PaymentWebhookEvent;
use App\Domains\Payments\Services\WebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The platform's own knobs: flags, settings, and the work that failed.
 *
 * Permissions are enforced by the matrix middleware rather than here — flags and settings
 * need `platform.flags.manage`, which operators deliberately do not have, because turning
 * a feature on for everybody is a release decision rather than an operational one.
 *
 * Every change is audited with the old value and the new one. A settings table says what a
 * value is now; only the audit log can say what it was and who decided.
 */
final class AdminSystemController
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly WebhookProcessor $webhooks,
        private readonly PlatformSettings $settings,
        private readonly Features $features,
    ) {}

    // --- feature flags ------------------------------------------------------------

    public function flags(): JsonResponse
    {
        return $this->json([
            'data' => FeatureFlag::query()->orderBy('key')->get()->map->toArray()->all(),
        ]);
    }

    public function saveFlag(Request $request, ?FeatureFlag $flag = null): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:300'],
            'is_enabled' => ['sometimes', 'boolean'],
            'rollout_percentage' => ['sometimes', 'integer', 'min:0', 'max:100'],
        ]);

        $flag ??= new FeatureFlag;

        $before = [
            'is_enabled' => $flag->is_enabled,
            'rollout_percentage' => $flag->rollout_percentage,
        ];

        $flag->fill($validated + ['updated_by' => $this->user($request)->getKey()])->save();

        // Same reason as the settings: a flag flipped during an incident has to take
        // effect on the next click, not on the next minute.
        $this->features->forget($flag->key);

        $this->audit->record(
            action: 'platform.flag.saved',
            subject: $flag,
            changes: [
                'is_enabled' => [$before['is_enabled'], $flag->is_enabled],
                'rollout_percentage' => [$before['rollout_percentage'], $flag->rollout_percentage],
            ],
            context: ['key' => $flag->key],
            actor: $this->user($request),
        );

        return $this->json(['data' => $flag->toArray()], $flag->wasRecentlyCreated ? 201 : 200);
    }

    // --- system settings ------------------------------------------------------------

    public function settings(): JsonResponse
    {
        return $this->json([
            'data' => SystemSetting::query()->orderBy('group')->orderBy('key')->get()->map->toArray()->all(),
        ]);
    }

    /**
     * Changes one value, validated against the type it declares.
     *
     * @throws ValidationException
     */
    public function saveSetting(Request $request, SystemSetting $setting): JsonResponse
    {
        $validated = $request->validate([
            'value' => ['present', 'nullable'],
        ]);

        $value = $validated['value'];

        // Checked against the type rather than accepted as a string. A settings screen
        // that takes anything into any field will one day set a hold period to "yes".
        $this->assertMatchesType($setting->type, $value);

        $before = $setting->is_secret ? '(gizli)' : $setting->value;

        $setting->forceFill([
            'value' => $value === null ? null : (is_array($value) ? json_encode($value) : (string) $value),
            'updated_by' => $this->user($request)->getKey(),
        ])->save();

        // Cleared rather than waited out, so whoever just changed a hold period sees the
        // platform behave differently on their next click instead of a minute later.
        $this->settings->forget($setting->key);

        $this->audit->record(
            action: 'platform.setting.changed',
            subject: $setting,
            // A secret's value never enters the audit log either: an audit trail is read by
            // more people than a secret store is.
            changes: ['value' => [$before, $setting->is_secret ? '(gizli)' : $setting->value]],
            context: ['key' => $setting->key],
            actor: $this->user($request),
        );

        return $this->json(['data' => $setting->toArray()]);
    }

    // --- failed work --------------------------------------------------------------

    /**
     * Jobs that gave up, and webhooks that could not be understood.
     *
     * On one screen because they are the same question — "what did the platform fail to
     * finish" — and an operator who has to remember two places will check neither.
     */
    public function jobs(): JsonResponse
    {
        $failed = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit(100)
            ->get()
            ->map(static fn (object $row): array => [
                'id' => $row->id,
                'uuid' => $row->uuid,
                'queue' => $row->queue,
                // The class name only: a serialised payload can carry anything the job was
                // given, and this screen is read by more people than that payload was
                // intended for.
                'job' => json_decode($row->payload, true)['displayName'] ?? 'bilinmiyor',
                'error' => mb_substr((string) $row->exception, 0, 300),
                'failed_at' => $row->failed_at,
            ])
            ->all();

        $webhooks = PaymentWebhookEvent::query()
            ->whereIn('status', ['failed', 'received'])
            ->orderByDesc('received_at')
            ->limit(100)
            ->get()
            ->map(static fn (PaymentWebhookEvent $event): array => [
                'id' => $event->id,
                'gateway' => $event->gateway,
                'event_type' => $event->event_type,
                'status' => $event->status,
                'signature_verified' => $event->signature_verified,
                'attempts' => $event->attempts,
                'error_message' => $event->error_message,
                'received_at' => $event->received_at->toIso8601String(),
            ])
            ->all();

        return $this->json([
            'data' => [
                'failed_jobs' => $failed,
                'webhooks' => $webhooks,
                'failed_job_count' => DB::table('failed_jobs')->count(),
            ],
        ]);
    }

    /**
     * Runs a stored webhook again.
     *
     * Safe to press twice: the processor claims the row with a conditional update and the
     * payment state machine refuses a transition that has already happened.
     */
    public function replayWebhook(Request $request, PaymentWebhookEvent $event): JsonResponse
    {
        if (! $event->signature_verified) {
            // An unverified event was refused at the door for a reason; replaying it by
            // hand would be a way around the signature check rather than a repair.
            throw ValidationException::withMessages([
                'event' => ['İmzası doğrulanmamış bir bildirim yeniden işlenemez.'],
            ]);
        }

        $this->webhooks->process($event);

        $this->audit->record(
            action: 'platform.webhook.replayed',
            subject: $event->fresh(),
            context: ['gateway' => $event->gateway],
            actor: $this->user($request),
        );

        return $this->json(['data' => ['status' => $event->fresh()?->status]]);
    }

    // --- internals -----------------------------------------------------------

    /**
     * @throws ValidationException
     */
    private function assertMatchesType(string $type, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        $ok = match ($type) {
            'integer' => is_numeric($value) && (string) (int) $value === (string) $value,
            'boolean' => is_bool($value) || in_array($value, ['0', '1', 'true', 'false', 0, 1], true),
            'json' => is_array($value),
            default => is_string($value) || is_numeric($value),
        };

        if (! $ok) {
            throw ValidationException::withMessages([
                'value' => [sprintf('Bu ayar %s tipinde bir değer bekliyor.', $type)],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'no-store, private');
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
