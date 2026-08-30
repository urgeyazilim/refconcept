<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Models\AiModel;
use App\Domains\Ai\Providers\GoogleAiProvider;
use App\Domains\Ai\Services\AiCall;
use App\Domains\Ai\Services\GeneratedImageStore;
use Illuminate\Console\Command;

/**
 * Draws the product photographs the demo catalogue is missing.
 *
 * A development tool, not a product feature, and deliberately outside the routing and
 * credit machinery every customer-facing call goes through: nobody is being billed, no
 * design is being produced, and putting seed-data generation into the AI cost accounting
 * would make the platform's own numbers a lie.
 *
 * It exists because the guided design is only as good as the catalogue underneath it. With
 * twelve products, nineteen of the categories the room programmes ask about had no seller
 * at all, so eight of the ten rooms answered fewer than half their questions and the wizard
 * spent its time saying "bu ürün grubunda henüz satıcımız yok". True, and useless for
 * anybody trying to see whether the thing works.
 *
 * Drawn rather than sourced. Real product photography belongs to real sellers; these are
 * plainly generated stand-ins for a development catalogue, and they never leave it.
 *
 * Idempotent by file: an image already on disk is left alone unless `--force`, so a run
 * that dies half-way costs nothing to repeat.
 */
final class GenerateDemoImagesCommand extends Command
{
    protected $signature = 'refconcept:generate-demo-images
        {--force : Redraw images that already exist}
        {--only= : One image key, for iterating on a single prompt}';

    protected $description = 'Generates the demo catalogue product photographs';

    private const ASSET_DIR = __DIR__.'/../../../database/seeders/assets/products';

    /**
     * What to draw, keyed by the filename the seeder asks for.
     *
     * Written as one sentence each because that is what the model follows well. The shared
     * half — plain background, one object, no props — is appended rather than repeated, so
     * the set looks like a set instead of nineteen photographs from nineteen shops.
     */
    private const SUBJECTS = [
        'oturma-grubu-kose' => 'A modern L-shaped corner sofa in warm grey woven fabric with low walnut legs',
        'tv-unitesi-mese' => 'A low oak television unit with two drawers and an open shelf, slim tapered legs',
        'konsol-mese' => 'A narrow oak console table with two drawers and slender legs',
        'puf-boucle' => 'A round cream bouclé pouffe with a soft flat top',
        'perde-keten' => 'A pair of floor-length natural linen curtains hanging in soft folds',
        'kirlent-keten' => 'A square mustard linen cushion with a plain woven texture',
        'tablo-soyut' => 'A framed abstract canvas in muted beige and grey tones, thin oak frame',
        'bitki-saksi' => 'A tall potted areca palm in a matte stone-coloured ceramic planter',
        'vazo-seramik' => 'A tall matte cream ceramic vase with a narrow neck',
        'gardirop-mese' => 'A two-door oak wardrobe with a plain front and recessed handles',
        'nevresim-keten' => 'A folded stack of washed linen bedding in soft sand colour',
        'masa-lambasi-pirinc' => 'A small table lamp with a brass base and a cream linen drum shade',
        'duvar-aydinlatma-pirinc' => 'A brass wall sconce with a small conical shade angled downward',
        'bar-taburesi-mese' => 'A bar stool with an oak seat, black metal legs and a low back',
        'mutfak-dolabi-mat' => 'A run of matte sage-green kitchen cabinets with slim brass handles',
        'tezgah-mermer' => 'A section of white marble kitchen worktop with soft grey veining',
        'lavabo-seramik' => 'A rectangular white ceramic countertop basin with straight sides',
        'banyo-dolabi-mese' => 'A wall-hung oak bathroom vanity unit with two drawers',
        'banyo-aksesuar-pirinc' => 'A set of brushed brass bathroom accessories: towel rail, hook and soap dish',
    ];

    /**
     * The half of the prompt every subject shares.
     *
     * Studio-plain on purpose. A photograph with a styled room behind it is a photograph of
     * a room, and these sit in a grid next to each other where a background is noise —
     * and worse, a hint of a setting the shopping list cannot deliver.
     */
    private const STYLE = 'Product photograph on a plain off-white seamless background, '
        .'soft even studio lighting, gentle shadow beneath the object, no props, no text, '
        .'no people, photographed straight on at eye level, photorealistic. '
        .'The object is centred and fills most of the frame with a small even margin.';

    public function handle(GoogleAiProvider $provider, GeneratedImageStore $store): int
    {
        if (app()->isProduction()) {
            $this->error('Demo verisi üretimi üretimde çalıştırılamaz.');

            return self::FAILURE;
        }

        $model = AiModel::query()
            ->with('provider')
            ->where('modality', 'image')
            ->where('is_active', true)
            ->whereHas('provider', fn ($query) => $query->where('driver', 'google'))
            ->first();

        if ($model === null) {
            $this->error('Aktif bir Google görsel modeli bulunamadı.');

            return self::FAILURE;
        }

        $key = $model->provider?->activeCredential()?->secret_encrypted;

        if ($key === null) {
            $this->error('Sağlayıcının aktif kimlik bilgisi yok.');

            return self::FAILURE;
        }

        $only = $this->option('only');
        $drawn = 0;
        $skipped = 0;

        foreach (self::SUBJECTS as $name => $subject) {
            if (is_string($only) && $only !== '' && $only !== $name) {
                continue;
            }

            $path = self::ASSET_DIR.'/'.$name.'.jpg';

            if (file_exists($path) && $this->option('force') !== true) {
                $skipped++;

                continue;
            }

            $this->line("  {$name} …");

            $result = $provider->execute(new AiCall(
                task: AiTask::ImageRenderDraft,
                model: $model,
                prompt: $subject.'. '.self::STYLE,
                // Square, because the storefront's product grid is square and a wide
                // photograph cropped to it loses the legs off every chair.
                temperatureBps: 4_000,
                timeoutSeconds: 120,
                apiKey: $key,
                options: ['aspect_ratio' => '1:1'],
            ));

            if (! $result->successful || $result->imageRefs === []) {
                $this->warn("    olmadı: {$result->failureMessage}");

                continue;
            }

            $stream = $store->read($result->imageRefs[0]);

            if (! is_resource($stream)) {
                $this->warn('    üretildi ama okunamadı');

                continue;
            }

            $this->write($path, (string) stream_get_contents($stream));
            $drawn++;
        }

        $this->newLine();
        $this->info(sprintf('%d görsel üretildi, %d zaten vardı.', $drawn, $skipped));

        return self::SUCCESS;
    }

    /**
     * Saves the bytes as JPEG, which is one of the two the seeder looks for.
     *
     * Not WebP: this container GD is built without WebP write support, and naming a JPEG
     * `.webp` produces a file that works everywhere except the one place that reads the
     * header. The seeder takes either extension rather than being lied to.
     */
    private function write(string $path, string $bytes): void
    {
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            $this->warn('    çözülemedi');

            return;
        }

        // 1200px square: large enough for the product page, small enough that nineteen of
        // them do not make the repository awkward to clone.
        $resized = imagescale($image, 1_200, 1_200);

        imagejpeg($resized === false ? $image : $resized, $path, 88);
        imagedestroy($image);

        if ($resized !== false) {
            imagedestroy($resized);
        }
    }
}
