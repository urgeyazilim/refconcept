<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Identity\Http\Requests\StoreAddressRequest;
use App\Domains\Identity\Http\Requests\UpdateAddressRequest;
use App\Domains\Identity\Http\Resources\AddressResource;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * The signed-in user's address book.
 *
 * Ownership is enforced twice: the list query is scoped to the user, and every
 * single-address route goes through the UserAddress policy. Belt and braces, because
 * an address leak exposes a home address.
 */
final class AddressController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $addresses = $user->addresses()
            ->orderByDesc('is_default_shipping')
            ->orderByDesc('created_at')
            ->get();

        return AddressResource::collection($addresses);
    }

    public function store(StoreAddressRequest $request, AuditLogger $audit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        $address = DB::transaction(function () use ($user, $validated): UserAddress {
            $this->clearConflictingDefaults($user, $validated);

            // The very first address becomes the default for both purposes, so a
            // customer who added exactly one address never sees an empty checkout.
            $isFirst = ! $user->addresses()->exists();

            return UserAddress::query()->create([
                ...$validated,
                'user_id' => $user->getKey(),
                'is_default_shipping' => (bool) ($validated['is_default_shipping'] ?? $isFirst),
                'is_default_billing' => (bool) ($validated['is_default_billing'] ?? $isFirst),
            ]);
        });

        $audit->record(action: 'identity.address.created', subject: $address, actor: $user);

        return response()->json(['data' => new AddressResource($address)], 201);
    }

    public function show(Request $request, UserAddress $address): JsonResponse
    {
        $this->authorizeAddress($request, $address);

        return response()->json(['data' => new AddressResource($address)]);
    }

    public function update(UpdateAddressRequest $request, UserAddress $address, AuditLogger $audit): JsonResponse
    {
        $this->authorizeAddress($request, $address);

        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        DB::transaction(function () use ($user, $address, $validated): void {
            $this->clearConflictingDefaults($user, $validated, exceptId: (string) $address->getKey());

            $address->fill($validated)->save();
        });

        $audit->recordChange('identity.address.updated', $address);

        return response()->json(['data' => new AddressResource($address->fresh())]);
    }

    public function destroy(Request $request, UserAddress $address, AuditLogger $audit): JsonResponse
    {
        $this->authorizeAddress($request, $address);

        // Soft delete: past orders reference a snapshot, but support still needs to
        // see what the customer had entered when a delivery goes wrong.
        $address->delete();

        $audit->record(action: 'identity.address.deleted', subject: $address, actor: $request->user());

        return response()->json(['message' => 'Adres silindi.']);
    }

    private function authorizeAddress(Request $request, UserAddress $address): void
    {
        abort_unless($request->user()?->can('view', $address) === true, 403);
    }

    /**
     * Partial unique indexes guarantee one default of each kind per user, so the
     * previous default must be cleared in the same transaction as the new one is set.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function clearConflictingDefaults(User $user, array $attributes, ?string $exceptId = null): void
    {
        foreach (['is_default_shipping', 'is_default_billing'] as $flag) {
            if (($attributes[$flag] ?? false) !== true) {
                continue;
            }

            $query = $user->addresses()->where($flag, true);

            if ($exceptId !== null) {
                $query->whereKeyNot($exceptId);
            }

            $query->update([$flag => false]);
        }
    }
}
