<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An opening without a place on the wall is not an opening the plan can draw.
 *
 * The reading names a door on the north wall and a window on the west wall and, on a bad
 * day, stops there — no offset, no width — and the plan, which keeps only what it can
 * place, draws a sealed box. The customer then asks, fairly, why four photographs of their
 * room did not put the door in it. Version 7 makes the position and size required inside
 * every opening and every estimate, so the model answers them or leaves the opening out
 * altogether, and says how far down a door's box goes.
 */
return new class extends Migration
{
    private const TASK = 'room_analysis';

    private const VERSION = 7;

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

        $schema['properties']['openings'] = [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'required' => ['type', 'wall', 'offset_mm', 'width_mm', 'height_mm'],
                'properties' => [
                    'type' => ['type' => 'string'],
                    'wall' => ['type' => 'string'],
                    'offset_mm' => ['type' => 'integer'],
                    'width_mm' => ['type' => 'integer'],
                    'height_mm' => ['type' => 'integer'],
                    'sill_height_mm' => ['type' => 'integer'],
                ],
            ],
        ];

        $schema['properties']['estimated_dimensions'] = [
            'type' => 'object',
            'required' => ['width_mm', 'length_mm', 'height_mm'],
            'properties' => [
                'width_mm' => ['type' => 'integer'],
                'length_mm' => ['type' => 'integer'],
                'height_mm' => ['type' => 'integer'],
                'confidence' => ['type' => 'number'],
            ],
        ];

        DB::table('prompt_versions')->insert([
            'id' => $id,
            'template_id' => $templateId,
            'version' => $version,
            'status' => 'published',
            'published_at' => now(),
            'temperature_bps' => $previous->temperature_bps ?? 2_000,
            'system_prompt' => rtrim((string) ($previous->system_prompt ?? ''))."\n\n".implode("\n", [
                'EKSİK BIRAKMA.',
                'openings içine yazdığın her açıklık için wall, offset_mm, width_mm ve height_mm',
                'zorunludur — birini bilmiyorsan en iyi tahminini yaz; hiçbirini kestiremiyorsan o',
                'açıklığı listeye alma. estimated_dimensions verdiğinde üç ölçüyü de ver.',
                'Kapı kutusunda ymax kapı kanadının döşemeye değdiği çizgidir; halıya ya da zemine',
                'uzatma. Birden çok fotoğraf varsa kapı ve pencereleri ana fotoğrafın duvar',
                'düzenine göre yerleştir; diğer fotoğraflar yalnızca görmediğin duvarları',
                'tamamlamak içindir.',
            ]),
            'user_template' => $previous->user_template ?? "Oda türü ipucu: {{ room_type }}\nKullanıcı notu: {{ notes }}\nBildirilen ölçüler (mm): {{ dimensions }}\nFotoğraf sayısı: {{ photo_count }} — {{ photo_note }}\n\nFotoğraflardaki sabit öğeleri, taşınabilir eşyaları ve yüzeyleri çıkar. Odanın ölçülerini ve açıklıklarını da tahmin et.",
            'response_schema' => json_encode($schema, JSON_UNESCAPED_UNICODE),
            'change_note' => 'Açıklıklarda konum ve ölçü, tahminde üç ölçü zorunlu; kapı kutusu döşemede biter.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
};
