<?php

declare(strict_types=1);

namespace App\Domains\Ai\Enums;

use App\Domains\Ai\Models\AiTaskRoute;

/**
 * Every kind of work RefConcept asks a model to do.
 *
 * An enum rather than a table, deliberately, and against the letter of the
 * specification's table list. A task type is *code*: each value has a prompt template
 * written for it, a response schema the application parses, and a call site that knows
 * what to do with the answer. Adding a row to a table would not add any of those, so a
 * database-driven list would only create a value nothing can execute.
 *
 * What genuinely belongs in the database is the *routing* — which provider and model
 * each task uses, with what timeout, retries and cost ceiling. That is
 * {@see AiTaskRoute}, and it is configurable precisely because
 * it changes without any code changing.
 */
enum AiTask: string
{
    /** Read a room photograph into a structured description of the room. */
    case RoomAnalysis = 'room_analysis';

    /** Decide what should go where, before any pixels are drawn. */
    case DesignPlan = 'design_plan';

    case ImageRenderDraft = 'image_render_draft';
    case ImageRenderPremium = 'image_render_premium';
    case ImageEdit = 'image_edit';

    /**
     * A render that has to obey the room rather than be reminded of it.
     *
     * The other renderers are handed a photograph of the plan and asked to follow it, which
     * is asking a photorealistic model to take a reference image seriously. It does not: it
     * takes the idea and resolves the rest however it likes, and the product owner's design
     * came back with the window on a different wall from their own flat, an armchair nobody
     * sells, a plant and a table lamp. The fidelity check said so and we showed it anyway,
     * because there was nothing better to show.
     *
     * This one is given the arrangement as a depth map, through a control-conditioned model.
     * The geometry stops being advice and becomes an input: the walls, the openings and
     * every piece of furniture come out where the customer's own room put them, because
     * there is nowhere else the model is able to put them.
     */
    case ImageRenderStructured = 'image_render_structured';

    /**
     * Take the furniture out of a room photograph.
     *
     * The plate every render starts from: the customer's own walls, floor, windows and
     * doors with nothing standing in front of them. Once per photograph, in the background,
     * and nobody is charged — an empty room is the shop's floor, not a customer's design.
     */
    case RoomClear = 'room_clear';

    /**
     * Look at a finished render and say whether it is the room it was meant to be.
     *
     * Rule K24: no invented furniture, no moved wall, no lost door. A vision call that
     * counts what stands in the picture against what the layout said and reports the
     * differences, so a bad picture is made again rather than shown.
     */
    case RenderCheck = 'render_check';

    /**
     * A short film of the finished room, from the render.
     *
     * The camera may only move within what the customer's photograph already showed. A
     * turn far enough to see the wall behind it would have to invent that wall, and this
     * product spent a week removing exactly that kind of invention.
     */
    case VideoTour = 'video_tour';

    /**
     * Turns a product photograph into a 3D model for the room planner.
     *
     * Run once per variant when a listing is approved, never per customer, and never for
     * the final render — the mesh's back is a guess, and a guess is fine in a planner seen
     * across a room and not fine in a picture somebody buys from.
     */
    case ProductModel = 'product_model';

    /**
     * Measures a room's shape from several photographs of it at once.
     *
     * The reading estimates a room's size by looking at one picture and reasoning about door
     * heights; this triangulates it from six. The two answer different questions and the
     * second is the one that can tell a long room from a square one — the reading gave the
     * product owner's living room as 3.8 by 4.5 metres one time and 4.5 by 5.0 the next, and
     * it is neither.
     *
     * **Never automatic.** It costs money per room and it is not yet good enough to be worth
     * spending without being asked: the first real attempt came back as a cloud with no walls
     * in it. A button, and a customer who pressed it.
     */
    case RoomScan = 'room_scan';

    /** Find the individual pieces of furniture inside a render. */
    case ObjectExtraction = 'object_extraction';

    case ProductTagging = 'product_tagging';

    /**
     * Works out which side of a product each of its photographs shows.
     *
     * So the mesh generator can be given the back as well as the front, and stop inventing
     * one. Cheap, once per product, and allowed to answer "I do not know" — a photograph
     * mislabelled as the back is worse than a missing one, because the generator fuses two
     * fronts and produces something that is not furniture.
     */
    case ProductViewTagging = 'product_view_tagging';

