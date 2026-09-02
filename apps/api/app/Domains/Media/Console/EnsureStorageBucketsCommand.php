<?php

declare(strict_types=1);

namespace App\Domains\Media\Console;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Console\Command;
use Throwable;

/**
 * Makes sure the two buckets exist before anything tries to write to one.
 *
 * This used to be a one-shot `minio-init` container that created the buckets and exited.
 * It worked, and it cost the deployment: Coolify judges a compose application by whether
 * every container in it is running, saw a permanently exited one, decided the application
 * had stopped, and ran StopApplication followed by a Docker prune. Ten hours after a clean
 * deploy the entire stack was gone — containers removed, images pruned — with nothing wrong
 * anywhere. A container that exits on purpose is a container a platform will eventually
 * misread.
 *
 * So it is a command instead, run from the same entrypoint that runs the migrations, in a
 * container that stays up. Nothing exits, and there is nothing for a health check to
 * misinterpret.
 *
 * Idempotent and quiet about it: on every boot after the first it finds both buckets and
 * says so.
 */
final class EnsureStorageBucketsCommand extends Command
{
    protected $signature = 'refconcept:ensure-buckets';

    protected $description = 'Özel ve genel depolama kovalarını yoksa oluşturur.';

    public function handle(): int
    {
        $private = (string) config('filesystems.disks.s3.bucket');
        $public = (string) config('filesystems.disks.s3-public.bucket');

        if ($private === '' && $public === '') {
            $this->info('Depolama kovası tanımlı değil; atlanıyor.');

            return self::SUCCESS;
        }

        try {
            $client = $this->client();
        } catch (Throwable $e) {
            $this->error('Depolamaya bağlanılamadı: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach (array_filter([$private, $public]) as $bucket) {
            if (! $this->ensure($client, $bucket)) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function client(): S3Client
    {
        /** @var array<string, mixed> $disk */
        $disk = (array) config('filesystems.disks.s3', []);

        return new S3Client([
            'version' => 'latest',
            'region' => (string) ($disk['region'] ?? 'us-east-1'),
            'endpoint' => (string) ($disk['endpoint'] ?? ''),
            // MinIO and most S3-compatible stores address buckets by path rather than by
            // subdomain, and a client that guesses wrong fails with a DNS error rather than
            // anything that mentions buckets.
            'use_path_style_endpoint' => (bool) ($disk['use_path_style_endpoint'] ?? true),
            'credentials' => [
                'key' => (string) ($disk['key'] ?? ''),
                'secret' => (string) ($disk['secret'] ?? ''),
            ],
        ]);
    }

    private function ensure(S3Client $client, string $bucket): bool
    {
        try {
            $client->headBucket(['Bucket' => $bucket]);

            $this->line("kova hazır: {$bucket}");

            return true;
        } catch (S3Exception $e) {
            // Anything other than "it is not there" is a real problem — wrong credentials,
            // the wrong endpoint, a bucket somebody else owns — and creating over it would
            // turn a clear error into a confusing one.
            if (! in_array($e->getStatusCode(), [403, 404], true)) {
                $this->error("kova okunamadı ({$bucket}): ".$e->getAwsErrorCode());

                return false;
            }

            if ($e->getStatusCode() === 403) {
                // It exists and belongs to somebody else, or these credentials cannot see
                // it. Either way creating it is not the answer.
                $this->error("kovaya erişim reddedildi: {$bucket}");

                return false;
            }
        }

        try {
            $client->createBucket(['Bucket' => $bucket]);

            $this->line("kova oluşturuldu: {$bucket}");

            return true;
        } catch (S3Exception $e) {
            /*
             * Two containers booting together can both find the bucket missing and both try
             * to create it. The loser gets this, and it is a success: the bucket is there.
             */
            if (in_array($e->getAwsErrorCode(), ['BucketAlreadyOwnedByYou', 'BucketAlreadyExists'], true)) {
                $this->line("kova zaten var: {$bucket}");

                return true;
            }

            $this->error("kova oluşturulamadı ({$bucket}): ".$e->getAwsErrorCode());

            return false;
        }
    }
}
