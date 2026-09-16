<?php

declare(strict_types=1);

namespace App\Domains\Projects\Http\Controllers;

use App\Domains\Ai\Enums\AiJobStatus;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Models\AiJob;
use App\Domains\Catalog\Enums\RoomType;
use App\Domains\Projects\Enums\ConstraintType;
use App\Domains\Projects\Enums\MeasurementQuality;
use App\Domains\Projects\Jobs\AnalyseRoom;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Room;
use App\Domains\Projects\Models\RoomAnalysis;
use App\Domains\Projects\Models\RoomConstraint;
use App\Domains\Projects\Services\RoomAnalyser;
use App\Domains\Projects\Services\RoomProgrammeReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * Rooms inside a project.
 *
 * Nested under the project on purpose: a room is only ever reachable through the
 * project it belongs to, so one authorisation check on the parent covers every route
 * here and there is no room id that opens a stranger's flat.
 */
final class RoomController
{
    public function __construct(
        private readonly RoomProgrammeReader $programmes,
        private readonly RoomAnalyser $analyser,
    ) {}

    /**
     * Asks for the room to be read from its photographs — all of them.
     *
     * Queued and answered with 202, like the plate: a reading takes the model a while and
     * nobody should wait on a spinner for it. Answered with 200 and no queueing when the
     * photographs the room has now were already read, unless the customer asks again on
     * purpose (`force`), which is how "yeniden tanı" works.
     */
    public function analyse(Request $request, Project $project, Room $room): JsonResponse
    {
        $this->authorizeProject($request, $project);
        $this->assertBelongs($room, $project);

        $validated = $request->validate([
            'force' => ['sometimes', 'boolean'],
        ]);

        $photoIds = $this->analyser->photoIds($room);

        if ($photoIds === []) {
            throw ValidationException::withMessages(['photos' => ['Tanıma için önce bir fotoğraf yükleyin.']]);
        }

        $force = ($validated['force'] ?? null) === true;

        if (! $force && $this->analyser->currentFor($room) !== null) {
            return response()->json(['data' => ['status' => 'ready']]);
        }

        AnalyseRoom::dispatch((string) $room->getKey(), $photoIds, $force);

        return response()->json(['data' => ['status' => 'queued', 'photo_count' => count($photoIds)]], 202);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProject($request, $project);

        $validated = $this->validateRoom($request);

        $room = Room::query()->create([
            ...$validated,
            'project_id' => $project->getKey(),
            'position' => ((int) $project->rooms()->max('position')) + 1,
        ]);

        return response()->json(['data' => $this->detail($room)], 201);
    }

    public function show(Request $request, Project $project, Room $room): JsonResponse
    {
        $this->authorizeProject($request, $project, 'view');
        $this->assertBelongs($room, $project);

        return response()->json([
            'data' => $this->detail($room),
            'meta' => [
                'room_types' => RoomType::options(),
                'measurement_qualities' => MeasurementQuality::options(),
                'constraint_types' => ConstraintType::options(),
            ],
        ]);
    }

    /**
     * The questions to ask about this room, and which answers the shop can honour.
     *
     * Replaces the blank textarea labelled "İstekleriniz" that almost nobody filled in —
     * not for want of taste, but because "describe your living room" is a professional's
     * question asked of somebody who has never had to answer it. People wrote "güzel olsun"
     * or nothing, and the engine downstream guessed.
     *
     * The style is a query parameter rather than stored state because the customer changes
     * it while looking at the questions: picking "Klasik" should immediately re-mark which
     * options the catalogue can supply in that style, without saving anything first.
     */
    public function programme(Request $request, Project $project, Room $room): JsonResponse
    {
        $this->authorizeProject($request, $project, 'view');
        $this->assertBelongs($room, $project);

        $style = $request->string('style')->toString();

        $programme = $this->programmes->forRoom($room, $style === '' ? null : $style);

        // A room type nobody has written questions for yet. Not an error — the free-text
        // brief still works, and the client falls back to it.
        abort_if($programme === null, 404, 'Bu oda tipi için henüz soru seti yok.');

        return response()->json(['data' => $programme]);
    }

    public function update(Request $request, Project $project, Room $room): JsonResponse
    {
        $this->authorizeProject($request, $project);
        $this->assertBelongs($room, $project);

        $validated = $this->validateRoom($request, partial: true);

        $room->fill($validated)->save();

        return response()->json(['data' => $this->detail($room->fresh())]);
    }

    public function destroy(Request $request, Project $project, Room $room): JsonResponse
    {
        $this->authorizeProject($request, $project);
        $this->assertBelongs($room, $project);

        $room->delete();

        return response()->json(['message' => 'Oda kaldırıldı.']);
    }

    // --- constraints ---------------------------------------------------------

