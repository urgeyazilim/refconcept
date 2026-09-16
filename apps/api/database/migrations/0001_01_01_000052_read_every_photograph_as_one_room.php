<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reads every photograph of a room as one room, and asks for a schema Google can enforce.
 *
 * Two things went wrong with the reading, and this version fixes both.
 *
 * The prompt spoke of "the photograph". A customer who walks round their room and shoots
 * four corners now sends all four, and the model has to be told they are the same room —
 * otherwise a window seen from two corners is two windows, and the regions it draws for the
 * plan screen belong to whichever picture it happened to look at last.
 *
 * The schema said `fixed_elements: array` and `surfaces: object` and nothing more. Google
 * enforces the schema it is given and cannot express an array with no item type or an object
 * with no properties, so the adapter dropped them — and the model, held to the reduced
 * schema, dutifully left those fields out. The validator then rejected every answer for
 * lacking them, and after three attempts the local simulator answered instead. A real
 * customer's room was "read" that way: the simulator's stock sofa, window and radiator,
 * presented as their own. Every array here now says what it holds and every object what it
 * has, and the adapter refuses to hand Google a schema with a required field missing.
 */
return new class extends Migration
{
    private const TASK = 'room_analysis';

    private const VERSION = 5;

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

        // Versions published by hand in a development database sit between the last
        // migration's and this one; the next free number keeps the trigger happy.
        $version = max(self::VERSION, (int) ($previous->version ?? 0) + 1);

        $id = (string) Str::uuid7();

        DB::table('prompt_versions')->insert([
            'id' => $id,
            'template_id' => $templateId,
            'version' => $version,
            'status' => 'published',
            'published_at' => now(),
            'temperature_bps' => $previous->temperature_bps ?? 2_000,
            // The measuring and openings instructions of the previous version, unchanged;
            // what follows is added to them.
            'system_prompt' => implode("\n", [
                (string) ($previous->system_prompt ?? 'Sen bir iç mimarlık asistanısın. Sana verilen oda fotoğraflarını incele ve yalnızca istenen JSON yapısında yanıt ver.'),
                '',
                'BİRDEN ÇOK FOTOĞRAF.',
                'Sana aynı odanın birden çok fotoğrafı verilebilir; ilki ana fotoğraftır. Hepsini',
                'birleştirerek tek bir oda tanımı çıkar: aynı pencereyi ya da kapıyı iki kez sayma,',
                'duvar adlarını ana fotoğrafa göre ver, regions kutularını yalnızca ana fotoğraf',
                'için çiz. Bir öğeyi hangi fotoğrafta gördüğünü photo_index ile belirt (ana',
                'fotoğraf 0).',
                '',
                'DİL.',
                'label, warnings ve serbest metin alanlarını Türkçe yaz; type alanları İngilizce',
                'anahtar olarak kalsın (sofa, armchair, rug, window, door, radiator, ...).',
            ]),
            'user_template' => implode("\n", [
                'Oda türü ipucu: {{ room_type }}',
                'Kullanıcı notu: {{ notes }}',
                'Bildirilen ölçüler (mm): {{ dimensions }}',
                'Fotoğraf sayısı: {{ photo_count }} — {{ photo_note }}',
                '',
                'Fotoğraflardaki sabit öğeleri (pencere, kapı, radyatör, kolon), taşınabilir eşyaları',
                've yüzeyleri çıkar. Odanın ölçülerini ve açıklıklarını da tahmin et.',
            ]),
            'response_schema' => json_encode([
                'required' => ['room_type', 'fixed_elements', 'surfaces'],
                'properties' => [
                    'room_type' => ['type' => 'string'],
                    'confidence' => ['type' => 'number'],
                    'style' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'dominant_colors' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'fixed_elements' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'type' => ['type' => 'string'],
                                'label' => ['type' => 'string'],
                                'wall' => ['type' => 'string'],
                                'preserve' => ['type' => 'boolean'],
                                'photo_index' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                    'movable_objects' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'type' => ['type' => 'string'],
                                'label' => ['type' => 'string'],
                                'condition' => ['type' => 'string'],
                                'photo_index' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                    'surfaces' => [
                        'type' => 'object',
                        'properties' => [
                            'floor' => ['type' => 'object', 'properties' => ['material' => ['type' => 'string'], 'color' => ['type' => 'string'], 'change_allowed' => ['type' => 'boolean']]],
                            'walls' => ['type' => 'object', 'properties' => ['material' => ['type' => 'string'], 'color' => ['type' => 'string'], 'change_allowed' => ['type' => 'boolean']]],
                            'ceiling' => ['type' => 'object', 'properties' => ['material' => ['type' => 'string'], 'color' => ['type' => 'string'], 'change_allowed' => ['type' => 'boolean']]],
                        ],
                    ],
                    'measurement_quality' => ['type' => 'string'],
                    'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
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
                    'regions' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'kind' => ['type' => 'string'],
                                'label' => ['type' => 'string'],
                                'box' => ['type' => 'array', 'items' => ['type' => 'number']],
                            ],
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE),
            'change_note' => 'Birden çok fotoğraf tek oda olarak okunur; şemada her dizi ve nesne tam tanımlı (Google şemayı uygular).',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
};
