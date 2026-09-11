<?php

declare(strict_types=1);

namespace App\Domains\Projects\Services;

use App\Domains\Projects\Models\DesignLayout;
use App\Domains\Projects\Models\DesignLayoutItem;
use App\Domains\Projects\Models\Room;
use App\Domains\Projects\Models\RoomGeometryVersion;
use Illuminate\Support\Facades\DB;

/**
 * Writing a layout down.
 *
 * The editor in the browser sends the whole layout rather than a list of edits, and this
 * replaces what was stored with what arrived. That is the right shape for a drag-and-drop
 * planner: the client is the one holding the arrangement while somebody works on it, and an
 * API of individual moves would need every move to arrive, in order, over a connection that
 * drops — which is how a sofa ends up in a position nobody ever put it in.
 *
 * Two things are recomputed here rather than trusted:
 *
 * The collision state. The client sends one, computed from the same rules, and it is thrown
 * away — a state is a fact about a room, not an opinion of whoever is holding the mouse.
 *
 * The version. Layouts are numbered per room and a client that picks the number races with
 * every other tab the customer has open.
 */
final class LayoutWriter
{
    public function __construct(private readonly LayoutGeometry $geometry) {}

    /**
     * The layout somebody is working on, creating one if this room has none.
     *
     * Tied to a confirmed geometry version, because a plan drawn against measurements nobody
     * agreed to is a plan that moves when they do.
     */
    public function draftFor(Room $room, RoomGeometryVersion $version, ?string $userId): DesignLayout
    {
        $existing = DesignLayout::query()
            ->where('room_id', $room->getKey())
            ->where('geometry_version_id', $version->getKey())
            ->where('status', 'draft')
            ->latest('version')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DesignLayout::query()->create([
            'room_id' => $room->getKey(),
            'geometry_version_id' => $version->getKey(),
            'version' => ((int) DesignLayout::query()->where('room_id', $room->getKey())->max('version')) + 1,
            'source' => 'user',
            'status' => 'draft',
            'created_by' => $userId,
        ]);
    }

    /**
     * Replaces a layout's furniture with what the editor sent.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, string> item id => collision state
     */
    public function save(DesignLayout $layout, array $items): array
    {
        return DB::transaction(function () use ($layout, $items): array {
            /*
             * Deleted and rewritten rather than reconciled.
             *
             * A layout is a few dozen small rows, and reconciling them is a second
             * implementation of "what changed" — the one that runs on the server, against a
             * payload it did not produce. The ids come from the client and are kept, so undo
             * in the browser and a reload from the server agree about which sofa is which.
             */
            $layout->items()->delete();

            /*
             * Ids the client sent that belong to somebody else's layout.
             *
             * The browser keeps an id per piece so that undo, the selection and a reload all
             * agree about which sofa is which, and it is allowed to choose them. It is not
             * allowed to choose one already in use: that would be an insert that fails, or —
             * if the delete above had been written differently — a row in another customer's
             * layout quietly overwritten. Anything taken is replaced with a fresh id.
             */
            $taken = DesignLayoutItem::query()
                ->whereIn('id', array_values(array_filter(array_map(
                    static fn (array $item): ?string => isset($item['id']) ? (string) $item['id'] : null,
                    $items,
                ))))
                ->pluck('id')
                ->all();

            foreach ($items as $item) {
                $id = isset($item['id']) ? (string) $item['id'] : null;

                $row = new DesignLayoutItem;

                // Set rather than filled: the primary key is deliberately not mass
                // assignable, and this is the one place allowed to choose one.
                if ($id !== null && ! in_array($id, $taken, true)) {
                    $row->id = $id;
                }

                $row->fill([
                    'layout_id' => $layout->getKey(),
                    'product_id' => $item['product_id'],
                    'sku_id' => $item['sku_id'],
                    'position_x_mm' => (int) $item['position_x_mm'],
                    'position_y_mm' => (int) ($item['position_y_mm'] ?? 0),
                    'position_z_mm' => (int) $item['position_z_mm'],
                    'rotation_y_deg' => (int) ($item['rotation_y_deg'] ?? 0),
                    'locked' => (bool) ($item['locked'] ?? false),
                    'metadata' => $item['metadata'] ?? null,
                ])->save();
            }

            $layout->load('items');

            // Computed here, from the room, rather than taken from the payload.
            $states = $this->geometry->evaluate($layout);

            foreach ($layout->items as $item) {
                $item->forceFill(['collision_state' => $states[(string) $item->getKey()] ?? 'ok'])->save();
            }

            $layout->touch();

            return $states;
        });
    }
}
