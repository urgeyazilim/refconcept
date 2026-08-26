<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Http\Controllers;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Models\Seller;
use App\Domains\Sellers\Services\ApplicationWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform administration of approved sellers.
 *
 * Suspension and reactivation are the high-risk actions here. Both demand a reason,
 * both are recorded in `seller_status_history` and the audit log, and neither is
 * available to the seller themselves — a seller who could lift their own suspension
 * would make suspension meaningless.
 */
final class AdminSellerController
{
    public function __construct(
        private readonly ApplicationWorkflow $workflow,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('viewAny', Seller::class) === true, 403);

        $validated = $request->validate([
            'status' => ['sometimes', 'string'],
            'search' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Seller::query()
            ->with('organization')
            ->orderByDesc('created_at');

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['search'])) {
            $term = '%'.$validated['search'].'%';
            $query->where(function ($q) use ($term): void {
                $q->where('display_name', 'ilike', $term)
                    ->orWhere('seller_code', 'ilike', $term);
            });
        }

        $sellers = $query->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'data' => collect($sellers->items())->map(fn (Seller $seller): array => [
                'id' => $seller->id,
                'seller_code' => $seller->seller_code,
                'display_name' => $seller->display_name,
                'status' => $seller->status->value,
                'status_label' => $seller->status->label(),
                'organization_id' => $seller->organization_id,
                'default_commission_bps' => $seller->default_commission_bps,
                'effective_commission_bps' => $seller->effectiveCommissionBps(),
                'approved_at' => $seller->approved_at?->toIso8601String(),
                'suspended_at' => $seller->suspended_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'current_page' => $sellers->currentPage(),
                'last_page' => $sellers->lastPage(),
                'total' => $sellers->total(),
            ],
        ]);
    }

    /**
     * A seller's own record, for the seller.
     *
     * A separate route rather than a shared one under `/admin`, because the two audiences
     * are answered by different questions. An administrative endpoint asks "does this
     * person hold the platform permission"; this one asks "is this their seller". Serving
     * both from one path meant the second could only be expressed as an exception to the
     * first, and an authorisation rule with an exception in it is a rule nobody can state.
     *
     * The policy still decides. The route only settles which question is being asked.
     */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $seller = Seller::query()
            ->whereHas('organization.memberships', fn ($query) => $query->where('user_id', $user->getKey()))
            ->first();

        abort_if($seller === null, 404, 'Bu hesaba bağlı bir satıcı kaydı yok.');

        return $this->sellerPayload($request, $seller);
    }

    public function show(Request $request, Seller $seller): JsonResponse
    {
        return $this->sellerPayload($request, $seller);
    }

    private function sellerPayload(Request $request, Seller $seller): JsonResponse
    {
        abort_unless($request->user()?->can('view', $seller) === true, 403);

        $seller->load(['organization', 'application', 'statusHistory.changedBy']);

        return response()->json([
            'data' => [
                'id' => $seller->id,
                'seller_code' => $seller->seller_code,
                'display_name' => $seller->display_name,
                'status' => $seller->status->value,
                'status_label' => $seller->status->label(),
                'organization' => [
                    'id' => $seller->organization?->id,
                    'name' => $seller->organization?->name,
                    'slug' => $seller->organization?->slug,
                ],
                'default_commission_bps' => $seller->default_commission_bps,
                'effective_commission_bps' => $seller->effectiveCommissionBps(),
                'approved_at' => $seller->approved_at?->toIso8601String(),
                'suspended_at' => $seller->suspended_at?->toIso8601String(),
                'status_history' => $seller->statusHistory
                    ->sortByDesc('changed_at')
                    ->values()
                    ->map(fn ($entry): array => [
                        'from' => $entry->from_status,
                        'to' => $entry->to_status,
                        'reason' => $entry->reason,
                        'changed_by' => $entry->changedBy?->displayName(),
                        'changed_at' => $entry->changed_at->toIso8601String(),
                    ])->all(),
            ],
        ]);
    }

    public function suspend(Request $request, Seller $seller): JsonResponse
    {
        abort_unless($request->user()?->can('suspend', $seller) === true, 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $this->workflow->suspendSeller($seller, $request->user(), (string) $validated['reason']);

        return response()->json(['message' => 'Satıcı askıya alındı.']);
    }

    public function reactivate(Request $request, Seller $seller): JsonResponse
    {
        abort_unless($request->user()?->can('reactivate', $seller) === true, 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $this->workflow->reactivateSeller($seller, $request->user(), (string) $validated['reason']);

        return response()->json(['message' => 'Satıcı yeniden aktifleştirildi.']);
    }

    public function setCommission(Request $request, Seller $seller): JsonResponse
    {
        abort_unless($request->user()?->can('setCommission', $seller) === true, 403);

        $validated = $request->validate([
            'commission_bps' => ['required', 'nullable', 'integer', 'min:0', 'max:10000'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $previous = $seller->default_commission_bps;

        $seller->forceFill(['default_commission_bps' => $validated['commission_bps']])->save();

        // A commission override is listed as a high-risk admin action; it changes what
        // every future order pays the platform, so it is audited like a payout.
        $this->audit->record(
            action: 'sellers.seller.commission_changed',
            subject: $seller,
            changes: ['default_commission_bps' => ['from' => $previous, 'to' => $validated['commission_bps']]],
            reason: (string) $validated['reason'],
            actor: $request->user(),
            organizationId: $seller->organization_id,
        );

        return response()->json(['message' => 'Komisyon oranı güncellendi.']);
    }
}
