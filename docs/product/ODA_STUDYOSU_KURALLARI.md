# Oda Stüdyosu — Ürün Kuralları ve Yapı

Durum: taslak, 2026-09-15. Ürün sahibinin tarifinden ve altı panelli storyboard'dan
çıkarıldı. Bu belge Türkçedir çünkü ürün sahibinin imzalayacağı sözleşmedir; kod ve teknik
belgeler İngilizce kalır (`docs/design/`).

> "Sadece bir fotoğraf, gerisi bizde."

## 1. Vizyon

Müşteri odasının bir fotoğrafını çeker ve gönderir. Sistem odayı tanır, içindeki eşyaları
kaldırıp boş odayı ortaya çıkarır, o boş odaya iç mimar kurallarıyla **gerçek, satın
alınabilir ürünleri** yerleştirir ve bunu bir görsel olarak gösterir. Müşteri "Düzenle"
dediğinde **aynı yerleşim** 3B sahnede açılır; ürünleri taşır, döndürür, ekler, çıkarır.
"Render al" dediğinde **kendi yerleşimi** kaydedilir ve gerçek ürünlerle, birebir o
yerleşimin fotogerçekçi görseli üretilir. Beğendiğini tek tıkla sepete atar.

## 2. Müşteri yolculuğu (yedi adım)

| # | Adım | Müşteri ne yapar | Sistem ne yapar | Storyboard |
|---|---|---|---|---|
| 1 | **Fotoğraf** | Odasının fotoğrafını yükler (telefon yeterli) | Kabul eder, gizli diske yazar, döndürme/EXIF düzeltir | Panel 1 |
| 2 | **Tanıma** | Bekler (≤ 20 sn) | Duvarlar, pencereler, kapılar, ölçüler, zemin/duvar malzemesi, mevcut eşyalar | Panel 2 |
| 3 | **Onay** | "Bu ölçüler doğru mu?" — evet der ya da düzeltir | Onaylanan ölçüler *gerçek* olur; öncesi sadece tekliftir | Panel 2 |
| 4 | **Boş oda** | Önce/sonra kaydırıcısında boşaltılmış odayı görür | Taşınabilir eşyaları siler, mimariyi korur → **plaka** (boş oda fotoğrafı) | *(yeni)* |
| 5 | **Öneri** | Stil, bütçe, oda amacını seçer; "Tasarla" der | İç mimar kurallarıyla katalogdan ürün seçer, **yerleşimi** hesaplar, plaka üzerine görselleştirir | Panel 4/6 |
| 6 | **Düzenle** | Görseldeki odayı 3B'de açar; taşır, döndürür, ekler, çıkarır | Aynı yerleşim, aynı ürünler; duvar dışına çıkmaz, çakışmaz | Panel 3/5 |
| 7 | **Render & sipariş** | "Render al" der; beğenirse "Sepete ekle" | Kaydedilen yerleşimden, plaka ve ürün fotoğraflarıyla render; tek sepette tüm ürünler | Panel 6 |

## 3. Kurallar (sözleşme)

Her kural bir "her zaman" ya da "asla"dır. Kod bu listeye göre test edilir.

### Fotoğraf ve mahremiyet
- **K1.** Tek bir fotoğraf yeterlidir. İkinci fotoğraf kaliteyi artırır, hiçbir adım için şart değildir.
- **K2.** Oda fotoğrafı ve ondan türeyen her görsel (plaka, render) **özel** kalır: gizli disk, rastgele anahtar, hiçbir yanıtta ham URL yok, yalnızca sahiplik kontrolünden geçen imzalı bağlantı. Dosya adı denetim kaydına bile yazılmaz.
- **K3.** Fotoğraf hiçbir sağlayıcıya *link* olarak gitmez; bayt olarak, kendi ağımızdan okunup gönderilir.

