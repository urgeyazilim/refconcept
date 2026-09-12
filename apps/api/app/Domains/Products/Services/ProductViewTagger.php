<?php

declare(strict_types=1);

namespace App\Domains\Products\Services;

use App\Domains\Ai\Enums\AiJobStatus;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Services\AiJobDispatcher;
use App\Domains\Products\Models\Product;
use App\Domains\Products\Models\ProductMedia;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Works out which side of a product each of its photographs shows.
 *
 * So the mesh generator can be handed the back as well as the front and stop inventing one.
 * It charges the same for four views as for one, so the only thing between a guessed back and
 * a photographed one is knowing which picture is which — and a catalogue photograph does not
 * say.
 *
 * **A seller's own labels are never touched.** Somebody who photographed the thing knows
 * which side they were standing on, and no model's opinion should overrule that. This runs
 * only over photographs nobody has labelled.
 *
 * **It would rather say nothing than guess.** A photograph mislabelled as the back is worse
 * than a missing one: the generator fuses two fronts and produces something that is not
 * furniture. Anything below {@see CONFIDENCE_FLOOR} is left unlabelled, and so is every
 * answer that is not one of the four sides — a detail shot is the absence of a view rather
 * than a view of its own.
 */
final class ProductViewTagger
{
    /**
     * How sure the model has to be before a label is written.
     *
     * Seven tenths, and the prompt says so, which is the point: told that its uncertainty
     * will be respected, a model has no reason to round its doubt up to a confident answer.
     */
    private const CONFIDENCE_FLOOR = 0.7;

    /** The only four answers that mean anything to the generator. */
    private const VIEWS = ['front', 'left', 'back', 'right'];

    public function __construct(private readonly AiJobDispatcher $dispatcher) {}

    /**
     * Labels what it can and leaves the rest alone.
     *
     * @return int how many photographs were labelled
     */
    public function tag(Product $product): int
    {
        $product->loadMissing(['media', 'primaryCategory']);

        /** @var Collection<int, ProductMedia> $photographs */
        $photographs = $product->media
            ->where('type', 'image')
            ->sortBy('position')
            ->values();

        if ($photographs->isEmpty()) {
            return 0;
        }

        // Somebody has already said. Asking a model to check a person's own answer is
        // spending money to introduce doubt.
        if ($photographs->whereNotNull('view')->isNotEmpty()) {
            return 0;
        }

        try {
            $ran = $this->dispatcher->runInline(
                task: AiTask::ProductViewTagging,
                input: [
                    'product_name' => (string) $product->name,
                    // Named because it changes the answer: "the front of a wardrobe" and
                    // "the front of a rug" are different questions about a photograph.
                    'category' => $product->primaryCategory === null ? '' : (string) $product->primaryCategory->name,
                    'image_roles' => $photographs
                        ->map(static fn (ProductMedia $media, int $index): string => sprintf('%d: fotoğraf', $index))
                        ->all(),
                    'image_sources' => $photographs
                        ->map(static fn (ProductMedia $media): array => [
                            'disk' => $media->disk,
                            'path' => $media->storage_path,
                        ])
                        ->all(),
                ],
                subject: $product,
                // Idempotent per set of photographs: a listing approved twice does not pay
                // twice to be told the same thing.
                idempotencyKey: 'product-views:'.md5($photographs->pluck('id')->implode(',')),
                creditCostOverride: 0,
            );
        } catch (Throwable $e) {
            Log::info('Görsel yönü belirlenemedi.', ['product' => $product->getKey(), 'reason' => $e->getMessage()]);

            return 0;
        }

        if ($ran->status !== AiJobStatus::Succeeded) {
            return 0;
        }

        return $this->apply($photographs, (array) ($ran->output['structured']['views'] ?? []));
    }

    /**
     * @param  Collection<int, ProductMedia>  $photographs
     * @param  array<int, mixed>  $answers
     */
    private function apply(Collection $photographs, array $answers): int
    {
        /** @var array<string, ProductMedia> $chosen */
        $chosen = [];

        foreach ($answers as $answer) {
            if (! is_array($answer)) {
                continue;
            }

            $index = is_int($answer['index'] ?? null) ? $answer['index'] : null;
            $view = is_string($answer['view'] ?? null) ? $answer['view'] : null;

            $confidence = is_float($answer['confidence'] ?? null) || is_int($answer['confidence'] ?? null)
                ? (float) $answer['confidence']
                : 0.0;

            if ($index === null || $view === null || ! in_array($view, self::VIEWS, true)) {
                continue;
            }

            if ($confidence < self::CONFIDENCE_FLOOR) {
                continue;
            }

            $photograph = $photographs->get($index);

            if ($photograph === null) {
                continue;
            }

            /*
             * One photograph per side, and the first confident answer keeps it.
             *
             * A model that names two backs has contradicted itself, and the database would
             * refuse the second write anyway — a partial unique index says one of each per
             * product. Better to drop it here than to catch a constraint violation.
             */
            if (isset($chosen[$view])) {
                continue;
            }

            $chosen[$view] = $photograph;
        }

        if ($chosen === []) {
            return 0;
        }

        DB::transaction(function () use ($chosen): void {
            foreach ($chosen as $view => $photograph) {
                $photograph->forceFill(['view' => $view])->save();
            }
        });

        return count($chosen);
    }
}
