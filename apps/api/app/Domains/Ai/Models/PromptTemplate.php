<?php

declare(strict_types=1);

namespace App\Domains\Ai\Models;

use App\Domains\Ai\Enums\AiTask;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A named prompt, and the versions it has had.
 *
 * The template is the identity; the {@see PromptVersion} rows are the content. A route
 * points at a *version*, never at the template, so promoting a new wording is an
 * explicit act rather than something that happens the moment somebody saves.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property AiTask $task
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PromptTemplate extends Model
{
    use HasUuidV7;

    protected $table = 'prompt_templates';

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'task', 'description'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['task' => AiTask::class];
    }

    /** @return HasMany<PromptVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(PromptVersion::class, 'template_id')->orderByDesc('version');
    }

    /** The newest published version, which is what a route would normally point at. */
    public function latestPublished(): ?PromptVersion
    {
        return $this->versions()->published()->orderByDesc('version')->first();
    }

    /** Numbers are never reused, so a version number means one thing forever. */
    public function nextVersionNumber(): int
    {
        return ((int) $this->versions()->max('version')) + 1;
    }
}
