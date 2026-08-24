<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\SystemRole;
use App\Domains\Identity\Models\User;
use App\Domains\Projects\Enums\ProjectRole;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMember;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;

/**
 * Who may see somebody's home.
 *
 * The strictest boundary in RefConcept, and the one where a mistake is least
 * recoverable: a leaked price is embarrassing, a leaked photograph of a child's bedroom
 * is not something an apology fixes. Every test here asks the same question from a
 * different angle — can somebody who was not invited get in.
 *
 * The super-admin case is the one worth reading twice. RefConcept has a `Gate::before`
 * bypass that gives platform staff everything, which is right for operational tables
 * and wrong for this one, and the exclusion is asserted here rather than assumed.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->owner = User::factory()->create();
    $this->stranger = User::factory()->create();
    $this->partner = User::factory()->create();

    $this->project = Project::factory()->ownedBy($this->owner)->withRoom()->create([
        'name' => 'Kadıköy Dairesi',
    ]);

    $this->room = $this->project->rooms()->firstOrFail();
});

/** Makes somebody an accepted member, the way accepting an invitation would. */
function addMember(Project $project, User $user, ProjectRole $role): ProjectMember
{
    $member = ProjectMember::query()->create([
        'project_id' => $project->getKey(),
        'user_id' => $user->getKey(),
        'invited_email' => $user->email,
        'role' => $role,
    ]);

    // Acceptance is not mass-assignable — the controller sets it after checking the
    // token — so the fixture takes the same route the accept endpoint does.
    $member->forceFill(['status' => 'active', 'accepted_at' => now()])->save();

    return $member;
}

// --- the basics -------------------------------------------------------------------

it('lists only the projects a person can actually see', function (): void {
    Project::factory()->ownedBy($this->stranger)->create(['name' => 'Başkasının Evi']);

    $response = $this->actingAs($this->owner)->getJson('/api/v1/projects')->assertOk();

    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['Kadıköy Dairesi']);
});

it('never lets a stranger open a project by id', function (): void {
    $this->actingAs($this->stranger)
        ->getJson("/api/v1/projects/{$this->project->getKey()}")
        ->assertForbidden();
});

it('never lets a stranger reach a room inside somebody else project', function (): void {
    $this->actingAs($this->stranger)
        ->getJson("/api/v1/projects/{$this->project->getKey()}/rooms/{$this->room->getKey()}")
        ->assertForbidden();
});

it('requires a verified e-mail before a project can exist at all', function (): void {
    $unverified = User::factory()->unverified()->create();

    // A project is where room photographs live, and an unverified address is not proof
    // of anything.
    $this->actingAs($unverified)
        ->postJson('/api/v1/projects', ['name' => 'Doğrulanmamış'])
        ->assertStatus(403);
});

// --- the super-admin exclusion ------------------------------------------------------

