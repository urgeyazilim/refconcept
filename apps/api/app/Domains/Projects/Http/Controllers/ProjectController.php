<?php

declare(strict_types=1);

namespace App\Domains\Projects\Http\Controllers;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Catalog\Enums\RoomType;
use App\Domains\Identity\Models\UserAddress;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Enums\ProjectType;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectStatusHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * A customer's projects.
 *
 * Every query is scoped by `visibleTo`, so there is no project id that opens somebody
 * else's home even before the policy runs. The scope alone would be enough for the
 * list; the policy is what protects the routes that take an id.
 */
final class ProjectController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:60'],
        ]);

        $query = Project::query()
            ->visibleTo($request->user())
            ->with(['rooms.primaryMedia', 'members'])
            ->withCount('rooms')
            ->orderByDesc('updated_at');

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $projects = $query->paginate($validated['per_page'] ?? 24);

        return response()->json([
            'data' => collect($projects->items())
                ->map(fn (Project $project): array => $this->summary($project, $request))
                ->all(),
            'meta' => [
                'current_page' => $projects->currentPage(),
                'last_page' => $projects->lastPage(),
                'per_page' => $projects->perPage(),
                'total' => $projects->total(),
                'project_types' => ProjectType::options(),
                'room_types' => RoomType::options(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'project_type' => ['sometimes', Rule::enum(ProjectType::class)],
            // Budget in minor units, like every amount on this API.
            'budget_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:99999999999'],
            'address_id' => ['sometimes', 'nullable', 'uuid'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $this->assertOwnAddress($request, $validated['address_id'] ?? null);

        $project = Project::query()->create([
            ...$validated,
            'user_id' => $request->user()->getKey(),
        ]);

        ProjectStatusHistory::query()->create([
            'project_id' => $project->getKey(),
            'from_status' => null,
            'to_status' => $project->status->value,
            'changed_by' => $request->user()->getKey(),
            'changed_at' => now(),
        ]);

        $this->audit->record(
            action: 'projects.project.created',
            subject: $project,
            actor: $request->user(),
        );

        return response()->json(['data' => $this->detail($project->fresh(), $request)], 201);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProject($request, 'view', $project);

        return response()->json(['data' => $this->detail($project, $request)]);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProject($request, 'update', $project);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'project_type' => ['sometimes', Rule::enum(ProjectType::class)],
            'budget_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:99999999999'],
            'address_id' => ['sometimes', 'nullable', 'uuid'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $this->assertOwnAddress($request, $validated['address_id'] ?? null);

        $project->fill($validated)->save();

        return response()->json(['data' => $this->detail($project->fresh(), $request)]);
    }

    /** Archiving, reopening, marking done. */
    public function setStatus(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProject($request, 'setStatus', $project);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(ProjectStatus::class)],
        ]);

        $target = ProjectStatus::from((string) $validated['status']);

        abort_unless(
            $project->status->canTransitionTo($target),
            422,
            sprintf('%s durumundan %s durumuna geçilemez.', $project->status->label(), $target->label()),
        );

        DB::transaction(function () use ($project, $target, $request): void {
            $from = $project->status;

            $project->forceFill(['status' => $target])->save();

            ProjectStatusHistory::query()->create([
                'project_id' => $project->getKey(),
                'from_status' => $from->value,
                'to_status' => $target->value,
                'changed_by' => $request->user()->getKey(),
                'changed_at' => now(),
            ]);
        });

        return response()->json(['data' => $this->detail($project->fresh(), $request)]);
    }

    public function destroy(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProject($request, 'delete', $project);

        // Soft delete. A customer who deletes a project by accident has lost months of
        // work and every design they were comparing; the rows stay recoverable for a
        // support conversation, and the photographs are purged by the retention job.
        $project->delete();

        $this->audit->record(
            action: 'projects.project.deleted',
            subject: $project,
            actor: $request->user(),
        );

        return response()->json(['message' => 'Proje kaldırıldı.']);
    }

    // --- helpers -------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function summary(Project $project, Request $request): array
    {
        $cover = $project->rooms
            ->map(fn ($room) => $room->primaryMedia)
            ->filter()
            ->first();

        return [
            'id' => $project->id,
            'name' => $project->name,
            'project_type' => $project->project_type->value,
            'project_type_label' => $project->project_type->label(),
            'status' => $project->status->value,
            'status_label' => $project->status->label(),
            'budget' => $project->budget_minor?->jsonSerialize(),
            'room_count' => $project->rooms_count ?? $project->rooms->count(),
            // Enough to tell the customer whether the project has a picture yet,
            // without handing out a link to it in a list response.
            'has_cover' => $cover !== null,
            'is_owner' => $project->isOwnedBy($request->user()),
            'can_edit' => $project->isEditableBy($request->user()),
            'member_count' => $project->members->filter(fn ($m) => $m->isActive())->count(),
            'created_at' => $project->created_at?->toIso8601String(),
            'updated_at' => $project->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Project $project, Request $request): array
    {
        $project->loadMissing(['rooms.primaryMedia', 'rooms.constraints', 'members.user.profile', 'address']);

        return [
            ...$this->summary($project, $request),
            'notes' => $project->notes,
            'address' => $project->address === null ? null : [
                'id' => $project->address->id,
                'label' => $project->address->label,
                'city' => $project->address->city,
                'district' => $project->address->district,
            ],
            'rooms' => $project->rooms->map(fn ($room): array => [
                'id' => $room->id,
                'name' => $room->name,
                'room_type' => $room->room_type->value,
                'room_type_label' => $room->room_type->label(),
                'measurement_quality' => $room->measurement_quality->value,
                'measurement_quality_label' => $room->measurement_quality->label(),
                'width_mm' => $room->width_mm,
                'length_mm' => $room->length_mm,
                'height_mm' => $room->height_mm,
                'floor_area_m2' => $room->floorAreaM2(),
                'has_photo' => $room->primary_media_id !== null,
                'constraint_count' => $room->constraints->count(),
                'is_ready_for_design' => $room->isReadyForDesign(),
            ])->all(),
            'members' => $project->members
                ->filter(fn ($member) => $member->revoked_at === null)
                ->map(fn ($member): array => [
                    'id' => $member->id,
                    'email' => $member->invited_email,
                    'name' => $member->user?->displayName(),
                    'role' => $member->role->value,
                    'role_label' => $member->role->label(),
                    'status' => $member->status,
                    'accepted_at' => $member->accepted_at?->toIso8601String(),
                ])->values()->all(),
        ];
    }

    /**
     * An address must belong to the person using it.
     *
     * Otherwise a project could point at somebody else's home address by id, and the
     * project detail response would hand back its city and district.
     */
    private function assertOwnAddress(Request $request, ?string $addressId): void
    {
        if ($addressId === null) {
            return;
        }

        $owned = UserAddress::query()
            ->whereKey($addressId)
            ->where('user_id', $request->user()->getKey())
            ->exists();

        abort_unless($owned, 404, 'Bu adres bulunamadı.');
    }

    private function authorizeProject(Request $request, string $ability, Project $project): void
    {
        abort_unless($request->user()?->can($ability, $project) === true, 403);
    }
}
