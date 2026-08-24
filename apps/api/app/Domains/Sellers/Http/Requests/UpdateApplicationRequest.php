<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateApplicationRequest extends FormRequest
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
        return [
            'company_name' => ['sometimes', 'string', 'max:200'],
            'display_name' => ['sometimes', 'string', 'max:160'],
            'legal_form' => ['sometimes', 'string', Rule::in([
                'anonim_sirket', 'limited_sirket', 'sahis_sirketi', 'kollektif_sirket', 'diger',
            ])],
            'contact_email' => ['sometimes', 'string', 'email:rfc', 'max:255'],
            'contact_phone' => ['sometimes', 'string', 'max:32'],
            'website' => ['nullable', 'string', 'url', 'max:255'],
            'product_categories' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'company_name.required' => 'Firma unvanı zorunludur.',
            'display_name.required' => 'Mağaza adı zorunludur.',
        ];
    }
}
