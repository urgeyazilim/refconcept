<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Requests;

use App\Support\Validation\EmailRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * There is intentionally no `exists:users,email` rule: confirming whether an address
 * is registered is exactly the disclosure this endpoint must avoid.
 */
final class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => EmailRules::forLookup(),
        ];
    }

    public function email(): string
    {
        return mb_strtolower(trim((string) $this->validated('email')));
    }
}
