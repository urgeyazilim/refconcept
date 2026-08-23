<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Resources;

use App\Domains\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public shape of a user.
 *
 * Everything a client receives about an account passes through here, which is what
 * keeps `password_hash`, raw tokens and internal columns from leaking through a
 * forgotten `->toArray()` somewhere.
 *
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'email_verified' => $this->email_verified_at !== null,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'phone_verified' => $this->phone_verified_at !== null,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            'profile' => $this->whenLoaded('profile', fn (): array => [
                'first_name' => $this->profile->first_name,
                'last_name' => $this->profile->last_name,
                'display_name' => $this->profile->display_name,
                'avatar_path' => $this->profile->avatar_path,
                'birth_date' => $this->profile->birth_date?->toDateString(),
                'marketing_opt_in' => $this->profile->marketing_opt_in,
            ]),
        ];
    }
}
