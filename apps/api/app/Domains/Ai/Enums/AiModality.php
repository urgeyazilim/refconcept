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

    /**
     * Produces video, from a still and a description of how the camera should move.
     *
     * Its own modality rather than a flavour of Image, because everything about the call is
     * different: it is answered by a long-running operation the caller polls for a minute
     * or two, the result is tens of megabytes, and it is priced by the second rather than
     * by the token. A model that draws pictures cannot serve it and should not be routed to
     * it by accident.
     */
    case Video = 'video';

    /**
     * Produces a 3D mesh from a photograph.
     *
     * Its own modality for the same reason video is: the call takes an image and answers
     * with a file, the file is measured in megabytes rather than tokens, it is priced per
     * model, and no text or image model can serve it. One product is converted once, ever —
     * this is a catalogue job, not something a customer waits for.
     */
    case Model3d = 'model_3d';

    case Embedding = 'embedding';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Metin',
            self::Vision => 'Görsel anlama',
            self::Image => 'Görsel üretimi',
            self::Video => 'Video üretimi',
            self::Model3d => '3B model üretimi',
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
