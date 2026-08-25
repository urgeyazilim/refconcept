<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Projects\Enums\DesignStatus;
use App\Domains\Projects\Enums\DesignVersionStatus;
use App\Domains\Projects\Exceptions\DesignVersionRefused;
use App\Domains\Projects\Models\Design;
use App\Domains\Projects\Models\DesignVersion;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\RoomMedia;
use App\Domains\Projects\Services\DesignVersionTree;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The version tree.
 *
 * A design is a tree because that is how people actually use one: generate a living
 * room, like it, ask for a darker sofa, dislike the result, want the first one back. A
 * flat list loses the first the moment the second exists.
 *
 * Three invariants are asserted here, and each is a bug that has happened somewhere
 * before: numbers never repeat even after a failure, only a finished version may be
 * branched from, and a finished version never changes.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->tree = app(DesignVersionTree::class);

    $this->owner = User::factory()->create();
    $this->stranger = User::factory()->create();

    $this->project = Project::factory()->ownedBy($this->owner)->withRoom()->create();
    $this->room = $this->project->rooms()->firstOrFail();

    // A photograph, written directly: this suite is about the tree, and the upload
    // path has its own tests.
    $photo = RoomMedia::query()->create([
        'room_id' => $this->room->getKey(),
        'type' => 'photo',
        'disk' => 's3',
        'storage_path' => 'room-media/'.$this->room->getKey().'/'.Str::uuid7().'.jpg',
        'original_name' => 'oda.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 120_000,
        'width' => 1600,
        'height' => 1200,
        'checksum_sha256' => str_repeat('a', 64),
    ]);

    $this->room->forceFill(['primary_media_id' => $photo->getKey()])->save();

    $this->design = Design::query()->create([
        'room_id' => $this->room->getKey(),
        'name' => 'Oturma odası tasarımı',
        'created_by' => $this->owner->getKey(),
    ]);

    $this->designUrl = "/api/v1/projects/{$this->project->getKey()}/rooms/{$this->room->getKey()}/designs";
});

/** Creates a version and marks it finished, the way the engine will from Phase 8. */
function readyVersion(?DesignVersion $parent = null, ?string $prompt = null): DesignVersion
{
    $version = test()->tree->branch(
        design: test()->design,
        parent: $parent,
        actor: test()->owner,
        userPrompt: $prompt,
    );

    test()->tree->markGenerating($version);

    return test()->tree->markReady($version);
}

// --- numbering ---------------------------------------------------------------------

it('numbers versions from one, in order', function (): void {
    $first = readyVersion();
    $second = readyVersion($first, 'Kanepeyi koyulaştır');

    expect($first->version_number)->toBe(1)
        ->and($second->version_number)->toBe(2);
});

it('never reuses the number of a failed attempt', function (): void {
    $first = readyVersion();

    $failed = $this->tree->branch($this->design, $first, $this->owner, 'Olmadı');
    $this->tree->markFailed($failed, 'Sağlayıcı yanıt vermedi.');

    $third = readyVersion($first, 'Tekrar dene');

    // "v4" has to mean the same thing to a customer tomorrow as it does today.
    // Reusing 2 would make a support conversation about two different v2s.
    expect($failed->version_number)->toBe(2)
        ->and($third->version_number)->toBe(3);
});

it('refuses a duplicate version number at the storage layer', function (): void {
    readyVersion();

    // The service picks the number under a row lock. The unique index is what makes a
    // double click impossible rather than merely unlikely.
    expect(fn () => DesignVersion::query()->create([
        'design_id' => $this->design->getKey(),
        'version_number' => 1,
    ]))->toThrow(QueryException::class);
});

// --- branching ----------------------------------------------------------------------

it('makes a refinement a child rather than a replacement', function (): void {
    $first = readyVersion();
    $second = readyVersion($first, 'Kanepeyi koyulaştır');

    expect($second->parent_version_id)->toBe($first->getKey())
        // The first attempt is still there, which is the entire point.
        ->and($first->fresh()->status)->toBe(DesignVersionStatus::Ready)
        ->and($first->fresh()->children()->count())->toBe(1);
});

