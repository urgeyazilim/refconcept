<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stops the renderer drawing its own measuring tape into the room.
 *
 * The measurement rules were added so furniture would be spaced the way an interior
 * designer spaces it — a coffee table forty-five centimetres from the sofa, a picture
 * centred at a hundred and fifty. The model followed them and then *showed its working*:
 * dimension arrows across the floor with "40 cm" and "45 cm" lettered over them, in the
 * finished photograph the customer is shown.
 *
 * Every frame of the room tour inherited it, because the render is the video's first frame.
 * The video prompt already said "no text" and it made no difference at all — the text was
 * in the picture before the camera ever moved.
 *
 * So the measurements are relabelled as instructions to the model rather than content, and
 * "there is no writing in a photograph" becomes a numbered rule beside the other three
 * rather than a line of guidance somewhere in the middle. The three rules that came before
 * it each cost a week to learn; this one is cheaper and belongs in the same place.
 */
return new class extends Migration
{
    private const TASKS = ['image_render_draft', 'image_render_premium'];

    private const VERSION = 6;

    public function up(): void
    {
        foreach (self::TASKS as $code) {
            $template = DB::table('prompt_templates')->where('code', $code)->first();

            if ($template === null) {
                continue;
            }

            $id = $this->publish((string) $template->id, $code);

            DB::table('ai_task_routes')
                ->where('task', $code)
                ->update(['prompt_version_id' => $id, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::TASKS as $code) {
            $template = DB::table('prompt_templates')->where('code', $code)->first();

            if ($template === null) {
                continue;
            }

            // Back to whatever the route used before this, by number rather than by name:
            // a published version is never edited, so the previous one is still there.
            $previous = DB::table('prompt_versions')
                ->where('template_id', $template->id)
                ->where('version', '<', self::VERSION)
                ->orderByDesc('version')
                ->value('id');

            if ($previous === null) {
                continue;
            }

            DB::table('ai_task_routes')
                ->where('task', $code)
                ->update(['prompt_version_id' => $previous, 'updated_at' => now()]);
        }
    }

    private function publish(string $templateId, string $code): string
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
            'system_prompt' => $this->systemPrompt($code),
            // Unchanged from version 5. The variables were never the problem.
            'user_template' => $previous->user_template ?? '',
            'response_schema' => $previous->response_schema ?? null,
            'change_note' => 'Görselde ölçü oku ve yazı yasağı kural hâline getirildi.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function systemPrompt(string $code): string
    {
        $lines = [
            'Sen bir iç mimarsın ve müşterinin gerçek odasının fotoğrafını düzenliyorsun.',
            '',
            'BİRİNCİ KURAL — ODA MÜŞTERİNİN ODASI KALIR.',
            'İlk görsel müşterinin odasıdır. Onu DÜZENLE, yeni bir oda çizme. Duvarların açısı',
            've rengi, pencerelerin sayısı, konumu ve boyutu, kapılar, zemin kaplamasının rengi',
            've yönü, tavan yüksekliği, kartonpiyer, kamera açısı ve perspektif birebir aynı',
            'kalsın. Müşteri sonucu gördüğünde kendi odasını tanımalı. Fotoğrafta filigran',
            'varsa sonuçta gösterme; altındaki oda korunsun.',
            '',
            'İKİNCİ KURAL — YALNIZCA LİSTEDEKİLER.',
            'Yerleştirilecek ürünler listesinde ne varsa yalnızca onları koy. Listede olmayan',
            'hiçbir şey ekleme: kitaplık, konsol, dolap, vazo, kitap, tabak, masa lambası,',
            'bitki, tablo, perde, kırlent, halı — hiçbiri. Oda sana boş görünse bile boş kalsın.',
            'Müşteri bu görseldeki her şeyi satın alabilmeli; listede olmayan bir eşya çizmek,',
            'satılmayan bir ürünü vaat etmektir. Listedeki adet ve genişliklere uy: "×2" yazan',
            'üründen iki adet, "×1" yazandan bir adet koy.',
            '',
            'ÜÇÜNCÜ KURAL — ÜRÜNLER GERÇEK ÜRÜNLERDİR.',
            'İlk görselden sonraki her görsel, odaya konacak gerçek bir üründür. Biçim, renk ve',
            'malzeme olarak onlara sadık kal; yerlerine benzerlerini uydurma.',
            '',
            'DÖRDÜNCÜ KURAL — GÖRSELDE HİÇBİR YAZI VE İŞARET OLMAZ.',
            'Sonuç bir fotoğraftır; teknik çizim, plan ya da sunum panosu değil. Ölçü oku, ölçü',
            'çizgisi, "45 cm" gibi etiketler, rakamlar, ok işaretleri, açıklama notları, marka',
            'adı, filigran ya da imza koyma. Aşağıdaki ölçüler yalnızca sana nereye koyacağını',
            'söyler — onları odanın içine çizmen isteniyor değil.',
            '',
            'SONRA TASARIM YAP — KOLAJ DEĞİL.',
            '- Kompozisyon kararlarındaki odak noktasına uy; her parça ona yönelsin.',
            '- Oturma grubunu duvara yapıştırma; plandaki konumlara göre duvardan ayır.',
            '- Yükseklik ritmi kur: yüksek, orta ve alçak kütleler bir arada bulunsun.',
            '- Parçalar kameraya göre birbirinin önünde ve arkasında dursun; hepsi tek düzlemde',
            '  yan yana dizilirse kolaj gibi durur.',
            '- Her nesnenin zeminde temas gölgesi olsun. Havada duran mobilya, yapıştırılmış',
            '  mobilyadır.',
            '- Işık tek yönden ve odanın kendi pencerelerinden gelsin; gölgeler tutarlı olsun.',
            '  Listede aydınlatma varsa yansın.',
            '- Kırlentler hafifçe dağınık, minderlerde oturulmuşluk izi olsun. Kusursuz simetri',
            '  boş bir showroom hissi verir.',
            '',
            'YERLEŞİM ÖLÇÜLERİ (yalnızca senin için; görselde gösterme):',
            '- Orta sehpa koltuktan 40-45 cm uzakta.',
            '- Oturma parçalarının en azından ön ayakları halının üzerinde.',
            '- Tablo merkezi yerden ~150 cm; mobilya üzerindeyse ondan 15-20 cm yukarıda.',
            '- Perde pencere üstünden 10-15 cm yukarıdan veya tavana yakın, iki yana taşarak.',
            '- Dolaşım için 75-90 cm boşluk bırak.',
            '',
            $code === 'image_render_premium'
                ? 'Yüksek kalite: gerçekçi malzeme dokuları, yumuşak geçişli gölgeler, pencereden'
                    .' gelen ışıkla uyumlu iç aydınlatma.'
                : 'Hızlı önizleme kalitesi yeterli, ancak ışık ve gölge tutarlı olmalı.',
        ];

        return implode("\n", $lines);
    }
};
