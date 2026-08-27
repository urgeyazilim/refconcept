<?php

declare(strict_types=1);

namespace App\Support\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Signs a link a browser can actually open.
 *
 * Object storage is reached by two different names, and until now only one of them was
 * considered. The API container talks to MinIO at `http://minio:9000` — a name that exists
 * only on the Docker network — while the browser reaches the same bucket at
 * `http://localhost:59000`. Laravel signs a temporary URL against the client's endpoint, so
 * every signed link pointed at `minio:9000` and every room photograph rendered as a broken
 * image with its filename showing.
 *
 * The host cannot simply be swapped afterwards: SigV4 signs the Host header, so rewriting it
 * turns a working link into a rejected one. What is needed is to *sign* against the host the
 * browser will use, which is what this does — the same credentials and bucket, a different
 * endpoint, used only for producing links.
 *
 * In production the two names are usually the same and this changes nothing. It exists for
 * every deployment where they are not: a container network, a private VPC endpoint, a CDN in
 * front of the bucket.
 */
final class PrivateLinkSigner
{
    /** @var array<string, Filesystem> */
    private array $signers = [];

    /**
     * A link to a stored object, valid for a few minutes.
     *
     * @throws RuntimeException when the disk cannot produce one at all
     */
    public function url(string $disk, string $path, Carbon $expiresAt): string
    {
        $filesystem = $this->signerFor($disk);

        if (! method_exists($filesystem, 'temporaryUrl')) {
            /*
             * The local disk cannot sign anything. Failing loudly is right: a caller that
             * silently received a permanent public path instead of a signed link would be
             * publishing private files while appearing to work.
             */
            throw new RuntimeException('Bu disk imzalı bağlantı üretemiyor.');
        }

        return $filesystem->temporaryUrl($path, $expiresAt);
    }

    /**
     * The disk to sign with: the configured one, or a copy of it pointed at the public host.
     */
    private function signerFor(string $disk): Filesystem
    {
        if (isset($this->signers[$disk])) {
            return $this->signers[$disk];
        }

        /** @var array<string, mixed> $config */
        $config = (array) config('filesystems.disks.'.$disk, []);

        $public = (string) config('refconcept.storage.public_endpoint', '');

        // Nothing configured, or not an S3 disk: sign with the disk as it stands. That is
        // the production case, where the two names are the same.
        if ($public === '' || ($config['driver'] ?? null) !== 's3') {
            return $this->signers[$disk] = Storage::disk($disk);
        }

        $config['endpoint'] = rtrim($public, '/');

        // `url` is what Storage::url() would return and has no bearing on signing; it is
        // rewritten too so a caller that reaches for it does not get the internal name.
        $config['url'] = rtrim($public, '/').'/'.($config['bucket'] ?? '');

        return $this->signers[$disk] = Storage::build($config);
    }
}