it('does not let platform staff open a customer project', function (): void {
    $admin = User::factory()->create();
    grantPlatformRole($admin, SystemRole::SuperAdmin);

    // The blanket Gate::before bypass deliberately does not apply here. An operator has
    // no operational reason to look inside somebody's flat, and "a super admin can see
    // everything" is how a support tool becomes the thing that leaks.
    $this->actingAs($admin)
        ->getJson("/api/v1/projects/{$this->project->getKey()}")
        ->assertForbidden();

    $this->actingAs($admin)
        ->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('still gives platform staff the bypass everywhere else', function (): void {
    $admin = User::factory()->create();
    grantPlatformRole($admin, SystemRole::SuperAdmin);

    // The exclusion is scoped to customer projects, not a removal of the bypass: an
    // operator must still be able to work the moderation queue.
    $this->actingAs($admin)->getJson('/api/v1/admin/products')->assertOk();
});

// --- sharing -------------------------------------------------------------------------

it('lets an invited viewer look but not touch', function (): void {
    addMember($this->project, $this->partner, ProjectRole::Viewer);

    $this->actingAs($this->partner)
        ->getJson("/api/v1/projects/{$this->project->getKey()}")
        ->assertOk();

    $this->actingAs($this->partner)
        ->patchJson("/api/v1/projects/{$this->project->getKey()}", ['name' => 'Yeni Ad'])
        ->assertForbidden();

    expect($this->project->fresh()->name)->toBe('Kadıköy Dairesi');
});

it('lets an invited editor change things', function (): void {
    addMember($this->project, $this->partner, ProjectRole::Editor);

    $this->actingAs($this->partner)
        ->patchJson("/api/v1/projects/{$this->project->getKey()}", ['name' => 'Ortak Karar'])
        ->assertOk();

    expect($this->project->fresh()->name)->toBe('Ortak Karar');
});

it('never lets an editor delete the project', function (): void {
    addMember($this->project, $this->partner, ProjectRole::Editor);

    // An editor who can delete is an editor who can delete somebody else's months of
    // work by accident, and there is no undo a customer would trust.
    $this->actingAs($this->partner)
        ->deleteJson("/api/v1/projects/{$this->project->getKey()}")
        ->assertForbidden();
});

it('never lets an editor invite anybody else', function (): void {
    addMember($this->project, $this->partner, ProjectRole::Editor);

    // A shared account that can give itself away is not shared, it is lost.
    $this->actingAs($this->partner)
        ->postJson("/api/v1/projects/{$this->project->getKey()}/members", [
            'email' => 'baskasi@example.com',
            'role' => 'editor',
        ])
        ->assertForbidden();
});

it('closes the door the moment access is revoked', function (): void {
    $member = addMember($this->project, $this->partner, ProjectRole::Editor);

    $this->actingAs($this->partner)->getJson("/api/v1/projects/{$this->project->getKey()}")->assertOk();

    $this->actingAs($this->owner)
        ->deleteJson("/api/v1/projects/{$this->project->getKey()}/members/{$member->getKey()}")
        ->assertOk();

    $this->actingAs($this->partner)
        ->getJson("/api/v1/projects/{$this->project->getKey()}")
        ->assertForbidden();
});

it('keeps a revoked membership on the record rather than deleting it', function (): void {
    $member = addMember($this->project, $this->partner, ProjectRole::Editor);

    $this->actingAs($this->owner)
        ->deleteJson("/api/v1/projects/{$this->project->getKey()}/members/{$member->getKey()}");

    // Who had access and when is worth being able to answer, and the row is the only
    // place that answer lives.
    expect($member->fresh()->status)->toBe('revoked')
        ->and($member->fresh()->revoked_at)->not->toBeNull();
});

// --- invitations ------------------------------------------------------------------------

it('returns the invitation token exactly once', function (): void {
    $response = $this->actingAs($this->owner)
        ->postJson("/api/v1/projects/{$this->project->getKey()}/members", [
            'email' => 'esim@example.com',
            'role' => 'editor',
        ])
        ->assertCreated();

    $token = $response->json('data.invitation_token');

    expect($token)->toBeString()->and(strlen($token))->toBe(64);

    // Hashed at rest, and never in a later response: the token is a bearer secret for
    // photographs of somebody's home.
    $member = ProjectMember::query()->firstOrFail();

    expect($member->invitation_token_hash)->not->toBe($token)
        ->and(Hash::check($token, $member->invitation_token_hash))->toBeTrue();

    $detail = $this->actingAs($this->owner)
        ->getJson("/api/v1/projects/{$this->project->getKey()}")
        ->assertOk();

    expect(json_encode($detail->json()))->not->toContain($token);
});

it('accepts an invitation and grants access', function (): void {
    $invitee = User::factory()->create(['email' => 'esim@example.com']);

    $token = $this->actingAs($this->owner)
        ->postJson("/api/v1/projects/{$this->project->getKey()}/members", [
            'email' => 'esim@example.com',
            'role' => 'viewer',
        ])
        ->json('data.invitation_token');

    $memberId = ProjectMember::query()->firstOrFail()->getKey();

    $this->actingAs($this->invitee ?? $invitee)
        ->postJson('/api/v1/projects/invitations/accept', ['member_id' => $memberId, 'token' => $token])
        ->assertOk();

    $this->actingAs($invitee)
        ->getJson("/api/v1/projects/{$this->project->getKey()}")
        ->assertOk();
});

it('refuses an invitation forwarded to somebody else', function (): void {
    User::factory()->create(['email' => 'esim@example.com']);

    $token = $this->actingAs($this->owner)
        ->postJson("/api/v1/projects/{$this->project->getKey()}/members", [
            'email' => 'esim@example.com',
            'role' => 'viewer',
        ])
        ->json('data.invitation_token');

    $memberId = ProjectMember::query()->firstOrFail()->getKey();

    // Without this check a forwarded link would let anybody who received it into
    // somebody's home.
    $this->actingAs($this->stranger)
        ->postJson('/api/v1/projects/invitations/accept', ['member_id' => $memberId, 'token' => $token])
        ->assertStatus(422);

    $this->actingAs($this->stranger)
        ->getJson("/api/v1/projects/{$this->project->getKey()}")
        ->assertForbidden();
});

it('refuses an invitation whose token is wrong', function (): void {
    $invitee = User::factory()->create(['email' => 'esim@example.com']);

    $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->getKey()}/members", [
        'email' => 'esim@example.com',
        'role' => 'viewer',
    ]);

    $memberId = ProjectMember::query()->firstOrFail()->getKey();

    $this->actingAs($invitee)
        ->postJson('/api/v1/projects/invitations/accept', ['member_id' => $memberId, 'token' => str_repeat('x', 64)])
        ->assertStatus(422);
});

