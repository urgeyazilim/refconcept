<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    public const DEFAULT_PASSWORD = 'RefConcept2026!';

    protected $model = User::class;

    /**
     * Hashing is deliberately expensive, so the default password is hashed once per
     * process instead of once per user; a suite that builds hundreds of accounts
     * would otherwise spend most of its runtime inside bcrypt.
     */
    private static ?string $cachedPassword = null;

    /**
     * Only mass-assignable attributes belong here. `password_hash` and
     * `email_verified_at` are intentionally not fillable — nothing arriving from a
     * request may set them — so the factory assigns them in configure() instead.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => null,
            'status' => UserStatus::Active,
            'locale' => 'tr',
            'timezone' => 'Europe/Istanbul',
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (User $user): void {
            $user->password_hash ??= self::$cachedPassword ??= Hash::make(self::DEFAULT_PASSWORD);

            // An active account that has never verified its address is a state the
            // application itself cannot produce, so the factory does not produce it either.
            if ($user->status === UserStatus::Active && $user->email_verified_at === null) {
                $user->email_verified_at = now();
            }
        })->afterCreating(function (User $user): void {
            /*
             * Reload so the instance carries every column, including the ones the
             * factory never assigned. Model::shouldBeStrict() throws on reading an
             * attribute that was never retrieved, so a partially hydrated model handed
             * to actingAs() fails in the resource layer rather than in the test.
             */
            $user->refresh();
        });
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => ['status' => UserStatus::PendingVerification]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => UserStatus::Suspended]);
    }

    public function banned(): static
    {
        return $this->state(fn (): array => ['status' => UserStatus::Banned]);
    }

    public function withPassword(string $plaintext): static
    {
        return $this->afterMaking(function (User $user) use ($plaintext): void {
            $user->password_hash = Hash::make($plaintext);
        });
    }

    public function withProfile(?string $firstName = null, ?string $lastName = null): static
    {
        return $this->afterCreating(function (User $user) use ($firstName, $lastName): void {
            $first = $firstName ?? $this->faker->firstName();
            $last = $lastName ?? $this->faker->lastName();

            UserProfile::query()->create([
                'user_id' => $user->getKey(),
                'first_name' => $first,
                'last_name' => $last,
                'display_name' => $first.' '.$last,
            ]);
        });
    }
}
