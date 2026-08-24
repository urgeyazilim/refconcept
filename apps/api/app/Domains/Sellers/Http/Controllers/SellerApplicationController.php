<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Http\Controllers;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Sellers\Enums\ApplicationStatus;
use App\Domains\Sellers\Exceptions\InvalidTransition;
use App\Domains\Sellers\Http\Requests\StoreApplicationRequest;
use App\Domains\Sellers\Http\Requests\UpdateApplicationRequest;
use App\Domains\Sellers\Http\Requests\UpdateApplicationSectionRequest;
use App\Domains\Sellers\Http\Resources\SellerApplicationResource;
use App\Domains\Sellers\Models\SellerApplication;
use App\Domains\Sellers\Services\ApplicationWorkflow;
use App\Domains\Sellers\Services\OnboardingChecklist;
use App\Support\ValueObjects\Iban;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The applicant's side of seller onboarding.
 *
 * Every route resolves the application from the signed-in user rather than from an id
 * in the path, so one applicant can never address another's file. Status is only ever
 * changed through {@see ApplicationWorkflow}.
 */
final class SellerApplicationController
{
    public function __construct(
        private readonly OnboardingChecklist $checklist,
        private readonly ApplicationWorkflow $workflow,
        private readonly AuditLogger $audit,
    ) {}

    /** The applicant's current application, if they have one. */
    public function show(Request $request): JsonResponse
    {
        $application = $this->currentApplication($request);

        if ($application === null) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => new SellerApplicationResource($application),
            'meta' => $this->meta($application),
        ]);
    }

    public function store(StoreApplicationRequest $request): JsonResponse
    {
        $user = $request->user();

        // A partial unique index enforces this too; checking here turns a database
        // exception into a message the applicant can act on.
        $existing = SellerApplication::query()
            ->where('applicant_user_id', $user->getKey())
            ->whereIn('status', [
                ApplicationStatus::Draft->value,
                ApplicationStatus::Submitted->value,
                ApplicationStatus::InReview->value,
            ])
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'application' => ['Zaten devam eden bir satıcı başvurunuz var.'],
            ]);
        }

        $application = SellerApplication::query()->create([
            ...$request->validated(),
            'applicant_user_id' => $user->getKey(),
        ]);

        $this->audit->record(
            action: 'sellers.application.created',
            subject: $application,
            actor: $user,
        );

        return response()->json([
            'data' => new SellerApplicationResource($application),
            'meta' => $this->meta($application),
        ], 201);
    }

    public function update(UpdateApplicationRequest $request): JsonResponse
    {
        $application = $this->currentApplicationOrFail($request);
        $this->authorize($request, 'update', $application);

        $application->fill($request->validated())->save();

        return response()->json([
            'data' => new SellerApplicationResource($application->fresh()),
            'meta' => $this->meta($application),
        ]);
    }

    /**
     * Saves one section of the onboarding form.
     *
     * One endpoint rather than six because the sections are steps of a single form:
     * the applicant fills them in any order, and each save must re-evaluate the whole
     * checklist so the progress bar cannot drift from the data.
     */
    public function updateSection(UpdateApplicationSectionRequest $request, string $section): JsonResponse
    {
        $application = $this->currentApplicationOrFail($request);
        $this->authorize($request, 'update', $application);

        $data = $request->validated();

        DB::transaction(function () use ($application, $section, $data): void {
            match ($section) {
                'legal-entity' => $application->legalEntity()->updateOrCreate(
                    ['application_id' => $application->getKey()],
                    $data,
                ),

                'tax-profile' => $application->taxProfile()->updateOrCreate(
                    ['application_id' => $application->getKey()],
                    $data,
                ),

                // One contact and one address per type: a second "primary" contact
                // makes "who do we notify" ambiguous.
                'contact' => $application->contacts()->updateOrCreate(
                    ['application_id' => $application->getKey(), 'type' => $data['type']],
                    $data,
                ),

                'address' => $application->addresses()->updateOrCreate(
                    ['application_id' => $application->getKey(), 'type' => $data['type']],
                    $data,
                ),

                'bank-account' => $this->saveBankAccount($application, $data),

                default => throw ValidationException::withMessages([
                    'section' => ['Bilinmeyen bölüm.'],
                ]),
            };
        });

        $fresh = $application->fresh();

        $this->audit->record(
            action: 'sellers.application.section_updated',
            subject: $application,
            context: ['section' => $section],
            actor: $request->user(),
        );

        return response()->json([
            'data' => new SellerApplicationResource($fresh),
            'meta' => $this->meta($fresh),
        ]);
    }

    public function submit(Request $request): JsonResponse
    {
        $application = $this->currentApplicationOrFail($request);
        $this->authorize($request, 'submit', $application);

        try {
            $this->workflow->submit($application, $request->user());
        } catch (InvalidTransition $e) {
            throw $e->toValidationException();
        }

        return response()->json([
            'message' => 'Başvurunuz incelemeye gönderildi.',
            'data' => new SellerApplicationResource($application->fresh()),
            'meta' => $this->meta($application->fresh()),
        ]);
    }

    public function withdraw(Request $request): JsonResponse
    {
        $application = $this->currentApplicationOrFail($request);
        $this->authorize($request, 'withdraw', $application);

        try {
            $this->workflow->withdraw($application, $request->user());
        } catch (InvalidTransition $e) {
            throw $e->toValidationException();
        }

        return response()->json(['message' => 'Başvurunuz geri çekildi.']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveBankAccount(SellerApplication $application, array $data): void
    {
        // Validated in the form request, so construction cannot fail here; the value
        // object is what guarantees the ciphertext, mask and fingerprint agree.
        $iban = Iban::fromString((string) $data['iban']);

        $account = $application->bankAccounts()->firstOrNew(['is_primary' => true]);

        $account->fill([
            'application_id' => $application->getKey(),
            'account_holder' => $data['account_holder'],
            'bank_name' => $data['bank_name'] ?? null,
            'currency' => $data['currency'] ?? 'TRY',
            'is_primary' => true,
        ]);

        $account->setIban($iban);
        $account->save();
    }

    private function currentApplication(Request $request): ?SellerApplication
    {
        return SellerApplication::query()
            ->with(['legalEntity', 'taxProfile', 'contacts', 'addresses', 'bankAccounts', 'documents', 'acceptances'])
            ->where('applicant_user_id', $request->user()?->getKey())
            ->orderByDesc('created_at')
            ->first();
    }

    private function currentApplicationOrFail(Request $request): SellerApplication
    {
        $application = $this->currentApplication($request);

        abort_if($application === null, 404, 'Satıcı başvurunuz bulunamadı.');

        return $application;
    }

    private function authorize(Request $request, string $ability, SellerApplication $application): void
    {
        abort_unless($request->user()?->can($ability, $application) === true, 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(SellerApplication $application): array
    {
        return [
            'checklist' => array_values($this->checklist->forApplication($application)),
            'completion_percent' => $this->checklist->completionPercent($application),
            'can_submit' => $application->isEditable() && $this->checklist->isComplete($application),
        ];
    }
}
