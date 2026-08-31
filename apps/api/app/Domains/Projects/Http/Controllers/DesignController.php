<?php

declare(strict_types=1);

namespace App\Domains\Projects\Http\Controllers;

use App\Domains\Credits\Exceptions\InsufficientCredits;
use App\Domains\Projects\Enums\RenderQuality;
use App\Domains\Projects\Exceptions\DesignVersionRefused;
use App\Domains\Projects\Models\Design;
use App\Domains\Projects\Models\DesignBrief;
use App\Domains\Projects\Models\DesignVersion;
use App\Domains\Projects\Models\DesignVersionEvent;
use App\Domains\Projects\Models\DesignVideo;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Room;
use App\Domains\Projects\Services\DesignVersionLauncher;
use App\Domains\Projects\Services\DesignVersionTree;
use App\Domains\Projects\Services\DesignVideoLauncher;
use App\Domains\Projects\Services\RoomPhotoStorage;
use App\Support\Storage\PrivateLinkSigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

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
        private readonly DesignVideoLauncher $videos,
        private readonly RoomPhotoStorage $storage,
        private readonly PrivateLinkSigner $links,
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

            /*
             * What the customer chose, when they were asked in pictures.
             *
             * Optional, and that is deliberate rather than transitional: a design started
             * from the old free-text form, or by a client that has not caught up, still
             * works. The pipeline handles both and this endpoint does not have to choose.
             *
             * Answers are validated for shape only — whether "corner-sofa" is a real option
             * of a real question is the programme's business, and duplicating that here
             * would be two places to keep in step.
             */
            'brief' => ['sometimes', 'array'],
            'brief.programme_id' => ['required_with:brief', 'uuid', Rule::exists('room_programmes', 'id')],
            'brief.style_code' => ['sometimes', 'nullable', 'string', Rule::exists('styles', 'code')],
            'brief.palette_code' => ['sometimes', 'nullable', 'string', Rule::exists('palettes', 'code')],
            'brief.budget_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'brief.answers' => ['sometimes', 'array'],
            'brief.answers.*' => ['array'],
            'brief.answers.*.*' => ['string', 'max:60'],
            'brief.note' => ['sometimes', 'nullable', 'string', 'max:2000'],
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
                // The brief's style wins when there is one: it is what the customer just
                // tapped, and the top-level field is the old form's way of saying the
                // same thing.
                styleCode: $validated['brief']['style_code'] ?? $validated['style_code'] ?? null,
            );
        } catch (DesignVersionRefused $e) {
            throw $e->toValidationException();
        }

        if (isset($validated['brief'])) {
            $this->recordBrief($version, $validated['brief']);

            /*
             * Recorded after the launch, not before.
             *
             * The launcher is the step that refuses — a room with no photograph, an
             * archived project, not enough credits — and it takes the customer's credits
             * once it is satisfied. Writing the brief first would leave one behind for a
             * version that was never created.
             */
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
            /*
             * The photograph the design started from.
             *
             * Sent so the screen can show before and after together. A render on its own is
             * a nice picture of a room; next to the room it came from it is the answer to
             * "what would mine look like", which is the question the customer actually
             * asked.
             */
            'source_image_url' => $this->sourcePhotographUrl($design),

            // The whole tree from one query, so a screen with twenty versions is one
            // round trip rather than twenty.
            'tree' => $this->tree->tree($design),
        ];
    }

    /**
     * A link to the room photograph this design was built on.
     */
    private function sourcePhotographUrl(Design $design): ?string
    {
        $room = $design->room;

        if ($room === null) {
            return null;
        }

        $room->loadMissing('primaryMedia');

        $media = $room->primaryMedia ?? $room->media()->orderBy('position')->first();

        if ($media === null) {
            return null;
        }

        try {
            return $this->links->url($media->disk, $media->storage_path, now()->addMinutes(30));
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Keeps what the customer chose, beside the version it produced.
     *
     * On the version rather than the room, because two designs for one room are two
     * different briefs — which is the point of keeping a tree of them. A refinement can
     * start from the answers, and a customer can see next spring what they asked for.
     *
     * @param  array<string, mixed>  $brief
     */
    private function recordBrief(DesignVersion $version, array $brief): void
    {
        DesignBrief::query()->create([
            'design_version_id' => $version->getKey(),
            'programme_id' => $brief['programme_id'] ?? null,
            'style_code' => $brief['style_code'] ?? null,
            'palette_code' => $brief['palette_code'] ?? null,
            'budget_minor' => $brief['budget_minor'] ?? null,
            // Normalised to lists of strings on the way in, so nothing downstream has to
            // wonder whether a single-choice answer might have arrived as a bare string.
            'answers' => $this->normaliseAnswers($brief['answers'] ?? []),
            'note' => $brief['note'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return array<string, array<int, string>>
     */
    private function normaliseAnswers(array $answers): array
    {
        $normalised = [];

        foreach ($answers as $question => $chosen) {
            $codes = array_values(array_filter(
                (array) $chosen,
                static fn (mixed $code): bool => is_string($code) && $code !== '',
            ));

            if ($codes !== []) {
                $normalised[(string) $question] = $codes;
            }
        }

        return $normalised;
    }

    private function defaultName(Room $room): string
    {
        return $room->name.' tasarımı';
    }

    /**
     * Films a finished design.
     *
     * Accepted and queued rather than performed. The provider answers with a long-running
     * operation and the file exists a minute or two later, so the honest response to this
     * request is "started", with a row the client can poll.
     *
     * The refusals are specific and they come before the charge: a design that is not
     * finished has no still for the camera to move through, and a film already in flight
     * is what two clicks on a slow page look like.
     */
    public function startVideo(
        Request $request,
        Project $project,
        Room $room,
        Design $design,
        DesignVersion $version,
    ): JsonResponse {
        // 'update' rather than 'view': this spends the customer's credits, and somebody
        // invited to look at a project must not be able to.
        $this->authorizeProject($request, $project);
        $this->assertBelongs($room, $project);
        $this->assertDesignBelongs($design, $room);
        abort_unless($version->design_id === $design->getKey(), 404);

        $user = $request->user();
        abort_if($user === null, 403);

        try {
            $video = $this->videos->launch($version, $user);
        } catch (DesignVersionRefused $e) {
            throw $e->toValidationException('video');
        } catch (InsufficientCredits $e) {
            /*
             * 402 rather than 422. The request was well formed and the customer is allowed
             * to make it; they cannot pay for it, and the client shows a top-up prompt
             * rather than a form error.
             */
            return response()->json([
                'message' => $e->getMessage(),
                'required_credits' => $this->videos->quote(),
            ], 402);
        }

        return response()->json(['data' => $this->videoPayload($video)], 202);
    }

    /**
     * The state of this design's films, for a client that is polling.
     *
     * Its own endpoint rather than a field on the version, for the same reason progress is:
     * this is the request a browser makes every couple of seconds for two minutes, and it
     * returns the smallest useful thing.
     */
    public function videos(
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

        $version->loadMissing('videos.asset');

        return response()->json([
            'data' => $version->videos
                ->sortByDesc('created_at')
                ->values()
                ->map(fn (DesignVideo $video): array => $this->videoPayload($video))
                ->all(),
            // What another one would cost, so the button can say so before it is pressed.
            'credit_cost' => $this->videos->quote(),
        ]);
    }

    /**
     * One film, as the client sees it.
     *
     * The link is issued here and now because the caller has just been authorised, and it
     * is short-lived for the same reason every other link to somebody's home is: a URL that
     * outlives the session that earned it is a URL that can be forwarded.
     *
     * @return array<string, mixed>
     */
    private function videoPayload(DesignVideo $video): array
    {
        $asset = $video->asset;

        return [
            'id' => $video->id,
            'status' => $video->status->value,
            'status_label' => $video->status->label(),
            'credit_cost' => $video->credit_cost,
            'duration_seconds' => $video->duration_seconds,
            'failure_reason' => $video->failure_reason,
            'created_at' => $video->created_at?->toIso8601String(),
            'completed_at' => $video->completed_at?->toIso8601String(),
            'url' => $asset === null ? null : $this->storage->temporaryAssetUrl($asset),
            'expires_in' => $asset === null ? null : 300,
        ];
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
