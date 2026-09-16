<?php

declare(strict_types=1);

namespace App\Domains\Projects\Http\Controllers;

use App\Domains\Commerce\Exceptions\CartRefused;
use App\Domains\Commerce\Services\CartService;
use App\Domains\Products\Models\ProductMedia;
use App\Domains\Products\Models\ProductSku;
use App\Domains\Projects\Enums\DesignVersionStatus;
use App\Domains\Projects\Enums\MeasurementQuality;
use App\Domains\Projects\Models\DesignLayout;
use App\Domains\Projects\Models\DesignLayoutItem;
use App\Domains\Projects\Models\DesignVersion;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Room;
use App\Domains\Projects\Models\RoomAnalysis;
use App\Domains\Projects\Models\RoomConstraint;
use App\Domains\Projects\Models\RoomGeometryVersion;
use App\Domains\Projects\Services\ComposableProducts;
use App\Domains\Projects\Services\LayoutComposer;
use App\Domains\Projects\Services\LayoutWriter;
use App\Domains\Projects\Services\RoomGeometryProposer;
use App\Domains\Projects\Services\RoomPhotoStorage;
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
    public function __construct(
        private readonly LayoutWriter $layouts,
        private readonly RoomGeometryProposer $proposer,
        private readonly LayoutComposer $composer,
        private readonly ComposableProducts $products,
        private readonly RoomPhotoStorage $photos,
        private readonly CartService $carts,
    ) {}

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
                'geometry' => $geometry === null ? null : $this->geometry($geometry, $this->floorMaterial($room)),
                // Proposals waiting to be confirmed or corrected. The screen asks "bu ölçüler
                // doğru mu?" about the newest one.
                'pending_geometry' => $this->pendingGeometry($room),
                'openings' => $room->constraints->map(fn (RoomConstraint $c): array => $this->opening($c))->all(),
                'layout' => $layout === null ? null : $this->layout($layout),
                // What the room is for, so the product search can offer the categories that
                // belong in it rather than the whole catalogue.
                'room_type' => $room->room_type->value,
                /*
                 * What the room itself says about its size, and whether it has a picture.
                 *
                 * The measurements a customer typed on the room screen are the best first
                 * answer the plan can offer when no geometry has been proposed yet — asking
                 * for three numbers they gave a minute ago is asking twice. The photo count
                 * lets the step strip say the photograph step is behind them.
                 */
                'room' => [
                    'width_mm' => $room->width_mm,
                    'length_mm' => $room->length_mm,
                    'height_mm' => $room->height_mm,
                    'photo_count' => $room->media->count(),
                ],
                /*
                 * The design a final image would be made from, if there is one.
                 *
                 * Named here so the plan screen can offer "produce the final image" without a
                 * second request — and can say nothing at all when there is no design to
                 * branch from, rather than offering a button that answers with an error.
                 */
                'design' => $this->designSummary($room),
                /*
                 * What the analysis says it saw, and where in the photograph it saw it.
                 *
                 * Three measurements are hard to answer: nobody knows their living room is
                 * 4.85 m wide, and a screen of digits gets "yes, probably". What somebody can
                 * answer instantly is whether the box drawn on their own photograph is around
                 * the window — and if it is around a mirror, every number that followed from
                 * it is wrong and they can see why.
                 */
                'detected' => $this->detected($room),
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

            /*
             * The doors and windows the photograph suggested, adopted with the measurements
             * they were measured against.
             *
             * One decision rather than two: a door added to somebody's list of fixed elements
             * the moment a photograph was read is a door they did not put there and will not
             * think to check. It goes in only if the room has none of its own — there is no
             * way to tell from here which of two windows a metre apart is the real one.
             */
            $version->setRelation('room', $room);
            $this->proposer->adoptOpenings($version);
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
    /**
     * Arranges the products a design settled on, in the room's own measurements.
     *
     * The design decides what goes in the room and roughly where — a sofa on the north wall,
     * a rug under the seating, a picture above the sideboard. Those are the right words for a
     * model to produce and the wrong thing to trust with coordinates: asked for millimetres it
     * produces millimetres that look like millimetres and put a wardrobe through a doorway,
     * because nothing in it is checking. The arithmetic happens here, against the room the
     * customer confirmed, and every position is checked before it is written.
     *
     * Refuses to overwrite an arrangement somebody has already made unless asked twice. A
     * customer who spent ten minutes moving furniture and pressed the wrong button should get
     * a question, not their afternoon back in the shape the engine likes.
     */
    public function compose(Request $request, Project $project, Room $room): JsonResponse
    {
        $this->authorizeProject($request, $project);
        $this->assertBelongs($room, $project);

        $geometry = $this->currentGeometry($room);

        abort_if($geometry === null, 422, 'Önce oda ölçülerinin onaylanması gerekiyor.');

        $version = $this->latestVersion($room, $request->string('design_version_id')->toString());

        // "Hazır", because that is the word the design screens use for a version that has
        // finished. A customer who has just watched one finish should recognise the state
        // they are being told they do not have.
        abort_if($version === null, 422, 'Bu oda için hazır bir tasarım yok. Önce bir tasarım oluşturun.');

        $layout = $this->layouts->draftFor($room, $geometry, $request->user()?->getKey());

        abort_if(
            $layout->items()->exists() && $request->boolean('replace') !== true,
            409,
            'Odada kayıtlı bir yerleşim var. Üzerine yazmak için onaylayın.',
        );

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

        return response()->json([
            'data' => $this->layout($layout->fresh()),
            'meta' => [
                /*
                 * What did not make it in, said plainly.
                 *
                 * A layout that quietly drops a product the customer chose is a layout that
                 * lies about the shopping list beside it. "Bunlar sığmadı" is a sentence
                 * somebody can act on — choose a narrower one, or move something themselves.
                 */
                'unplaced' => $composed['unplaced'],
                'unmeasured' => $catalogue['unmeasured'],
            ],
        ]);
    }

    /**
     * Puts everything standing in the room into the basket.
     *
     * The point of the whole module. A plan is a list of real products at real sizes in a room
     * they have been checked against — one press away from being an order is the only sensible
     * place for it to end, and asking somebody to find each piece again in the shop is asking
     * them to do the work twice.
     *
     * What cannot be bought is reported rather than skipped. A basket that quietly contains
     * four of the five things somebody planned is a basket they discover at the door.
     */
    public function addToCart(Request $request, Project $project, Room $room): JsonResponse
    {
        $this->authorizeProject($request, $project);
        $this->assertBelongs($room, $project);

        $user = $request->user();

        abort_if($user === null, 401);

        $geometry = $this->currentGeometry($room);

        abort_if($geometry === null, 422, 'Önce oda ölçülerinin onaylanması gerekiyor.');

        $layout = DesignLayout::query()
            ->where('room_id', $room->getKey())
            ->where('geometry_version_id', $geometry->getKey())
            ->whereHas('items')
            ->latest('version')
            ->first();

        abort_if($layout === null, 422, 'Odada henüz ürün yok.');

        /*
         * Two of the same sideboard is a quantity of two, not two lines.
         *
         * The layout holds one row per piece standing in the room, which is the right shape
         * for a plan and the wrong shape for a basket: a customer ordering a pair of bedside
         * tables wants one line saying two.
         */
        $quantities = $layout->items->groupBy('sku_id')->map->count();

        $skus = ProductSku::query()
            // `product.skus`, because a product decides whether it is publicly visible by
            // looking at its own variants. Lazily, that is a query per basket line — and with
            // lazy loading disabled, which it is here, a 500 for the customer.
            ->with(['product.skus.seller', 'seller'])
            ->whereIn('id', $quantities->keys())
            ->get();

        $added = 0;
        $refused = [];

        foreach ($skus as $sku) {
            try {
                $this->carts->add($user, $sku, (int) $quantities[$sku->getKey()]);
                $added++;
            } catch (CartRefused $e) {
                // Sold out, unpublished, or withdrawn since the plan was made. Named, so the
                // customer can take it out of the room rather than wonder what happened.
                $refused[] = ['name' => $sku->product?->name, 'reason' => $e->getMessage()];
            }
        }

        return response()->json([
            'data' => ['added' => $added],
            'meta' => ['refused' => $refused],
        ]);
    }

    /**
     * Where a product would go, if it were added to this room.
     *
     * Asked when somebody picks something from the catalogue. It writes nothing: the editor
     * holds the arrangement while the page is open, and a position that arrives without the
     * piece existing yet is a position it can put in its own undo history like any other.
     *
     * The same rules that arrange a whole design, because a customer adding a bookcase
     * expects it against a wall — that is where bookcases go. The browser's own answer, the
     * nearest free rectangle to the middle of the floor, is where nothing goes: five products
     * added that way stand in a heap in the centre of the room.
     */
    public function place(Request $request, Project $project, Room $room): JsonResponse
    {
        $this->authorizeProject($request, $project);
        $this->assertBelongs($room, $project);

        $geometry = $this->currentGeometry($room);

        abort_if($geometry === null, 422, 'Önce oda ölçülerinin onaylanması gerekiyor.');

        $validated = $request->validate([
            'sku_id' => ['required', 'uuid', 'exists:product_skus,id'],
            // What is already standing in the room, as the editor currently has it — which is
            // not what is saved: somebody may have moved three things since the last autosave
            // and a position computed against stale furniture lands on top of something.
            'existing' => ['present', 'array', 'max:200'],
            'existing.*.category' => ['sometimes', 'nullable', 'string', 'max:120'],
            'existing.*.width_mm' => ['required', 'integer', 'min:0', 'max:30000'],
            'existing.*.depth_mm' => ['required', 'integer', 'min:0', 'max:30000'],
            'existing.*.position_x_mm' => ['required', 'integer', 'min:-60000', 'max:60000'],
            'existing.*.position_y_mm' => ['sometimes', 'integer', 'min:0', 'max:30000'],
            'existing.*.position_z_mm' => ['required', 'integer', 'min:-60000', 'max:60000'],
            'existing.*.rotation_y_deg' => ['sometimes', 'integer', 'min:0', 'max:359'],
        ]);

        $sku = ProductSku::query()
            ->with(['dimensions', 'product.primaryCategory'])
            ->findOrFail($validated['sku_id']);

        $dimensions = $sku->dimensions;

        abort_if(
            ($dimensions->width_mm ?? 0) <= 0 || ($dimensions->depth_mm ?? 0) <= 0,
            422,
            'Bu ürünün ölçüleri girilmemiş.',
        );

        $placed = $this->composer->placeOne(
            $geometry,
            $room->constraints->all(),
            array_map(static fn (array $item): array => [
                'product_id' => '',
                'sku_id' => '',
                'category' => $item['category'] ?? null,
                'width_mm' => (int) $item['width_mm'],
                'depth_mm' => (int) $item['depth_mm'],
                'position_x_mm' => (int) $item['position_x_mm'],
                'position_y_mm' => (int) ($item['position_y_mm'] ?? 0),
                'position_z_mm' => (int) $item['position_z_mm'],
                'rotation_y_deg' => (int) ($item['rotation_y_deg'] ?? 0),
            ], $validated['existing']),
            [
                'product_id' => (string) $sku->product_id,
                'sku_id' => (string) $sku->getKey(),
                'category' => $sku->product?->primaryCategory?->slug,
                'width_mm' => (int) $dimensions->width_mm,
                'depth_mm' => (int) $dimensions->depth_mm,
                'height_mm' => $dimensions->height_mm,
                'wall' => null,
            ],
        );

        return response()->json([
            'data' => $placed === null ? null : [
                'position_x_mm' => $placed['position_x_mm'],
                'position_y_mm' => $placed['position_y_mm'],
                'position_z_mm' => $placed['position_z_mm'],
                'rotation_y_deg' => $placed['rotation_y_deg'],
            ],
        ]);
    }

    /**
     * Keeps a picture of the 3D plan, for the renderer to work from.
     *
     * The whole reason the 3D module exists. A photorealistic model handed a photograph and a
     * list of furniture will rearrange the room to make a better picture — it narrowed a
     * doorway, moved a window wall and added a sofa nobody sells, and the customer saw their
     * own flat with furniture in it that does not exist. Prompt rules helped and did not fix
     * it, because the model is not disobeying: it is resolving an underdetermined scene, and
     * the only real answer is to stop leaving it underdetermined.
     *
     * So the browser renders the room it has already agreed with the customer — the walls at
     * the confirmed measurements, the openings where the openings are, every piece at its
     * real size in its chosen place — and that picture goes to the renderer alongside the
     * photograph, as the structure to follow rather than a suggestion.
     *
     * It is the customer's home either way, so it lands on the private disk under the same
     * rules as their photographs, and no URL for it appears in any response.
     */
    public function storeSnapshot(Request $request, Project $project, Room $room): JsonResponse
    {
        $this->authorizeProject($request, $project);
        $this->assertBelongs($room, $project);

        $geometry = $this->currentGeometry($room);

        abort_if($geometry === null, 422, 'Önce oda ölçülerinin onaylanması gerekiyor.');

        $validated = $request->validate([
            // A canvas gives us a data URL. Capped at roughly six megabytes of base64, which
            // is a generous 2000-pixel PNG and far short of anything worth worrying about.
            'image' => ['required', 'string', 'max:8000000'],
        ]);

        $bytes = $this->decodePng((string) $validated['image']);

        $layout = $this->layouts->draftFor($room, $geometry, $request->user()?->getKey());

        $temporary = tempnam(sys_get_temp_dir(), 'layout');

        abort_if($temporary === false, 500, 'Geçici dosya oluşturulamadı.');

        try {
            file_put_contents($temporary, $bytes);

            $stored = $this->photos->storeLayoutSnapshot((string) $layout->getKey(), $temporary);
        } finally {
            // Scratch space nobody empties becomes an archive of every room ever planned.
            @unlink($temporary);
        }

        $layout->forceFill([
            'snapshot_disk' => $stored['disk'],
            'snapshot_path' => $stored['path'],
            'snapshot_taken_at' => now(),
        ])->save();

        // Deliberately no path and no URL. The client knows it succeeded; it has no business
        // knowing where a picture of somebody's home is stored.
        return response()->json(['data' => ['stored' => true]]);
    }

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

    /**
     * The design version to arrange, named or the newest one that finished.
     *
     * Newest rather than the one marked current, because arranging furniture is something
     * somebody does right after seeing a design they like — and a version explicitly asked
     * for wins over both.
     */
    private function latestVersion(Room $room, string $id): ?DesignVersion
    {
        $query = DesignVersion::query()
            ->whereIn('design_id', $room->designs()->select('id'))
            ->where('status', DesignVersionStatus::Ready);

        if ($id !== '') {
            return $query->whereKey($id)->first();
        }

        return $query->latest('created_at')->first();
    }

    /**
     * The bytes out of a canvas data URL.
     *
     * Only PNG, and only after the bytes have been looked at rather than the header trusted:
     * the prefix is whatever the caller typed, and this ends up on the disk where the room
     * photographs live. `getimagesizefromstring` reads the actual image header, so a file
     * claiming to be a PNG and containing something else is refused here rather than stored.
     */
    private function decodePng(string $dataUrl): string
    {
        $comma = strpos($dataUrl, ',');

        $encoded = $comma === false ? $dataUrl : substr($dataUrl, $comma + 1);

        $bytes = base64_decode($encoded, true);

        abort_if($bytes === false || $bytes === '', 422, 'Görüntü çözümlenemedi.');

        $info = @getimagesizefromstring($bytes);

        abort_if($info === false || $info[2] !== IMAGETYPE_PNG, 422, 'Görüntü PNG olmalı.');

        return $bytes;
    }

    /**
     * The boxes the analysis drew on the photograph, and which photograph they are on.
     *
     * The media id rather than a link: a link is a separate, deliberate request that runs the
     * ownership check and expires in five minutes, and one issued here would be issued on
     * every load of this screen whether or not anybody looked at the picture.
     *
     * A box outside the frame is dropped rather than clamped. A model that answered in pixels
     * when it was asked for fractions produces 1440 where it meant 0.75, and a box clamped to
     * the edge is a confident rectangle around the wrong thing — which is precisely the
     * mistake this feature exists to let the customer catch.
     *
     * @return array<string, mixed>|null
     */
    private function detected(Room $room): ?array
    {
        $analysis = RoomAnalysis::query()
            ->where('room_id', $room->getKey())
            ->current()
            ->first();

        if ($analysis === null) {
            return null;
        }

        $regions = [];

        foreach ((array) ($analysis->payload['regions'] ?? []) as $region) {
            if (! is_array($region)) {
                continue;
            }

            $box = $this->boxOf($region);

            if ($box === null) {
                continue;
            }

            $inFrame = array_filter($box, static fn (float|int $value): bool => $value >= 0 && $value <= 1);

            if (count($inFrame) !== 4 || $box[0] >= $box[2] || $box[1] >= $box[3]) {
                continue;
            }

            $regions[] = [
                'kind' => is_string($region['kind'] ?? null) ? $region['kind'] : 'other',
                'label' => is_string($region['label'] ?? null) ? $region['label'] : null,
                'box' => array_map(static fn (float|int $value): float => round((float) $value, 4), $box),
            ];
        }

        return [
            'media_id' => $analysis->media_id,
            'regions' => $regions,
            'warnings' => array_values(array_filter(
                (array) ($analysis->warnings ?? []),
                static fn (mixed $warning): bool => is_string($warning),
            )),
        ];
    }

    /**
     * A region's box as the screen draws it: [x1, y1, x2, y2] as fractions of the picture.
     *
     * The model answers in its own convention — `box_2d` as [ymin, xmin, ymax, xmax] on a
     * 0–1000 grid, which is how it was taught to point at things — and older readings
     * carried `box` in ours. Both are accepted; the conversion lives here and nowhere else.
     *
     * @param  array<string, mixed>  $region
     * @return array{float, float, float, float}|null
     */
    private function boxOf(array $region): ?array
    {
        $numbers = static fn (mixed $list): array => array_values(array_filter(
            (array) $list,
            static fn (mixed $value): bool => is_int($value) || is_float($value),
        ));

        $native = $numbers($region['box_2d'] ?? []);

        if (count($native) === 4) {
            [$top, $left, $bottom, $right] = $native;

            return [$left / 1000, $top / 1000, $right / 1000, $bottom / 1000];
        }

        $ours = $numbers($region['box'] ?? []);

        if (count($ours) === 4) {
            return [(float) $ours[0], (float) $ours[1], (float) $ours[2], (float) $ours[3]];
        }

        return null;
    }

    /**
     * The newest finished design in this room, for the screen to branch a final image from.
     *
     * @return array<string, mixed>|null
     */
    private function designSummary(Room $room): ?array
    {
        $version = $this->latestVersion($room, '');

        if ($version === null) {
            return null;
        }

        return [
            'design_id' => $version->design_id,
            'version_id' => $version->id,
            'version_number' => $version->version_number,
        ];
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
    private function geometry(RoomGeometryVersion $version, ?string $floor = null): array
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
            // What the floor is made of, as far as the photograph said: the planner draws
            // boards, tiles or carpet accordingly. Null when nothing said, and it draws boards.
            'floor' => $floor,
        ];
    }

    /**
     * The floor material the analysis saw, in the planner's three words.
     *
     * The model describes a floor in whatever words it likes — "parquet", "laminat",
     * "porcelain tile", "wall-to-wall carpet" — and the planner has three textures. Anything
     * it cannot place is null, and the planner draws its default rather than guessing.
     */
    private function floorMaterial(Room $room): ?string
    {
        $analysis = RoomAnalysis::query()
            ->where('room_id', $room->getKey())
            ->where('is_current', true)
            ->latest('created_at')
            ->first();

        $material = $analysis?->surfaces['floor']['material'] ?? null;

        if (! is_string($material)) {
            return null;
        }

        $word = mb_strtolower($material);

        return match (true) {
            (bool) preg_match('/wood|parquet|parke|ahşap|laminat|timber|oak|meşe/u', $word) => 'wood',
            (bool) preg_match('/tile|ceramic|seramik|fayans|porcelain|marble|mermer|stone|taş|granit/u', $word) => 'tile',
            (bool) preg_match('/carpet|halı|hali|rug|moquette|moket/u', $word) => 'carpet',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function opening(RoomConstraint $constraint): array
    {
        return [
            'id' => $constraint->id,
            'type' => $constraint->type->value,
            'variant' => $constraint->variant?->value,
            'swing' => $constraint->swing?->value,
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
        $layout->loadMissing([
            'items.product.primaryCategory',
            // The photograph the editor puts on the front of each box, eager because a layout
            // is a dozen items and a query each is a dozen queries for one screen.
            'items.product.media',
            'items.sku.dimensions',
        ]);

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

        /*
         * The seller's own file first, a mesh generated from their photograph second.
         *
         * One is the shape of the thing; the other is a likeness whose far side was never
         * photographed. Ordered here rather than in the query because a layout carries a
         * dozen products and this is a sort of at most two rows each.
         */
        $model = $item->product?->media
            ?->where('type', 'model_3d')
            ->sortBy(fn (ProductMedia $media): int => $media->source === 'seller' ? 0 : 1)
            ->first();

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
            /*
             * The product's own photograph, which the editor wraps onto the front of the box.
             *
             * A room of anonymous brown boxes at the right sizes answers "does it fit" and
             * nothing else; the customer cannot tell which box is the sofa they chose. The
             * picture is on the public product disk — it is a shop photograph, not anything
             * of theirs — so a plain URL is the right thing here and would not be two lines
             * further down, where the room photographs live.
             */
            'image_url' => $item->product?->media?->firstWhere('type', 'image')?->url(),
            // What the piece costs now, so the room can keep a running total beside the
            // customer while they arrange it (K20): a product added or removed moves the sum.
            'price' => $item->sku?->effectivePrice()->jsonSerialize(),

            /*
             * A 3D model, when the product has one.
             *
             * The seller's own file wins over a mesh generated from their photograph: one is
             * the shape of the thing and the other is a likeness whose far side was never
             * photographed. The editor scales whichever it gets to the variant's recorded
             * dimensions, so a model that came back at the wrong size is corrected rather
             * than believed.
             */
            'model_url' => $model?->url(),

            /*
             * Whether that model is the real shape or a likeness.
             *
             * The planner says so on screen. A mesh made from a single photograph guessed its
             * far side, and somebody walking round the back of a sofa should know they are
             * looking at a guess rather than at the thing they are about to buy.
             */
            'model_source' => $model?->source,
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