### Tanıma ve onay
- **K4.** Analiz **teklif eder, karar vermez.** Ölçüler müşteri "evet" deyinceye kadar tahmindir ve hiçbir hesap onlara dayanmaz.
- **K5.** Analiz bulduğunu **fotoğrafın üstünde gösterir** (kutular). Yanlış bulduğu şey görünür olmalıdır; %71 güven yazısı kimseye bir şey söylemez.
- **K6.** Müşteri her ölçüyü ve her kapı/pencereyi düzeltebilir; düzeltme analizin üstüne yazar ve *kaynak: kullanıcı* olarak işaretlenir.
- **K7.** Kapı, pencere, radyatör gibi **sabit öğeler korunur**; hiçbir öneri ve hiçbir render bunları taşıyamaz, kapatamaz, kaldıramaz. Kapı önünde 90 cm, pencere önünde 30 cm boş kalır.

### Boş oda (plaka)
- **K8.** Odadaki taşınabilir eşyalar (mobilya, halı, aksesuar) kaldırılır; **mimari** (duvar, zemin, tavan, kapı, pencere, priz, radyatör, sabit aydınlatma) olduğu gibi kalır. Sonuç bir **plaka**dır ve o odanın her renderı bu plakadan başlar.
- **K9.** Plaka müşteriye önce/sonra olarak gösterilir; müşteri "eşyalarım kalsın" da diyebilir (o zaman öneri mevcut eşyaların etrafına yapılır).
- **K10.** Plaka üretimi bir kez yapılır, sonra saklanır; her render için yeniden ödenmez.

### Öneri (iç mimar kuralları)
- **K11.** Öneri **yalnızca katalogdaki, satışta olan, ölçüsü girilmiş** ürünlerden yapılır. Ölçüsüz ürün plana giremez; uydurulmuş ürün asla görünmez.
- **K12.** Yerleşimi dil modeli *kelimelerle* önerir (hangi ürün, hangi duvar, neye göre); **milimetreleri kurallar hesaplar** (`LayoutComposer`). Bir dil modelinden gelen koordinata güvenilmez.
- **K13.** Uygulanan iç mimar kuralları — her biri kodda adıyla bulunur:
  - **Odak**: her odanın bir odak duvarı vardır (pencere, şömine, TV); oturma grubu ona bakar.
  - **Dolaşım**: ana geçişler ≥ 80 cm; kapı ile odak arasında engel yok.
  - **Ölçek**: mobilya oda alanının %40'ından fazlasını kaplamaz; kanepe uzunluğu duvarın 2/3'ünü geçmez.
  - **Sehpa kuralı**: sehpa kanepeden 40–45 cm önde, kanepe boyunun 2/3'ü genişlikte.
  - **Halı kuralı**: halı oturma grubunun ön ayaklarını içine alır.
  - **Simetri ve çift**: komodinler, aplikler çift; TV ünitesi koltuğun karşısında, ekran orta çizgide.
  - **Yükseklik**: tablolar göz hizası 145–150 cm merkez; aplik 170 cm; perde tavandan.
  - **Aydınlatma katmanları**: genel + görev + vurgu; oturma grubunun yanında en az bir lambader.
  - **Stil ve palet**: seçilen stil ve fotoğraftaki zemin/duvar renkleriyle uyumlu ürünler; tek odada en fazla üç malzeme ailesi.
  - **Bütçe**: verilen bütçe aşılmaz; aşıyorsa en pahalı parçadan başlayarak muadil önerilir ve **söylenir**.
- **K14.** Öneri görseli plaka üzerine, yerleşimin plan görüntüsüyle koşullanarak üretilir; duvarlar kaymaz, ürünler *ürünün kendi fotoğraflarından* çizilir.