    public function storeConstraint(Request $request, Project $project, Room $room): JsonResponse
    {
        $this->authorizeProject($request, $project);
        $this->assertBelongs($room, $project);

        $validated = $this->validateConstraint($request);

        $type = ConstraintType::from((string) $validated['type']);

        $constraint = RoomConstraint::query()->create([
            ...$validated,
            'room_id' => $room->getKey(),
            // Defaults come from the type rather than from the form: a customer adding
            // a window should not have to know that windows must stay visible.
            'is_blocking' => $validated['is_blocking'] ?? $type->blocksByDefault(),
            'must_stay_visible' => $validated['must_stay_visible'] ?? $type->mustStayVisibleByDefault(),
        ]);

        return response()->json(['data' => $this->constraint($constraint)], 201);
    }

    public function updateConstraint(
        Request $request,
        Project $project,
        Room $room,
        RoomConstraint $constraint,
    ): JsonResponse {
        $this->authorizeProject($request, $project);
        $this->assertBelongs($room, $project);

        // 404 rather than 403: a constraint id from another room should not be
        // confirmable as existing.
        abort_unless($constraint->room_id === $room->getKey(), 404);

        $constraint->fill($this->validateConstraint($request, partial: true))->save();

        return response()->json(['data' => $this->constraint($constraint->fresh())]);
    }

    public function destroyConstraint(
        Request $request,
        Project $project,
        Room $room,
        RoomConstraint $constraint,
    ): JsonResponse {
        $this->authorizeProject($request, $project);
        $this->assertBelongs($room, $project);
        abort_unless($constraint->room_id === $room->getKey(), 404);

        $constraint->delete();

        return response()->json(['message' => 'Kısıt kaldırıldı.']);
    }

    // --- helpers -------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function validateRoom(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $validator = Validator::make($request->all(), [
            'name' => [$required, 'string', 'min:2', 'max:160'],
            'room_type' => [$required, Rule::enum(RoomType::class)],
            'measurement_quality' => ['sometimes', Rule::enum(MeasurementQuality::class)],

            // Millimetres, like every dimension in the system. 100 metres is the
            // sanity bound: beyond that somebody has typed centimetres as millimetres.
            'width_mm' => ['sometimes', 'nullable', 'integer', 'min:100', 'max:100000'],
            'length_mm' => ['sometimes', 'nullable', 'integer', 'min:100', 'max:100000'],
            'height_mm' => ['sometimes', 'nullable', 'integer', 'min:1000', 'max:20000'],

            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        /*
         * Mirrors the database CHECK, so the customer gets a sentence rather than a
         * constraint violation. Claiming a room was measured while leaving the numbers
         * empty puts a confident badge on nothing — and the design engine believes
         * badges.
         */
        $validator->after(function (ValidatorInstance $check) use ($request, $partial): void {
            $quality = MeasurementQuality::tryFrom((string) $request->input('measurement_quality', ''));

            if ($quality?->requiresDimensions() !== true) {
                return;
            }

            // On a partial update the existing values count: a customer marking an
            // already-measured room as verified is not being asked to retype it.
            $room = $partial ? $request->route('room') : null;

            $width = $request->input('width_mm', $room?->width_mm);
            $length = $request->input('length_mm', $room?->length_mm);

            if ($width === null) {
                $check->errors()->add('width_mm', 'Ölçülmüş bir oda için genişlik zorunludur.');
            }

            if ($length === null) {
                $check->errors()->add('length_mm', 'Ölçülmüş bir oda için uzunluk zorunludur.');
            }
        });

        return $validator->validate();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateConstraint(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'type' => [$required, Rule::enum(ConstraintType::class)],
            'label' => ['sometimes', 'nullable', 'string', 'max:160'],
            'wall' => ['sometimes', 'nullable', Rule::in(['north', 'east', 'south', 'west', 'ceiling', 'floor'])],
            'offset_mm' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'width_mm' => ['sometimes', 'nullable', 'integer', 'min:10', 'max:100000'],
            'height_mm' => ['sometimes', 'nullable', 'integer', 'min:10', 'max:20000'],
            'sill_height_mm' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:20000'],
            'is_blocking' => ['sometimes', 'boolean'],
            'must_stay_visible' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Room $room): array
    {
        $room->loadMissing(['constraints', 'media', 'designs']);

        return [
            'id' => $room->id,
            'project_id' => $room->project_id,
            'name' => $room->name,
            'room_type' => $room->room_type->value,
            'room_type_label' => $room->room_type->label(),
            'measurement_quality' => $room->measurement_quality->value,
            'measurement_quality_label' => $room->measurement_quality->label(),
            'measurement_confidence_bps' => $room->measurement_quality->confidenceBps(),
            'width_mm' => $room->width_mm,
            'length_mm' => $room->length_mm,
            'height_mm' => $room->height_mm,
            'floor_area_m2' => $room->floorAreaM2(),
            'notes' => $room->notes,
            'primary_media_id' => $room->primary_media_id,
            'is_ready_for_design' => $room->isReadyForDesign(),
            'missing_for_design' => $room->missingForDesign(),
            'photo_count' => $room->media->count(),
            'design_count' => $room->designs->count(),
            'constraints' => $room->constraints->map(fn (RoomConstraint $c): array => $this->constraint($c))->all(),
            /*
             * What the reading found, in words the room screen can show: which photographs
             * it read, what stands in the room, what is fixed, what it was unsure of. The
             * boxes drawn on the photograph belong to the plan screen; this is the summary.
             */
            'analysis' => $this->analysis($room),
            /*
             * Why the last reading did not happen, when it did not. A reading that failed
             * leaves the room with no analysis and the screen with nothing to say; the job
             * knows, and the customer is owed the sentence.
             */
            'analysis_failure' => $this->analysisFailure($room),
        ];
    }

