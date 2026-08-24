<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Http\Requests;

use App\Domains\Sellers\Enums\TaxpayerType;
use App\Support\ValueObjects\Iban;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates one section of the onboarding form.
 *
 * The rule set is chosen from the `{section}` route segment, so each section gets
 * exactly the rules it needs and an unknown section is rejected before any handler
 * runs — rather than falling through to a partial save.
 */
final class UpdateApplicationSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ((string) $this->route('section')) {
            'legal-entity' => $this->legalEntityRules(),
            'tax-profile' => $this->taxProfileRules(),
            'contact' => $this->contactRules(),
            'address' => $this->addressRules(),
            'bank-account' => $this->bankAccountRules(),
            default => ['section' => ['prohibited']],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function legalEntityRules(): array
    {
        return [
            'legal_name' => ['required', 'string', 'max:250'],
            'tax_office' => ['nullable', 'string', 'max:120'],

            // Identifiers, not numbers: fixed length, digits only, leading zeros kept.
            'tax_number' => ['nullable', 'string', 'digits:10'],
            'national_id' => ['nullable', 'string', 'digits:11'],
            'mersis_number' => ['nullable', 'string', 'digits:16'],
            'trade_registry_number' => ['nullable', 'string', 'max:40'],
            'kep_address' => ['nullable', 'string', 'email:rfc', 'max:160'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function taxProfileRules(): array
    {
        return [
            'taxpayer_type' => ['required', Rule::enum(TaxpayerType::class)],

            // Basis points, so 20% arrives as 2000 and no float ever enters the system.
            'default_vat_rate_bps' => ['required', 'integer', 'min:0', 'max:10000'],
            'e_invoice_enabled' => ['sometimes', 'boolean'],
            'e_archive_enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contactRules(): array
    {
        return [
            'type' => ['required', Rule::in(['primary', 'finance', 'logistics', 'technical', 'legal'])],
            'full_name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'title' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function addressRules(): array
    {
        return [
            'type' => ['required', Rule::in(['registered', 'warehouse', 'billing', 'return'])],
            'country_code' => ['sometimes', 'string', 'size:2'],
            'city' => ['required', 'string', 'max:120'],
            'district' => ['nullable', 'string', 'max:120'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bankAccountRules(): array
    {
        return [
            'account_holder' => ['required', 'string', 'max:200'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'currency' => ['sometimes', 'string', 'size:3'],

            /*
             * The mod-97 check is the whole point: it catches the single-character and
             * transposition mistakes people actually make typing an IBAN. Without it a
             * seller's payouts silently go nowhere, or somewhere else.
             */
            'iban' => [
                'required',
                'string',
                'max:42',
                function (string $attribute, mixed $value, callable $fail): void {
                    if (! is_string($value) || ! Iban::isValid($value)) {
                        $fail('Geçerli bir IBAN girin.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tax_number.digits' => 'Vergi numarası 10 haneli olmalıdır.',
            'national_id.digits' => 'T.C. kimlik numarası 11 haneli olmalıdır.',
            'mersis_number.digits' => 'MERSİS numarası 16 haneli olmalıdır.',
        ];
    }
}
