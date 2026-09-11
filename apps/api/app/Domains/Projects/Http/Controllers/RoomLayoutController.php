<?php

declare(strict_types=1);

namespace App\Domains\Projects\Http\Controllers;

use App\Domains\Products\Models\ProductSku;
use App\Domains\Projects\Enums\MeasurementQuality;
use App\Domains\Projects\Models\DesignLayout;
use App\Domains\Projects\Models\DesignLayoutItem;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Room;
use App\Domains\Projects\Models\RoomConstraint;
use App\Domains\Projects\Models\RoomGeometryVersion;
use App\Domains\Projects\Services\LayoutWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The room as a plan: measurements somebody agreed to, and furniture standing in it.
 *
 * Nested under the project like everything else in this subtree, so one authorisation check
 * on the parent covers the branch and there is no layout id that opens a stranger's flat.
 *
 * The confirmation step is the reason this exists rather than the room's own width and
 * length being used directly. A photograph analysed by a model gives measurements that are
 * usually close and occasionally wrong by half a metre, and everything downstream — whether
 * the sofa fits, what the render is told the room looks like, what the customer is invited to
 * buy — rests on them. So they are proposed, shown, and used only once somebody has said yes.
 * A measurement nobody agreed to is a guess with a decimal point on it.
 */
final class RoomLayoutController
{
    public function __construct(private readonly LayoutWriter $layouts) {}

    /**
     * Everything the editor needs to open: the room, its openings and its furniture.
     *
     * One request rather than three, because the editor cannot draw anything useful with a
     * subset — a room with no openings is a sealed box and a layout with no geometry has
     * nowhere to stand.
     */
    public function show(Request $request, Project $project, Room $room): JsonResponse
    {
        $this->authorizeProject($request, $project, 'view');
        $this->assertBelongs($room, $project);

        $geometry = $this->currentGeometry($room);

        $layout = $geometry === null
            ? null
            : DesignLayout::query()
                ->where('room_id', $room->getKey())
                ->where('geometry_version_id', $geometry->getKey())
                ->where('status', 'draft')
                ->latest('version')
                ->first();

        return response()->json([
            'data' => [
                'geometry' => $geometry === null ? null : $this->geometry($geometry),
                // Proposals waiting to be confirmed or corrected. The screen asks "bu ölçüler
                // doğru mu?" about the newest one.
                'pending_geometry' => $this->pendingGeometry($room),
                'openings' => $room->constraints->map(fn (RoomConstraint $c): array => $this->opening($c))->all(),
                'layout' => $layout === null ? null : $this->layout($layout),
            ],
        ]);
    }

