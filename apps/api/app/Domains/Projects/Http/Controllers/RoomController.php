<?php

declare(strict_types=1);

namespace App\Domains\Projects\Http\Controllers;

use App\Domains\Catalog\Enums\RoomType;
use App\Domains\Projects\Enums\ConstraintType;
use App\Domains\Projects\Enums\MeasurementQuality;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Room;
use App\Domains\Projects\Models\RoomConstraint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
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
        ];
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
