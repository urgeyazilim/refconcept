<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Profile edits.
 *
 * E-mail, phone, status and roles are absent by design: changing an e-mail must go
 * through re-verification, and status/roles are administrative actions with their own
 * authorization and audit requirements.
 */
final class UpdateProfileRequest extends FormRequest
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
            'first_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'birth_date' => ['sometimes', 'nullable', 'date', 'before:today'],
            'marketing_opt_in' => ['sometimes', 'boolean'],
            'locale' => ['sometimes', 'string', Rule::in(['tr', 'en'])],
            'timezone' => ['sometimes', 'string', 'timezone'],
        ];
    }
}
