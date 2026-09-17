<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Two things the reading could see and was never asked.
 *
 * **Which kind of window.** The model reported a hole with a width, and the kind — single,
 * double, triple, French balcony — was guessed here from that width alone. A 1.2 m opening
 * became a double casement whether the photograph showed two sashes or one tall pane, and
 * the customer met a room drawn with the wrong window. The model is looking at the window;
 * it can say. The guess stays as the fallback for a reading that does not.
 *
 * **What colour the room is.** The surfaces came back as materials — "plaster_paint",
 * "wood_floor" — with no colour, so the planner drew every room in the same cream whatever
 * the photograph showed. The product owner's grey walls with white cornice came out white on
 * white. One hex per surface is all it takes, and it costs nothing: the same call, a wider
 * answer.
 */
return new class extends Migration
{
    private const TASK = 'room_analysis';

    private const VERSION = 9;

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

        /*
         * The kind, beside the hole.
         *
         * Not required: a reading that cannot tell should leave it out rather than pick one,
         * and the width still says something. Named exactly as the enum stores them so there
         * is nothing to translate on the way in.
         */
        $openings = $schema['properties']['openings'] ?? [];
        $openings['items']['properties']['variant'] = [
            'type' => 'string',
            'enum' => ['single', 'double', 'triple', 'french_balcony', 'single_door', 'double_door', 'sliding'],
        ];

        $schema['properties']['openings'] = $openings;

        // A colour per surface, as hex. The planner paints with it; anything unreadable is
        // dropped and the room is drawn in its default cream, which is what happens today.
        $surface = [
            'type' => 'object',
            'properties' => [
                'material' => ['type' => 'string'],
                'color_hex' => ['type' => 'string'],
            ],
        ];

        $schema['properties']['surfaces'] = [
            'type' => 'object',
            'properties' => [
                'floor' => $surface,
                'walls' => $surface,
                'ceiling' => $surface,
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
                'TÜRÜNÜ VE RENGİNİ DE SÖYLE.',
                'Her açıklık için variant ver: pencerede kanat sayısına bak — tek kanat "single",',
                'iki kanat "double", üç ve fazlası "triple"; döşemeye kadar inen cam kapı',
                '"french_balcony". Kapıda tek kanat "single_door", çift kanat "double_door",',
                'yana kayan "sliding". Emin değilsen variant alanını hiç yazma.',
                'surfaces içindeki her yüzey için color_hex ver: duvarın, zeminin ve tavanın',
                'fotoğraftaki baskın rengi, "#RRGGBB" biçiminde. Gölgeye değil, aydınlık',
                'bölgedeki gerçek rengine bak.',
            ]),
            'user_template' => $previous->user_template ?? "Oda türü ipucu: {{ room_type }}\nKullanıcı notu: {{ notes }}\nBildirilen ölçüler (mm): {{ dimensions }}\nFotoğraf sayısı: {{ photo_count }} — {{ photo_note }}\n\nFotoğraflardaki sabit öğeleri, taşınabilir eşyaları ve yüzeyleri çıkar. Odanın ölçülerini ve açıklıklarını da tahmin et.",
            'response_schema' => json_encode($schema, JSON_UNESCAPED_UNICODE),
            'change_note' => 'Açıklıkta kanat türü, yüzeylerde renk.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
};
