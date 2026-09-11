<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Asks the analysis for measurements and openings, not just a description.
 *
 * Until now a photograph was read for what was in the room — fixed elements, surfaces,
 * movable objects — and the room's size came from whatever the customer had typed, which for
 * most rooms is nothing. That was enough while a design was a picture. It is not enough for a
 * plan: a plan needs a rectangle with a door in it, and "there is a window on the left" does
 * not place anything.
 *
 * Two things are added, and both are asked for the same way.
 *
 * `estimated_dimensions` — the room's width, length and height in millimetres, with a
 * confidence. Asked for in millimetres so it is the same unit as everything else and there is
 * no centimetre boundary for somebody to get wrong later.
 *
 * `openings` — doors and windows as a wall, an offset along that wall and a width, which is
 * the shape the geometry already stores them in. The offset convention is stated in the
 * prompt because it is the one thing here a model cannot infer and will otherwise mirror: it
 * runs along the wall's own axis from the room's origin corner, the same on all four walls.
 *
 * Nothing in this migration makes those numbers authoritative. They arrive as a proposal the
 * customer is shown and asked to agree to, because a photograph read by a model is usually
 * close and occasionally wrong by half a metre.
 */
return new class extends Migration
{
    private const TASK = 'room_analysis';

    private const VERSION = 2;

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

        $id = (string) Str::uuid7();

        DB::table('prompt_versions')->insert([
            'id' => $id,
            'template_id' => $templateId,
            'version' => self::VERSION,
            'status' => 'published',
            'published_at' => now(),
            'temperature_bps' => $previous->temperature_bps ?? 2_000,
            'system_prompt' => implode("\n", [
                'Sen bir iç mimarlık asistanısın. Sana verilen oda fotoğrafını incele ve yalnızca',
                'istenen JSON yapısında yanıt ver.',
                '',
                'ÖLÇÜLER.',
                'Odanın genişliğini, uzunluğunu ve tavan yüksekliğini milimetre cinsinden tahmin et.',
                'Referans olarak fotoğraftaki bilinen ölçüleri kullan: iç kapı yüksekliği yaklaşık',
                '2000-2100 mm, kapı genişliği 800-900 mm, priz yüksekliği ~400 mm, fayans ve parke',
                'genişlikleri. Tahminini kesinmiş gibi sunma; ne kadar emin olduğunu confidence ile',
                'söyle ve şüphelerini warnings içine yaz. Ölçüyü hiç kestiremiyorsan',
                'estimated_dimensions alanını boş bırak — uydurma bir sayı, boş bir alandan çok',
                'daha kötüdür, çünkü müşteri ona güvenerek mobilya satın alır.',
                '',
                'AÇIKLIKLAR.',
                'Kapı ve pencereleri openings içinde bildir. Her açıklık bir duvara aittir ve o',
                'duvar boyunca bir noktadan başlar:',
                '- Duvar adları odanın içinden bakıldığında: north, south, east, west.',
                '- offset_mm, açıklığın duvarın kendi ekseni boyunca odanın köşe başlangıcından',
                '  uzaklığıdır. north ve south duvarlarında x = 0 köşesinden, east ve west',
                '  duvarlarında z = 0 köşesinden ölçülür. Dört duvarda da aynı yönde sayılır.',
                '- width_mm açıklığın genişliği, height_mm yüksekliği, sill_height_mm pencere',
                '  denizliğinin yerden yüksekliğidir (kapıda 0).',
                'Göremediğin duvarlar hakkında tahmin yürütme; yalnızca fotoğrafta görünen',
                'açıklıkları bildir.',
            ]),
            'user_template' => implode("\n", [
                'Oda türü ipucu: {{ room_type }}',
                'Kullanıcı notu: {{ notes }}',
                'Bildirilen ölçüler (mm): {{ dimensions }}',
                '',
                'Fotoğraftaki sabit öğeleri (pencere, kapı, radyatör, kolon), taşınabilir eşyaları',
                've yüzeyleri çıkar. Odanın ölçülerini ve açıklıklarını da tahmin et.',
            ]),
            'response_schema' => json_encode([
                'required' => ['room_type', 'fixed_elements', 'surfaces'],
                'properties' => [
                    'room_type' => ['type' => 'string'],
                    'confidence' => ['type' => 'number'],
                    'style' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'dominant_colors' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'fixed_elements' => ['type' => 'array'],
                    'movable_objects' => ['type' => 'array'],
                    'surfaces' => ['type' => 'object'],
                    'measurement_quality' => ['type' => 'string'],
                    'warnings' => ['type' => 'array'],

                    // Not required. A model that cannot tell how big a room is should say so
                    // by leaving this out, and a schema that demands it teaches it to guess.
                    'estimated_dimensions' => [
                        'type' => 'object',
                        'properties' => [
                            'width_mm' => ['type' => 'integer'],
                            'length_mm' => ['type' => 'integer'],
                            'height_mm' => ['type' => 'integer'],
                            'confidence' => ['type' => 'number'],
                        ],
                    ],
                    'openings' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'type' => ['type' => 'string'],
                                'wall' => ['type' => 'string'],
                                'offset_mm' => ['type' => 'integer'],
                                'width_mm' => ['type' => 'integer'],
                                'height_mm' => ['type' => 'integer'],
                                'sill_height_mm' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE),
            'change_note' => 'Oda ölçüsü ve açıklık tahmini eklendi (3B plan için).',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
};