### Düzenleme (3B) — birebirlik
- **K15.** **Tek gerçek yerleşim vardır**: `DesignLayout`. Öneri görseli, 3B sahne ve render hepsi ondan okur. Görseldeki kanepe ile 3B'deki kanepe aynı ürün, aynı koordinat, aynı yöndür.
- **K16.** "Düzenle" 3B sahneyi **o yerleşimle** açar; sıfırdan değil, "yaklaşık" değil.
- **K17.** 3B'de ürünler oklarla taşınır, halkayla döndürülür (15° adım, Shift serbest); duvarlar hizalanır; ölçüler canlı görünür.
- **K18.** Hiçbir ürün duvarın dışına çıkamaz, duvara giremez, başka ürünün içine giremez, kapının önüne konamaz. Bu bir uyarı değil, **kısıttır**: parça duvarda durur ve duvar boyunca kayar. Aynı kural sunucuda da vardır ve karar sunucunundur.
- **K19.** Her ürün **gerçek ölçüsünde** çizilir; 3B model varsa modelle, yoksa türünün katı şekliyle (kanepe kanepe gibi). Fotoğraf kesimi kullanılmaz.
- **K20.** Ürün ekleme/çıkarma katalogdan, sepete ekler gibi yapılır; çıkarılan ürün bütçeden düşer, eklenen ekler; toplam her an görünür.
- **K21.** Her hareket geri alınabilir; yerleşim kendiliğinden kaydedilir; sekme kapansa bile kaybolmaz.

### Render — "benim yerleşimim"
- **K22.** "Render al" önce yerleşimi **kaydeder**, sonra render'ı **o kayıttan** üretir. Kayıt ile render arasında el değmez.
- **K23.** Render girdileri her zaman üçtür ve kaydedilir: **plaka** (boş oda), **yerleşim anlık görüntüsü** (planın çizimi + 3B sahnenin görüntüsü) ve **ürün fotoğrafları**. Bir render hangi üçlüden yapıldıysa o üçlü sonradan gösterilebilir.
- **K24.** Render duvarları, kapı ve pencereleri plakadan alır; ürünleri anlık görüntüdeki yere, fotoğraflarındaki gibi çizer. Uydurma ürün, kayan duvar, kaybolan kapı **kabul edilmez**; tespit edildiğinde render yeniden üretilir, müşteriye gösterilmez.
- **K25.** Render ücreti krediyle ödenir ve **tıklamadan önce** yazılır. Başarısız render kredi iade eder.
- **K26.** Her render bir versiyondur; müşteri eski versiyona dönebilir, ikisini yan yana görebilir.

### Sipariş
- **K27.** "Sepete ekle" render'daki (yani yerleşimdeki) ürünlerin tamamını, satıcıya göre gruplayarak sepete koyar; stokta olmayan **adıyla söylenir**, sessizce atlanmaz.
- **K28.** Render'daki fiyat sepetteki fiyattır; fark varsa fark gösterilir ve onay istenir.

### Para ve şeffaflık
- **K29.** Müşteriden alınan her kredi ve platformun her sağlayıcı harcaması kayıt altındadır; hiçbir adım gizli harcama yapmaz.
- **K30.** Analiz ve plaka ücretsizdir (platform maliyeti); öneri ve render kredi harcar; fiyat listesi tek yerdedir.

## 4. Sistem yapısı

### 4.1 Boru hattı

```text
Fotoğraf ─▶ RoomAnalysis ─▶ (onay) RoomGeometryVersion + RoomConstraint
         └▶ RoomClear ─────▶ plaka (room_media.type = 'plate')
Brief ──▶ DesignPlan (kelimeler) ─▶ LayoutComposer (mm) ─▶ DesignLayout  ◀── 3B düzenleme
DesignLayout + plaka + ürün fotoğrafları ─▶ Render ─▶ DesignVersion (asset + render_inputs)
DesignLayout ─▶ Sepet
```

| Aşama | Görev | Durum |
|---|---|---|
| Tanıma | `AiTask::RoomAnalysis` (Gemini, yapısal) | **Var** — ölçü teklifi, açıklıklar, yüzeyler, bölgeler |
| Onay | `RoomGeometryVersion.confirm`, kısıtlar | **Var** — plan üzerinde sürükleme dahil |
| Boş oda | `AiTask::RoomClear` (görsel düzenleme) → plaka | **Yok** — kurulacak |
| Öneri | `AiTask::DesignPlan` + `LayoutComposer` | **Var** — iç mimar kuralları kısmen (odak, duvar, geçiş); K13'ün tamamı değil |
| Öneri görseli | `AiTask::ImageRender*` plan snapshot'ıyla | **Var** — plaka yerine ham fotoğraf kullanıyor |
| 3B düzenleme | `RoomEditor` + kısıtlar + tutamaçlar | **Var** — v2 fazları 0–4 |
| Render | pipeline `render()` | **Var** — girdiler kaydı (K23) ve sadakat denetimi (K24) yok |
| Sepet | `layout/cart` | **Var** |

