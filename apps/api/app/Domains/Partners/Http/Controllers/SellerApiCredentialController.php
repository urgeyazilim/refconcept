<?php

declare(strict_types=1);

namespace App\Domains\Partners\Http\Controllers;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Identity\Services\AccessControl;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Partners\Models\ApiCredential;
use App\Domains\Partners\Models\ApiRequestLog;
use App\Domains\Partners\Services\CredentialIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A seller managing their own integration credentials.
 *
 * The secret appears in exactly one response — the creation one — and the copy shown
 * there is the only copy that will ever exist. That is stated in the response rather
 * than left for the seller to discover.
 */
final class SellerApiCredentialController
{
    public function __construct(
        private readonly CredentialIssuer $issuer,
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $organizationIds = $this->organizationIds($request);

        $credentials = ApiCredential::query()
            ->whereIn('organization_id', $organizationIds)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $credentials->map(fn (ApiCredential $credential): array => $this->summary($credential))->all(),
            'meta' => ['available_scopes' => ApiCredential::SCOPES],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $organizationIds = $this->organizationIds($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(ApiCredential::SCOPES)],
            'expires_in_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:730'],
            'rate_limit_per_minute' => ['sometimes', 'integer', 'min:10', 'max:6000'],
        ]);

        $organization = Organization::query()->findOrFail($organizationIds[0]);

        try {
            $issued = $this->issuer->issue(
                organization: $organization,
                name: (string) $validated['name'],
                scopes: $validated['scopes'],
                actor: $request->user(),
                expiresInDays: $validated['expires_in_days'] ?? null,
                rateLimitPerMinute: (int) ($validated['rate_limit_per_minute'] ?? 120),
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['scopes' => [$e->getMessage()]]);
        }

        $this->audit->record(
            action: 'partners.credential.issued',
            subject: $issued['credential'],
            context: ['scopes' => $validated['scopes']],
            actor: $request->user(),
            organizationId: $organization->getKey(),
        );

        return response()->json([
            'data' => [
                ...$this->summary($issued['credential']),

                // The only time this is ever returned. Said out loud in the payload so
                // a client cannot treat it as something it can fetch again later.
                'secret' => $issued['secret'],
                'secret_notice' => 'Bu gizli anahtar yalnızca bir kez gösterilir. Güvenli bir yere kaydedin.',
            ],
        ], 201);
    }

    public function destroy(Request $request, ApiCredential $credential): JsonResponse
    {
        $this->authorizeCredential($request, $credential);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:290'],
        ]);

        $this->issuer->revoke($credential, (string) $validated['reason']);

        $this->audit->record(
            action: 'partners.credential.revoked',
            subject: $credential,
            reason: (string) $validated['reason'],
            actor: $request->user(),
            organizationId: $credential->organization_id,
        );

        return response()->json(['message' => 'Kimlik bilgisi iptal edildi.']);
    }

    /** Recent requests made with this credential, so a seller can debug their own sync. */
    public function usage(Request $request, ApiCredential $credential): JsonResponse
    {
        $this->authorizeCredential($request, $credential);

        $logs = ApiRequestLog::query()
            ->where('credential_id', $credential->getKey())
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $logs->map(fn (ApiRequestLog $log): array => [
                'method' => $log->method,
                'path' => $log->path,
                'status' => $log->status,
                'ok' => $log->wasSuccessful(),
                'duration_ms' => $log->duration_ms,
                'created_at' => $log->created_at->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(ApiCredential $credential): array
    {
        return [
            'id' => $credential->id,
            'name' => $credential->name,
            'key_id' => $credential->key_id,
            'secret_hint' => '****'.$credential->secret_hint,
            'scopes' => $credential->scopes,
            'rate_limit_per_minute' => $credential->rate_limit_per_minute,
            'is_usable' => $credential->isUsable(),
            'last_used_at' => $credential->last_used_at?->toIso8601String(),
            'expires_at' => $credential->expires_at?->toIso8601String(),
            'revoked_at' => $credential->revoked_at?->toIso8601String(),
            'revoked_reason' => $credential->revoked_reason,
            'created_at' => $credential->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function organizationIds(Request $request): array
    {
        $ids = $this->access->organizationIds($request->user());

        abort_if($ids === [], 403, 'Satıcı hesabınız bulunmuyor.');

        return $ids;
    }

    private function authorizeCredential(Request $request, ApiCredential $credential): void
    {
        abort_unless(
            in_array($credential->organization_id, $this->organizationIds($request), true),
            404,
        );
    }
}
