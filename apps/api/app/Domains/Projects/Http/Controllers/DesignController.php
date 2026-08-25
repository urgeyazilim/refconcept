<?php

declare(strict_types=1);

namespace App\Domains\Projects\Http\Controllers;

use App\Domains\Projects\Enums\RenderQuality;
use App\Domains\Projects\Exceptions\DesignVersionRefused;
use App\Domains\Projects\Models\Design;
use App\Domains\Projects\Models\DesignVersion;
use App\Domains\Projects\Models\DesignVersionEvent;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Room;
use App\Domains\Projects\Services\DesignVersionLauncher;
use App\Domains\Projects\Services\DesignVersionTree;
use App\Domains\Projects\Services\RoomPhotoStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Designs and their version trees.
 *
 * Creating a version is accepted and queued rather than performed: the generation
 * itself belongs to the AI gateway (Phase 6) and the design engine (Phase 8). What
 * exists here is the shape those phases fill in — the tree, its numbering, and the
 * rules about what may branch from what — and it is fully testable without either.
 *
 * A version is returned `pending`, which is honest: it has been accepted, nothing has
 * been drawn yet.
 */
final class DesignController
{
    public function __construct(
        private readonly DesignVersionTree $tree,
        private readonly DesignVersionLauncher $launcher,
        private readonly RoomPhotoStorage $storage,
    ) {}

    public function index(Request $request, Project $project, Room $room): JsonResponse
    {
        $this->authorizeProject($request, $project, 'view');
        $this->assertBelongs($room, $project);

        $room->loadMissing(['designs.currentVersion.assets', 'designs.versions']);

        return response()->json([
            'data' => $room->designs->map(fn (Design $design): array => $this->summary($design))->all(),
        ]);
    }

