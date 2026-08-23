<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Identity\Http\Requests\UpdateProfileRequest;
use App\Domains\Identity\Http\Resources\UserResource;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The signed-in user's own profile.
 *
 * Scoped to `$request->user()` throughout: there is no id in the route, so there is
 * no path by which one account can edit another.
 */
final class ProfileController
{
    public function show(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => new UserResource($user->load('profile')),
        ]);
    }

    public function update(UpdateProfileRequest $request, AuditLogger $audit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        DB::transaction(function () use ($user, $validated): void {
            $profileAttributes = array_intersect_key($validated, array_flip([
                'first_name',
                'last_name',
                'display_name',
                'birth_date',
                'marketing_opt_in',
            ]));

            if ($profileAttributes !== []) {
                UserProfile::query()->updateOrCreate(
                    ['user_id' => $user->getKey()],
                    $profileAttributes,
                );
            }

            $userAttributes = array_intersect_key($validated, array_flip(['locale', 'timezone']));

            if ($userAttributes !== []) {
                $user->fill($userAttributes)->save();
            }
        });

        $audit->record(
            action: 'identity.profile.updated',
            subject: $user,
            // Field names only: profile values are personal data and the trail is read
            // by support staff who have no business seeing a birth date they did not ask for.
            context: ['fields' => array_keys($validated)],
            actor: $user,
        );

        return response()->json([
            'message' => 'Profiliniz güncellendi.',
            'data' => new UserResource($user->fresh(['profile'])),
        ]);
    }
}
