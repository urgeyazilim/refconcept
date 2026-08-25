<?php

declare(strict_types=1);

namespace App\Domains\Projects\Enums;

use App\Domains\Ai\Enums\AiTask;

/**
 * How good a render the customer asked for.
 *
 * Two levels rather than a slider, because the difference a customer can actually
 * perceive is "quick look" versus "the one I show people", and a third option in between
 * would be a choice nobody can make confidently.
 *
 * Stored on the version rather than inferred from the route, because it is the price the
 * customer was quoted. A route repointed at a different model next month must not rewrite
 * what a version already in the tree was.
 */
enum RenderQuality: string
{
    case Draft = 'draft';
    case Premium = 'premium';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Hızlı önizleme',
            self::Premium => 'Yüksek kalite',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Draft => 'Fikri görmek için hızlı ve ucuz.',
            self::Premium => 'Sunulacak kalitede, daha uzun sürer.',
        };
    }

    public function task(): AiTask
    {
        return match ($this) {
            self::Draft => AiTask::ImageRenderDraft,
            self::Premium => AiTask::ImageRenderPremium,
        };
    }
}
