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
        return $call->model->modality === AiModality::Model3d
            || ($call->model->modality === AiModality::Image && $this->isControlled($call));
    }

    /**
     * Whether this is a render the room controls rather than one it only suggests.
     *
     * Read off the model's own code, because that is what decides the request shape: a
     * control-conditioned endpoint takes a depth map where an ordinary one takes nothing, and
     * pointing the route at a plain generator would silently produce a render nothing
     * constrains — which is exactly the failure this whole path exists to end.
     */
    private function isControlled(AiCall $call): bool
    {
        return str_contains($call->model->code, 'control');
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

        /*
         * A reconstruction is not a generation, so it takes the other road entirely.
         *
         * Every photograph at once rather than four labelled sides; a point cloud back rather
         * than a mesh; and no idea of a "front", because the thing being measured is a room
         * and the camera was inside it.
         */
        if (str_contains($call->model->code, 'vggt')) {
            return $this->reconstruct($call, $key);
        }

        if ($call->model->modality === AiModality::Image) {
            return $this->controlled($call, $key);
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
     * Measures a room's shape from every photograph of it at once.
     *
     * VGGT takes a handful of unposed pictures and returns where each camera stood and a
     * coloured point cloud of what they all saw. It says nothing about how big any of it is —
     * the shape is faithful and the scale is arbitrary — which is why what comes back is a
     * shape to be scaled against something known rather than a measurement to be trusted.
     *
     * The photographs go up to fal's storage first, as bytes, exactly as the product path
     * does: a signed link to a customer's room must not leave this system, and a link signed
     * for the browser's host cannot be fetched from where the model runs anyway.
     */
    /**
     * A render the room controls: the arrangement as a depth map, and nothing else of theirs.
     *
     * The whole point is the order of authority. The depth map is what the customer
     * confirmed — their walls at their measurements, their door and window where the reading
     * found them, every product at the size a seller recorded — and a control-conditioned
     * model cannot depart from it. The prompt decides everything the geometry does not:
     * materials, light, colour, style. The colour screenshot this replaces was the same
     * information offered as a suggestion, and their design came back with the window on a
     * different wall from their own flat.
     *
     * **Only the depth map leaves, and that is deliberate.** This provider cannot be handed
     * bytes: it fetches what it is given, so anything sent to it has to be put on fal's CDN
     * first, behind an unguessable but unauthenticated link. A photograph of somebody's
     * living room must never be on such a link, so none is sent — not the photograph, not
     * the plate, not the render. What goes is a grey geometric frame with no colour, no
     * texture, no window view and nothing of theirs in it, and it is deleted from the room
     * the moment there is a better answer.
     *
     * That is also why this endpoint is the text-to-image one rather than the image-to-image
     * one: the second takes a photograph as its colour reference, and the price of that
     * reference is a customer's home on a public URL.
     */
    private function controlled(AiCall $call, string $key): AiResult
    {
        /*
         * The depth map arrives as bytes, the way every other image in this system does.
         *
         * Not as a data URI in the job's options: the input column is JSON somebody reads in
         * a console, and half a megabyte of base64 in it makes the whole row unreadable. The
         * gateway loads the file off the private disk and hands the bytes over, and only
         * this adapter — which has no other way — turns them into a link.
         */
        $blob = $call->imageBlobs[0] ?? null;

        $depth = is_array($blob)
            ? 'data:'.$blob['mime'].';base64,'.$blob['data']
            : ($call->options['depth_url'] ?? null);

        if (! is_string($depth) || $depth === '') {
            /*
             * A configuration failure rather than a provider one.
             *
             * Without the depth map this endpoint is an expensive ordinary renderer, and
             * running it anyway would spend somebody's money on the picture we already knew
             * how to make badly. The caller falls back to the renderer it has.
             */
            return AiResult::failure(
                AiFailureKind::NoRouteConfigured,
                'Odaya bağlı render için derinlik haritası gerekiyor.',
            );
        }

        /*
         * The same view in colour, when the scene drew one.
         *
         * A depth map alone is a grey gradient, and a model trained on maps estimated from
         * photographs answers a CAD gradient with an illustration — three real renders said
         * so. This is the material it starts from: our own render of the confirmed geometry,
         * wearing generic product models. Still nothing photographed.
         */
        $second = $call->imageBlobs[1] ?? null;

        $inside = is_array($second)
            ? 'data:'.$second['mime'].';base64,'.$second['data']
            : null;

        if ($inside === null && str_contains($call->model->code, 'image-to-image')) {
            /*
             * This endpoint starts from a picture and there is not one.
             *
             * A configuration failure rather than a provider one: the scene did not draw
             * the colour frame, which is something about this room rather than about fal.
             * Running it anyway would be a 422 and a bill for nothing.
             */
            return AiResult::failure(
                AiFailureKind::NoRouteConfigured,
                'Odaya bağlı render için odanın 3B karesi gerekiyor.',
            );
        }

        try {
            $controlUrl = $this->hosted($depth, $key, $call->options);
            $insideUrl = $inside === null ? null : $this->hosted($inside, $key, $call->options);
        } catch (Throwable $e) {
            return AiResult::failure(
                AiFailureKind::NetworkError,
                'Oda çizimleri fal.ai deposuna yüklenemedi: '.$e->getMessage(),
            );
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Key '.$key,
                'Content-Type' => 'application/json',
            ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(rtrim($call->options['base_url'] ?? self::DEFAULT_BASE_URL, '/').'/'.$call->model->code, [
                    'prompt' => $call->prompt,
                    'control_lora_image_url' => $controlUrl,
                    ...($insideUrl === null ? [] : [
                        'image_url' => $insideUrl,
                        /*
                         * How far the picture may travel from the render it starts from.
                         *
                         * High, because the render is grey plaster and untextured boxes and
                         * the point is to make it a photograph; not so high that the model
                         * stops using it, which is the whole reason it is there.
                         */
                        'strength' => (float) ($call->options['strength'] ?? 0.82),
                    ]),
                    'image_size' => 'landscape_4_3',
                    /*
                     * How hard the depth map holds the picture.
                     *
                     * At full, because the arrangement is not a style choice: it is what the
                     * customer confirmed, and what they are about to be invited to buy at
                     * those sizes. Everything a control image does not decide — the oak, the
                     * daylight, the wall colour — the prompt decides.
                     */
                    /*
                     * Below full, because full is not what it sounds like.
                     *
                     * The published guidance for these control models is 0.3 to 0.8, and
                     * the reason showed up the first time this ran against a real room: at
                     * 1.0 the flat planes of a CAD depth map come through as flat planes of
                     * colour and the answer is an illustration rather than a photograph.
                     * The arrangement still holds at 0.7 — it is a strong constraint, not a
                     * literal one.
                     */
                    'control_lora_strength' => (float) ($call->options['control_strength'] ?? 0.7),
                    'num_inference_steps' => (int) ($call->options['steps'] ?? 28),
                    'guidance_scale' => (float) ($call->options['guidance'] ?? 3.5),
                    'num_images' => 1,
                    'output_format' => 'png',
                    'enable_safety_checker' => true,
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
                httpStatus: $response->status(),
            );
        }

        /** @var array<int, array<string, mixed>> $images */
        $images = (array) data_get($response->json() ?? [], 'images', []);

        $urls = [];

        foreach ($images as $image) {
            if (isset($image['url']) && is_string($image['url']) && $image['url'] !== '') {
                $urls[] = $image['url'];
            }
        }

        if ($urls === []) {
            return AiResult::failure(
                AiFailureKind::MalformedOutput,
                'fal.ai yanıtında kullanılabilir bir görsel yok.',
                httpStatus: $response->status(),
            );
        }

        return AiResult::success(
            imageUrls: $urls,
            imageCount: count($urls),
            httpStatus: $response->status(),
        );
    }

    private function reconstruct(AiCall $call, string $key): AiResult
    {
        $photographs = array_values(array_filter(
            $call->imageUrls,
            static fn (mixed $url): bool => is_string($url) && $url !== '',
        ));

        if (count($photographs) < 2) {
            return AiResult::failure(
                AiFailureKind::NoRouteConfigured,
                'Oda taraması için en az iki fotoğraf gerekir.',
            );
        }

        try {
            $hosted = array_map(fn (string $url): string => $this->hosted($url, $key, $call->options), $photographs);
        } catch (Throwable $e) {
            return AiResult::failure(
                AiFailureKind::NetworkError,
                'Oda fotoğrafları fal.ai deposuna yüklenemedi: '.$e->getMessage(),
            );
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Key '.$key,
                'Content-Type' => 'application/json',
            ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(rtrim($call->options['base_url'] ?? self::DEFAULT_BASE_URL, '/').'/'.$call->model->code, [
                    'image_urls' => $hosted,
                    // The cloud is the whole answer. Depth maps are one PNG per photograph and
                    // nothing here reads them; the prediction data carries the camera poses.
                    'export_depth_maps' => false,
                    'export_point_cloud' => true,
                    'export_prediction_data' => true,
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

        $url = $response->json('point_cloud.url');

        if (! is_string($url) || $url === '') {
            return AiResult::failure(
                AiFailureKind::MalformedOutput,
                'fal.ai bir nokta bulutu döndürmedi.',
                $response->status(),
            );
        }

        try {
            $cloud = Http::timeout(self::TIMEOUT_SECONDS)->get($url);
        } catch (Throwable $e) {
            return AiResult::failure(AiFailureKind::NetworkError, $e->getMessage());
        }

        if ($cloud->failed()) {
            return AiResult::failure(
                AiFailureKind::NetworkError,
                'Oda taraması indirilemedi.',
                $response->status(),
            );
        }

        return AiResult::success(
            imageRefs: [$this->files->stash($cloud->body(), 'model/gltf-binary')],
            imageCount: 1,
            httpStatus: $response->status(),
            // What the reconstruction believed about the cameras, kept for whoever wants to
            // scale the cloud later: it is the only thing in the answer with units in it.
            structured: [
                'frames' => $response->json('num_frames'),
                'depth_range' => $response->json('depth_range'),
                'extrinsics' => $response->json('extrinsics'),
                'intrinsics' => $response->json('intrinsics'),
            ],
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
                    // Hunyuan refuses anything under forty thousand ("Input should be greater
                    // than or equal to 40000"); the optimiser brings it down afterwards.
                    'face_count' => max(40_000, $faceLimit),
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
