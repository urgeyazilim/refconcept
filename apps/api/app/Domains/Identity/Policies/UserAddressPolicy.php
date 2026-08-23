<?php

declare(strict_types=1);

namespace App\Domains\Identity\Policies;

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserAddress;

/**
 * An address belongs to exactly one account and is visible to nobody else.
 *
 * Platform staff are not exempted here: reading a customer's home address is a
 * support action that must go through an audited admin endpoint, not through the
 * customer-facing address routes.
 */
final class UserAddressPolicy
{
    public function view(User $user, UserAddress $address): bool
    {
        return $this->owns($user, $address);
    }

    public function update(User $user, UserAddress $address): bool
    {
        return $this->owns($user, $address);
    }

    public function delete(User $user, UserAddress $address): bool
    {
        return $this->owns($user, $address);
    }

    private function owns(User $user, UserAddress $address): bool
    {
        return $address->user_id === $user->getKey();
    }
}
