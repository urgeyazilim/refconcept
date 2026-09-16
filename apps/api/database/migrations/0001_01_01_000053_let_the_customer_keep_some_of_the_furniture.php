<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The plate can leave some things in the room.
 *
 * "Eşyalarım kalsın" was an open decision in the studio contract; the guide now asks it as
 * a question — "hangilerini kaldırayım, hangileri kalsın?" — and the emptying prompt has to
 * be able to hear the answer. `{{ keep }}` names what stays, `{{ objects }}` what goes.
 */
return new class extends Migration
{
    private const TASK = 'room_clear';

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

        $version = max(self::VERSION, (int) ($previous->version ?? 0) + 1);
        $id = (string) Str::uuid7();

        DB::table('prompt_versions')->insert([
            'id' => $id,
            'template_id' => $templateId,
            'version' => $version,
            'status' => 'published',
            'published_at' => now(),
            'temperature_bps' => $previous->temperature_bps ?? 2_000,
            'system_prompt' => $previous->system_prompt ?? implode(' ', [
                'You edit a photograph of a room so that it is empty.',
                'Remove every movable object: furniture, rugs, lamps, plants, cushions, pictures, curtains that hang free, boxes, clutter.',
                'Keep the architecture exactly as photographed: walls, floor, ceiling, windows, doors, radiators, sockets, switches, built-in lighting, skirting, the view through the windows.',
                'Fill the floor and walls that were hidden so they continue the visible surfaces seamlessly — same material, same pattern, same lighting and shadows.',
                'Do not change the camera, the perspective, the crop, the colour balance or the time of day. Do not add anything.',
                'Output the edited photograph only.',
            ]),
            'user_template' => "Remove these from the room: {{ objects }}.\nKeep exactly where they are, untouched: {{ keep }}.\nRoom type: {{ room_type }}.\n",
            'response_schema' => $previous->response_schema ?? null,
            'change_note' => 'Müşterinin bırakmak istediği eşyalar ({{ keep }}) yerinde kalır.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
};