### 4.2 Yeni parçalar

1. **RoomClear** — `AiTask::RoomClear` (modality Image; `image_edit` modeliyle), `RoomClearer`
   servisi, `ClearRoomPhotograph` kuyruk işi, `room_media.type = 'plate'`, uç noktalar:
   `POST rooms/{room}/media/{medium}/clear`, `GET …/plate`. Prompt: "taşınabilir her şeyi
   kaldır, mimariyi koru, açılan zemin ve duvarı çevresiyle tutarlı doldur; perspektif ve
   ışık değişmesin". Analizden gelen `movable_objects` listesi prompt'a eklenir.
2. **Render girdileri kaydı** — `design_versions.render_inputs` (JSON): plaka medya id'si,
   yerleşim anlık görüntüsü referansları, ürün fotoğraf id'leri, layout versiyonu.
3. **Sadakat denetimi** — `AiTask::RenderCheck` (vision, yapısal): render'da yerleşimdeki
   ürün sayısı/kabaca yerleri ve açıklıklar doğrulanır; uymuyorsa bir kez yeniden üretilir.
4. **İç mimar kuralları** — `LayoutComposer`'a K13 maddeleri: sehpa kuralı, halı kuralı,
   çiftler, TV karşı çizgisi, ölçek sınırı, aydınlatma katmanı. Her kural bir test.
5. **Oda Stüdyosu ekranı** — tek sayfa, yedi adımlı; aşağıda.

### 4.3 Durum makinesi (oda başına)

```text
photo_uploaded → analysed → confirmed → cleared → proposed → edited → rendered → ordered
                     ↑          │                       ↑        │
                     └── düzelt ┘                       └─ düzenle ┘  (her render yeni versiyon)
```

Her geçiş bir olaydır (`DesignVersionEvent` gibi), geri dönüş her zaman mümkündür.

## 5. UI / UX

**Tek ekran, yedi adım.** `projects/{id}/rooms/{roomId}/studio` — üstte adım şeridi (1 Fotoğraf ·
2 Tanıma · 3 Onay · 4 Boş oda · 5 Öneri · 6 Düzenle · 7 Render). Tamamlanan adım tik alır,
aktif adım vurgulanır, sonraki adımlar kilitli ama görünür — müşteri nerede olduğunu ve neyin
geldiğini her an bilir.

Adım adım:

1. **Fotoğraf** — büyük sürükle-bırak alanı, telefonda kamera düğmesi; yüklenirken
   ilerleme; iyi fotoğraf ipuçları (köşeden çek, gündüz, kapı görünsün).
2. **Tanıma** — fotoğrafın üstünde canlı çizilen kutular (duvar kenarları, pencere, kapı) ve
   sağda ölçü kartı; bulunan eşyalar listesi ("2 koltuk, 1 sehpa, halı").
3. **Onay** — tek soru: "Bu ölçüler doğru mu?" Evet / Düzelt. Düzelt: ölçü kutuları ve plan
   üzerinde sürüklenebilir açıklıklar (var).
4. **Boş oda** — önce/sonra kaydırıcısı; "Eşyalarım kalsın" seçeneği; yeniden dene.
5. **Öneri** — stil kartları (görsel), bütçe kaydırıcısı, oda amacı; "Tasarla" → ilerleme
   (tanıyor → seçiyor → yerleştiriyor → çiziyor); sonuç: büyük görsel + ürün listesi + toplam.
