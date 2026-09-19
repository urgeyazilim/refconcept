<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * We were asking a pair of eyes for a tape measure.
 *
 * The reading is given photographs and asked, for every door and window, `offset_mm` — how
 * many millimetres along the wall it starts. Nobody can answer that from a photograph, and
 * the model does not refuse: it answers with a plausible round number. The product owner's
 * room came back 4000 by 5500 by 2600, with a window at 1500 offset and 2500 wide, a door at
 * 2800 offset and 900 wide, a sill at 850 — nine numbers, every one of them round, at a
 * stated confidence of 0.7. They are not measurements. They are a guess wearing a decimal
 * point, and the product owner could see it.
 *
 * What a model looking at a wall can genuinely answer is a proportion: this window starts
 * about a third of the way along this wall and ends about four fifths of the way along. That
 * is a judgement about a picture rather than a number it has no way to know, and it is
 * exactly what the planner needs, because the planner already knows how long the wall is.
 *
 * So version 11 asks where along the wall, as a fraction from 0 to 1, and the millimetres are
 * worked out from the wall the opening is on. Two things follow for free: an opening can no
 * longer overrun the end of its own wall, which used to need a rule and a clamp; and a wrong
 * room size no longer puts the window in the wrong place as well as the wrong room — the
 * window stays a third of the way along whatever the wall turns out to be.
 *
 * The millimetre fields stay in the schema and stay honoured. A reading that answers the old
 * way is still a reading, and that has to be true on the day this ships, for every room read
 * before it.
 */
return new class extends Migration
{
    private const TASK = 'room_analysis';

    private const VERSION = 11;

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
            'system_prompt' => rtrim((string) ($previous->system_prompt ?? ''))."\n\n".implode("\n", [
                'AÇIKLIK DUVARIN NERESİNDE.',
                'Milimetre tahmin etme. Fotoğraftan milimetre ölçemezsin; yuvarlak bir sayı',
                'uydurmak ölçmek değildir.',
                'Her kapı ve pencere için duvarın neresinde olduğunu oran olarak ver:',
                'starts_at ve ends_at, 0 ile 1 arasında.',
                '- 0 duvarın sol ucu, 1 sağ ucudur; duvara odanın içinden baktığını varsay.',
                '- Sol uç, o duvarın offset sayımının başladığı uçtur.',
                '- Duvarın ortasında, duvarın yarısı kadar geniş bir pencere: starts_at 0.25,',
                '  ends_at 0.75.',
                '- Sağ köşeye yakın dar bir kapı: starts_at 0.70, ends_at 0.92.',
                'starts_at her zaman ends_at değerinden küçük olmalı, ikisi de 0 ile 1',
                'arasında kalmalıdır.',
                '',
                'YÜKSEKLİK DE ORAN İLE.',
                'sill_ratio: açıklığın alt kenarının yerden yüksekliği, tavana oranla.',
                'Kapıda 0; tipik pencerede 0.30 civarı.',
                'head_ratio: açıklığın üst kenarının yerden yüksekliği, tavana oranla.',
                'Tipik kapıda 0.80, tipik pencerede 0.85 civarı.',
                'head_ratio her zaman sill_ratio değerinden büyük olmalıdır.',
                '',
                'Milimetre alanlarını da doldurabilirsin, ama oran verdiysen ölçüler oranlardan',
                'hesaplanır. Emin olmadığın bir sayı yerine oranı ver.',
            ]),
            'user_template' => $previous->user_template ?? '',
            'response_schema' => $this->schema((string) ($previous->response_schema ?? '{}')),
            'change_note' => 'Açıklıkların yeri milimetre yerine duvara oranla soruluyor.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * The same schema, with the four proportions added to every opening.
     *
     * Added rather than swapped in: a reading that answers with millimetres is still a
     * reading, and the planner keeps honouring one.
     */
    private function schema(string $json): string
    {
        /** @var array<string, mixed>|null $schema */
        $schema = json_decode($json, true);

        if (! is_array($schema) || ! isset($schema['properties']['openings']['items']['properties'])) {
            return $json;
        }

        $ratio = ['type' => 'number', 'minimum' => 0, 'maximum' => 1];

        foreach (['starts_at', 'ends_at', 'sill_ratio', 'head_ratio'] as $field) {
            $schema['properties']['openings']['items']['properties'][$field] = $ratio;
        }

        /*
         * The millimetres stop being required.
         *
         * A model told "offset_mm is required" produces one whatever else it is offered, and
         * the number it produces looks exactly like a measurement to everything downstream.
         * Asked for the proportion and allowed to leave the millimetres out, it answers the
         * question it can actually answer.
         */
        $schema['properties']['openings']['items']['required'] = ['type', 'wall', 'starts_at', 'ends_at'];

        return (string) json_encode($schema);
    }
};
