<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * We asked for a compass bearing and never said where north was.
 *
 * The prompt listed four wall names — "Duvar adları odanın içinden bakıldığında: north,
 * south, east, west" — and stopped. A model looking at a photograph of a living room has no
 * compass and no way to know which of those four names belongs to the wall in front of it, so
 * it picked one. It also never learned that the planner measures `width_mm` along the north
 * and south walls and `length_mm` along the east and west ones, so the two numbers and the
 * four names were free to disagree.
 *
 * They did. The product owner's room is a long wall with two sconces facing the camera and a
 * window on the short wall to the right; the reading came back 4.0 m wide by 6.0 m long with
 * the window on the east — which is to say, on a six-metre wall — and the room step drew the
 * proportions the wrong way round. A fair complaint, and not the model's fault: it answered
 * the question it was asked.
 *
 * Version 10 anchors the names to the main photograph and ties them to the measurements, with
 * the check written out so the answer can be tested against itself before it is given.
 */
return new class extends Migration
{
    private const TASK = 'room_analysis';

    private const VERSION = 10;

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
                'KUZEY NERESİ.',
                'Duvar adlarının pusulayla ilgisi yok; ana fotoğrafa göre tanımlıdırlar.',
                '- Ana fotoğrafta karşında gördüğün duvar: north.',
                '- Ona bakarken sağındaki duvar: east. Solundaki: west.',
                '- Fotoğrafı çekerken arkanda kalan duvar: south.',
                'Bu dört ad her zaman bu anlama gelir; başka fotoğraflara göre yeniden adlandırma.',
                '',
                'ÖLÇÜ İLE DUVAR ADI AYNI ŞEYİ SÖYLEMELİ.',
                'width_mm, north ve south duvarlarının uzunluğudur — yani karşında gördüğün',
                'duvarın boyu. length_mm, east ve west duvarlarının uzunluğudur — yani odanın',
                'senden karşı duvara doğru derinliği.',
                'Cevabını vermeden önce kendin kontrol et:',
                '- Karşındaki duvar geniş, yanlardakiler dar ise width_mm > length_mm olmalı.',
                '- Karşındaki duvar dar, oda derine uzuyorsa width_mm < length_mm olmalı.',
                '- north ya da south duvarındaki bir açıklık için offset_mm + width_mm,',
                '  width_mm değerini aşamaz; east ya da west duvarındaki bir açıklık için',
                '  length_mm değerini aşamaz. Aşıyorsa duvar adını ya da ölçüyü yanlış',
                '  vermişsindir; düzelt.',
                'Örnek: karşında iki aplikli uzun bir duvar, sağda pencereli kısa bir duvar var.',
                'O zaman apliklerin duvarı north ve uzun olan odur (width_mm büyük olan),',
                'pencere east duvarındadır ve east duvarının boyu length_mm (küçük olan).',
            ]),
            'user_template' => $previous->user_template ?? "Oda türü ipucu: {{ room_type }}\nKullanıcı notu: {{ notes }}\nBildirilen ölçüler (mm): {{ dimensions }}\nFotoğraf sayısı: {{ photo_count }} — {{ photo_note }}\n\nFotoğraflardaki sabit öğeleri, taşınabilir eşyaları ve yüzeyleri çıkar. Odanın ölçülerini ve açıklıklarını da tahmin et.",
            'response_schema' => $previous->response_schema ?? '{}',
            'change_note' => 'Duvar adları ana fotoğrafa göre tanımlandı; width/length duvar adlarına bağlandı.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
};
