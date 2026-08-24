<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Http\Controllers;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Sellers\Enums\DocumentStatus;
use App\Domains\Sellers\Exceptions\InvalidTransition;
use App\Domains\Sellers\Http\Resources\SellerApplicationResource;
use App\Domains\Sellers\Models\SellerApplication;
use App\Domains\Sellers\Models\SellerDocument;
use App\Domains\Sellers\Services\ApplicationWorkflow;
use App\Domains\Sellers\Services\OnboardingChecklist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Platform review of seller applications.
 *
 * Every decision carries a mandatory reason. That is not paperwork: an approval or
 * rejection nobody can explain six months later is exactly what the audit rules in
 * 06_SECURITY_PAYMENT_FINANCE_RULES.md exist to prevent, and the database enforces
 * the same rule with a CHECK constraint.
 */
final class AdminSellerApplicationController
{
    public function __construct(
        private readonly ApplicationWorkflow $workflow,
        private readonly OnboardingChecklist $checklist,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->can('viewAny', SellerApplication::class) === true, 403);

        $validated = $request->validate([
            'status' => ['sometimes', 'string'],
            'search' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = SellerApplication::query()
            ->with(['applicant', 'legalEntity', 'taxProfile'])
            ->orderByDesc('submitted_at')
            ->orderByDesc('created_at');

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        } else {
            // The default view is the operator's queue, not every application ever made.
            $query->awaitingReview();
        }

        if (isset($validated['search'])) {
            $term = '%'.$validated['search'].'%';
            $query->where(function ($q) use ($term): void {
                $q->where('company_name', 'ilike', $term)
                    ->orWhere('display_name', 'ilike', $term)
                    ->orWhere('contact_email', 'ilike', $term);
            });
        }

        return SellerApplicationResource::collection(
            $query->paginate($validated['per_page'] ?? 20),
        );
    }

    public function show(Request $request, SellerApplication $application): JsonResponse
    {
        abort_unless($request->user()?->can('view', $application) === true, 403);

        $application->load([
            'applicant', 'legalEntity', 'taxProfile', 'contacts',
            'addresses', 'bankAccounts', 'documents', 'acceptances',
        ]);

        return response()->json([
            'data' => new SellerApplicationResource($application),
            'meta' => [
                'checklist' => array_values($this->checklist->forApplication($application)),
                'completion_percent' => $this->checklist->completionPercent($application),
            ],
        ]);
    }

    public function startReview(Request $request, SellerApplication $application): JsonResponse
    {
        abort_unless($request->user()?->can('decide', $application) === true, 403);

        try {
            $this->workflow->startReview($application, $request->user());
        } catch (InvalidTransition $e) {
            throw $e->toValidationException();
        }

        return response()->json([
            'message' => 'Başvuru incelemeye alındı.',
            'data' => new SellerApplicationResource($application->fresh()),
        ]);
    }

    public function approve(Request $request, SellerApplication $application): JsonResponse
    {
        abort_unless($request->user()?->can('decide', $application) === true, 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],

            // Basis points so the stored rate is exact; 12.5% is 1250, never 0.125.
            'commission_bps' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        try {
            $seller = $this->workflow->approve(
                application: $application,
                actor: $request->user(),
                reason: (string) $validated['reason'],
                commissionBps: $validated['commission_bps'] ?? null,
            );
        } catch (InvalidTransition $e) {
            throw $e->toValidationException();
        }

        return response()->json([
            'message' => 'Başvuru onaylandı, satıcı hesabı oluşturuldu.',
            'data' => [
                'seller_id' => $seller->id,
                'seller_code' => $seller->seller_code,
                'organization_id' => $seller->organization_id,
            ],
        ]);
    }

    public function reject(Request $request, SellerApplication $application): JsonResponse
    {
        abort_unless($request->user()?->can('decide', $application) === true, 403);

        $validated = $request->validate([
            // A rejection the applicant cannot act on just becomes a support ticket.
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        try {
            $this->workflow->reject($application, $request->user(), (string) $validated['reason']);
        } catch (InvalidTransition $e) {
            throw $e->toValidationException();
        }

        return response()->json([
            'message' => 'Başvuru reddedildi.',
            'data' => new SellerApplicationResource($application->fresh()),
        ]);
    }

    /** Marks one uploaded document approved or rejected during review. */
    public function reviewDocument(Request $request, SellerDocument $document): JsonResponse
    {
        $application = $document->application;

        abort_unless($request->user()?->can('reviewDocuments', $application) === true, 403);

        $validated = $request->validate([
            'status' => ['required', Rule::in([DocumentStatus::Approved->value, DocumentStatus::Rejected->value])],
            'note' => ['required_if:status,rejected', 'nullable', 'string', 'max:500'],
        ]);

        $document->forceFill([
            'status' => $validated['status'],
            'review_note' => $validated['note'] ?? null,
            'reviewed_by' => $request->user()->getKey(),
            'reviewed_at' => now(),
        ])->save();

        $this->audit->record(
            action: 'sellers.document.reviewed',
            subject: $document,
            context: ['status' => $validated['status']],
            reason: $validated['note'] ?? null,
            actor: $request->user(),
        );

        return response()->json(['message' => 'Belge durumu güncellendi.']);
    }
}
