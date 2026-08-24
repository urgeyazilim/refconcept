<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Enums\ApplicationStatus;
use App\Domains\Sellers\Enums\DocumentType;
use App\Domains\Sellers\Enums\TaxpayerType;
use App\Domains\Sellers\Models\SellerAgreement;
use App\Domains\Sellers\Models\SellerApplication;
use App\Support\ValueObjects\Iban;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SellerApplication>
 */
final class SellerApplicationFactory extends Factory
{
    protected $model = SellerApplication::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = $this->faker->company();

        return [
            'applicant_user_id' => User::factory(),
            'company_name' => $company.' A.Ş.',
            'display_name' => $company,
            'legal_form' => 'anonim_sirket',
            'contact_email' => $this->faker->unique()->companyEmail(),
            'contact_phone' => '+90555'.$this->faker->numerify('#######'),
            'website' => null,
            'product_categories' => 'Mobilya, aydınlatma',
        ];
    }

    /**
     * Fills in every section so the application passes the submission guard.
     *
     * Used by tests that care about what happens *after* a complete application —
     * writing the same six sections inline in each of those tests would bury the
     * behaviour under setup.
     */
    public function complete(TaxpayerType $taxpayerType = TaxpayerType::Corporate): static
    {
        return $this->afterCreating(function (SellerApplication $application) use ($taxpayerType): void {
            $application->legalEntity()->create([
                'legal_name' => $application->company_name,
                'tax_office' => 'Kadıköy',
                'tax_number' => $taxpayerType === TaxpayerType::Individual ? null : '1234567890',
                'national_id' => $taxpayerType === TaxpayerType::Individual ? '12345678901' : null,
            ]);

            $application->taxProfile()->create([
                'taxpayer_type' => $taxpayerType,
                'default_vat_rate_bps' => 2000,
            ]);

            $application->contacts()->create([
                'type' => 'primary',
                'full_name' => 'Yetkili Kişi',
                'email' => $application->contact_email,
                'phone' => $application->contact_phone,
            ]);

            $application->addresses()->create([
                'type' => 'registered',
                'city' => 'İstanbul',
                'district' => 'Kadıköy',
                'address_line1' => 'Bağdat Caddesi 1',
            ]);

            $account = $application->bankAccounts()->make([
                'account_holder' => $application->company_name,
                'bank_name' => 'Demo Bank',
                'currency' => 'TRY',
                'is_primary' => true,
            ]);
            $account->application_id = $application->getKey();
            $account->setIban(Iban::fromString('TR330006100519786457841326'));
            $account->save();

            foreach (DocumentType::requiredFor($taxpayerType) as $type) {
                $application->documents()->create([
                    'type' => $type,
                    'original_name' => $type->value.'.pdf',
                    'storage_path' => 'seller-documents/'.$application->getKey().'/'.$type->value.'.pdf',
                    'mime_type' => 'application/pdf',
                    'size_bytes' => 1024,
                    'checksum_sha256' => str_repeat('a', 64),
                    'uploaded_by' => $application->applicant_user_id,
                ]);
            }

            foreach (SellerAgreement::query()->where('is_mandatory', true)->get() as $agreement) {
                $application->acceptances()->create([
                    'agreement_id' => $agreement->getKey(),
                    'accepted_by' => $application->applicant_user_id,
                    'accepted_at' => now(),
                    'body_checksum' => $agreement->bodyChecksum(),
                ]);
            }
        });
    }

    public function submitted(): static
    {
        return $this->afterCreating(function (SellerApplication $application): void {
            $application->forceFill([
                'status' => ApplicationStatus::Submitted,
                'submitted_at' => now(),
            ])->save();
        });
    }
}
