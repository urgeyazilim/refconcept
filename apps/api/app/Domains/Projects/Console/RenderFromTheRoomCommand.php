<?php

declare(strict_types=1);

namespace App\Domains\Projects\Console;

use App\Domains\Ai\Enums\AiJobStatus;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Services\AiJobDispatcher;
use App\Domains\Projects\Models\DesignLayout;
use App\Domains\Projects\Models\DesignVersion;
use App\Domains\Projects\Models\Room;
use App\Domains\Projects\Models\RoomAnalysis;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * One render the room controls, for comparing against the one it only advises.
 *
 * A console command rather than a screen, and deliberately. This spends money per image and
 * exists to answer a question nobody can settle by reasoning: whether a renderer that cannot
 * move a wall produces a better picture of somebody's living room than a renderer that can.
 * Until that has been looked at, the task stays paused and nothing in the product can reach
 * it — so the only way to run one is for a person to type it.
 *
 * It writes the picture to a file and touches nothing. The design tree is the customer's
 * record of what they were shown; an experiment does not belong in it.
 */
final class RenderFromTheRoomCommand extends Command
{
    protected $signature = 'refconcept:render-from-the-room
        {room : The room id}
        {--design-version= : Which design version to render; the newest finished one otherwise}
        {--out=storage/app/compare : Where to write the picture}';

    protected $description = 'Odanın kendisine bağlı tek bir render alır ve dosyaya yazar (ücretlidir).';

    public function handle(AiJobDispatcher $dispatcher): int
    {
        $room = Room::query()->with('constraints')->find((string) $this->argument('room'));

        if ($room === null) {
            $this->error('Oda bulunamadı.');

            return self::FAILURE;
        }

        $layout = DesignLayout::query()
            ->where('room_id', $room->getKey())
            ->whereNotNull('depth_path')
            ->latest('version')
            ->first();

        if ($layout === null) {
            $this->error('Bu odanın derinlik haritası yok. Plan ekranını bir kez açın; on saniye içinde kaydedilir.');

            return self::FAILURE;
        }

        $version = $this->versionFor($room, (string) ($this->option('design-version') ?? ''));

        if ($version === null) {
            $this->error('Bu odanın bitmiş bir tasarımı yok.');

            return self::FAILURE;
        }

        $prompt = $this->promptFor($room, $version);

        $this->line('İstem:');
        $this->line($prompt);
        $this->newLine();

        $ran = $dispatcher->runInline(
            task: AiTask::ImageRenderStructured,
            input: [
                'prompt' => $prompt,
                // The depth map, read off the private disk and handed over as bytes. The
                // only thing about this room that ever leaves: no photograph, no plate, no
                // colour, no window view.
                'image_sources' => [[
                    'disk' => (string) $layout->depth_disk,
                    'path' => (string) $layout->depth_path,
                ]],
                'image_urls' => [],
            ],
            subject: $version,
            // A comparison per arrangement. Asking twice about the same room is the same
            // question, and the answer is already in the folder.
            idempotencyKey: 'structured-render:'.$layout->getKey().':'.$layout->snapshot_taken_at?->timestamp,
            creditCostOverride: 0,
        );

        if ($ran->status !== AiJobStatus::Succeeded) {
            $this->error(sprintf(
                'Render alınamadı: %s — %s',
                $ran->failure_kind->value,
                (string) $ran->failure_reason,
            ));

            return self::FAILURE;
        }

        $url = $ran->output['image_urls'][0] ?? null;

        if (! is_string($url) || $url === '') {
            $this->error('Yanıtta görsel yok.');

            return self::FAILURE;
        }

        $path = $this->write($url, (string) $room->getKey());

        if ($path === null) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Yazıldı: '.$path);
        $this->line(sprintf(
            'Ölçülen bedel: %.4f USD · süre: %d ms',
            ($ran->total_cost_micros ?? 0) / 1_000_000,
            (int) ($ran->total_latency_ms ?? 0),
        ));

        return self::SUCCESS;
    }

    private function versionFor(Room $room, string $wanted): ?DesignVersion
    {
        $query = DesignVersion::query()
            ->whereIn('design_id', $room->designs()->select('id'))
            ->where('status', 'ready');

        return $wanted === ''
            ? $query->latest('created_at')->first()
            : $query->whereKey($wanted)->first();
    }

    /**
     * What the depth map cannot say.
     *
     * The geometry is settled before a word is written: the walls, the openings and every
     * piece of furniture are in the control image and the model has nowhere else to put
     * them. So the prompt is only about substance — what the room is made of, what the
     * light is like, what the furniture is upholstered in — and it says so out loud, because
     * a prompt that also describes the layout invites the model to negotiate with the
     * control image, and it will.
     */
    private function promptFor(Room $room, DesignVersion $version): string
    {
        $analysis = RoomAnalysis::query()
            ->where('room_id', $room->getKey())
            ->latest('created_at')
            ->first();

        $surfaces = $analysis === null ? [] : (array) $analysis->surfaces;

        $plan = $version->plan;

        $pieces = [];

        foreach ($plan === null ? [] : (array) $plan->placements as $placement) {
            if (is_array($placement) && is_string($placement['category'] ?? null)) {
                $pieces[] = $placement['category'];
            }
        }

        $lines = [
            'A photorealistic interior photograph of this exact room.',
            'The room, its walls, its window, its door and every piece of furniture are fixed by the control image: keep all of them exactly where they are, at exactly the size and proportion they are drawn. Add nothing that is not in it and remove nothing that is.',
            'Style: '.($plan === null ? 'modern' : (string) $plan->style).'.',
        ];

        if ($plan !== null && is_array($plan->palette) && $plan->palette !== []) {
            $lines[] = 'Palette: '.implode(', ', array_map('strval', $plan->palette)).'.';
        }

        $floor = $surfaces['floor'] ?? null;
        $walls = $surfaces['walls'] ?? null;

        if (is_array($floor)) {
            $lines[] = 'Floor: '.trim((string) ($floor['material'] ?? 'wood').' '.(string) ($floor['color_hex'] ?? '')).'.';
        }

        if (is_array($walls)) {
            $lines[] = 'Walls: '.trim((string) ($walls['material'] ?? 'plaster').' '.(string) ($walls['color_hex'] ?? '')).'.';
        }

        if ($pieces !== []) {
            $lines[] = 'The blocks in the control image are, in the plan: '.implode(', ', array_unique($pieces)).'. Render each as real furniture of that kind, in the palette above.';
        }

        $lines[] = 'Daylight through the window. No people, no text, no watermark.';

        return implode(' ', $lines);
    }

    /** The picture, on the local disk, named so two runs of one room sit side by side. */
    private function write(string $url, string $roomId): ?string
    {
        try {
            $response = Http::timeout(120)->get($url);
        } catch (Throwable $e) {
            $this->error('Görsel indirilemedi: '.$e->getMessage());

            return null;
        }

        if ($response->failed()) {
            $this->error('Görsel indirilemedi: HTTP '.$response->status());

            return null;
        }

        $path = sprintf('compare/%s-%s.png', $roomId, now()->format('Ymd-His'));

        Storage::disk('local')->put($path, $response->body());

        return (string) Storage::disk('local')->path($path);
    }
}
