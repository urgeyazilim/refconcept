<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Part of the shared descriptive vocabulary.
 *
 * First-class rather than free text so the Phase 9 matching engine can reason about
 * it: the AI extracts a property from a design and looks for products that carry the
 * same row, not a string each seller spells differently.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $hex
 * @property string|null $family
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Color extends Model
{
    use HasUuidV7;

    protected $table = 'colors';

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'hex', 'family', 'position'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
