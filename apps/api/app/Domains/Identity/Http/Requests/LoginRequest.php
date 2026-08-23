<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Requests;

use App\Domains\Identity\DTOs\LoginData;
use App\Support\Validation\EmailRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a login payload.
 *
 * Deliberately loose on the password field: applying the strength rules here would
 * reject an old-but-valid password and tell an attacker the policy before they
 * authenticate.
 */
final class LoginRequest extends FormRequest
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
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:160'],
        ];
    }

    public function toData(): LoginData
    {
        return new LoginData(
            email: mb_strtolower(trim((string) $this->validated('email'))),
            password: (string) $this->validated('password'),
            deviceName: $this->validated('device_name'),
            ipAddress: $this->ip(),
            userAgent: $this->userAgent(),
        );
    }
}
