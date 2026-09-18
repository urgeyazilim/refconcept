<?php

declare(strict_types=1);

namespace App\Domains\Projects\Services;

use App\Domains\Projects\Models\Room;
use App\Domains\Projects\Models\RoomAnalysis;
use App\Domains\Projects\Models\RoomMedia;

/**
 * Which of a customer's photographs the design is drawn from.
 *
 * The screen used to ask. Three photographs, three buttons saying "use this one", and a
 * customer who had just been told to photograph four corners was left wondering why they had
 * bothered — the product owner put it plainly: "sen yapay zekâsın, en iyi fotoğrafı sen
 * bulacaksın; kullanıcıyı neden yoruyoruz". So this chooses, and the button on the screen is
 * there for the one person in fifty who disagrees.
 *
 * **Every photograph is read whatever this decides.** The reading merges all of them into one
 * description of the room and the renderer is given the others as references. What is being
 * chosen here is only the viewpoint of the finished picture, because a render is one view of
 * a room and has to be drawn from somewhere.
 *
 * Nothing here costs anything. The shape of a photograph is recorded when it is uploaded and
 * the reading has already said which photograph it saw each fixture in, so the choice is made
 * from what is on the table.
 */
final class PrimaryPhotoChooser
{
    /**
     * Picks the photograph the design should be drawn from, unless somebody already has.
     *
     * @return bool whether the room's primary photograph changed
     */
    public function choose(Room $room): bool
    {
        if ($room->primary_is_manual) {
            return false;
        }

        $best = $this->best($room);

        if ($best === null || (string) $best->getKey() === (string) $room->primary_media_id) {
            return false;
        }

        $room->forceFill(['primary_media_id' => $best->getKey()])->save();

        return true;
    }

    /** The customer's own choice, recorded so that nothing overrules it later. */
    public function chosenByCustomer(Room $room, RoomMedia $media): void
    {
        $room->forceFill([
            'primary_media_id' => $media->getKey(),
            'primary_is_manual' => true,
        ])->save();
    }

    /**
     * The best photograph to draw a room from, or null when there is nothing to choose.
     *
     * Three things, in order, and each of them is something the system can see for itself:
     *
     *  - **shape.** A render is a wide picture of a room. A photograph taken with the phone
     *    upright hands the model a strip of one — ceiling and floor, with the walls the
     *    furniture goes against cut off at both sides — and the design that comes back is
     *    visibly worse. The product owner worked this out before we did.
     *  - **how much of the room it shows.** The reading says which photograph it saw each
     *    fixture in, so the corner that caught the window, the door and the radiator is the
     *    corner that shows the room.
     *  - **size.** All else equal, more pixels is more to work from.
     *
     * An emptied photograph beats the furnished one it was made from: it is the same view of
     * the same room with nothing in the way, which is exactly what a design wants to start on.
     */
    public function best(Room $room): ?RoomMedia
    {
        $photos = $room->media()
            ->whereIn('type', ['photo', 'plate'])
            ->orderBy('position')
            ->get();

        if ($photos->isEmpty()) {
            return null;
        }

        $analysis = RoomAnalysis::query()
            ->where('room_id', $room->getKey())
            ->where('is_current', true)
            ->latest('created_at')
            ->first();

        $seen = $this->fixturesPerPhoto($analysis);
        $order = $analysis === null ? [] : array_flip(array_map('strval', (array) ($analysis->payload['photo_ids'] ?? [])));

        $best = null;
        $bestScore = null;

        foreach ($photos as $photo) {
            /*
             * A plate scores as the photograph it came from, plus a nudge.
             *
             * It is the same view with the furniture gone, and the reading never looked at it,
             * so on its own it would score nothing and never win.
             */
            $subject = $photo->type === 'plate' && $photo->source_media_id !== null
                ? (string) $photo->source_media_id
                : (string) $photo->getKey();

            $score = [
                $this->isPortrait($photo) ? 0 : 1,
                $seen[$subject] ?? 0,
                $photo->type === 'plate' ? 1 : 0,
                ($photo->width ?? 0) * ($photo->height ?? 0),
                // A stable tiebreak: the order the reading put them in, then gallery order.
                -($order[$subject] ?? 999),
            ];

            if ($bestScore === null || $score > $bestScore) {
                $best = $photo;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /** Taller than it is wide, with a tenth of slack so a square is not called upright. */
    private function isPortrait(RoomMedia $media): bool
    {
        return $media->width !== null
            && $media->height !== null
            && $media->height > (int) round($media->width * 1.1);
    }

    /**
     * How many fixtures the reading saw in each photograph, by photograph id.
     *
     * @return array<string, int>
     */
    private function fixturesPerPhoto(?RoomAnalysis $analysis): array
    {
        if ($analysis === null) {
            return [];
        }

        $ids = array_map('strval', (array) ($analysis->payload['photo_ids'] ?? []));
        $counts = [];

        foreach ((array) ($analysis->payload['fixed_elements'] ?? []) as $element) {
            if (! is_array($element) || ! is_int($element['photo_index'] ?? null)) {
                continue;
            }

            $id = $ids[$element['photo_index']] ?? null;

            if ($id !== null) {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
