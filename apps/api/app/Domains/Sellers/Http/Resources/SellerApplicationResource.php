<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Http\Resources;

use App\Domains\Sellers\Models\SellerApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public shape of a seller application.
 *
 * Note what is absent: the IBAN appears only as its masked last four digits, and a
 * document's storage path never appears at all. Both are deliberate — this resource
 * is the boundary that keeps them from leaking into a response, a log or a cache.
 *
 * @mixin SellerApplication
 */
final class SellerApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_name' => $this->company_name,
            'display_name' => $this->display_name,
            'legal_form' => $this->legal_form,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'website' => $this->website,
            'product_categories' => $this->product_categories,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_editable' => $this->status->isEditable(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'decision_reason' => $this->decision_reason,
            'created_at' => $this->created_at?->toIso8601String(),

            'legal_entity' => $this->whenLoaded('legalEntity', fn (): ?array => $this->legalEntity === null ? null : [
                'legal_name' => $this->legalEntity->legal_name,
                'tax_office' => $this->legalEntity->tax_office,
                'tax_number' => $this->legalEntity->tax_number,
                'national_id' => $this->legalEntity->national_id,
                'mersis_number' => $this->legalEntity->mersis_number,
                'trade_registry_number' => $this->legalEntity->trade_registry_number,
                'kep_address' => $this->legalEntity->kep_address,
            ]),

            'tax_profile' => $this->whenLoaded('taxProfile', fn (): ?array => $this->taxProfile === null ? null : [
                'taxpayer_type' => $this->taxProfile->taxpayer_type->value,
                'taxpayer_type_label' => $this->taxProfile->taxpayer_type->label(),
                'default_vat_rate_bps' => $this->taxProfile->default_vat_rate_bps,
                'e_invoice_enabled' => $this->taxProfile->e_invoice_enabled,
                'e_archive_enabled' => $this->taxProfile->e_archive_enabled,
            ]),

            'contacts' => $this->whenLoaded('contacts', fn (): array => $this->contacts
                ->map(fn ($contact): array => [
                    'id' => $contact->id,
                    'type' => $contact->type,
                    'full_name' => $contact->full_name,
                    'email' => $contact->email,
                    'phone' => $contact->phone,
                    'title' => $contact->title,
                ])->all()),

            'addresses' => $this->whenLoaded('addresses', fn (): array => $this->addresses
                ->map(fn ($address): array => [
                    'id' => $address->id,
                    'type' => $address->type,
                    'country_code' => $address->country_code,
                    'city' => $address->city,
                    'district' => $address->district,
                    'address_line1' => $address->address_line1,
                    'address_line2' => $address->address_line2,
                    'postal_code' => $address->postal_code,
                ])->all()),

            // Masked only. The plaintext IBAN never leaves the encrypted column.
            'bank_accounts' => $this->whenLoaded('bankAccounts', fn (): array => $this->bankAccounts
                ->map(fn ($account): array => [
                    'id' => $account->id,
                    'account_holder' => $account->account_holder,
                    'bank_name' => $account->bank_name,
                    'iban_masked' => $account->maskedIban(),
                    'currency' => $account->currency,
                    'is_primary' => $account->is_primary,
                ])->all()),

            'documents' => $this->whenLoaded('documents', fn (): array => $this->documents
                ->map(fn ($document): array => [
                    'id' => $document->id,
                    'type' => $document->type->value,
                    'type_label' => $document->type->label(),
                    'original_name' => $document->original_name,
                    'size_bytes' => $document->size_bytes,
                    'status' => $document->status->value,
                    'status_label' => $document->status->label(),
                    'review_note' => $document->review_note,
                    'uploaded_at' => $document->created_at?->toIso8601String(),
                ])->all()),

            'accepted_agreement_ids' => $this->whenLoaded(
                'acceptances',
                fn (): array => $this->acceptances->pluck('agreement_id')->all(),
            ),
        ];
    }
}
