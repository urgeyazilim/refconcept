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
use RuntimeException;
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
 * **The photograph goes onto fal's own storage before the generator sees it.** The job hands
 * over the bytes as a data URI; a link to our bucket only works where the bucket is reachable
 * from the internet, and a local MinIO is not. The generator itself cannot open a data URI
 * either — both were tried for real — so the adapter uploads it to fal's CDN, free, and passes
 * that link. Nothing in a shop photograph needs protecting. Room photographs go nowhere near this provider, and there is no path here that could send
 * one: the only input is a product image.
 *
 * **A synchronous call rather than a queued one.** Generation is seconds, the queue API would
 * be a second polling loop to maintain, and this already runs inside a queued job — which is
 * the right place for something that takes a moment.
 */
final class FalAiProvider implements AiProvider
{
    private const DEFAULT_BASE_URL = 'https://fal.run';

    /** fal's file storage. Uploads are free; what they return is a link the models can fetch. */
    private const STORAGE_URL = 'https://rest.alpha.fal.ai';

    /**
     * The model behind the task.
     *
     * Named here rather than in the routing table because it is not interchangeable with
     * the other things on fal: the request and response shapes are this model's own, and
     * swapping the route to a different mesh generator would need a different adapter, which
     * is exactly what the driver column is for.
     */
    private const SINGLE_PATH = 'tripo3d/tripo/v2.5/image-to-3d';

    /**
     * The same model, given four sides instead of one.
     *
     * Identical price. With only a front it has to invent the back of the sofa; given the
     * back it does not, which makes this the cheapest quality available anywhere in this
     * system — the only cost is knowing which photograph is which.
     */
    private const MULTIVIEW_PATH = 'tripo3d/tripo/v2.5/multiview-to-3d';

    /**
     * Generous, and deliberately so.
     *
     * Nobody is watching: this runs in a queue after a listing is approved. A timeout that
     * fires while a mesh is being written is a listing with no model and a seller wondering
     * why — and a bill for a mesh nobody received. Tripo takes a minute; Rodin at its regular
     * tier can take four. Nine minutes is longer than any of them and shorter than the AI
     * worker's own limit.
     */
    private const TIMEOUT_SECONDS = 540;

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

        /*
         * Four views when four views are known, one when only one is.
         *
         * A different endpoint rather than a flag, because that is how the provider is built —
         * and the price is identical, which is the whole reason to bother: given the back, the
         * generator stops inventing one for nothing.
         *
         * Only the four sides are passed on. A detail shot or a photograph of the sofa in
         * somebody's living room would be a fifth angle of a different scene, and a generator
         * told that is the left side produces something that is not furniture.
         */
        $views = array_filter(
            (array) ($call->options['views'] ?? []),
            static fn (mixed $url, string $side): bool => is_string($url)
                && $url !== ''
                && in_array($side, ['front', 'left', 'back', 'right'], true),
            ARRAY_FILTER_USE_BOTH,
        );

        /*
         * Every photograph onto fal's own storage first.
         *
         * The generator sits behind fal and downloads what it is given; it cannot open a data
         * URI and it cannot reach a bucket on somebody's laptop. Both were tried against the
         * real endpoint and both came back "Failed to download the file". Uploading is free,
         * returns a link on fal's CDN, and that link is reachable from wherever the model runs.
         */
        try {
            $image = $this->hosted($image, $key, $call->options);

            $views = array_map(fn (string $url): string => $this->hosted($url, $key, $call->options), $views);
        } catch (Throwable $e) {
            return AiResult::failure(
                AiFailureKind::NetworkError,
                'Ürün görseli fal.ai deposuna yüklenemedi: '.$e->getMessage(),
            );
        }

        $faceLimit = (int) ($call->options['face_limit'] ?? 20_000);

        ['path' => $path, 'body' => $body] = $this->requestFor($call->model->code, $image, $views, $faceLimit);

