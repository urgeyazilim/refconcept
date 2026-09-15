<?php

declare(strict_types=1);

namespace App\Domains\Products\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Makes a generated mesh one the browser can afford.
 *
 * A generator answers with whatever it likes — Tripo was asked for twenty thousand faces and
 * sent a hundred and one thousand — and the planner has to draw ten of these in one room on
 * a laptop with an integrated GPU. The mesh-tools container welds, decimates towards the
 * target, compresses the geometry and shrinks the textures; this is the call to it.
 *
 * **Never a reason to have no model.** If the service is down, slow or unhappy, the raw mesh
 * is stored as it came. A heavy model is worse than a light one and better than none.
 */
final class MeshOptimiser
{
    /** What the planner wants: enough for a sofa's silhouette across a room, no more. */
    public const TARGET_FACES = 20_000;

    /** Texture edge in pixels. A cushion seen from three metres does not need 4K. */
    public const TEXTURE_PX = 1024;

    private const TIMEOUT_SECONDS = 120;

    /**
     * @return array{bytes: string, optimised: bool, triangles_before: int|null, triangles_after: int|null}
     */
    public function optimise(string $bytes): array
    {
        $base = (string) config('services.mesh_tools.url', '');

        $raw = ['bytes' => $bytes, 'optimised' => false, 'triangles_before' => null, 'triangles_after' => null];

        if ($base === '') {
            return $raw;
        }

        try {
            $response = Http::withBody($bytes, 'model/gltf-binary')
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(rtrim($base, '/').'/optimise?'.http_build_query([
                    'faces' => self::TARGET_FACES,
                    'texture' => self::TEXTURE_PX,
                ]));
        } catch (Throwable $e) {
            Log::warning('Mesh sadeleştirilemedi; ham model kaydedilecek.', ['reason' => $e->getMessage()]);

            return $raw;
        }

        $body = $response->body();

        if ($response->failed() || strlen($body) < 20 || ! str_starts_with($body, 'glTF')) {
            Log::warning('Mesh sadeleştirilemedi; ham model kaydedilecek.', ['status' => $response->status()]);

            return $raw;
        }

        return [
            'bytes' => $body,
            'optimised' => true,
            'triangles_before' => (int) $response->header('X-Triangles-Before') ?: null,
            'triangles_after' => (int) $response->header('X-Triangles-After') ?: null,
        ];
    }
}
