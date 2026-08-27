<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gives the layout plan a shape the matcher can actually read.
 *
 * The plan is the contract between the model that arranges the room and the search that
 * finds real furniture for it, and the contract said `"placements": {"type": "array"}`.
 * Any array satisfied that. The system prompt said "return JSON" and never described a
 * placement, so the model chose its own shape each run — sometimes
 * `{category, wall, max_width_mm}`, which works, and sometimes a prose description
 * `{name, notes, position_description, relation_to_fixed_elements}`, which validates
 * perfectly and is useless: the matcher reads `category`, finds nothing, and returns an
 * empty shopping list.
 *
 * What the customer saw was worse than an empty list. No matches means no product
 * photographs go to the renderer either, so it was handed the room and a paragraph of
 * prose and drew furniture it invented. The design "succeeded" every time. It simply had
 * nothing to do with the catalogue.
 *
 * Two changes, both here because they are one thought:
 *
 *  - The schema now describes a placement, and `category` and `max_width_mm` are required.
 *    A prose plan is now malformed output, which the gateway already knows how to retry.
 *  - The prompt says which categories exist and demands one of them. Asking a model to
 *    invent a taxonomy that must match a database is asking it to guess.
 *
 * Prompt versions are immutable by trigger, so this adds a version and repoints the route.
 */
return new class extends Migration
{
    /**
     * The catalogue's own category slugs.
     *
     * Listed in the prompt rather than fetched at render time: a plan built against
     * categories that no longer exist is a plan whose furniture cannot be bought, and a
     * fixed list makes that a migration to write rather than a silent drift.
     */
    private const CATEGORIES = [
        'kanepe', 'koltuk', 'oturma-grubu', 'puf', 'sehpa', 'masa-sandalye', 'yemek-masasi',
        'sandalye', 'bar-taburesi', 'tv-unitesi', 'konsol', 'kitaplik', 'depolama', 'gardirop',
        'komodin', 'yatak', 'yatak-odasi-mobilya', 'hali', 'perde', 'kirlent', 'tekstil',
        'nevresim', 'aydinlatma', 'tavan-aydinlatma', 'duvar-aydinlatma', 'lambader',
        'masa-lambasi', 'ayna', 'tablo', 'vazo', 'bitki', 'dekorasyon',
    ];

    public function up(): void
    {
        $template = DB::table('prompt_templates')->where('code', 'design_plan')->first();

        if ($template === null) {
            return;
        }

        $versionId = $this->publish($template->id);

        /*
         * Repointed whether or not this run created the version.
         *
         * The last prompt migration checked for an existing version and returned early,
         * which meant a re-run left the route pinned to v1 and the new prompt was never
         * used by anything. The route is the part that matters; write it every time.
         */
        DB::table('ai_task_routes')
            ->where('task', 'design_plan')
            ->update(['prompt_version_id' => $versionId, 'updated_at' => now()]);
    }

    public function down(): void
    {
        $template = DB::table('prompt_templates')->where('code', 'design_plan')->first();

        if ($template === null) {
            return;
        }

        $previous = DB::table('prompt_versions')
            ->where('template_id', $template->id)
            ->where('version', 1)
            ->value('id');

        if ($previous !== null) {
            DB::table('ai_task_routes')
                ->where('task', 'design_plan')
                ->update(['prompt_version_id' => $previous, 'updated_at' => now()]);
        }
    }

    /** Adds version 2, or returns the existing one so a re-run still repoints the route. */
    private function publish(string $templateId): string
    {
        $existing = DB::table('prompt_versions')
            ->where('template_id', $templateId)
            ->where('version', 2)
            ->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $id = (string) Str::uuid7();

        DB::table('prompt_versions')->insert([
            'id' => $id,
            'template_id' => $templateId,
            'version' => 2,
            'status' => 'published',
            'published_at' => now(),
            'temperature_bps' => DB::table('prompt_versions')
                ->where('template_id', $templateId)
                ->where('version', 1)
                ->value('temperature_bps') ?? 2000,
            'system_prompt' => $this->systemPrompt(),
            'user_template' => implode("\n", [
                'Oda analizi: {{ analysis }}',
                'Kısıtlar: {{ constraints }}',
                'Bütçe (kuruş): {{ budget_minor }}',
                'İstenen stil: {{ style }}',
                'Müşteri notu: {{ prompt }}',
            ]),
            'response_schema' => json_encode($this->responseSchema(), JSON_UNESCAPED_UNICODE),
            'change_note' => 'Yerleşim öğelerine zorunlu kategori ve genişlik eklendi.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function systemPrompt(): string
    {
        return implode("\n", [
            'Sen bir iç mimarsın. Verilen oda analizine ve kısıtlara uyan, uygulanabilir bir yerleşim planı üret.',
            'Sabit öğeleri (pencere, kapı, radyatör, kolon) asla kaldırma ve yerlerini değiştirme.',
            '',
            'Her yerleşim öğesi için şunlar zorunludur:',
            '- category: yalnızca şu listeden bir değer olmalı: '.implode(', ', self::CATEGORIES),
            '- max_width_mm: o öğenin sığabileceği en büyük genişlik, milimetre cinsinden tamsayı',
            '',
            'İsteğe bağlı: wall (north, south, east, west), name, notes.',
            '',
            'Kategori listesinde karşılığı olmayan bir öğe önerme; o öğeyi plandan çıkar.',
            'Aynı kategoriden birden fazla öğe gerekiyorsa ayrı ayrı listele.',
            'Yalnızca JSON döndür, başka hiçbir metin yazma.',
        ]);
    }

    /** @return array<string, mixed> */
    private function responseSchema(): array
    {
        return [
            'required' => ['style', 'placements'],
            'properties' => [
                'style' => ['type' => 'string'],
                'notes' => ['type' => 'string'],
                'palette' => ['type' => 'array', 'items' => ['type' => 'string']],
                'placements' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        // The two the matcher cannot work without. Everything else is
                        // detail the render prompt enjoys and the search does not need.
                        'required' => ['category', 'max_width_mm'],
                        'properties' => [
                            'category' => ['type' => 'string'],
                            'max_width_mm' => ['type' => 'integer'],
                            'wall' => ['type' => 'string'],
                            'name' => ['type' => 'string'],
                            'notes' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }
};
