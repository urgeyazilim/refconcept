<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Thirteen pieces of furniture, every one of them three and a half centimetres wide.
 *
 * The layout plan had been coming back with no `max_width_mm` at all — four attempts, three
 * hundred and thirty-two seconds, a design thrown away and the customer told "Geçersiz yanıt
 * biçimi". So the shape was guaranteed rather than requested, and the field started arriving.
 * It arrived as 36. On all thirteen placements. The sofa, the bookcase, the rug, the ceiling
 * light: thirty-six millimetres each.
 *
 * Nothing in the catalogue is that narrow — the narrowest of two hundred and forty-three
 * measured products is a hundred and twenty millimetres — so the search returned nothing for
 * every placement, and the customer was told "Bu plandaki ürünlerin hiçbiri katalogda
 * bulunamadı. Farklı bir stil veya bütçe ile tekrar deneyebilirsiniz." Which is a lie, in the
 * particular way that matters: it blames the catalogue and sends them to change a style that
 * had nothing to do with it.
 *
 * The prompt asked for the field in four words — "category ve max_width_mm zorunlu" — and
 * never said what it was. Not the unit, not the scale, not one example. The model had been
 * answering by leaving it out, which at least the validator caught. Forced to write something
 * and told nothing about what, it wrote a number.
 *
 * Version 6 says it. Millimetres, the width of the piece rather than of the gap it sits in,
 * with the sizes a designer would recognise and the room's own measurements to check against.
 */
return new class extends Migration
{
    private const TASK = 'design_plan';

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

        DB::table('prompt_versions')->insert([
            'id' => $id,
            'template_id' => $templateId,
            'version' => $version,
            'status' => 'published',
            'published_at' => now(),
            'temperature_bps' => $previous->temperature_bps ?? 3_000,
            'system_prompt' => rtrim((string) ($previous->system_prompt ?? ''))."\n\n".implode("\n", [
                'max_width_mm NEDİR:',
                'O parçanın kendi genişliği, MİLİMETRE cinsinden tam sayı. Durduğu boşluğun',
                'genişliği değil, parçanın kendisinin genişliği. Bu sayıyla katalogda ürün',
                'aranır: yazdığın sayıdan geniş olan hiçbir ürün müşteriye gösterilmez.',
                '',
                'Bir iç mimarın tanıyacağı ölçüler (üst sınır olarak yaz, tam ölçü değil):',
                '- üçlü kanepe 2000-2400, ikili kanepe 1500-1800, tekli koltuk 800-1000',
                '- orta sehpa 1000-1300, yan sehpa 450-600, konsol 1000-1400',
                '- tv-unitesi 1600-2200, kitaplik 800-1200, gardirop 1500-2500',
                '- salon halısı 2000-3000, yemek masası 1400-2000, sandalye 450-550',
                '- lambader 350-500, masa-lambasi 250-400, tavan-aydinlatma 400-900',
                '- tablo 600-1200, vazo 150-350, bitki 400-800, perde 1500-3000',
                '',
                'Yazmadan önce iki şeyi kontrol et:',
                '1. Sayı oda ölçülerine sığıyor mu? Oda genişliği analiz içinde milimetre',
                '   olarak veriliyor; 3900 mm bir odaya 4000 mm kanepe sığmaz.',
                '2. Sayı üç ya da dört haneli mi? 36 ya da 250 gibi bir sayı kanepe için',
                '   santimetre ya da başka bir birim yazdığın anlamına gelir; milimetreye',
                '   çevir. Bir mobilya asla 100 mm den dar değildir.',
            ]),
            'user_template' => $previous->user_template ?? '',
            'response_schema' => $previous->response_schema ?? '{}',
            'change_note' => 'max_width_mm milimetre demek: birim, ölçek ve örnekler yazıldı.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
};
