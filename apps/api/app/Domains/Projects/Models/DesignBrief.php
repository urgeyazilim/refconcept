<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the customer chose, in their own room's terms.
 *
 * The design brief used to be a blank textarea labelled "İstekleriniz" that almost nobody
 * filled in, and the engine downstream guessed. This is what replaced it: a style, a
 * palette, a budget and one answer per question — all of them chosen from pictures rather
 * than written.
 *
 * Answers are stored by question code rather than by row id, so a design from last spring
 * still reads `{"seating": ["three-seater"]}` after the question has been reworded, moved
 * or re-versioned. The programme version travels alongside, so "why does my design have two
 * side tables" is answerable a year later.
 *
 * Always a list, even for a single-choice question. One shape means the pipeline never has
 * to ask whether this particular answer might be a string.
 *
 * @property string $id
 * @property string $design_version_id
 * @property string|null $programme_id
 * @property string|null $style_code
 * @property string|null $palette_code
 * @property int|null $budget_minor
 * @property array<string, array<int, string>> $answers
 * @property string|null $note
 */
final class DesignBrief extends Model
{
    use HasUuids;

    protected $table = 'design_briefs';

    protected $fillable = [
        'design_version_id',
        'programme_id',
        'style_code',
        'palette_code',
        'budget_minor',
        'answers',
        'note',
    ];

    /** @return BelongsTo<DesignVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DesignVersion::class, 'design_version_id');
    }

    /**
     * The option codes chosen for one question.
     *
     * @return array<int, string>
     */
    public function chosen(string $questionCode): array
    {
        $answer = $this->answers[$questionCode] ?? [];

        return array_values(array_filter($answer, static fn (mixed $code): bool => is_string($code)));
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'budget_minor' => 'integer',
        ];
    }
}
