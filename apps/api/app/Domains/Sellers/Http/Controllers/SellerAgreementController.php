<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Http\Controllers;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Sellers\Models\SellerAgreement;
use App\Domains\Sellers\Models\SellerAgreementAcceptance;
use App\Domains\Sellers\Models\SellerApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Agreements a seller must read and accept.
 *
 * Acceptance records the agreement's body checksum alongside the timestamp, address
 * and user agent. That is what makes it evidence: if the stored text were ever
 * altered, the checksum on the acceptance would no longer match, and the row still
 * says what the seller actually saw.
 */
final class SellerAgreementController
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** The agreements currently in force, newest version of each. */
    public function index(Request $request): JsonResponse
    {
        $application = $this->currentApplication($request);

        $agreements = SellerAgreement::query()
            ->effective()
            ->orderByDesc('effective_from')
            ->get()
            ->groupBy('code')
            ->map(fn ($versions) => $versions->first())
            ->values();

        $accepted = $application === null
            ? []
            : $application->acceptances()->pluck('agreement_id')->all();

        return response()->json([
            'data' => $agreements->map(fn (SellerAgreement $agreement): array => [
                'id' => $agreement->id,
                'code' => $agreement->code,
                'version' => $agreement->version,
                'title' => $agreement->title,
                'body' => $agreement->body,
                'is_mandatory' => $agreement->is_mandatory,
                'effective_from' => $agreement->effective_from->toIso8601String(),
                'accepted' => in_array($agreement->id, $accepted, true),
            ])->all(),
        ]);
    }

    public function accept(Request $request, SellerAgreement $agreement): JsonResponse
    {
        $application = $this->currentApplication($request);

        abort_if($application === null, 404, 'Satıcı başvurunuz bulunamadı.');
        abort_unless($request->user()?->can('update', $application) === true, 403);

        if ($agreement->effective_from->isFuture()) {
            throw ValidationException::withMessages([
                'agreement' => ['Bu sözleşme henüz yürürlükte değil.'],
            ]);
        }

        // Acceptances are immutable, so re-accepting is a no-op rather than an error:
        // a double-clicked button must not produce a 500.
        $existing = $application->acceptances()
            ->where('agreement_id', $agreement->getKey())
            ->first();

        if ($existing !== null) {
            return response()->json(['message' => 'Bu sözleşme zaten onaylanmış.']);
        }

        SellerAgreementAcceptance::query()->create([
            'application_id' => $application->getKey(),
            'agreement_id' => $agreement->getKey(),
            'accepted_by' => $request->user()->getKey(),
            'accepted_at' => now(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent() === null ? null : mb_substr($request->userAgent(), 0, 512),
            'body_checksum' => $agreement->bodyChecksum(),
        ]);

        $this->audit->record(
            action: 'sellers.agreement.accepted',
            subject: $application,
            context: [
                'agreement_code' => $agreement->code,
                'agreement_version' => $agreement->version,
            ],
            actor: $request->user(),
        );

        return response()->json(['message' => 'Sözleşme onaylandı.'], 201);
    }

    private function currentApplication(Request $request): ?SellerApplication
    {
        return SellerApplication::query()
            ->where('applicant_user_id', $request->user()?->getKey())
            ->orderByDesc('created_at')
            ->first();
    }
}
