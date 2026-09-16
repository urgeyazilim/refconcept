<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Asks for the boxes on the photograph in the model's own convention.
 *
 * The owner's door was drawn a hand's width to the right and a third too tall. Gemini was
 * trained to answer detection as `[ymin, xmin, ymax, xmax]` on a 0–1000 grid; we asked for
 * `[x1, y1, x2, y2]` as fractions, and a model translating between the two conventions on
 * the fly is a model that drifts. Version 6 asks for `box_2d` exactly the way the model
 * draws, and the plan screen converts. The box is also defined: frame to frame, the open
 * leaf included, floor to lintel — so "the door" is the whole door and not the gap in it.
 */
return new class extends Migration
{
    private const TASK = 'room_analysis';

    private const VERSION = 6;

    public function up(): void
    {
        $template = DB::table('prompt_templates')->where('code', self::TASK)->first();

        if ($template === null) {
            return;
        }

        $id = $this->publish((string) $template->id);

        DB::table('ai_task_routes')
            ->where('task', self::TASK)
            ->update(['prompt_version_id' => $id, 'updated_at' => now()]);
    }

    public function down(): void
    {
        $template = DB::table('prompt_templates')->where('code', self::TASK)->first();

        if ($template === null) {
            return;
        }

        $previous = DB::table('prompt_versions')
            ->where('template_id', $template->id)
            ->where('version', '<', self::VERSION)
            ->orderByDesc('version')
            ->value('id');

        if ($previous === null) {
            return;
        }

        DB::table('ai_task_routes')
            ->where('task', self::TASK)
            ->update(['prompt_version_id' => $previous, 'updated_at' => now()]);
    }

    private function publish(string $templateId): string
    {
        $existing = DB::table('prompt_versions')
            ->where('template_id', $templateId)
            ->where('version', self::VERSION)
            ->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $previous = DB::table('prompt_versions')
            ->where('template_id', $templateId)
            ->where('version', '<', self::VERSION)
            ->orderByDesc('version')
            ->first();

        $version = max(self::VERSION, (int) ($previous->version ?? 0) + 1);
        $id = (string) Str::uuid7();

        $schema = json_decode((string) ($previous->response_schema ?? '{}'), true);

        if (! is_array($schema)) {
            $schema = ['required' => ['room_type', 'fixed_elements', 'surfaces'], 'properties' => []];
        }

        $schema['properties']['regions'] = [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'kind' => ['type' => 'string'],
                    'label' => ['type' => 'string'],
                    // [ymin, xmin, ymax, xmax] on a 0–1000 grid: the model's native detection shape.
                    'box_2d' => ['type' => 'array', 'items' => ['type' => 'integer']],
                ],
            ],
        ];

        // The old system prompt's "NEREDE OLDUĞUNU DA GÖSTER" paragraph described the other
        // convention; it is replaced wholesale rather than contradicted.
        $system = (string) ($previous->system_prompt ?? '');
        $cut = mb_strpos($system, 'NEREDE OLDUĞUNU DA GÖSTER.');

        if ($cut !== false) {
            $end = mb_strpos($system, 'BİRDEN ÇOK FOTOĞRAF.', $cut);
            $system = mb_substr($system, 0, $cut).($end === false ? '' : mb_substr($system, $end));
        }

        DB::table('prompt_versions')->insert([
            'id' => $id,
            'template_id' => $templateId,
            'version' => $version,
            'status' => 'published',
            'published_at' => now(),
            'temperature_bps' => $previous->temperature_bps ?? 2_000,
            'system_prompt' => rtrim($system)."\n\n".implode("\n", [
                'NEREDE OLDUĞUNU DA GÖSTER.',
                'Bulduğun pencere, kapı, balkon kapısı, radyatör ve kolon için regions listesine birer',
                'kutu ekle. Kutuyu box_2d alanında, [ymin, xmin, ymax, xmax] sırasıyla ve 0-1000',
                'ölçeğinde ver (fotoğrafın sol üstü 0,0; sağ altı 1000,1000). Kutu öğenin tamamını',
                'sarmalı: kapıda kasadan kasaya, açık kanat dahil, döşemeden lentoya; pencerede',
                'kasadan kasaya, perde varsa perdeyi değil pencereyi. kind alanı window, door,',
                'balcony_door, radiator veya column olabilir; label alanına ölçüsünü yaz (örneğin',
                '"0.90 × 2.10 m"). Kutuları yalnızca ilk (ana) fotoğraf için ver. Emin olmadığın bir',
                'öğeyi listeye ekleme — yanlış yere çizilmiş bir kutu müşteriye kendi odasında',
                'olmayan bir pencereyi gösterir.',
            ]),
            'user_template' => $previous->user_template ?? "Oda türü ipucu: {{ room_type }}\nKullanıcı notu: {{ notes }}\nBildirilen ölçüler (mm): {{ dimensions }}\nFotoğraf sayısı: {{ photo_count }} — {{ photo_note }}\n\nFotoğraflardaki sabit öğeleri, taşınabilir eşyaları ve yüzeyleri çıkar.",
            'response_schema' => json_encode($schema, JSON_UNESCAPED_UNICODE),
            'change_note' => 'Kutular modelin kendi biçiminde (box_2d, ymin/xmin/ymax/xmax, 0-1000) ve öğenin tamamını sarar.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
};
