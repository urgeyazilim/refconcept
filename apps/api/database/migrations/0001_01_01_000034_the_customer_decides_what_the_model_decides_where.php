<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Hands the layout model a list instead of asking it for one.
 *
 * The customer now answers eight tapped questions about their living room, and those
 * answers are a better programme than any model could invent: they know whether they want a
 * corner sofa, and the catalogue knows what it stocks. Left to itself the model asked for a
 * television unit, floor-length curtains, a framed picture and four cushions against a shop
 * that sells none of them.
 *
 * So the division of labour moves. The customer decides *what*; the model decides *where* —
 * which wall, in what relation to the window it can see in the photograph, in what order
 * along the room. That is the part it is genuinely good at, and the part nobody wants to be
 * asked about.
 *
 * `required_placements` rather than `placements` on purpose: a prompt that asks the model to
 * produce placements and a prompt that hands it some should not look alike to whoever edits
 * the template next.
 *
 * The old behaviour survives for a design with no brief — one started before the wizard
 * existed, or by a client that skipped it. The prompt covers both cases because the template
 * renders an empty list as empty, and the instruction reads correctly either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        $template = DB::table('prompt_templates')->where('code', 'design_plan')->first();

        if ($template === null) {
            return;
        }

        $versionId = $this->publish($template->id);

        // Repointed on every run, not only when the version is created: an earlier prompt
        // migration returned early when its version already existed, and left the route
        // pinned to the old one.
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
            ->where('version', 2)
            ->value('id');

        if ($previous !== null) {
            DB::table('ai_task_routes')
                ->where('task', 'design_plan')
                ->update(['prompt_version_id' => $previous, 'updated_at' => now()]);
        }
    }

    private function publish(string $templateId): string
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
            'temperature_bps' => $previous->temperature_bps ?? 2_000,
            'system_prompt' => $this->systemPrompt(),
            'user_template' => implode("\n", [
                'Oda analizi: {{ analysis }}',
                'Kısıtlar: {{ constraints }}',
                'Bütçe (kuruş): {{ budget_minor }}',
                'İstenen stil: {{ style }}',
                'Renk paleti: {{ palette }}',
                'Müşterinin seçtiği parçalar: {{ required_placements }}',
                'Müşteri notu: {{ prompt }}',
            ]),
            'response_schema' => $previous->response_schema ?? null,
            'change_note' => 'Müşterinin seçtiği parçalar verildiğinde model yalnızca konumlandırır.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function systemPrompt(): string
    {
        return implode("\n", [
            'Sen bir iç mimarsın. Verilen oda analizine ve kısıtlara uyan, uygulanabilir bir',
            'yerleşim planı üret.',
            '',
            'Sabit öğeleri (pencere, kapı, radyatör, kolon) asla kaldırma ve yerlerini',
            'değiştirme.',
            '',
            'MÜŞTERİNİN SEÇTİĞİ PARÇALAR VERİLDİYSE:',
            'Listedeki her parça için bir yerleşim döndür. Listeye parça ekleme, listeden',
            'parça çıkarma. Senin işin bunların odada nereye ve hangi sırayla konacağına',
            'karar vermek: hangi duvara, pencereye ve kapıya göre nasıl, dolaşım yolunu',
            'kapatmadan nasıl. Her parçanın category ve max_width_mm değerini verildiği gibi',
            'aynen koru; wall ve notes alanlarını sen doldur.',
            '',
            'LİSTE BOŞSA:',
            'Odaya uygun parçaları kendin öner. Her öğe için category ve max_width_mm zorunlu.',
            '',
            'category yalnızca şu listeden olmalı: kanepe, koltuk, oturma-grubu, puf, sehpa,',
            'masa-sandalye, yemek-masasi, sandalye, bar-taburesi, tv-unitesi, konsol, kitaplik,',
            'depolama, gardirop, komodin, yatak, yatak-odasi-mobilya, hali, perde, kirlent,',
            'tekstil, nevresim, aydinlatma, tavan-aydinlatma, duvar-aydinlatma, lambader,',
            'masa-lambasi, ayna, tablo, vazo, bitki, dekorasyon.',
            '',
            'wall değeri north, south, east veya west olmalı.',
            'Yalnızca JSON döndür, başka hiçbir metin yazma.',
        ]);
    }
};
