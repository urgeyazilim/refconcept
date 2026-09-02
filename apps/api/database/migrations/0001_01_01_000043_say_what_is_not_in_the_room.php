<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tells the renderer what the room is *not* getting, because a gap is not an instruction.
 *
 * A customer chose a corner sofa, a rug and curtains. All three were thrown out by a width
 * check that did not apply to any of them, and the renderer was handed a living room
 * containing a television, a coffee table, a floor lamp and nowhere at all to sit.
 *
 * It drew a corner sofa. Also a rug, cushions, an ottoman, a framed picture — and while
 * recomposing the scene around furniture that was never in the plan, it moved the walls: the
 * doorway narrowed and shifted, a new wall segment appeared, the window wall slid across.
 * The customer saw their own room with a sofa in it that nobody can sell them.
 *
 * The prompt already said "only what is on the list, nothing else" and had said so since
 * version 5. It made no difference, and the reason is worth writing down: **a prohibition
 * loses to a contradiction.** A living room with no seating is not a sparse room, it is an
 * impossible one, and a photorealistic model resolves impossibility in favour of the
 * picture. Told instead that there is deliberately no sofa and the seating area is meant to
 * be empty, it is no longer being asked for something impossible — only for something
 * sparse, which it can draw.
 *
 * So version 7 adds one variable and one rule: the categories that are absent, and an
 * instruction to leave their space empty and say nothing about it.
 */
return new class extends Migration
{
    private const TASKS = ['image_render_draft', 'image_render_premium'];

    private const VERSION = 7;

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
            'user_template' => implode("\n", [
                'Görsellerin sırası ve anlamı: {{ image_roles }}',
                'Yerleştirilecek ürünler: {{ plan }}',
                // The new one, and it is deliberately directly under the list it qualifies.
                'BU ODADA OLMAYACAK kategoriler: {{ absent }}',
                'Kompozisyon kararları: {{ composition }}',
                'Oda: {{ room_type }}',
                'Stil: {{ style }}',
                'Renk paleti: {{ palette }}',
                'Korunacak mimari öğeler: {{ preserve }}',
                'Müşterinin isteği: {{ instruction }}',
            ]),
            'response_schema' => $previous->response_schema ?? null,
            'change_note' => 'Eksik kategoriler modele açıkça bildiriliyor; boşluk bırakma kuralı eklendi.',
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
            've rengi, pencerelerin sayısı, konumu ve boyutu, kapılar ve kapı boşluklarının',
            'genişliği, zemin kaplamasının rengi ve yönü, tavan yüksekliği, kartonpiyer, kamera',
            'açısı ve perspektif birebir aynı kalsın. Duvar ekleme, duvar kaldırma, kapı',
            'boşluğunu daraltma veya kaydırma. Pencereden görünen manzarayı değiştirme.',
            'Müşteri sonucu gördüğünde kendi odasını tanımalı. Fotoğrafta filigran varsa',
            'sonuçta gösterme; altındaki oda korunsun.',
            '',
            'İKİNCİ KURAL — YALNIZCA LİSTEDEKİLER.',
            'Yerleştirilecek ürünler listesinde ne varsa yalnızca onları koy. Listede olmayan',
            'hiçbir şey ekleme: koltuk, berjer, puf, kitaplık, konsol, dolap, vazo, kitap,',
            'tabak, masa lambası, bitki, tablo, perde, kırlent, halı, televizyon — hiçbiri.',
            'Listedeki adet ve genişliklere uy: "×2" yazan üründen iki adet, "×1" yazandan bir',
            'adet koy.',
            '',
            'ÜÇÜNCÜ KURAL — OLMAYAN KATEGORİ BOŞ KALIR.',
            '"BU ODADA OLMAYACAK" listesindeki her kategori bilerek yoktur. O parçanın duracağı',
            'alanı BOŞ bırak; yerine başka bir şey koyma, benzerini koyma, küçüğünü koyma.',
            'Oturma grubu bu listedeyse odada oturulacak hiçbir şey olmayacak — koltuk, berjer,',
            'puf, sedir, minder hiçbiri. Halı bu listedeyse zemin çıplak kalacak. Perde bu',
            'listedeyse pencere çıplak kalacak.',
            'Oda sana eksik veya tuhaf görünecek. Öyle kalmalı. Müşteri bu görseldeki her şeyi',
            'satın alabilmeli; satılmayan bir eşya çizmek, olmayan bir ürünü vaat etmektir ve',
            'bu, odanın boş görünmesinden çok daha kötüdür.',
            '',
            'DÖRDÜNCÜ KURAL — ÜRÜNLER GERÇEK ÜRÜNLERDİR.',
            'İlk görselden sonraki her görsel, odaya konacak gerçek bir üründür. Biçim, renk ve',
            'malzeme olarak onlara sadık kal; yerlerine benzerlerini uydurma.',
            '',
            'BEŞİNCİ KURAL — GÖRSELDE HİÇBİR YAZI VE İŞARET OLMAZ.',
            'Sonuç bir fotoğraftır; teknik çizim değil. Ölçü oku, ölçü çizgisi, "45 cm" gibi',
            'etiketler, rakamlar, ok işaretleri, açıklama notları, marka adı, filigran ya da',
            'imza koyma. Aşağıdaki ölçüler yalnızca sana nereye koyacağını söyler.',
            '',
            'SONRA TASARIM YAP — KOLAJ DEĞİL.',
            '- Kompozisyon kararlarındaki odak noktasına uy; her parça ona yönelsin.',
            '- Listede oturma grubu VARSA duvara yapıştırma; plandaki konuma göre duvardan ayır.',
            '- Yükseklik ritmi kur: yüksek, orta ve alçak kütleler bir arada bulunsun.',
            '- Parçalar kameraya göre birbirinin önünde ve arkasında dursun; hepsi tek düzlemde',
            '  yan yana dizilirse kolaj gibi durur.',
            '- Her nesnenin zeminde temas gölgesi olsun. Havada duran mobilya, yapıştırılmış',
            '  mobilyadır.',
            '- Işık tek yönden ve odanın kendi pencerelerinden gelsin; gölgeler tutarlı olsun.',
            '  Listede aydınlatma varsa yansın.',
            '',
            'YERLEŞİM ÖLÇÜLERİ (yalnızca senin için; görselde gösterme):',
            '- Orta sehpa koltuktan 40-45 cm uzakta.',
            '- Oturma parçalarının en azından ön ayakları halının üzerinde.',
            '- Tablo merkezi yerden ~150 cm; mobilya üzerindeyse ondan 15-20 cm yukarıda.',
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
