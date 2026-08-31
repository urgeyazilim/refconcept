<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Says "nothing else" in the one place a renderer reads first, and adds the sizes.
 *
 * Moving the render to `gpt-image-2` fixed the thing that mattered — the room in the
 * picture is now the customer's room — and exposed the next one. Given the same prompt it
 * kept the room faithfully and then furnished it with a bookcase, a sideboard, two vases
 * and a table lamp that were nowhere in the plan. Better at fidelity, looser about
 * instruction, and a shopping list that covers half of what is on screen is the exact
 * failure this product started with.
 *
 * So the constraint moves to the top and is stated as a rule rather than as a preference,
 * and the sizes travel with it: a plan entry now says "kanepe ×1, en fazla 300 cm" rather
 * than "kanepe", because a model told how many and how wide has far less room to improvise
 * a second one.
 *
 * The image-order line moves up too. It was near the bottom, after four paragraphs of
 * craft, and it is the single most important sentence in the prompt — everything else is
 * meaningless if the model does not know which picture is the room.
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
                ->where('version', 4)
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
            ->where('version', 5)
            ->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $previous = DB::table('prompt_versions')
            ->where('template_id', $templateId)
            ->where('version', 4)
            ->first();

        $id = (string) Str::uuid7();

        DB::table('prompt_versions')->insert([
            'id' => $id,
            'template_id' => $templateId,
            'version' => 5,
            'status' => 'published',
            'published_at' => now(),
            'temperature_bps' => $previous->temperature_bps ?? 2_000,
            'system_prompt' => $this->systemPrompt($code),
            'user_template' => implode("\n", [
                'Görsellerin sırası ve anlamı: {{ image_roles }}',
                'Yerleştirilecek ürünler: {{ plan }}',
                'Kompozisyon kararları: {{ composition }}',
                'Oda: {{ room_type }}',
                'Stil: {{ style }}',
                'Renk paleti: {{ palette }}',
                'Korunacak mimari öğeler: {{ preserve }}',
                'Müşterinin isteği: {{ instruction }}',
            ]),
            'response_schema' => $previous->response_schema ?? null,
            'change_note' => 'Listede olmayan eşya yasağı öne alındı; ölçüler prompta girdi.',
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
            'ÖLÇÜ KURALLARI:',
            '- Orta sehpa koltuktan 40-45 cm uzakta.',
            '- Oturma parçalarının en azından ön ayakları halının üzerinde.',
            '- Tablo merkezi yerden ~150 cm; mobilya üzerindeyse ondan 15-20 cm yukarıda.',
            '- Perde pencere üstünden 10-15 cm yukarıdan veya tavana yakın, iki yana taşarak.',
            '- Dolaşım için 75-90 cm boşluk bırak.',
        ];

        $lines[] = '';
        $lines[] = $code === 'image_render_premium'
            ? 'Yüksek kalite: gerçekçi malzeme dokuları, yumuşak geçişli gölgeler, pencereden'
                .' gelen ışıkla uyumlu iç aydınlatma.'
            : 'Hızlı önizleme kalitesi yeterli, ancak ışık ve gölge tutarlı olmalı.';

        return implode("\n", $lines);
    }
};
