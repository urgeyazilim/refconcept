<?php

declare(strict_types=1);

namespace App\Domains\Projects\Http\Controllers;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Projects\Models\DesignAsset;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Room;
use App\Domains\Projects\Models\RoomMedia;
use App\Domains\Projects\Services\RoomPhotoStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A customer's room photographs, and the renders made from them.
 *
 * Nothing here returns a bare URL. A listing returns ids and metadata; a link is a
 * separate, deliberate request that runs the ownership check and hands back a URL that
 * expires in five minutes. That extra round trip is the price of a photograph of
 * somebody's home not being one leaked log line away from public.
 *
 * The download routes exist for storage drivers that cannot sign — the local disk in
 * tests and bare setups. The bytes pass through the application so the policy still
 * applies, which a public path would not.
 */
final class RoomMediaController
{
    public function __construct(
        private readonly RoomPhotoStorage $storage,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, Project $project, Room $room): JsonResponse
    {
        $this->authorizeProject($request, $project, 'view');
        $this->assertBelongs($room, $project);

        $room->loadMissing('media');

        return response()->json([
            'data' => $room->media->map(fn (RoomMedia $media): array => $this->summary($media, $room))->all(),
        ]);
    }

    public function store(Request $request, Project $project, Room $room): JsonResponse
    {
        $this->authorizeProject($request, $project);
        $this->assertBelongs($room, $project);

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.(RoomPhotoStorage::MAX_SIZE_BYTES / 1024),
                'mimetypes:'.implode(',', RoomPhotoStorage::ALLOWED_MIME_TYPES),
            ],
            'type' => ['sometimes', 'string', 'in:photo,floor_plan,inspiration,document'],
            'caption' => ['sometimes', 'nullable', 'string', 'max:300'],
            // Whether this becomes the photograph the design engine works from.
            'set_primary' => ['sometimes', 'boolean'],
        ]);

        try {
            $media = $this->storage->store(
                room: $room,
                file: $request->file('file'),
                uploader: $request->user(),
                type: (string) ($validated['type'] ?? 'photo'),
                caption: $validated['caption'] ?? null,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
        }

        // The first photograph becomes the primary one without being asked: a customer
        // who uploads one picture and finds the room still "not ready" has been given a
        // puzzle rather than a product.
        $shouldSetPrimary = ($validated['set_primary'] ?? null) === true
            || ($room->primary_media_id === null && $media->type === 'photo');

        if ($shouldSetPrimary) {
            $room->forceFill(['primary_media_id' => $media->getKey()])->save();
        }

        $this->audit->record(
            action: 'projects.room_media.uploaded',
            subject: $media,
            // Deliberately no filename and no caption: the audit log is read by staff,
            // and "bebek-odasi-2.jpg" tells them something they have no business
            // knowing. The id and the size are enough to investigate a problem.
            context: ['room_id' => $room->getKey(), 'size_bytes' => $media->size_bytes],
            actor: $request->user(),
        );

        return response()->json(['data' => $this->summary($media->fresh(), $room->fresh())], 201);
    }

    /** A short-lived link, issued only after the ownership check above. */
    public function link(Request $request, Project $project, Room $room, RoomMedia $medium): JsonResponse
    {
        $this->authorizeProject($request, $project, 'view');
        $this->assertBelongs($room, $project);
        abort_unless($medium->room_id === $room->getKey(), 404);

        return response()->json([
            'data' => [
                'url' => $this->storage->temporaryUrl($medium),
                'expires_in' => 300,
            ],
        ]);
    }

    /**
     * Streams a photograph for drivers that cannot sign a URL.
     *
     * Authorised through the room's project, so the policy applies to the bytes and
     * not merely to the metadata.
     */
    public function download(Request $request, RoomMedia $medium): StreamedResponse
    {
        $medium->loadMissing('room.project');

        $project = $medium->room?->project;

        abort_if($project === null, 404);
        abort_unless($request->user()?->can('view', $project) === true, 403);

        abort_unless($this->storage->exists($medium->disk, $medium->storage_path), 404);

        $stream = $this->storage->readStream($medium->disk, $medium->storage_path);

        abort_if($stream === null, 404);

        return $this->stream($stream, $medium->mime_type, $medium->original_name);
    }

    /** The same, for an image a design version produced. */
    public function downloadAsset(Request $request, DesignAsset $asset): StreamedResponse
    {
        $asset->loadMissing('version.design.room.project');

        $project = $asset->version?->design?->room?->project;

        abort_if($project === null, 404);
        abort_unless($request->user()?->can('view', $project) === true, 403);

        abort_unless($this->storage->exists($asset->disk, $asset->storage_path), 404);

        $stream = $this->storage->readStream($asset->disk, $asset->storage_path);

        abort_if($stream === null, 404);

        return $this->stream($stream, $asset->mime_type, 'tasarim.'.pathinfo($asset->storage_path, PATHINFO_EXTENSION));
    }

    public function update(Request $request, Project $project, Room $room, RoomMedia $medium): JsonResponse
    {
        $this->authorizeProject($request, $project);
        $this->assertBelongs($room, $project);
        abort_unless($medium->room_id === $room->getKey(), 404);

        $validated = $request->validate([
            'caption' => ['sometimes', 'nullable', 'string', 'max:300'],
            'set_primary' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('caption', $validated)) {
            $medium->update(['caption' => $validated['caption']]);
        }

        if (($validated['set_primary'] ?? null) === true) {
            abort_if(
                $medium->type !== 'photo',
                422,
                'Yalnızca bir fotoğraf tasarımın çalışacağı görsel olarak seçilebilir.',
            );

            $room->forceFill(['primary_media_id' => $medium->getKey()])->save();
        }

        return response()->json(['data' => $this->summary($medium->fresh(), $room->fresh())]);
    }

    public function destroy(Request $request, Project $project, Room $room, RoomMedia $medium): JsonResponse
    {
        $this->authorizeProject($request, $project);
        $this->assertBelongs($room, $project);
        abort_unless($medium->room_id === $room->getKey(), 404);

        $wasPrimary = $room->primary_media_id === $medium->getKey();

        $disk = $medium->disk;
        $path = $medium->storage_path;

        $medium->delete();

        if ($wasPrimary) {
            // Promote another photograph rather than leaving the room unable to be
            // designed with pictures still visibly in it.
            $replacement = $room->media()->where('type', 'photo')->orderBy('position')->first();

            $room->forceFill(['primary_media_id' => $replacement?->getKey()])->save();
        }

        /*
         * The bytes go too. Unlike a seller's onboarding document there is no dispute
         * this could later have to answer, and keeping a picture of somebody's home
         * after they asked for it gone is indefensible.
         */
        $this->storage->purge($disk, $path);

        $this->audit->record(
            action: 'projects.room_media.deleted',
            subject: $medium,
            context: ['room_id' => $room->getKey()],
            actor: $request->user(),
        );

        return response()->json(['message' => 'Fotoğraf kaldırıldı.']);
    }

    // --- helpers -------------------------------------------------------------

    /**
     * @param  resource  $stream
     */
    private function stream($stream, string $mimeType, string $filename): StreamedResponse
    {
        return response()->stream(
            function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            },
            200,
            [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'inline; filename="'.addslashes($filename).'"',
                // Never cached by a proxy: this is one person's photograph, and a
                // shared cache is a way for it to become two people's.
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(RoomMedia $media, Room $room): array
    {
        return [
            'id' => $media->id,
            'type' => $media->type,
            'original_name' => $media->original_name,
            'mime_type' => $media->mime_type,
            'size_bytes' => $media->size_bytes,
            'width' => $media->width,
            'height' => $media->height,
            'caption' => $media->caption,
            'position' => $media->position,
            'is_primary' => $room->primary_media_id === $media->id,
            'uploaded_at' => $media->created_at?->toIso8601String(),
            // No URL. A link is a separate request that checks ownership and expires.
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
