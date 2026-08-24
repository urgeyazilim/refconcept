<?php

declare(strict_types=1);

namespace App\Domains\Projects\Http\Controllers;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Identity\Models\User;
use App\Domains\Projects\Enums\ProjectRole;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sharing a project.
 *
 * A flat, a partner and sometimes an interior designer is the ordinary case. Invitations
 * go to an e-mail address rather than a user id, because the person you want to show
 * your living room to usually does not have an account yet.
 *
 * The invitation token is a bearer secret for photographs of somebody's home, so it is
 * handled like a password: random, hashed at rest, returned exactly once, and expiring
 * on its own.
 */
final class ProjectMemberController
{
    private const INVITATION_TTL_DAYS = 14;

    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request, Project $project): JsonResponse
    {
        abort_unless($request->user()?->can('invite', $project) === true, 403);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::enum(ProjectRole::class)],
        ]);

        $email = mb_strtolower(trim((string) $validated['email']));

        if ($email === mb_strtolower((string) $project->owner?->email)) {
            throw ValidationException::withMessages([
                'email' => ['Proje zaten sizin.'],
            ]);
        }

        $existing = ProjectMember::query()
            ->where('project_id', $project->getKey())
            ->whereRaw('lower(invited_email) = ?', [$email])
            ->where('status', '!=', 'revoked')
            ->first();

        $token = Str::random(64);

        if ($existing !== null) {
            // Inviting somebody twice is a resend, not a second seat. The old token
            // stops working, which is the right outcome for a link that may have gone
            // to the wrong address.
            $existing->forceFill([
                'role' => ProjectRole::from((string) $validated['role']),
                'invitation_token_hash' => Hash::make($token),
                'invitation_expires_at' => now()->addDays(self::INVITATION_TTL_DAYS),
            ])->save();

            $member = $existing;
        } else {
            $member = ProjectMember::query()->create([
                'project_id' => $project->getKey(),
                'invited_email' => $email,
                'role' => $validated['role'],
                'invitation_token_hash' => Hash::make($token),
                'invitation_expires_at' => now()->addDays(self::INVITATION_TTL_DAYS),
                'invited_by' => $request->user()->getKey(),
            ]);
        }

        $this->audit->record(
            action: 'projects.member.invited',
            subject: $member,
            context: ['project_id' => $project->getKey(), 'role' => $member->role->value],
            actor: $request->user(),
        );

        return response()->json([
            'data' => [
                ...$this->summary($member),

                // Returned once, to be put in the invitation e-mail. Phase 12 sends
                // that mail; until then the caller has the token and nothing else does.
                'invitation_token' => $token,
                'expires_at' => $member->invitation_expires_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Accepting an invitation.
     *
     * The e-mail on the invitation has to match the signed-in account: without that,
     * a forwarded link would let anybody who received it into somebody's home.
     */
    public function accept(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'member_id' => ['required', 'uuid'],
            'token' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $member = ProjectMember::query()->find($validated['member_id']);

        // One answer for every failure — unknown id, wrong token, expired, wrong
        // account — because distinguishing them tells a stranger which projects exist.
        $refuse = static fn (): never => throw ValidationException::withMessages([
            'token' => ['Bu davet geçerli değil ya da süresi dolmuş.'],
        ]);

        if ($member === null
            || $member->status !== 'invited'
            || $member->invitationHasExpired()
            || $member->invitation_token_hash === null
            || ! Hash::check((string) $validated['token'], $member->invitation_token_hash)
            || mb_strtolower((string) $user->email) !== mb_strtolower($member->invited_email)
        ) {
            $refuse();
        }

        $member->forceFill([
            'user_id' => $user->getKey(),
            'status' => 'active',
            'accepted_at' => now(),
            // Burned on use: the link in the mailbox stops working the moment it has
            // done its job.
            'invitation_token_hash' => null,
            'invitation_expires_at' => null,
        ])->save();

        $this->audit->record(
            action: 'projects.member.accepted',
            subject: $member,
            context: ['project_id' => $member->project_id],
            actor: $user,
        );

        return response()->json([
            'data' => ['project_id' => $member->project_id, ...$this->summary($member->fresh())],
        ]);
    }

    public function update(Request $request, Project $project, ProjectMember $member): JsonResponse
    {
        abort_unless($request->user()?->can('invite', $project) === true, 403);
        abort_unless($member->project_id === $project->getKey(), 404);

        $validated = $request->validate([
            'role' => ['required', Rule::enum(ProjectRole::class)],
        ]);

        $member->forceFill(['role' => ProjectRole::from((string) $validated['role'])])->save();

        return response()->json(['data' => $this->summary($member->fresh())]);
    }

    public function destroy(Request $request, Project $project, ProjectMember $member): JsonResponse
    {
        abort_unless($request->user()?->can('removeMember', $project) === true, 403);
        abort_unless($member->project_id === $project->getKey(), 404);

        // Revoked rather than deleted: who had access and when is worth being able to
        // answer, and the row is the only place that answer lives.
        $member->forceFill([
            'status' => 'revoked',
            'revoked_at' => now(),
            'invitation_token_hash' => null,
        ])->save();

        $this->audit->record(
            action: 'projects.member.revoked',
            subject: $member,
            context: ['project_id' => $project->getKey()],
            actor: $request->user(),
        );

        return response()->json(['message' => 'Erişim kaldırıldı.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(ProjectMember $member): array
    {
        return [
            'id' => $member->id,
            'email' => $member->invited_email,
            'role' => $member->role->value,
            'role_label' => $member->role->label(),
            'status' => $member->status,
            'accepted_at' => $member->accepted_at?->toIso8601String(),
        ];
    }
}