it('refuses an invitation that has expired', function (): void {
    $invitee = User::factory()->create(['email' => 'esim@example.com']);

    $token = $this->actingAs($this->owner)
        ->postJson("/api/v1/projects/{$this->project->getKey()}/members", [
            'email' => 'esim@example.com',
            'role' => 'viewer',
        ])
        ->json('data.invitation_token');

    $memberId = ProjectMember::query()->firstOrFail()->getKey();

    $this->travel(15)->days();

    $this->actingAs($invitee)
        ->postJson('/api/v1/projects/invitations/accept', ['member_id' => $memberId, 'token' => $token])
        ->assertStatus(422);
});

it('burns the token once it has been used', function (): void {
    $invitee = User::factory()->create(['email' => 'esim@example.com']);

    $token = $this->actingAs($this->owner)
        ->postJson("/api/v1/projects/{$this->project->getKey()}/members", [
            'email' => 'esim@example.com',
            'role' => 'viewer',
        ])
        ->json('data.invitation_token');

    $memberId = ProjectMember::query()->firstOrFail()->getKey();

    $this->actingAs($invitee)
        ->postJson('/api/v1/projects/invitations/accept', ['member_id' => $memberId, 'token' => $token])
        ->assertOk();

    // The link in the mailbox stops working the moment it has done its job.
    expect(ProjectMember::query()->firstOrFail()->invitation_token_hash)->toBeNull();
});

it('treats a second invitation to the same person as a resend', function (): void {
    foreach (['viewer', 'editor'] as $role) {
        $this->actingAs($this->owner)
            ->postJson("/api/v1/projects/{$this->project->getKey()}/members", [
                'email' => 'esim@example.com',
                'role' => $role,
            ])
            ->assertCreated();
    }

    // One seat, with the newer role — not two rows and an ambiguous answer to "what
    // can this person do".
    expect(ProjectMember::query()->count())->toBe(1)
        ->and(ProjectMember::query()->firstOrFail()->role)->toBe(ProjectRole::Editor);
});

// --- archiving --------------------------------------------------------------------------

it('stops edits once a project is archived', function (): void {
    $this->actingAs($this->owner)
        ->patchJson("/api/v1/projects/{$this->project->getKey()}/status", ['status' => 'archived'])
        ->assertOk();

    $this->actingAs($this->owner)
        ->patchJson("/api/v1/projects/{$this->project->getKey()}", ['name' => 'Değişiklik'])
        ->assertForbidden();
});

it('lets an archived project be reopened', function (): void {
    $this->actingAs($this->owner)
        ->patchJson("/api/v1/projects/{$this->project->getKey()}/status", ['status' => 'archived']);

    // Archiving is tidying up, not deleting.
    $this->actingAs($this->owner)
        ->patchJson("/api/v1/projects/{$this->project->getKey()}/status", ['status' => 'active'])
        ->assertOk();

    expect($this->project->fresh()->status->value)->toBe('active');
});

it('records every status change', function (): void {
    $this->actingAs($this->owner)
        ->patchJson("/api/v1/projects/{$this->project->getKey()}/status", ['status' => 'completed']);

    $history = $this->project->statusHistory()->get();

    // A project completed in March and reopened in September is a different story from
    // one that has been open all year, and only the history can tell them apart. The
    // fixture was built by the factory rather than the endpoint, so the creation row
    // is absent and this one change is the whole history.
    expect($history)->toHaveCount(1)
        ->and($history->first()->from_status)->toBe('active')
        ->and($history->first()->to_status)->toBe('completed');
});

// --- addresses ----------------------------------------------------------------------------

it('refuses to attach somebody else address to a project', function (): void {
    $strangerAddress = $this->stranger->addresses()->create([
        'recipient_name' => 'Yabancı',
        'country_code' => 'TR',
        'city' => 'İzmir',
        'address_line1' => 'Bir Sokak 1',
    ]);

    // Otherwise the project detail response would hand back a stranger's city and
    // district to anybody who guessed an address id.
    $this->actingAs($this->owner)
        ->patchJson("/api/v1/projects/{$this->project->getKey()}", [
            'address_id' => $strangerAddress->getKey(),
        ])
        ->assertNotFound();
});
