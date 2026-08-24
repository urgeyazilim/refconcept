<?php

declare(strict_types=1);

namespace App\Domains\Ai\Models;

use App\Domains\Identity\Models\User;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One version of a prompt, and once published, a permanent record of it.
 *
 * A prompt is the single largest lever on output quality, so "we changed the wording
 * last Tuesday" has to be a fact somebody can look up against a specific job rather
 * than a memory. A published version is immutable — enforced by a database trigger,
 * not by convention — because one UPDATE would silently rewrite the history of every
 * job that ever ran against it.
 *
 * Retiring is allowed. That changes which version gets chosen next; it does not change
 * what an old one said.
 *
 * @property string $id
 * @property string $template_id
 * @property int $version
 * @property string|null $system_prompt
 * @property string $user_template
 * @property array<string, mixed>|null $response_schema
 * @property int $temperature_bps
 * @property string $status
 * @property string|null $change_note
 * @property string|null $created_by
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PromptVersion extends Model
{
    use HasUuidV7;

    protected $table = 'prompt_versions';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'temperature_bps' => 7000,
    ];

    /** @var list<string> */
    protected $fillable = [
        'template_id',
        'version',
        'system_prompt',
        'user_template',
        'response_schema',
        'temperature_bps',
        'change_note',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'response_schema' => 'array',
            'temperature_bps' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PromptTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(PromptTemplate::class, 'template_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /**
     * Fills the placeholders in the template.
     *
     * Deliberately dumb: `{{ key }}` replaced by value, nothing else. A prompt template
     * with conditionals and loops is a program written in a database column, debugged
     * by reading provider output — and the moment somebody wants that, the answer is a
     * new task type with its own template, not a template language.
     *
     * A placeholder with no value is left as it is rather than blanked, so a missing
     * variable is visible in the recorded prompt instead of producing a subtly emptier
     * question nobody notices.
     *
     * @param  array<string, string|int|float|null>  $variables
     */
    public function render(array $variables): string
    {
        $rendered = $this->user_template;

        foreach ($variables as $key => $value) {
            $rendered = str_replace(
                ['{{'.$key.'}}', '{{ '.$key.' }}'],
                (string) ($value ?? ''),
                $rendered,
            );
        }

        return $rendered;
    }

    /**
     * Placeholders the template asks for.
     *
     * @return array<int, string>
     */
    public function placeholders(): array
    {
        preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $this->user_template, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @param  Builder<$this>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', 'published');
    }
}