    /**
     * Records a set of measurements, unconfirmed.
     *
     * Unconfirmed whoever sent them. A customer typing their own room's size is more likely
     * to be right than a model reading a photograph, and is still typing into a form with a
     * keyboard: "485" where "4850" was meant is one keystroke, and the difference between a
     * room and a corridor.
     */
    public function storeGeometry(Request $request, Project $project, Room $room): JsonResponse
    {
        $this->authorizeProject($request, $project);
        $this->assertBelongs($room, $project);

        $validated = $request->validate([
            // The same bounds as the table's CHECK, so a typo comes back as a sentence
            // rather than a constraint violation. One metre to thirty: below that it is not
            // a room, above it somebody has typed centimetres.
            'width_mm' => ['required', 'integer', 'min:1000', 'max:30000'],
            'length_mm' => ['required', 'integer', 'min:1000', 'max:30000'],
            'height_mm' => ['required', 'integer', 'min:1000', 'max:30000'],
            // The same two the table's CHECK allows: a model's estimate, or figures somebody
            // typed. A third value here would be a row the database refuses at insert time.
            'source' => ['sometimes', Rule::in(['ai', 'user'])],
            'confidence_bps' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        $version = RoomGeometryVersion::query()->create([
            ...$validated,
            'room_id' => $room->getKey(),
            'version' => ((int) RoomGeometryVersion::query()->where('room_id', $room->getKey())->max('version')) + 1,
            'source' => $validated['source'] ?? 'user',
        ]);

        return response()->json(['data' => $this->geometry($version)], 201);
    }

    /**
     * "Bu ölçüler doğru mu?" — evet.
     *
     * Confirming writes the measurements back onto the room as well, because the rest of the
     * system reads them from there: the design brief, the render prompt, the shopping list's
     * idea of what will fit. Two places holding a room's size is one place holding a stale
     * one, and the stale one is always the place somebody forgot to look at.
     */
    public function confirmGeometry(
        Request $request,
        Project $project,
        Room $room,
        RoomGeometryVersion $version,
    ): JsonResponse {
        $this->authorizeProject($request, $project);
        $this->assertBelongs($room, $project);

        // 404 rather than 403: a version id from another room should not be confirmable as
        // existing.
        abort_unless($version->room_id === $room->getKey(), 404);

        $userId = $request->user()?->getKey();

        DB::transaction(function () use ($room, $version, $userId): void {
            /*
             * Exactly one confirmed version per room, enforced by a partial unique index.
             * The old one is stood down first rather than both being written and one losing
             * — a confirmation that fails on an index is a customer told their measurements
             * were rejected for reasons nobody can explain.
             */
            RoomGeometryVersion::query()
                ->where('room_id', $room->getKey())
                ->where('is_confirmed', true)
                ->whereKeyNot($version->getKey())
                ->update(['is_confirmed' => false]);

            $version->forceFill([
                'is_confirmed' => true,
                'confirmed_by' => $userId,
                'confirmed_at' => now(),
            ])->save();

            $room->forceFill([
                'width_mm' => $version->width_mm,
                'length_mm' => $version->length_mm,
                'height_mm' => $version->height_mm,
                // Somebody has now looked at these numbers and said yes, which is a
                // different thing from a model having estimated them.
                'measurement_quality' => $version->source === 'ai'
                    ? MeasurementQuality::Estimated
                    : MeasurementQuality::Manual,
            ])->save();
        });

        return response()->json(['data' => $this->geometry($version->fresh())]);
    }

    /**
     * Saves the arrangement the editor is holding.
     *
     * The whole layout, not a list of moves: the browser is the one holding the arrangement
     * while somebody works on it, and an API of individual moves needs every move to arrive,
     * in order, over a connection that drops.
     */
    public function save(Request $request, Project $project, Room $room): JsonResponse
    {
        $this->authorizeProject($request, $project);
        $this->assertBelongs($room, $project);

        $geometry = $this->currentGeometry($room);

        abort_if($geometry === null, 422, 'Önce oda ölçülerinin onaylanması gerekiyor.');

        $validated = $request->validate([
            'items' => ['present', 'array', 'max:200'],
            'items.*.id' => ['sometimes', 'nullable', 'uuid'],
            'items.*.product_id' => ['required', 'uuid', 'exists:products,id'],
            'items.*.sku_id' => ['required', 'uuid', 'exists:product_skus,id'],
            // Twice the largest room the geometry table will accept, and negative, because a
            // piece half outside the room is a position the collision check has to see and
            // refuse rather than one validation quietly rounds into the room.
            'items.*.position_x_mm' => ['required', 'integer', 'min:-60000', 'max:60000'],
            'items.*.position_y_mm' => ['sometimes', 'integer', 'min:0', 'max:30000'],
            'items.*.position_z_mm' => ['required', 'integer', 'min:-60000', 'max:60000'],
            'items.*.rotation_y_deg' => ['sometimes', 'integer', 'min:0', 'max:359'],
            'items.*.locked' => ['sometimes', 'boolean'],
        ]);

        /** @var list<array<string, mixed>> $items */
        $items = $validated['items'];

        $this->assertSkusMatchProducts($items);

        $layout = $this->layouts->draftFor($room, $geometry, $request->user()?->getKey());

        $this->layouts->save($layout, $items);

        return response()->json(['data' => $this->layout($layout->fresh())]);
    }

    // --- helpers -------------------------------------------------------------

    /**
     * A variant belongs to the product it was sent with.
     *
     * Checked because the two ids arrive separately and the pair is what decides the size of
     * the box in the room. A layout holding a 2200 mm sofa's product and a 2600 mm variant's
     * id is a plan that fits on screen and does not fit in the flat.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function assertSkusMatchProducts(array $items): void
    {
        if ($items === []) {
            return;
        }

        $pairs = ProductSku::query()
            ->whereIn('id', array_column($items, 'sku_id'))
            ->pluck('product_id', 'id')
            ->all();

        foreach ($items as $item) {
            abort_unless(
                ($pairs[(string) $item['sku_id']] ?? null) === (string) $item['product_id'],
                422,
                'Seçilen ürün ile varyant birbirine ait değil.',
            );
        }
    }

    private function currentGeometry(Room $room): ?RoomGeometryVersion
    {
        return RoomGeometryVersion::query()
            ->where('room_id', $room->getKey())
            ->where('is_confirmed', true)
            ->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pendingGeometry(Room $room): array
    {
        return RoomGeometryVersion::query()
            ->where('room_id', $room->getKey())
            ->where('is_confirmed', false)
            ->latest('version')
            ->limit(3)
            ->get()
            ->map(fn (RoomGeometryVersion $version): array => $this->geometry($version))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function geometry(RoomGeometryVersion $version): array
    {
        return [
            'id' => $version->id,
            'version' => $version->version,
            'source' => $version->source,
            'width_mm' => $version->width_mm,
            'length_mm' => $version->length_mm,
            'height_mm' => $version->height_mm,
            'floor_area_m2' => $version->floorAreaM2(),
            'confidence_percent' => $version->confidencePercent(),
            'is_confirmed' => $version->is_confirmed,
            'confirmed_at' => $version->confirmed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function opening(RoomConstraint $constraint): array
    {
        return [
            'id' => $constraint->id,
            'type' => $constraint->type->value,
            'wall' => $constraint->wall,
            'offset_mm' => $constraint->offset_mm,
            'width_mm' => $constraint->width_mm,
            'height_mm' => $constraint->height_mm,
            'sill_height_mm' => $constraint->sill_height_mm,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function layout(DesignLayout $layout): array
    {
        $layout->loadMissing(['items.product.primaryCategory', 'items.sku.dimensions']);

        return [
            'id' => $layout->id,
            'version' => $layout->version,
            'source' => $layout->source,
            'status' => $layout->status,
            'has_collisions' => $layout->hasCollisions(),
            'updated_at' => $layout->updated_at?->toIso8601String(),
            'items' => $layout->items->map(fn (DesignLayoutItem $item): array => $this->item($item))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function item(DesignLayoutItem $item): array
    {
        $dimensions = $item->sku?->dimensions;

        return [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'sku_id' => $item->sku_id,
            'name' => $item->product?->name,
            'category' => $item->product?->primaryCategory?->slug,
            'position_x_mm' => $item->position_x_mm,
            'position_y_mm' => $item->position_y_mm,
            'position_z_mm' => $item->position_z_mm,
            'rotation_y_deg' => $item->rotation_y_deg,
            'locked' => $item->locked,
            'collision_state' => $item->collision_state,
            // Null rather than a default when the variant has never been measured: the
            // editor draws a placeholder and says so, instead of a box at a guessed size
            // the customer would have no reason to distrust.
            'width_mm' => $dimensions?->width_mm,
            'height_mm' => $dimensions?->height_mm,
            'depth_mm' => $dimensions?->depth_mm,
            'image_url' => null,
            'model_url' => null,
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
