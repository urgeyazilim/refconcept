<?php

declare(strict_types=1);

namespace App\Domains\Matching\Enums;

/**
 * What a product vector was made from.
 *
 * Two genuinely different questions, and folding them into one column would make both
 * worse. A *text* vector describes what the seller wrote about a product; an *image*
 * vector describes what the product looks like. "A sofa like the one in this render" is
 * the second question, and no amount of reading the description answers it.
 *
 * Only text is produced today. The image case exists in the schema and in this enum
 * because leaving it out would mean a migration and a re-embedding run to add it, and
 * because naming the gap is more honest than pretending there is only one kind of
 * similarity.
 */
enum EmbeddingSource: string
{
    case Text = 'text';
    case Image = 'image';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Metin',
            self::Image => 'Görsel',
        };
    }
}