it('lets two different refinements branch from the same version', function (): void {
    $first = readyVersion();

    $warm = readyVersion($first, 'Daha sıcak tonlar');
    $cool = readyVersion($first, 'Daha soğuk tonlar');

    // Comparing two directions from one starting point is the normal case, not an
    // edge case a customer has to work around.
    expect($first->fresh()->children()->pluck('id')->all())
        ->toContain($warm->getKey())
        ->toContain($cool->getKey());
});

it('refuses to branch from an attempt that never finished', function (): void {
    $pending = $this->tree->branch($this->design, null, $this->owner);

    // Refining a half-generated attempt asks the engine to improve an image nobody has.
    expect(fn () => $this->tree->branch($this->design, $pending, $this->owner, 'Devam et'))
        ->toThrow(DesignVersionRefused::class);
});

it('refuses to branch from a failed attempt', function (): void {
    $failed = $this->tree->branch($this->design, null, $this->owner);
    $this->tree->markFailed($failed, 'Sağlayıcı yanıt vermedi.');

    expect(fn () => $this->tree->branch($this->design, $failed, $this->owner, 'Devam et'))
        ->toThrow(DesignVersionRefused::class);
});

it('refuses a parent that belongs to another design', function (): void {
    $other = Design::query()->create([
        'room_id' => $this->room->getKey(),
        'name' => 'Başka tasarım',
        'created_by' => $this->owner->getKey(),
    ]);

    $foreign = $this->tree->branch($other, null, $this->owner);
    $this->tree->markGenerating($foreign);
    $this->tree->markReady($foreign);

    // Growing one room's tree from another's render is not a feature, it is a mix-up.
    expect(fn () => $this->tree->branch($this->design, $foreign, $this->owner, 'Karıştır'))
        ->toThrow(DesignVersionRefused::class);
});

it('refuses to design a room with no photograph', function (): void {
    $this->room->forceFill(['primary_media_id' => null])->save();

    // The engine works from the room the customer actually has, and there is no way to
    // invent one.
    expect(fn () => $this->tree->branch($this->design->fresh(), null, $this->owner))
        ->toThrow(DesignVersionRefused::class);
});

it('refuses to design inside an archived project', function (): void {
    $this->project->forceFill(['status' => 'archived'])->save();

    expect(fn () => $this->tree->branch($this->design->fresh(), null, $this->owner))
        ->toThrow(DesignVersionRefused::class);
});

// --- immutability ------------------------------------------------------------------------

it('never lets a finished version change again', function (): void {
    $version = readyVersion();

    // Re-running produces a sibling. That is what makes "I preferred the third one"
    // actionable rather than nostalgic.
    expect(fn () => $this->tree->markGenerating($version))
        ->toThrow(DesignVersionRefused::class);

    expect(fn () => $this->tree->markFailed($version, 'Fikrimi değiştirdim'))
        ->toThrow(DesignVersionRefused::class);
});

it('refuses a ready version with no completion time at the storage layer', function (): void {
    $version = $this->tree->branch($this->design, null, $this->owner);

    // "ready" is a claim; completed_at is the evidence, and the constraint is what
    // stops the two drifting apart.
    expect(fn () => DB::table('design_versions')
        ->where('id', $version->getKey())
        ->update(['status' => 'ready', 'completed_at' => null]))
        ->toThrow(QueryException::class);
});

it('refuses a failed version with no reason at the storage layer', function (): void {
    $version = $this->tree->branch($this->design, null, $this->owner);

    // Otherwise "failed" is a status nobody can act on.
    expect(fn () => DB::table('design_versions')
        ->where('id', $version->getKey())
        ->update(['status' => 'failed', 'failure_reason' => null]))
        ->toThrow(QueryException::class);
});

it('refuses a version that is its own parent', function (): void {
    $version = $this->tree->branch($this->design, null, $this->owner);

    expect(fn () => DB::table('design_versions')
        ->where('id', $version->getKey())
        ->update(['parent_version_id' => $version->getKey()]))
        ->toThrow(QueryException::class);
});

// --- what the customer is looking at --------------------------------------------------------

it('shows the newest finished version by default', function (): void {
    readyVersion();
    $second = readyVersion(DesignVersion::query()->where('version_number', 1)->first(), 'Koyulaştır');

    expect($this->design->fresh()->current_version_id)->toBe($second->getKey())
        ->and($this->design->fresh()->status)->toBe(DesignStatus::Ready);
});

