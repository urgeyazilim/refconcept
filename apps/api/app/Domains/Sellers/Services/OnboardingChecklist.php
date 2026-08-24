<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Services;

use App\Domains\Sellers\Enums\DocumentStatus;
use App\Domains\Sellers\Enums\DocumentType;
use App\Domains\Sellers\Enums\OnboardingStep;
use App\Domains\Sellers\Enums\TaxpayerType;
use App\Domains\Sellers\Models\SellerAgreement;
use App\Domains\Sellers\Models\SellerApplication;

/**
 * Decides which onboarding steps an application has actually completed.
 *
 * Completion is **derived from the data**, never stored as a flag. A stored flag can
 * be set by a bug, a partial save or a well-meaning operator, and then the portal
 * cheerfully reports "bank account: done" for an application with no IBAN — right up
 * until the first payout fails. Recomputing costs one eager-loaded query and removes
 * a whole class of lie.
 *
 * The same object answers two questions: what the seller still has to do (portal
 * progress) and whether submission is allowed (the guard in the workflow). Those must
 * never disagree, which is why there is one implementation.
 */
final class OnboardingChecklist
{
    /**
     * @return array<string, array{step: string, label: string, completed: bool, detail: string|null}>
     */
    public function forApplication(SellerApplication $application): array
    {
        $application->loadMissing([
            'legalEntity',
            'taxProfile',
            'contacts',
            'addresses',
            'bankAccounts',
            'documents',
            'acceptances',
        ]);

        $results = [];

        foreach (OnboardingStep::cases() as $step) {
            [$completed, $detail] = $this->evaluate($application, $step);

            $results[$step->value] = [
                'step' => $step->value,
                'label' => $step->label(),
                'completed' => $completed,
                'detail' => $detail,
            ];
        }

        return $results;
    }

    /**
     * Steps still outstanding, in checklist order.
     *
     * @return array<int, string>
     */
    public function missingSteps(SellerApplication $application): array
    {
        $missing = [];

        foreach ($this->forApplication($application) as $step) {
            if (! $step['completed']) {
                $missing[] = $step['label'];
            }
        }

        return $missing;
    }

    public function isComplete(SellerApplication $application): bool
    {
        return $this->missingSteps($application) === [];
    }

    public function completionPercent(SellerApplication $application): int
    {
        $steps = $this->forApplication($application);
        $done = count(array_filter($steps, static fn (array $step): bool => $step['completed']));

        return (int) round(($done / max(1, count($steps))) * 100);
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private function evaluate(SellerApplication $application, OnboardingStep $step): array
    {
        return match ($step) {
            OnboardingStep::Company => [
                $application->company_name !== '' && $application->contact_email !== '',
                null,
            ],

            OnboardingStep::LegalEntity => $this->evaluateLegalEntity($application),

            OnboardingStep::Contacts => [
                $application->contacts->contains(fn ($contact): bool => $contact->type === 'primary'),
                'Birincil iletişim kişisi zorunludur.',
            ],

            OnboardingStep::Address => [
                $application->addresses->contains(fn ($address): bool => $address->type === 'registered'),
                'Kayıtlı adres zorunludur.',
            ],

            OnboardingStep::BankAccount => [
                $application->bankAccounts->contains(fn ($account): bool => $account->is_primary),
                'Hakediş ödemeleri için birincil banka hesabı zorunludur.',
            ],

            OnboardingStep::TaxProfile => [
                $application->taxProfile !== null,
                'Vergi profili zorunludur.',
            ],

            OnboardingStep::Documents => $this->evaluateDocuments($application),

            OnboardingStep::Agreements => $this->evaluateAgreements($application),
        };
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private function evaluateLegalEntity(SellerApplication $application): array
    {
        $entity = $application->legalEntity;

        if ($entity === null) {
            return [false, 'Yasal bilgiler girilmedi.'];
        }

        $taxpayerType = $application->taxProfile?->taxpayer_type;

        // An individual is identified by TCKN, a company by VKN. Demanding the wrong
        // one blocks a legitimate applicant from ever completing onboarding.
        if ($taxpayerType === TaxpayerType::Individual) {
            return [
                $entity->national_id !== null && $entity->national_id !== '',
                'Bireysel satıcı için T.C. kimlik numarası zorunludur.',
            ];
        }

        return [
            $entity->tax_number !== null && $entity->tax_number !== '',
            'Vergi numarası zorunludur.',
        ];
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private function evaluateDocuments(SellerApplication $application): array
    {
        $taxpayerType = $application->taxProfile?->taxpayer_type;

        if ($taxpayerType === null) {
            return [false, 'Önce vergi profilini tamamlayın.'];
        }

        $required = DocumentType::requiredFor($taxpayerType);

        // A rejected document does not count as supplied; the seller must replace it.
        $supplied = $application->documents
            ->reject(fn ($document): bool => $document->status === DocumentStatus::Rejected)
            ->pluck('type')
            ->all();

        $missing = [];

        foreach ($required as $type) {
            if (! in_array($type, $supplied, true)) {
                $missing[] = $type->label();
            }
        }

        return [
            $missing === [],
            $missing === [] ? null : 'Eksik belgeler: '.implode(', ', $missing),
        ];
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private function evaluateAgreements(SellerApplication $application): array
    {
        $mandatory = SellerAgreement::query()
            ->effective()
            ->where('is_mandatory', true)
            ->get()
            // Only the newest version of each agreement code is binding; older versions
            // stay in the table as the evidence trail for past acceptances.
            ->groupBy('code')
            ->map(fn ($versions) => $versions->sortByDesc('effective_from')->first());

        $accepted = $application->acceptances->pluck('agreement_id')->all();

        $missing = $mandatory
            ->reject(fn ($agreement): bool => in_array($agreement->id, $accepted, true))
            ->pluck('title')
            ->all();

        return [
            $missing === [],
            $missing === [] ? null : 'Onaylanmayan sözleşmeler: '.implode(', ', $missing),
        ];
    }
}
