<?php

declare(strict_types=1);

namespace App\Domains\Ai\Providers;

use App\Domains\Ai\Contracts\AiProvider;
use App\Domains\Ai\Enums\AiFailureKind;
use App\Domains\Ai\Enums\AiModality;
use App\Domains\Ai\Services\AiCall;
use App\Domains\Ai\Services\AiResult;
use App\Domains\Ai\Services\GeneratedImageStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Turns a product photograph into a 3D model, through fal.ai.
 *
 * One modality and one job: a catalogue photograph in, a glTF binary out, once per product,
 * for the room planner. Nothing a customer waits for and nothing a render ever uses.
 *
 * Three things about this are decisions rather than details.
 *
 * **The mesh's size is not trusted.** Tripo can guess real-world dimensions and the guess is
 * discarded: the catalogue already knows the variant is 2200 mm wide because a seller
 * measured it, and a model scaled to its own guess is the "beautiful model at the wrong size"
 * that makes a planner worse than a box. The editor normalises every mesh to the SKU's
 * recorded dimensions.
 *
 * **The photograph is sent as a URL rather than as bytes.** It is a shop photograph on the
 * public product disk — the one thing in this system that is already public — so there is
 * nothing to protect and a URL costs a fraction of the request. Room photographs go nowhere
 * near this provider, and there is no path here that could send one: the only input is a
 * product image.
 *
 * **A synchronous call rather than a queued one.** Generation is seconds, the queue API would
 * be a second polling loop to maintain, and this already runs inside a queued job — which is
 * the right place for something that takes a moment.
 */
final class FalAiProvider implements AiProvider
{
    private const DEFAULT_BASE_URL = 'https://fal.run';

    /**
     * The model behind the task.
     *
     * Named here rather than in the routing table because it is not interchangeable with
     * the other things on fal: the request and response shapes are this model's own, and
     * swapping the route to a different mesh generator would need a different adapter, which
     * is exactly what the driver column is for.
     */
    private const MODEL_PATH = 'tripo3d/tripo/v2.5/image-to-3d';

    /**
     * Generous, and deliberately so.
     *
     * Nobody is watching: this runs in a queue after a listing is approved. A timeout that
     * fires while a mesh is being written is a listing with no model and a seller wondering
     * why, which costs more than a worker sitting still for two minutes.
     */
    private const TIMEOUT_SECONDS = 180;

    public function __construct(private readonly GeneratedImageStore $files) {}

    public function driver(): string
    {
        return 'fal';
    }

    public function supports(AiCall $call): bool
    {
        return $call->model->modality === AiModality::Model3d;
    }

    public function execute(AiCall $call): AiResult
    {
        if (! $this->supports($call)) {
            return AiResult::failure(
                AiFailureKind::NoRouteConfigured,
                'fal.ai yalnızca 3B model üretimi için yapılandırılabilir.',
            );
        }

        $key = $call->apiKey;

        if ($key === null || $key === '') {
            return AiResult::failure(
                AiFailureKind::NoRouteConfigured,
                'fal.ai anahtarı tanımlı değil.',
            );
        }

        $image = $call->imageUrls[0] ?? null;

        if (! is_string($image) || $image === '') {
            /*
             * Said as a configuration failure rather than a provider one.
             *
             * There is no photograph to work from, which is a fact about the catalogue and
             * not about fal.ai — a seller has not uploaded one, or the URL is not reachable
             * from outside. Reporting it as a provider error sends somebody to the wrong logs.
             */
            return AiResult::failure(
                AiFailureKind::NoRouteConfigured,
                'Modeli üretilecek ürün görseli yok.',
            );
        }

        try {
            $response = Http::withHeaders([
                // fal's own scheme: the word Key, then the credential.
                'Authorization' => 'Key '.$key,
                'Content-Type' => 'application/json',
            ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(rtrim($call->options['base_url'] ?? self::DEFAULT_BASE_URL, '/').'/'.self::MODEL_PATH, [
                    'image_url' => $image,
                    // Standard textures. HD costs a third more per model and the difference
                    // is invisible on a sofa seen across a room, which is the only place
                    // these are ever shown.
                    'texture' => 'standard',
                    /*
                     * Left off on purpose: `auto_size` asks the model to guess real-world
                     * dimensions, and the catalogue already knows them because a seller
                     * measured the thing. A mesh scaled to a guess is the "beautiful model at
                     * the wrong size" that makes a planner worse than a box.
                     */
                    'face_limit' => (int) ($call->options['face_limit'] ?? 20_000),
                ]);
        } catch (ConnectionException $e) {
            return AiResult::failure(AiFailureKind::NetworkError, $e->getMessage());
        } catch (Throwable $e) {
            return AiResult::failure(AiFailureKind::ProviderError, $e->getMessage());
        }

        if ($response->failed()) {
            return AiResult::failure(
                $this->kindFor($response->status()),
                $this->messageFrom($response->json(), $response->status()),
                $response->status(),
            );
        }

        $url = $response->json('model_mesh.url');

        if (! is_string($url) || $url === '') {
            return AiResult::failure(
                AiFailureKind::MalformedOutput,
                'fal.ai bir mesh döndürmedi.',
                $response->status(),
            );
        }

        try {
            $mesh = Http::timeout(self::TIMEOUT_SECONDS)->get($url);
        } catch (Throwable $e) {
            return AiResult::failure(AiFailureKind::NetworkError, $e->getMessage());
        }

        if ($mesh->failed()) {
            return AiResult::failure(
                AiFailureKind::NetworkError,
                'Üretilen mesh indirilemedi.',
                $mesh->status(),
            );
        }

        /*
         * Staged on the private disk and handed on as a reference.
         *
         * The same path every generated file takes. Where it finally lives — the public
         * product disk, beside the photograph it was made from — is a decision for the job
         * that asked for it, not for the adapter that fetched it.
         */
        $reference = $this->files->stash($mesh->body(), 'model/gltf-binary');

        return AiResult::success(
            imageRefs: [$reference],
            imageCount: 1,
            httpStatus: $response->status(),
        );
    }

    private function kindFor(int $status): AiFailureKind
    {
        return match (true) {
            $status === 401 || $status === 403 => AiFailureKind::AuthenticationFailed,
            $status === 429 => AiFailureKind::RateLimited,
            $status >= 500 => AiFailureKind::ProviderError,
            default => AiFailureKind::InvalidRequest,
        };
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function messageFrom(?array $body, int $status): string
    {
        $detail = $body['detail'] ?? $body['error'] ?? null;

        if (is_string($detail) && $detail !== '') {
            return $detail;
        }

        if (is_array($detail)) {
            $first = $detail[0]['msg'] ?? null;

            if (is_string($first)) {
                return $first;
            }
        }

        return sprintf('fal.ai %d ile yanıt verdi.', $status);
    }
}