6. **Düzenle** — 3B sahne (var) solda, ürün listesi ve katalog sağda; üstte "Öneri görseli /
   3B" geçişi yan yana; alt çubukta Taşı·Döndür·Duvara hizala·Kopyala·Sil; toplam ve
   "Render al" sabit sağ altta.
7. **Render** — kredi bedeli ve süre yazan onay penceresi; ilerleme; sonuç: tam ekran görsel,
   versiyon şeridi, "Sepete ekle", "Paylaş", "Yeniden düzenle".

**Görsel dil:** mevcut token'lar (ink, charcoal, accent, line); bol boşluk, büyük görseller,
küçük metin; her adımda tek birincil düğme; hata ve bekleme durumları tasarlanmış (boş oda
üretilemedi → "fotoğrafta eşya yoktu, olduğu gibi devam"). Mobilde adımlar tam ekran
kaydırılır; 3B dokunmatik (tek parmak sürükle, iki parmak döndür/yakınlaş). Erişilebilirlik:
her adım klavyeyle tamamlanabilir, ölçüler metin olarak okunur.

## 6. İnşa planı

| Sprint | İş | Kabul ölçütü |
|---|---|---|
| S1 ✅ | RoomClear görevi + plaka + önce/sonra ekranı | Test odası fotoğrafında eşyalar gidiyor, kapı/pencere yerinde; plaka gizli diskte; testler simülatörle — *2026-09-15: kuruldu; gerçek fotoğrafta deneme ürün sahibinin onayını bekliyor* |
| S2 ✅ | Render girdileri kaydı; render plakadan başlar; sadakat denetimi | Her render'ın üçlüsü sorgulanabilir; kayan duvar yakalanınca yeniden üretim — *2026-09-15: kuruldu (`render_inputs`, `fidelity`, `render_check` görevi, bir kez yeniden üretim)* |
| S3 | İç mimar kuralları (K13) LayoutComposer'da | Her kuralın adıyla testi; kompoze oda kısıtlardan geçiyor |
| S4 ✅ | Oda Stüdyosu ekranı (adım şeridi + 7 adım), mevcut ekranların içine alınması | Tarayıcı testi: fotoğraf → render → sepet tek sayfada — *2026-09-15: adım şeridi (`StudioStepper`) oda, plan ve tasarım ekranlarında; oda ekranı bölümleri adım numarasıyla; plan formu odanın ölçüsüyle açılıyor; sadakat notu render'ın altında; `tests/e2e/studio-stepper.spec.ts`. Ayrı "tek sayfa" yerine mevcut üç ekran şeritle bağlandı — §8'deki dördüncü karar sahibinde.* |
| S5 | Versiyon şeridi, yan yana karşılaştırma, paylaşım | İki render yan yana; paylaşılan bağlantı fotoğrafı sızdırmaz |

Sıra S1 → S2 → S4 → S3 → S5 olabilir: önce boş oda ve birebirlik (ürünün özü), sonra ekran.

## 7. Maliyet notları

Analiz ~0,01 $ (Gemini), plaka ~0,05–0,13 $ (görsel düzenleme), öneri görseli ve render
~0,05–0,15 $ (model kademesine göre), ürün 3B modeli ürün başına bir kez ~0,53 $. Kredi
fiyatlaması bu maliyetlerin üstünde ve **tek yerde** (`CreditEconomySeeder`).

## 8. Açık kararlar (ürün sahibinden)

1. Plaka ücretsiz mi (platform maliyeti), yoksa kredi mi? (Öneri: ücretsiz — vitrindir.)
2. "Eşyalarım kalsın" seçeneği ilk sürümde var mı? (Öneri: evet, basit.)
3. Render sadakat denetimi başarısızsa kaç kez yeniden denenir? (Öneri: bir kez, sonra
   müşteriye "elimizden gelen bu" ile en iyisi gösterilir.)
4. Stüdyo ekranı mevcut oda/plan/tasarım sayfalarının **yerine mi** geçer, yanına mı?
   (Öneri: yerine; eski sayfalar yönlendirir.)
