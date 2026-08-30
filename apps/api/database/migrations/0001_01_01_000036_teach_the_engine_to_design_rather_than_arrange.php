<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Replaces "put these things in the room" with how an interior designer actually works.
 *
 * The renders were correct and lifeless. Every piece flat against a wall, evenly spaced,
 * symmetrical, one ceiling light, nothing overlapping, nothing layered — a catalogue
 * collage rather than a room. Anybody could cut the product photographs out and paste them
 * onto the customer's picture and get the same result, which is precisely the complaint.
 *
 * The cause was that neither prompt asked for a design. The plan prompt asked which wall
 * each item goes against; the render prompt asked for the items to be placed. A compass
 * bearing is not a design decision, and "place these" is not a brief.
 *
 * Both prompts now carry the craft, with the measurements that make it checkable rather
 * than aspirational. The numbers are the ones the trade actually uses:
 *
 *  - Seating floated 30-45cm off the wall, grouped within a 2.5-3m conversation circle,
 *    with 75-90cm of circulation around it.
 *  - Coffee table 40-45cm from the seat edge, at or just below seat height.
 *  - Rug big enough that the front legs of every seat stand on it.
 *  - Three layers of light — ambient, task, accent — placed as a triangle across the plan,
 *    never one ceiling fitting alone.
 *  - Art centred at 145-155cm, and 15-20cm above whatever it hangs over.
 *  - Curtains hung 10-15cm above the frame or near the ceiling, extending 15-25cm past it
 *    each side so the window reads wider than it is.
 *  - Heights varied deliberately — tall, medium, low — so the eye has somewhere to travel.
 *  - Objects grouped in threes, and one wall left deliberately breathing.
 *
 * The plan also has to *say* its decisions now: the focal point, the sightline from the
 * door, and each placement written as a relationship — "facing the window, floated 40cm off
 * the wall, front legs on the rug" — rather than a wall name. The renderer is given those
 * sentences, which is the difference between a picture that follows a layout and one that
 * follows a design.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->publishPlan();

        foreach (['image_render_draft', 'image_render_premium'] as $code) {
            $this->publishRender($code);
        }
    }

    public function down(): void
    {
        foreach (['design_plan' => 3, 'image_render_draft' => 3, 'image_render_premium' => 3] as $code => $version) {
            $template = DB::table('prompt_templates')->where('code', $code)->first();

            if ($template === null) {
                continue;
            }

            $previous = DB::table('prompt_versions')
                ->where('template_id', $template->id)
                ->where('version', $version)
                ->value('id');

            if ($previous !== null) {
                DB::table('ai_task_routes')
                    ->where('task', $code)
                    ->update(['prompt_version_id' => $previous, 'updated_at' => now()]);
            }
        }
    }

    // --- the plan -------------------------------------------------------------

    private function publishPlan(): void
    {
        $template = DB::table('prompt_templates')->where('code', 'design_plan')->first();

        if ($template === null) {
            return;
        }

        $id = $this->version($template->id, 4, [
            'system_prompt' => $this->planSystemPrompt(),
            'user_template' => implode("\n", [
                'Oda analizi: {{ analysis }}',
                'Kısıtlar: {{ constraints }}',
                'Bütçe (kuruş): {{ budget_minor }}',
                'İstenen stil: {{ style }}',
                'Renk paleti: {{ palette }}',
                'Müşterinin seçtiği parçalar: {{ required_placements }}',
                'Müşteri notu: {{ prompt }}',
            ]),
            'response_schema' => json_encode($this->planSchema(), JSON_UNESCAPED_UNICODE),
            'change_note' => 'Plan artık odak noktası, bölge ve ilişkisel konum kararı veriyor.',
        ]);

        DB::table('ai_task_routes')
            ->where('task', 'design_plan')
            ->update(['prompt_version_id' => $id, 'updated_at' => now()]);
    }

    private function planSystemPrompt(): string
    {
        return implode("\n", [
            'Sen deneyimli bir iç mimarsın. Görevin mobilyaları odaya dizmek değil, odayı',
            'tasarlamak. Bir yerleşim planı, hangi eşyanın hangi duvara yaslanacağı listesi',
            'değildir; nereye bakıldığı, nerede oturulduğu, gözün nasıl gezdiğidir.',
            '',
            'ÖNCE ŞU ÜÇ KARARI VER:',
            '',
            '1. ODAK NOKTASI. Her odanın bir odak noktası vardır: manzaralı bir pencere,',
            '   şömine, televizyon duvarı, yatağın başucu. Analizdeki sabit öğelere bakarak',
            '   birini seç ve her şeyi ona göre yönlendir. Odak noktası olmayan oda,',
            '   mobilyası ne kadar iyi olursa olsun tamamlanmamış görünür.',
            '',
            '2. BÖLGE. Oturma grubunu duvara yapıştırma. Parçaları birbirine bakacak şekilde',
            '   2,5-3 metrelik bir çember içinde topla ve duvardan 30-45 cm ayır. Duvara',
            '   dayalı sıralanmış koltuklar bir bekleme salonudur, oturma odası değil.',
            '   Çevresinde 75-90 cm dolaşım payı bırak.',
            '',
            '3. GİRİŞ HATTI. Kapıdan girildiğinde ilk ne görülüyor? Oraya en iyi parçayı',
            '   koy; dolabın arkasını veya bir koltuğun sırtını koyma.',
            '',
            'SONRA KOMPOZİSYONU KUR:',
            '',
            '- YÜKSEKLİK RİTMİ. Yüksek (kitaplık, uzun bitki, tavana yakın perde), orta',
            '  (kanepe sırtı, konsol), alçak (sehpa, halı). Hepsi aynı yükseklikte olan bir',
            '  oda düzdür. Gözün tırmanacağı ve ineceği bir yol olsun.',
            '- ASİMETRİK DENGE. Her şeyi ortalama. Görsel ağırlığı dengele: bir yanda ağır',
            '  bir dolap varsa karşısına yüksek bir bitki ve bir lambader koy, aynısını',
            '  değil.',
            '- ÜÇLÜ GRUPLAR. Dekoratif öğeleri tek sayılarla, tercihen üçlü ve farklı',
            '  yüksekliklerde grupla.',
            '- BOŞLUK. Her duvarı doldurma. En az bir duvar veya köşe bilinçli olarak boş',
            '  kalsın; boşluk da bir tasarım kararıdır.',
            '',
            'ÖLÇÜ KURALLARI (bunlara uy):',
            '- Orta sehpa, koltuk önünden 40-45 cm uzakta; yüksekliği oturma yüksekliğinde',
            '  veya biraz altında.',
            '- Halı, tüm oturma parçalarının en azından ön ayakları üzerinde duracak kadar',
            '  büyük olmalı. Ortada yüzen küçük halı hatadır.',
            '- Aydınlatma üç katman: genel (tavan), işlevsel (lambader, masa lambası),',
            '  vurgu (duvar apliği, obje aydınlatması). Tek tavan armatürü yeterli değildir.',
            '  Işık kaynaklarını planda üçgen oluşturacak şekilde dağıt.',
            '- Tablo merkezi yerden 145-155 cm; bir mobilyanın üzerindeyse mobilyadan',
            '  15-20 cm yukarıda.',
            '- Perde, pencere üstünden 10-15 cm yukarıdan veya tavana yakın asılır; her iki',
            '  yana 15-25 cm taşar ki pencere olduğundan geniş görünsün.',
            '',
            'MÜŞTERİNİN SEÇTİĞİ PARÇALAR VERİLDİYSE:',
            'Listedeki her parça için bir yerleşim döndür. Listeye parça ekleme, listeden',
            'parça çıkarma. category ve max_width_mm değerlerini verildiği gibi aynen koru.',
            'Senin işin position, wall ve notes alanlarını doldurmak.',
            '',
            'LİSTE BOŞSA: odaya uygun parçaları kendin öner; category ve max_width_mm zorunlu.',
            '',
            'position alanı en önemli alandır. Pusula yönü değil, İLİŞKİ yaz:',
            '  kötü:  "güney duvarı"',
            '  iyi:   "pencereye bakacak şekilde, duvardan 40 cm ayrık, ön ayakları halının',
            '          üzerinde, sehpayla arasında 45 cm"',
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

    /** @return array<string, mixed> */
    private function planSchema(): array
    {
        return [
            'required' => ['style', 'placements', 'composition'],
            'properties' => [
                'style' => ['type' => 'string'],
                'notes' => ['type' => 'string'],
                'palette' => ['type' => 'array', 'items' => ['type' => 'string']],

                /*
                 * The room-level decisions, and required — a plan that cannot name its own
                 * focal point has not made one, and the render will show it.
                 */
                'composition' => [
                    'type' => 'object',
                    'required' => ['focal_point', 'entry_view'],
                    'properties' => [
                        'focal_point' => ['type' => 'string'],
                        'entry_view' => ['type' => 'string'],
                        'zone' => ['type' => 'string'],
                        'circulation' => ['type' => 'string'],
                        'height_rhythm' => ['type' => 'string'],
                        'breathing_space' => ['type' => 'string'],
                    ],
                ],

                'placements' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        // `position` joins the two the matcher cannot work without: it is
                        // what turns a list into a layout, so a plan without it is the old
                        // inventory wearing the new schema.
                        'required' => ['category', 'max_width_mm', 'position'],
                        'properties' => [
                            'category' => ['type' => 'string'],
                            'max_width_mm' => ['type' => 'integer'],
                            'position' => ['type' => 'string'],
                            'wall' => ['type' => 'string'],
                            'name' => ['type' => 'string'],
                            'notes' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    // --- the render -----------------------------------------------------------

    private function publishRender(string $code): void
    {
        $template = DB::table('prompt_templates')->where('code', $code)->first();

        if ($template === null) {
            return;
        }

        $previous = DB::table('prompt_versions')
            ->where('template_id', $template->id)
            ->where('version', 3)
            ->first();

        $id = $this->version($template->id, 4, [
            'system_prompt' => $this->renderSystemPrompt($code),
            'user_template' => implode("\n", [
                'Oda: {{ room_type }}',
                'Stil: {{ style }}',
                'Kompozisyon kararları: {{ composition }}',
                'Yerleşim planı: {{ plan }}',
                'Renk paleti: {{ palette }}',
                'Korunacak mimari öğeler: {{ preserve }}',
                'Görsellerin sırası ve anlamı: {{ image_roles }}',
                'Müşterinin isteği: {{ instruction }}',
            ]),
            'response_schema' => $previous->response_schema ?? null,
            'change_note' => 'Render artık iç mimarlık tekniğiyle sahneliyor.',
        ]);

        DB::table('ai_task_routes')
            ->where('task', $code)
            ->update(['prompt_version_id' => $id, 'updated_at' => now()]);
    }

    private function renderSystemPrompt(string $code): string
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
            'ekleme. Müşteri bu görseldeki her şeyi satın alabilmeli.',
            '',
            'SEN BİR İÇ MİMARSIN, KOLAJ YAPMIYORSUN.',
            'Ürünleri fotoğrafa yapıştırma; sahneye yerleştir. Aradaki fark şunlardır:',
            '',
            '- KOMPOZİSYON. Kompozisyon kararlarındaki odak noktasına uy. Her parça ona',
            '  yönelsin. Oturma grubunu duvara yapıştırma; plandaki gibi duvardan ayır ve',
            '  parçaları birbirine baktır.',
            '- YÜKSEKLİK RİTMİ. Yüksek, orta ve alçak kütleler bir arada bulunsun. Gözün',
            '  düz bir çizgide gezdiği kare bir tasarım değildir.',
            '- ÖRTÜŞME VE DERİNLİK. Parçalar kameraya göre birbirinin biraz önünde ve',
            '  arkasında dursun. Hepsi tek bir düzlemde yan yana dizilirse kolaj gibi durur.',
            '- IŞIK. Üç katmanı da göster: genel aydınlık, işlevsel ışık (lambader/masa',
            '  lambası yanıyor), ve yüzeylerde vurgu. Işık kaynakları gerçekten ışık versin;',
            '  gölgeler tek yönden ve tutarlı düşsün.',
            '- GÖLGE VE TEMAS. Her nesnenin zeminde temas gölgesi olsun. Havada duran',
            '  mobilya, yapıştırılmış mobilyadır.',
            '- DOKU. Kumaş kumaş gibi, ahşap ahşap gibi görünsün; yüzeylerde malzemenin',
            '  dokusu okunsun.',
            '- YAŞANMIŞLIK. Kırlentler hafifçe dağınık, halı kenarı doğal, minderlerde',
            '  oturulmuşluk izi. Kusursuz simetri boş bir showroom hissi verir.',
            '',
            'ÖLÇÜ KURALLARI:',
            '- Orta sehpa koltuktan 40-45 cm uzakta.',
            '- Oturma parçalarının en azından ön ayakları halının üzerinde.',
            '- Tablo merkezi yerden ~150 cm; mobilya üzerindeyse ondan 15-20 cm yukarıda.',
            '- Perde pencere üstünden 10-15 cm yukarıdan veya tavana yakın, iki yana taşarak.',
            '- Dolaşım için 75-90 cm boşluk bırak; parçaların arasından geçilebilsin.',
            '',
            'Oda listelenenlerden sonra boş görünüyorsa boş kalsın. Boşluğu doldurmak için',
            'eşya ekleme; boşluk da bir tasarım kararıdır.',
            '',
        ];

        $lines[] = $code === 'image_render_premium'
            ? 'Yüksek kalite: gerçekçi malzeme dokuları, yumuşak geçişli gölgeler, doğru'
            : 'Hızlı önizleme kalitesi yeterli, ancak ışık ve gölge tutarlı olmalı.';

        if ($code === 'image_render_premium') {
            $lines[] = 'renk sıcaklığı ve pencereden gelen ışığa uyumlu iç aydınlatma bekleniyor.';
        }

        return implode("\n", $lines);
    }

    // --- shared ---------------------------------------------------------------

    /**
     * Adds a prompt version, or returns the existing one so a re-run still repoints.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function version(string $templateId, int $number, array $attributes): string
    {
        $existing = DB::table('prompt_versions')
            ->where('template_id', $templateId)
            ->where('version', $number)
            ->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $id = (string) Str::uuid7();

        DB::table('prompt_versions')->insert([
            'id' => $id,
            'template_id' => $templateId,
            'version' => $number,
            'status' => 'published',
            'published_at' => now(),
            'temperature_bps' => DB::table('prompt_versions')
                ->where('template_id', $templateId)
                ->orderByDesc('version')
                ->value('temperature_bps') ?? 2_000,
            ...$attributes,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
};