    private function analysisFailure(Room $room): ?string
    {
        $job = AiJob::query()
            ->where('task', AiTask::RoomAnalysis->value)
            ->where('subject_type', $room->getMorphClass())
            ->where('subject_id', $room->getKey())
            ->orderByDesc('created_at')
            ->first();

        if ($job === null || $job->status !== AiJobStatus::Failed) {
            return null;
        }

        $current = RoomAnalysis::query()->where('room_id', $room->getKey())->current()->first();

        // A failure older than the reading the room has is history, not news.
        if ($current !== null && $current->created_at !== null && $job->created_at !== null && $job->created_at->lt($current->created_at)) {
            return null;
        }

        return $job->failure_kind?->label() ?? 'Fotoğraflar okunamadı.';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function analysis(Room $room): ?array
    {
        $analysis = RoomAnalysis::query()
            ->where('room_id', $room->getKey())
            ->current()
            ->first();

        if ($analysis === null) {
            return null;
        }

        $read = $this->analyser->readPhotoIds($analysis);

        return [
            'id' => $analysis->id,
            'photo_ids' => $read,
            'photo_count' => count($read),
            // Whether a photograph was added or removed since: the reading is of a room
            // that no longer quite exists, and the screen offers to read it again.
            'is_stale' => $read !== $this->analyser->photoIds($room),
            'detected_room_type' => $analysis->detected_room_type,
            'confidence_bps' => $analysis->confidence_bps,
            'fixed_elements' => $this->names($analysis->payload['fixed_elements'] ?? null),
            // Type and label both: the guide shows the label and asks the plate to keep
            // things by it, the icon is chosen by the type.
            'movable_objects' => $this->objects($analysis->payload['movable_objects'] ?? null),
            'dominant_colors' => array_values(array_filter((array) ($analysis->payload['dominant_colors'] ?? []), 'is_string')),
            'estimated_dimensions' => is_array($analysis->payload['estimated_dimensions'] ?? null)
                ? [
                    'width_mm' => $analysis->payload['estimated_dimensions']['width_mm'] ?? null,
                    'length_mm' => $analysis->payload['estimated_dimensions']['length_mm'] ?? null,
                    'height_mm' => $analysis->payload['estimated_dimensions']['height_mm'] ?? null,
                ]
                : null,
            'warnings' => array_values(array_filter((array) ($analysis->warnings ?? []), 'is_string')),
            'created_at' => $analysis->created_at?->toIso8601String(),
        ];
    }

    /**
     * The movable things a model listed, as type and label.
     *
     * @return list<array{type: string, label: string}>
     */
    private function objects(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $objects = [];

        foreach ($items as $item) {
            if (is_string($item) && $item !== '') {
                $objects[] = ['type' => $item, 'label' => $item];

                continue;
            }

            if (! is_array($item)) {
                continue;
            }

            $type = is_string($item['type'] ?? null) && $item['type'] !== '' ? $item['type'] : null;
            $label = is_string($item['label'] ?? null) && $item['label'] !== '' ? $item['label'] : $type;

            if ($type !== null && $label !== null) {
                $objects[] = ['type' => $type, 'label' => $label];
            }
        }

        return $objects;
    }

    /**
     * The things a model listed, as names — whether it answered with strings or objects.
     *
     * @return list<string>
     */
    private function names(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $names = [];

        foreach ($items as $item) {
            if (is_string($item) && $item !== '') {
                $names[] = $item;

                continue;
            }

            if (is_array($item)) {
                $name = $item['label'] ?? $item['name'] ?? $item['type'] ?? null;

                if (is_string($name) && $name !== '') {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    private function constraint(RoomConstraint $constraint): array
    {
        return [
            'id' => $constraint->id,
            'type' => $constraint->type->value,
            'type_label' => $constraint->type->label(),
            'label' => $constraint->label,
            'wall' => $constraint->wall,
            'offset_mm' => $constraint->offset_mm,
            'width_mm' => $constraint->width_mm,
            'height_mm' => $constraint->height_mm,
            'sill_height_mm' => $constraint->sill_height_mm,
            'is_blocking' => $constraint->is_blocking,
            'must_stay_visible' => $constraint->must_stay_visible,
            // Whether the engine can actually reason about it, or it is only a note.
            'is_placed' => $constraint->isPlaced(),
            'description' => $constraint->describe(),
            'notes' => $constraint->notes,
        ];
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
