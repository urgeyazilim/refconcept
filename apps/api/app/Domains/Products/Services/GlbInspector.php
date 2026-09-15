<?php

declare(strict_types=1);

namespace App\Domains\Products\Services;

/**
 * Reads what a glTF binary says about itself, without a 3D library.
 *
 * Enough to compare generators: how many triangles, how many textures, and whether the file is
 * one at all. A GLB is a twelve-byte header, then a JSON chunk, then binary; everything this
 * needs is in the JSON, so the binary chunk is never touched.
 */
final class GlbInspector
{
    /** @return array{triangles: int|null, meshes: int, textures: int, bytes: int}|null null when it is not a glTF binary */
    public static function inspect(string $bytes): ?array
    {
        if (strlen($bytes) < 20 || substr($bytes, 0, 4) !== 'glTF') {
            return null;
        }

        $jsonLength = unpack('V', substr($bytes, 12, 4))[1] ?? 0;

        if (! is_int($jsonLength) || $jsonLength <= 0 || 20 + $jsonLength > strlen($bytes)) {
            return null;
        }

        $json = json_decode(substr($bytes, 20, $jsonLength), true);

        if (! is_array($json)) {
            return null;
        }

        /** @var array<int, array{count?: int}> $accessors */
        $accessors = (array) ($json['accessors'] ?? []);

        /** @var array<int, array{primitives?: array<int, array{indices?: int, attributes?: array<string, int>}>}> $meshes */
        $meshes = (array) ($json['meshes'] ?? []);

        $triangles = 0;
        $counted = false;

        foreach ($meshes as $mesh) {
            foreach ((array) ($mesh['primitives'] ?? []) as $primitive) {
                // Indexed geometry counts its indices; unindexed counts its positions.
                $accessor = isset($primitive['indices'])
                    ? ($accessors[$primitive['indices']] ?? null)
                    : ($accessors[$primitive['attributes']['POSITION'] ?? -1] ?? null);

                if ($accessor !== null && isset($accessor['count'])) {
                    $triangles += intdiv((int) $accessor['count'], 3);
                    $counted = true;
                }
            }
        }

        return [
            'triangles' => $counted ? $triangles : null,
            'meshes' => count($meshes),
            'textures' => count((array) ($json['images'] ?? [])),
            'bytes' => strlen($bytes),
        ];
    }
}
