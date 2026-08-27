<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stops the renderer furnishing rooms out of its own imagination.
 *
 * The layout plan is what an interior designer would ask for; the catalogue is what this
 * shop actually stocks, and the two are not the same list. A plan calling for a television
 * unit, a coffee table, curtains, a framed picture and a potted palm went to the renderer
 * whole, against a catalogue holding none of those things, and the model — following its
 * instructions perfectly — drew all of them. The customer got a beautiful room and a
 * shopping list covering four items out of nine, with nothing on the page to explain the
 * difference between what they were looking at and what they could have.
 *
 * The pipeline now sends only placements a real product was found for. This says the rest
 * out loud, because a prompt that lists five items and does not forbid a sixth will get a
 * sixth: models fill empty rooms, and an empty corner reads to them as an omission rather
 * than as a decision.
 *
 * Applies to both render qualities — draft and premium differ in how much time they spend,
 * not in what they are allowed to invent.
 */
return new class extends Migration
{
    private const TASKS = ['image_render_draft', 'image_render_premium'];

    public function up(): void
    {
        foreach (self::TASKS as $code) {
            $template = DB::table('prompt_templates')->where('code', $code)->first();

            if ($template === null) {
                continue;
            }

            $versionId = $this->publish($template->id, $code);

            // Repointed on every run, not only when the version is created. An earlier
            // prompt migration returned early when the version already existed, so a re-run
            // left the route pinned to the old one and the new prompt was never used.
            DB::table('ai_task_routes')
                ->where('task', $code)
                ->update(['prompt_version_id' => $versionId, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::TASKS as $code) {
            $template = DB::table('prompt_templates')->where('code', $code)->first();

            if ($template === null) {
                continue;
            }

            $previous = DB::table('prompt_versions')
                ->where('template_id', $template->id)
                ->where('version', 2)
                ->value('id');

            if ($previous !== null) {
                DB::table('ai_task_routes')
                    ->where('task', $code)
                    ->update(['prompt_version_id' => $previous, 'updated_at' => now()]);
            }
        }
    }

    private function publish(string $templateId, string $code): string
    {
        $existing = DB::table('prompt_versions')
            ->where('template_id', $templateId)
            ->where('version', 3)
            ->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $previous = DB::table('prompt_versions')
            ->where('template_id', $templateId)
            ->where('version', 2)
            ->first();

        $id = (string) Str::uuid7();

        DB::table('prompt_versions')->insert([
            'id' => $id,
            'template_id' => $templateId,
            'version' => 3,
            'status' => 'published',
            'published_at' => now(),
            'temperature_bps' => $previous->temperature_bps ?? 2000,
            'system_prompt' => $this->systemPrompt($code),
            'user_template' => $previous->user_template ?? '',
            'response_schema' => $previous->response_schema ?? null,
            'change_note' => 'Yalnızca katalogda karşılığı olan ürünler yerleştirilsin.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function systemPrompt(string $code): string
    {
        $lines = [
            'Sana verilen ilk görsel, müşterinin gerçek odasının fotoğrafıdır. Bu fotoğrafı',
            'DÜZENLE. Yeni bir oda çizme.',
            '',
            'Odayı olduğu gibi koru: duvarların açısı ve rengi, pencerenin konumu ve boyutu,',
            'kapılar, radyatörler, zemin kaplaması, kamera açısı ve perspektif birebir aynı',
            'kalsın. Müşteri sonuçta kendi odasını tanımalı.',
            '',
            'İlk görselden sonra gelen görseller, odaya yerleştirilecek gerçek ürünlerdir.',
            'Bunları biçim, renk ve malzeme olarak sadık biçimde sahneye yerleştir; yerlerine',
            'benzerlerini uydurma.',
            '',
            'ÇOK ÖNEMLİ: Yalnızca yerleşim planında listelenen ürünleri yerleştir. Plana',
            'girmeyen hiçbir mobilya, tekstil, aydınlatma, bitki, tablo, perde veya dekor',
            'ekleme. Müşteri bu görseldeki her şeyi satın alabilmeli; katalogda olmayan bir',
            'eşya çizmek, satılmayan bir ürünü vaat etmek demektir.',
            '',
            'Oda listelenenlerden sonra boş görünüyorsa boş kalsın. Boşluğu doldurmak için',
            'eşya ekleme.',
        ];

        $lines[] = '';
        $lines[] = $code === 'image_render_premium'
            ? 'Yüksek kalite, gerçekçi aydınlatma ve doğru gölgeler bekleniyor.'
            : 'Hızlı önizleme kalitesi yeterli.';

        return implode("\n", $lines);
    }
};
