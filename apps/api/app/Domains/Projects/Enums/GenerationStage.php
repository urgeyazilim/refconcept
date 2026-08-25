<?php

declare(strict_types=1);

namespace App\Domains\Projects\Enums;

/**
 * The steps a design version goes through, in order.
 *
 * A customer waits the better part of a minute for a render, and a spinner that says
 * nothing for fifty seconds is indistinguishable from one that has hung. Naming the
 * stages turns that wait into something somebody is willing to sit through — and turns
 * "it is slow" into "it is slow at the render step", which is a bug report rather than a
 * feeling.
 */
enum GenerationStage: string
{
    case Queued = 'queued';

    /** Reading the photograph: what is in this room and what cannot be moved. */
    case Analysis = 'analysis';

    /** Deciding what goes where, before any pixels exist. */
    case Plan = 'plan';

    case Render = 'render';

    /** Writing the image and marking the version ready. */
    case Save = 'save';

    /** Finding products a customer can actually buy for the layout. */
    case Match = 'match';

    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Sıraya alındı',
            self::Analysis => 'Oda inceleniyor',
            self::Plan => 'Yerleşim planlanıyor',
            self::Render => 'Görsel üretiliyor',
            self::Save => 'Kaydediliyor',
            self::Match => 'Ürünler eşleştiriliyor',
            self::Done => 'Tamamlandı',
        };
    }

    /**
     * Roughly how far along this stage is, in basis points.
     *
     * Honest about being an estimate. A progress bar driven by real timings would jump
     * about as providers vary; one driven by stage boundaries moves predictably, which
     * is what somebody watching actually wants from it.
     */
    public function progressBps(): int
    {
        return match ($this) {
            self::Queued => 500,
            self::Analysis => 2_500,
            self::Plan => 4_500,
            self::Render => 8_000,
            self::Save => 9_000,
            self::Match => 9_600,
            self::Done => 10_000,
        };
    }
}