    /**
     * Turn a product description into a vector.
     *
     * The task that makes "warm minimalist oak" find a product described as "İskandinav
     * meşe" without either phrase appearing in the other. Runs over the catalogue rather
     * than on a customer request, which is why it is cheap enough to be worth doing for
     * every listing.
     */
    case TextEmbedding = 'text_embedding';
    case ProductQueryRewrite = 'product_query_rewrite';
    case ProductMatchRerank = 'product_match_rerank';
    case BudgetOptimize = 'budget_optimize';
    case SupportAssist = 'support_assist';
    case CatalogEnrichment = 'catalog_enrichment';

    public function label(): string
    {
        return match ($this) {
            self::RoomAnalysis => 'Oda analizi',
            self::DesignPlan => 'Tasarım planı',
            self::ImageRenderDraft => 'Görsel üretimi (taslak)',
            self::ImageRenderPremium => 'Görsel üretimi (yüksek kalite)',
            self::ImageEdit => 'Görsel düzenleme',
            self::ImageRenderStructured => 'Görsel üretimi (odaya bağlı)',
            self::RoomClear => 'Oda boşaltma',
            self::RenderCheck => 'Render sadakat denetimi',
            self::VideoTour => 'Oda videosu',
            self::ProductModel => 'Ürün 3B modeli',
            self::RoomScan => 'Oda taraması',
            self::ProductViewTagging => 'Ürün görsel yönü',
            self::ObjectExtraction => 'Nesne çıkarımı',
            self::ProductTagging => 'Ürün etiketleme',
            self::TextEmbedding => 'Metin vektörü',
            self::ProductQueryRewrite => 'Arama sorgusu iyileştirme',
            self::ProductMatchRerank => 'Ürün eşleştirme sıralaması',
            self::BudgetOptimize => 'Bütçe optimizasyonu',
            self::SupportAssist => 'Destek asistanı',
            self::CatalogEnrichment => 'Katalog zenginleştirme',
        };
    }

    /** What the model has to be able to do. Routing refuses a model that cannot. */
    public function modality(): AiModality
    {
        return match ($this) {
            self::RoomAnalysis, self::ObjectExtraction, self::ProductTagging, self::RenderCheck => AiModality::Vision,
            self::TextEmbedding => AiModality::Embedding,
            self::ImageRenderDraft, self::ImageRenderPremium, self::ImageEdit,
            self::ImageRenderStructured, self::RoomClear => AiModality::Image,
            self::VideoTour => AiModality::Video,
            self::ProductModel, self::RoomScan => AiModality::Model3d,
            self::ProductViewTagging => AiModality::Vision,
            default => AiModality::Text,
        };
    }

    /**
     * Whether the answer must parse into a schema the application defined.
     *
     * A room analysis that comes back as prose is unusable: the next step reads
     * `fixed_elements` and there is nothing to read. Tasks marked here are validated
     * against their prompt version's schema, and a malformed answer is a failure
     * rather than something to pass downstream and discover later.
     */
    public function requiresStructuredOutput(): bool
    {
        return match ($this) {
            self::RoomAnalysis, self::DesignPlan, self::ObjectExtraction,
            self::ProductTagging, self::ProductMatchRerank, self::BudgetOptimize,
            self::ProductViewTagging, self::RenderCheck => true,
            default => false,
        };
    }

    /**
     * Whether a customer is waiting for this in a browser.
     *
     * Interactive tasks get shorter timeouts and fewer retries: a customer watching a
     * spinner would rather be told it failed than wait ninety seconds for a third
     * attempt at something that is not going to work.
     */
    public function isInteractive(): bool
    {
        return match ($this) {
            self::SupportAssist, self::ProductQueryRewrite => true,
            default => false,
        };
    }

    /**
     * @return array<int, array{value: string, label: string, modality: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $task): array => [
                'value' => $task->value,
                'label' => $task->label(),
                'modality' => $task->modality()->value,
            ],
            self::cases(),
        );
    }
}
