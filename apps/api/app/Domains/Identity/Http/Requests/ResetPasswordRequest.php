<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Requests;

use App\Support\Validation\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

final class ResetPasswordRequest extends FormRequest
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
            'token' => ['required', 'string', 'max:255'],
            'password' => PasswordRules::forNewPassword(),
        ];
    }
}
