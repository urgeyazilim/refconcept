<?php

declare(strict_types=1);

namespace App\Domains\Ai\Enums;

/**
 * What a model can actually do.
 *
 * Routing checks this before it checks anything else: pointing an image task at a
 * text model produces a confusing provider error rather than an obvious
 * misconfiguration, and the operator who made the change is the one least likely to
 * be reading provider logs.
 */
enum AiModality: string
{
    case Text = 'text';

    /** Reads images and answers in text. */
    case Vision = 'vision';

    /** Produces images. */
    case Image = 'image';

    case Embedding = 'embedding';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Metin',
            self::Vision => 'Görsel anlama',
            self::Image => 'Görsel üretimi',
            self::Embedding => 'Vektör',
        };
    }

    /** Whether a model of this modality can serve a task of that one. */
    public function canServe(self $required): bool
    {
        // A vision model answers text tasks too; the reverse is not true.
        if ($this === self::Vision && $required === self::Text) {
            return true;
        }

        return $this === $required;
    }
}
