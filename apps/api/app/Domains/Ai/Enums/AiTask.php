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
     * A short film of the finished room, from the render.
     *
     * The camera may only move within what the customer's photograph already showed. A
     * turn far enough to see the wall behind it would have to invent that wall, and this
     * product spent a week removing exactly that kind of invention.
     */
    case VideoTour = 'video_tour';

    /** Find the individual pieces of furniture inside a render. */
    case ObjectExtraction = 'object_extraction';

    case ProductTagging = 'product_tagging';

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
            self::VideoTour => 'Oda videosu',
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
            self::RoomAnalysis, self::ObjectExtraction, self::ProductTagging => AiModality::Vision,
            self::TextEmbedding => AiModality::Embedding,
            self::ImageRenderDraft, self::ImageRenderPremium, self::ImageEdit => AiModality::Image,
            self::VideoTour => AiModality::Video,
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
            self::ProductTagging, self::ProductMatchRerank, self::BudgetOptimize => true,
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
