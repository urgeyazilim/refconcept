<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The plan described a floor lamp it had not asked for.
 *
 * The product owner said their room looked empty next to the design it was made from, and it
 * did: seven pieces, 22 % of the floor, and a bare two metres at the entrance. The design
 * picture beside it has a floor lamp, curtains, a table lamp and two plants.
 *
 * The plan is not vague about any of that. Its notes read "Plana eklenen lambadere ek olarak,
 * TV ünitesi üzerinde veya yan sehpada bir masa lampası ve tavanda ... şık bir avize" — "in
 * addition to the floor lamp added to the plan". There is no floor lamp in the plan. The model
 * decided on one, wrote about it in prose, and never put a row in `placements`; and the room
 * is furnished from `placements` alone, so nothing downstream ever heard of it.
 *
 * The catalogue was not the problem. A lambader, a perde and a bitki are all sitting in it,
 * active and measured, and no design has ever asked for one.
 *
 * The prompt already teaches the composition — three layers of light, curtains hung high and
 * wide, greenery to lift the palette. It teaches them under "ÖLÇÜ KURALLARI", which reads as
 * advice about how to describe a room rather than as an instruction about what to list. So
 * version 5 says the thing that was assumed: the list is the plan, and a piece that is not on
 * it does not exist.
 */
return new class extends Migration
{
    private const TASK = 'design_plan';

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
                'LİSTEDE OLMAYAN PARÇA YOKTUR.',
                'Oda yalnızca placements listesinden döşenir. composition ya da notes içinde',
                'anlattığın ama placements içine satır olarak koymadığın hiçbir şey odaya',
                'girmez; müşteri onu ne görür ne satın alabilir.',
                '',
                'Cevabını vermeden önce şunu kontrol et: composition ve notes alanlarında',
                'adını andığın her mobilya, aydınlatma, tekstil ve bitkinin placements içinde',
                'bir satırı var mı? Yoksa ya satırı ekle ya da o cümleyi sil.',
                '',
                'Kurduğun kompozisyonun gerektirdikleri de satır olmak zorundadır:',
                '- Üç katman aydınlatma dediysen üç satır olur: tavan-aydinlatma,',
                '  lambader ya da masa-lambasi, ve duvar-aydinlatma.',
                '- Odada pencere varsa perde satırı olur.',
                '- Yeşillik dediysen bitki satırı olur.',
                '- Konsol, sehpa gibi bir yüzeyin üstünü doldurduysan o objenin de satırı olur.',
                '',
                'Bunların hepsi is_required: false ile eklenir. Gerekli olan kanepedir; bir',
                'lambader odayı tamamlar ama bütçe yetmezse düşebilir. Zorunlu işaretlemek,',
                'bütçesi dar bir müşteriye "bu olmadan olmaz" demektir.',
                '',
                'notes alanı müşterinin bilmesi gereken şey içindir — bakım, ölçü uyarısı,',
                'alternatif. Mobilya listesi için değildir.',
            ]),
            'user_template' => $previous->user_template ?? '',
            'response_schema' => $previous->response_schema ?? '{}',
            'change_note' => 'Kompozisyonda anlatılan her parça placements içinde satır olmak zorunda.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
};