        try {
            $response = Http::withHeaders([
                // fal's own scheme: the word Key, then the credential.
                'Authorization' => 'Key '.$key,
                'Content-Type' => 'application/json',
            ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(rtrim($call->options['base_url'] ?? self::DEFAULT_BASE_URL, '/').'/'.$path, $body);
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

        $url = $this->meshUrlFrom((array) $response->json());

        if ($url === null) {
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
                // The generation's status, not the download's: fal billed for a model that
                // was made, and the ledger has to say so even though we could not fetch it.
                $response->status(),
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

    /**
     * The endpoint and the body, for whichever generator the routing table named.
     *
     * The model's code *is* its fal path — `tripo3d/tripo/v2.5/image-to-3d`,
     * `fal-ai/hyper3d/rodin/v2.5` — and each family has its own idea of how to be handed a
     * photograph: one field, four named fields, or a list. What every family gets asked for is
     * the same: a textured mesh, about twenty thousand faces, and no guess at its real size.
     *
     * Four views when four views are known. Given the back, none of them invents one, and none
     * charges more for it — which is the whole reason the catalogue labels its photographs.
     *
     * @param  array<string, string>  $views  front/left/back/right, whichever are known
     * @return array{path: string, body: array<string, mixed>}
     */
    private function requestFor(string $code, string $front, array $views, int $faceLimit): array
    {
        $ordered = array_values(array_filter([
            $views['front'] ?? $front,
            $views['left'] ?? null,
            $views['back'] ?? null,
            $views['right'] ?? null,
        ]));

        $multiview = count($ordered) > 1;

        // Tripo 2.5: two endpoints, four named fields on the second.
        if (str_starts_with($code, 'tripo3d/tripo/')) {
            return [
                'path' => $multiview ? self::MULTIVIEW_PATH : self::SINGLE_PATH,
                'body' => [
                    ...($multiview
                        ? [
                            'front_image_url' => $views['front'] ?? $front,
                            ...(isset($views['left']) ? ['left_image_url' => $views['left']] : []),
                            ...(isset($views['back']) ? ['back_image_url' => $views['back']] : []),
                            ...(isset($views['right']) ? ['right_image_url' => $views['right']] : []),
                        ]
                        : ['image_url' => $front]),
                    // Standard textures. HD costs a third more per model and the difference is
                    // invisible on a sofa seen across a room, which is the only place these are shown.
                    'texture' => 'standard',
                    /*
                     * `auto_size` is left off on purpose: it asks the model to guess real-world
                     * dimensions, and the catalogue already knows them because a seller measured
                     * the thing. A mesh scaled to a guess is the "beautiful model at the wrong
                     * size" that makes a planner worse than a box.
                     */
                    'face_limit' => $faceLimit,
                ],
            ];
        }

        // Tripo H3.1: the same two endpoints, but the multiview one takes a list of 2–4.
        if (str_starts_with($code, 'tripo3d/h3.1/')) {
            $base = 'tripo3d/h3.1';

            return [
                'path' => $multiview ? $base.'/multiview-to-3d' : $base.'/image-to-3d',
                'body' => [
                    ...($multiview ? ['image_urls' => $ordered] : ['image_url' => $front]),
                    'texture' => true,
                    'pbr' => true,
                    'texture_quality' => 'standard',
                    'face_limit' => $faceLimit,
                ],
            ];
        }

        // Rodin: one endpoint, a list of up to five images, any angles.
        if (str_starts_with($code, 'fal-ai/hyper3d/rodin')) {
            return [
                'path' => $code,
                'body' => [
                    'image_urls' => $ordered,
                    'geometry_file_format' => 'glb',
                    'material' => 'PBR',
                ],
            ];
        }

        // Hunyuan3D v3: one endpoint, the front named and the other three optional.
        if (str_starts_with($code, 'fal-ai/hunyuan3d-v3/')) {
            return [
                'path' => $code,
                'body' => [
                    'input_image_url' => $views['front'] ?? $front,
                    ...(isset($views['back']) ? ['back_image_url' => $views['back']] : []),
                    ...(isset($views['left']) ? ['left_image_url' => $views['left']] : []),
                    ...(isset($views['right']) ? ['right_image_url' => $views['right']] : []),
                    'enable_pbr' => true,
                    'face_count' => $faceLimit,
                ],
            ];
        }

        // Anything else on fal that takes a single `image_url` and answers with a mesh.
        return ['path' => $code, 'body' => ['image_url' => $front]];
    }

    /**
     * Where the mesh is, in whichever field this family puts it.
     *
     * @param  array<string, mixed>  $json
     */
    private function meshUrlFrom(array $json): ?string
    {
        foreach (['model_mesh', 'model_glb', 'model_glb_pbr'] as $field) {
            $url = $json[$field]['url'] ?? null;

            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        $url = $json['model_urls']['glb']['url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * A link the generator can download: a data URI is uploaded, anything else is left alone.
     *
     * Two calls, both free: ask fal for an upload slot, then put the bytes in it. What comes
     * back is a file on fal's CDN, which is the one host the model is guaranteed to reach.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws RuntimeException when the upload is refused
     */
    private function hosted(string $image, string $key, array $options): string
    {
        if (! str_starts_with($image, 'data:')) {
            return $image;
        }

        if (preg_match('#^data:([^;,]+);base64,(.+)$#s', $image, $parts) !== 1) {
            throw new RuntimeException('Görsel verisi okunamadı.');
        }

        $mime = $parts[1];
        $bytes = base64_decode($parts[2], true);

        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Görsel verisi çözülemedi.');
        }

        $initiate = Http::withHeaders(['Authorization' => 'Key '.$key])
            ->timeout(60)
            ->post(
                rtrim((string) ($options['storage_url'] ?? self::STORAGE_URL), '/').'/storage/upload/initiate?storage_type=fal-cdn-v3',
                [
                    'content_type' => $mime,
                    'file_name' => 'product.'.(explode('/', $mime)[1] ?? 'bin'),
                ],
            );

        $uploadUrl = $initiate->json('upload_url');
        $fileUrl = $initiate->json('file_url');

        if ($initiate->failed() || ! is_string($uploadUrl) || ! is_string($fileUrl)) {
            throw new RuntimeException(sprintf('yükleme başlatılamadı (%d)', $initiate->status()));
        }

        $put = Http::withBody($bytes, $mime)->timeout(60)->put($uploadUrl);

        if ($put->failed()) {
            throw new RuntimeException(sprintf('yükleme tamamlanamadı (%d)', $put->status()));
        }

        return $fileUrl;
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
