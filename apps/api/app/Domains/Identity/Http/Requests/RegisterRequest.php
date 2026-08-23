<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Requests;

use App\Domains\Identity\DTOs\ConsentData;
use App\Domains\Identity\DTOs\RegistrationData;
use App\Domains\Identity\Enums\ConsentType;
use App\Support\Validation\EmailRules;
use App\Support\Validation\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a registration payload.
 *
 * Registration is public, so this is the only place standing between an anonymous
 * request and a row in `users`.
 */
final class RegisterRequest extends FormRequest
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
            'email' => [...EmailRules::forRegistration(), Rule::unique('users', 'email')],
            'password' => PasswordRules::forNewPassword(),

            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32', Rule::unique('users', 'phone')],

            'locale' => ['sometimes', 'string', Rule::in(['tr', 'en'])],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'marketing_opt_in' => ['sometimes', 'boolean'],
            'device_name' => ['nullable', 'string', 'max:160'],

            'consents' => ['required', 'array', 'min:1'],
            'consents.*.type' => ['required', 'string', Rule::enum(ConsentType::class)],
            'consents.*.version' => ['required', 'string', 'max:40'],
            'consents.*.granted' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The mandatory consents cannot be expressed as per-field rules, because they are
     * a condition on the *set*: privacy notice and terms must both be present and
     * granted. Marketing consent is deliberately not required.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var array<int, array{type?: string, granted?: bool}> $consents */
                $consents = $this->input('consents', []);

                $granted = [];

                foreach ($consents as $consent) {
                    if (($consent['granted'] ?? true) === true && isset($consent['type'])) {
                        $granted[] = $consent['type'];
                    }
                }

                foreach (ConsentType::requiredForRegistration() as $required) {
                    if (! in_array($required->value, $granted, true)) {
                        $validator->errors()->add(
                            'consents',
                            "Kayıt için '{$required->value}' onayı zorunludur."
                        );
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Bu e-posta adresi zaten kayıtlı.',
            'email.email' => 'Geçerli bir e-posta adresi girin.',
            'consents.required' => 'Gizlilik bildirimi ve kullanım koşulları onayı zorunludur.',
        ];
    }

    public function toData(): RegistrationData
    {
        /** @var array<int, array{type: string, version: string, granted?: bool}> $consents */
        $consents = $this->validated('consents');

        return new RegistrationData(
            email: mb_strtolower(trim((string) $this->validated('email'))),
            password: (string) $this->validated('password'),
            consents: array_map(ConsentData::fromArray(...), $consents),
            firstName: $this->validated('first_name'),
            lastName: $this->validated('last_name'),
            phone: $this->validated('phone'),
            locale: (string) ($this->validated('locale') ?? 'tr'),
            timezone: (string) ($this->validated('timezone') ?? 'Europe/Istanbul'),
            marketingOptIn: (bool) ($this->validated('marketing_opt_in') ?? false),
            ipAddress: $this->ip(),
            userAgent: $this->userAgent(),
            deviceName: $this->validated('device_name'),
        );
    }
}
