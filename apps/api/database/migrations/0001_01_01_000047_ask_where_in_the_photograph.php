<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Asks the analysis to point at what it found, not only to name it.
 *
 * The customer is shown measurements and asked whether they are right. Three numbers are hard
 * to answer: nobody knows their living room is 4.85 m wide, and "yes, probably" is what a
 * screen full of digits gets. What they can answer instantly is whether the thing outlined on
 * their own photograph is the window — and if the box is around a mirror, every number that
 * followed from it is wrong and they can see why.
 *
 * So version 3 adds `regions`: a normalised box per detected element, in the photograph's own
 * coordinates. Boxes rather than outlines, because a box is the shape a vision model returns
 * reliably and an outline is the shape it returns beautifully about a third of the time.
 *
 * Normalised 0–1 rather than pixels, because the image the model was given may have been
 * resized on the way, and a coordinate in pixels is a coordinate in *which* pixels.
 */
return new class extends Migration
{
    private const TASK = 'room_analysis';

    private const VERSION = 3;

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

        $schema = json_decode((string) ($previous->response_schema ?? '{}'), true);

        $schema = is_array($schema) ? $schema : [];

        $schema['properties']['regions'] = [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'kind' => ['type' => 'string'],
                    'label' => ['type' => 'string'],
                    // [x1, y1, x2, y2], each 0–1 from the top-left of the photograph.
                    'box' => ['type' => 'array', 'items' => ['type' => 'number']],
                ],
            ],
        ];

        $id = (string) Str::uuid7();

        DB::table('prompt_versions')->insert([
            'id' => $id,
            'template_id' => $templateId,
            'version' => self::VERSION,
            'status' => 'published',
            'published_at' => now(),
            'temperature_bps' => $previous->temperature_bps ?? 2_000,
            'system_prompt' => ($previous->system_prompt ?? '')."\n".implode("\n", [
                '',
                'NEREDE OLDUĞUNU DA GÖSTER.',
                'Bulduğun pencere, kapı ve belirgin sabit öğeler için regions listesine birer',
                'kutu ekle. Her kutu fotoğrafın kendi koordinatlarında, sol üst köşe 0,0 ve sağ',
                'alt köşe 1,1 olacak şekilde [x1, y1, x2, y2] biçiminde 0 ile 1 arasında',
                'sayılardan oluşur. kind alanı window, door, balcony_door, radiator veya column',
                'olabilir; label alanına ölçüsünü yaz (örneğin "1.80 × 1.60 m").',
                'Kutuyu gördüğün öğenin çevresine koy. Emin olmadığın bir öğeyi listeye ekleme —',
                'yanlış yere çizilmiş bir kutu, müşteriye kendi odasında olmayan bir pencereyi',
                'gösterir ve ardından gelen bütün ölçülere duyduğu güveni haklı olarak yıkar.',
            ]),
            'user_template' => $previous->user_template ?? '',
            'response_schema' => json_encode($schema, JSON_UNESCAPED_UNICODE),
            'change_note' => 'Açıklıkların fotoğraf üzerindeki konumu (regions) eklendi.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
};
