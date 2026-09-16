<?php

declare(strict_types=1);

namespace App\Domains\Projects\Services;

use App\Domains\Projects\Enums\MeasurementQuality;
use App\Domains\Projects\Models\DesignLayout;
use App\Domains\Projects\Models\DesignVersion;
use App\Domains\Projects\Models\Room;
use App\Domains\Projects\Models\RoomGeometryVersion;
use Illuminate\Support\Facades\DB;

/**
 * The 3D room, furnished with the design's products, without anybody asking for it.
 *
 * The product owner's words: "yapay zekâ tasarımı yaptı, o zaman 3B'de sen yerleştir".
 * The design decides what goes in the room and roughly where; the arithmetic that puts
 * each piece at its real size in a place the room will take is free and deterministic, so
 * there is no reason to leave it behind a button. It runs the moment a design is ready,
 * and again when a plan is opened for a room that has a design and no arrangement yet.
 *
 * It needs the room's measurements. When the customer has agreed to a set, those; when
 * they have not yet, the reading's own proposal is taken as agreed — marked as the
 * reading's, so the guide still asks "doğru mu?" and a correction rearranges everything.
 * An arrangement somebody has already made is never overwritten from here.
 */
final class LayoutAutoComposer
{
    public function __construct(
        private readonly LayoutWriter $layouts,
        private readonly LayoutComposer $composer,
        private readonly ComposableProducts $products,
        private readonly RoomGeometryProposer $proposer,
    ) {}

    /**
     * Arranges the version's products in the room.
     *
     * @return array{layout: DesignLayout, unplaced: array<int, array<string, mixed>>, unmeasured: array<int, array<string, mixed>>}|null
     *                                                                                                                                    null when there is nothing to arrange against — no measurements at all — or an
     *                                                                                                                                    arrangement already exists and `replace` was not asked for
     */
    public function compose(Room $room, DesignVersion $version, ?string $userId, bool $replace = false): ?array
    {
        $geometry = $this->geometryFor($room, $userId);

        if ($geometry === null) {
            return null;
        }

        $layout = $this->layouts->draftFor($room, $geometry, $userId);

        if ($layout->items()->exists() && ! $replace) {
            return null;
        }

        $room->load('constraints');

        $catalogue = $this->products->forVersion($version);
        $composed = $this->composer->compose($geometry, $room->constraints->all(), $catalogue['pieces']);

        $this->layouts->save($layout, array_map(static fn (array $item): array => [
            'product_id' => $item['product_id'],
            'sku_id' => $item['sku_id'],
            'position_x_mm' => $item['position_x_mm'],
            'position_y_mm' => $item['position_y_mm'],
            'position_z_mm' => $item['position_z_mm'],
            'rotation_y_deg' => $item['rotation_y_deg'],
        ], $composed['items']));

        return [
            'layout' => $layout->fresh() ?? $layout,
            'unplaced' => $composed['unplaced'],
            'unmeasured' => $catalogue['unmeasured'],
        ];
    }

    /**
     * Whether the room can be arranged at all: agreed measurements, or a proposal to agree to.
     */
    public function canCompose(Room $room): bool
    {
        return RoomGeometryVersion::query()->where('room_id', $room->getKey())->exists();
    }

    /**
     * The agreed measurements, or the reading's latest proposal taken as agreed.
     */
    private function geometryFor(Room $room, ?string $userId): ?RoomGeometryVersion
    {
        $confirmed = RoomGeometryVersion::query()
            ->where('room_id', $room->getKey())
            ->where('is_confirmed', true)
            ->first();

        if ($confirmed !== null) {
            return $confirmed;
        }

        $proposal = RoomGeometryVersion::query()
            ->where('room_id', $room->getKey())
            ->latest('version')
            ->first();

        if ($proposal === null) {
            return null;
        }

        return $this->confirm($room, $proposal, $userId);
    }

    /**
     * The same act as the customer pressing "Evet, doğru", done for them.
     *
     * Exactly one confirmed version per room; the room takes the numbers; the doors and
     * windows the reading found go in if the room has none. The quality stays "estimated"
     * because nobody has measured anything — the guide keeps asking until somebody does.
     */
    private function confirm(Room $room, RoomGeometryVersion $version, ?string $userId): RoomGeometryVersion
    {
        DB::transaction(function () use ($room, $version, $userId): void {
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
                'measurement_quality' => $version->source === 'ai'
                    ? MeasurementQuality::Estimated
                    : MeasurementQuality::Manual,
            ])->save();

            $version->setRelation('room', $room);
            $this->proposer->adoptOpenings($version);
        });

        return $version->fresh() ?? $version;
    }
}