it('lets a customer go back to an earlier version', function (): void {
    $first = readyVersion();
    readyVersion($first, 'Koyulaştır');

    $this->tree->setCurrent($this->design->fresh(), $first);

    // Going back after five attempts is the normal case, not an undo — it is what the
    // whole tree exists to allow.
    expect($this->design->fresh()->current_version_id)->toBe($first->getKey());
});

it('does not let a failed refinement make a working design look broken', function (): void {
    $first = readyVersion();

    $failed = $this->tree->branch($this->design, $first, $this->owner, 'Olmadı');
    $this->tree->markFailed($failed, 'Sağlayıcı yanıt vermedi.');

    // One failed refinement on a design that already has a good image is a failed
    // refinement, not a failed design.
    expect($this->design->fresh()->status)->toBe(DesignStatus::Ready);
});

it('reports the whole tree from one query', function (): void {
    $first = readyVersion();
    $warm = readyVersion($first, 'Sıcak');
    readyVersion($warm, 'Daha da sıcak');
    readyVersion($first, 'Soğuk');

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $tree = $this->tree->tree($this->design->fresh());

    // A design with twenty versions must not be twenty round trips to draw one screen.
    expect($queries)->toBeLessThanOrEqual(3)
        ->and($tree)->toHaveCount(1)
        ->and($tree[0]['children'])->toHaveCount(2)
        ->and($tree[0]['children'][0]['children'])->toHaveCount(1);
});

it('remembers every prompt that shaped an image', function (): void {
    $first = readyVersion(null, 'İskandinav, açık renk');
    $second = readyVersion($first, 'Kanepeyi koyulaştır');
    $third = readyVersion($second, 'Halıyı değiştir');

    $chain = array_map(
        static fn (DesignVersion $v): ?string => $v->user_prompt,
        $third->fresh()->ancestry(),
    );

    // What a customer means by "how did I get here".
    expect($chain)->toBe(['İskandinav, açık renk', 'Kanepeyi koyulaştır', 'Halıyı değiştir']);
});

// --- through the API ---------------------------------------------------------------------------

it('creates a design and its first version in one request', function (): void {
    $response = $this->actingAs($this->owner)
        ->postJson($this->designUrl, ['name' => 'Yeni deneme', 'user_prompt' => 'Sıcak minimal'])
        ->assertCreated();

    $version = DesignVersion::query()->orderByDesc('id')->firstOrFail();

    expect($response->json('data.version_count'))->toBe(1)
        ->and($version->design_id)->not->toBeNull();

    /*
     * This suite deliberately configures no AI routes, so the engine has nothing to run
     * the version with — and the version says so in words a customer can read rather than
     * sitting at "pending" forever. The generation path itself is exercised in
     * DesignGenerationTest, which does configure it.
     */
    expect($version->status)->toBe(DesignVersionStatus::Failed)
        ->and($version->failure_reason)->toContain('kullanılamıyor');
});

it('refuses to create a design for a room with no photograph', function (): void {
    $this->room->forceFill(['primary_media_id' => null])->save();

    $this->actingAs($this->owner)
        ->postJson($this->designUrl, [])
        ->assertStatus(422);
});

it('never lets a stranger branch somebody else design', function (): void {
    $first = readyVersion();

    $this->actingAs($this->stranger)
        ->postJson("{$this->designUrl}/{$this->design->getKey()}/branch", [
            'parent_version_id' => $first->getKey(),
            'user_prompt' => 'Değiştir',
        ])
        ->assertForbidden();

    expect(DesignVersion::query()->count())->toBe(1);
});

it('never lets a viewer branch a design they can only look at', function (): void {
    $viewer = User::factory()->create();

    $member = $this->project->members()->create([
        'invited_email' => $viewer->email,
        'user_id' => $viewer->getKey(),
        'role' => 'viewer',
    ]);

    $member->forceFill(['status' => 'active', 'accepted_at' => now()])->save();

    $first = readyVersion();

    $this->actingAs($viewer)
        ->getJson("{$this->designUrl}/{$this->design->getKey()}")
        ->assertOk();

    // Generating costs credits. Somebody invited to look at a design should not be
    // able to spend the owner's money on it.
    $this->actingAs($viewer)
        ->postJson("{$this->designUrl}/{$this->design->getKey()}/branch", [
            'parent_version_id' => $first->getKey(),
            'user_prompt' => 'Değiştir',
        ])
        ->assertForbidden();
});