    public function store(Request $request, Project $project, Room $room): JsonResponse
    {
        $this->authorizeProject($request, $project);
        $this->assertBelongs($room, $project);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'style_code' => ['sometimes', 'nullable', 'string', 'max:60'],
            'user_prompt' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'render_quality' => ['sometimes', Rule::enum(RenderQuality::class)],
        ]);

        abort_unless(
            $room->isReadyForDesign(),
            422,
            'Tasarım oluşturmadan önce odanın fotoğrafını yüklemeniz gerekiyor.',
        );

        $design = Design::query()->create([
            'room_id' => $room->getKey(),
            'name' => $validated['name'] ?? $this->defaultName($room),
            'created_by' => $request->user()->getKey(),
        ]);

        try {
            $version = $this->launcher->launch(
                design: $design,
                parent: null,
                actor: $request->user(),
                quality: RenderQuality::from((string) ($validated['render_quality'] ?? 'draft')),
                userPrompt: $validated['user_prompt'] ?? null,
                styleCode: $validated['style_code'] ?? null,
            );
        } catch (DesignVersionRefused $e) {
            throw $e->toValidationException();
        }

        return response()->json([
            'data' => $this->detail($design->fresh()),
            'version_id' => $version->getKey(),
        ], 201);
    }

    public function show(Request $request, Project $project, Room $room, Design $design): JsonResponse
    {
        $this->authorizeProject($request, $project, 'view');
        $this->assertBelongs($room, $project);
        $this->assertDesignBelongs($design, $room);

        return response()->json(['data' => $this->detail($design)]);
    }

    /**
     * A refinement: "make the sofa darker".
     *
     * Produces a *child* of the version being looked at rather than replacing it, so
     * the customer can always get back to the one they liked.
     */
    public function branch(Request $request, Project $project, Room $room, Design $design): JsonResponse
    {
        $this->authorizeProject($request, $project);
        $this->assertBelongs($room, $project);
        $this->assertDesignBelongs($design, $room);

        $validated = $request->validate([
            'parent_version_id' => ['required', 'uuid'],
            'user_prompt' => ['required', 'string', 'min:3', 'max:2000'],
            'style_code' => ['sometimes', 'nullable', 'string', 'max:60'],
            'render_quality' => ['sometimes', Rule::enum(RenderQuality::class)],
        ]);

        $parent = DesignVersion::query()->findOrFail($validated['parent_version_id']);

        try {
            $version = $this->launcher->launch(
                design: $design,
                parent: $parent,
                actor: $request->user(),
                quality: RenderQuality::from((string) ($validated['render_quality'] ?? 'draft')),
                userPrompt: (string) $validated['user_prompt'],
                styleCode: $validated['style_code'] ?? null,
            );
        } catch (DesignVersionRefused $e) {
            throw $e->toValidationException('parent_version_id');
        }

        return response()->json([
            'data' => $this->detail($design->fresh()),
            'version_id' => $version->getKey(),
        ], 201);
    }

    /** Going back to an earlier version — the point of keeping the tree. */
    public function setCurrentVersion(Request $request, Project $project, Room $room, Design $design): JsonResponse
    {
        $this->authorizeProject($request, $project);
        $this->assertBelongs($room, $project);
        $this->assertDesignBelongs($design, $room);

        $validated = $request->validate([
            'version_id' => ['required', 'uuid'],
        ]);

        $version = DesignVersion::query()->findOrFail($validated['version_id']);

        try {
            $this->tree->setCurrent($design, $version);
        } catch (DesignVersionRefused $e) {
            throw $e->toValidationException('version_id');
        }

        return response()->json(['data' => $this->detail($design->fresh())]);
    }

    /** One version in full, including the prompts that led to it. */
    public function version(
        Request $request,
        Project $project,
        Room $room,
        Design $design,
        DesignVersion $version,
    ): JsonResponse {
        $this->authorizeProject($request, $project, 'view');
        $this->assertBelongs($room, $project);
        $this->assertDesignBelongs($design, $room);
        abort_unless($version->design_id === $design->getKey(), 404);

        $version->loadMissing(['assets', 'author', 'plan', 'events']);

        return response()->json([
            'data' => [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'status' => $version->status->value,
                'status_label' => $version->status->label(),
                'style_code' => $version->style_code,
                'render_quality' => $version->render_quality->value,
                'render_quality_label' => $version->render_quality->label(),
                'user_prompt' => $version->user_prompt,
                'credit_cost' => $version->credit_cost,
                'failure_reason' => $version->failure_reason,

                /*
                 * The layout, not only the picture. This is what a customer reads when
                 * they ask why there is a sideboard there — and from Phase 9 it is what
                 * the shopping list beside the image is built from.
                 */
                'plan' => $version->plan === null ? null : [
                    'style' => $version->plan->style,
                    'palette' => $version->plan->palette,
                    'placements' => $version->plan->placements,
                    'notes' => $version->plan->notes,
                    // Said out loud rather than dropped: a plan that quietly loses a
                    // piece of furniture produces an image and a shopping list that
                    // disagree, and the customer is left to work out which is wrong.
                    'rejected' => $version->plan->rejected,
                ],

                'progress' => $this->progressOf($version),
                'author' => $version->author?->displayName(),
                'created_at' => $version->created_at?->toIso8601String(),
                'completed_at' => $version->completed_at?->toIso8601String(),

                // Every prompt that shaped this image, oldest first — what a customer
                // means by "how did I get here".
                'ancestry' => array_map(
                    static fn (DesignVersion $node): array => [
                        'id' => $node->id,
                        'version_number' => $node->version_number,
                        'user_prompt' => $node->user_prompt,
                    ],
                    $version->ancestry(),
                ),

                'assets' => $version->assets->map(fn ($asset): array => [
                    'id' => $asset->id,
                    'type' => $asset->type,
                    'width' => $asset->width,
                    'height' => $asset->height,
                    // A link, issued now because the caller has just been authorised.
                    'url' => $this->storage->temporaryAssetUrl($asset),
                    'expires_in' => 300,
                ])->all(),
            ],
        ]);
    }

    /**
     * Progress on one version, for a client that is polling.
     *
     * Deliberately its own endpoint rather than a field on the design. A render takes the
     * better part of a minute, and this is the request a browser will make every couple of
     * seconds for the whole of it — so it returns the smallest useful thing and nothing
     * that costs a join.
     */
    public function progress(
        Request $request,
        Project $project,
        Room $room,
        Design $design,
        DesignVersion $version,
    ): JsonResponse {
        $this->authorizeProject($request, $project, 'view');
        $this->assertBelongs($room, $project);
        $this->assertDesignBelongs($design, $room);
        abort_unless($version->design_id === $design->getKey(), 404);

        return response()->json(['data' => $this->progressOf($version)])
            // Never cached. This is polled precisely because the answer changes, and a
            // proxy holding the first reply would freeze a finished render on a spinner.
            ->header('Cache-Control', 'no-store, private');
    }

    public function destroy(Request $request, Project $project, Room $room, Design $design): JsonResponse
    {
        $this->authorizeProject($request, $project);
        $this->assertBelongs($room, $project);
        $this->assertDesignBelongs($design, $room);

        $design->delete();

        return response()->json(['message' => 'Tasarım kaldırıldı.']);
    }

    // --- helpers -------------------------------------------------------------

    /**
     * What a customer sees while they wait.
     *
     * The percentage comes from which stage was last announced rather than from real
     * timings. A bar driven by measured durations jumps about as providers vary; one
     * driven by stage boundaries moves predictably, which is what somebody watching a
     * spinner actually wants from it.
     *
     * @return array<string, mixed>
     */
    private function progressOf(DesignVersion $version): array
    {
        $version->loadMissing('events');

        $last = $version->events->last();

        return [
            'status' => $version->status->value,
            'status_label' => $version->status->label(),
            'is_finished' => $version->status->isTerminal(),
            'stage' => $last?->stage->value,
            'stage_label' => $last?->stage->label(),
            'progress_bps' => $version->status->isTerminal() ? 10_000 : ($last?->stage->progressBps() ?? 0),
            'failure_reason' => $version->failure_reason,

            'events' => $version->events->map(static fn (DesignVersionEvent $event): array => [
                'stage' => $event->stage->value,
                'label' => $event->stage->label(),
                'status' => $event->status,
                'message' => $event->message,
                'duration_ms' => $event->duration_ms,
                'at' => $event->created_at->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Design $design): array
    {
        return [
            'id' => $design->id,
            'name' => $design->name,
            'status' => $design->status->value,
            'status_label' => $design->status->label(),
            'version_count' => $design->versions->count(),
            'current_version_number' => $design->currentVersion?->version_number,
            'total_credit_cost' => $design->totalCreditCost(),
            'created_at' => $design->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Design $design): array
    {
        $design->loadMissing(['versions.assets', 'currentVersion.assets']);

        return [
            ...$this->summary($design),
            'current_version' => $design->currentVersion === null ? null : [
                'id' => $design->currentVersion->id,
                'version_number' => $design->currentVersion->version_number,
                'status' => $design->currentVersion->status->value,
                'user_prompt' => $design->currentVersion->user_prompt,
            ],
            // The whole tree from one query, so a screen with twenty versions is one
            // round trip rather than twenty.
            'tree' => $this->tree->tree($design),
        ];
    }

    private function defaultName(Room $room): string
    {
        return $room->name.' tasarımı';
    }

    private function assertDesignBelongs(Design $design, Room $room): void
    {
        abort_unless($design->room_id === $room->getKey(), 404);
    }

    private function assertBelongs(Room $room, Project $project): void
    {
        abort_unless($room->project_id === $project->getKey(), 404);
    }

    private function authorizeProject(Request $request, Project $project, string $ability = 'update'): void
    {
        abort_unless($request->user()?->can($ability, $project) === true, 403);
    }
}
