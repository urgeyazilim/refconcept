# Ekran denetimi — 17 Eylül 2026

Ürün sahibinin isteği üzerine on adım Playwright ile baştan sona yürütüldü, her adımda 1440 ×
900 tek boy kare alındı ve ekranlar yan yana karşılaştırıldı. Kareler
`test-results/walkthrough/`, yürüyüşün kendisi `tests/e2e/studio-walkthrough.spec.ts`.

Yürüyüş ölçüm de alıyor: sayfanın soldan başladığı nokta, üst kroma yüksekliği ve sayfanın
ekranın altından ne kadar taştığı. Aşağıdaki sayılar göz kararı değil, o tablodan.

## Ölçülen

| Ekran grubu | Sol kenar | Üst kroma | Taşma |
|---|---|---|---|
| Stüdyo (10 adım), projeler, proje | 48 px | 89 px | 1 px |
| Ana sayfa, katalog | 0 px | 73 px | — |
| Favoriler, sepet, hesap, krediler | 48 px | 129 px | 0–227 px |

Aynı çerçeve üç ayrı yükseklikte başlıyor. Sayfadan sayfaya geçerken içerik zıplıyor.

## Bulgular

Ağırlığa göre sıralı. Her biri yukarıdaki karelerden biriyle gösterilebilir.

### Çerçeve

1. **Üç ayrı üst kroma.** 73, 89 ve 129 piksel. Tek bir kabuk yok; her sayfa ailesi kendi
   boşluğunu kuruyor.
2. **Stüdyo hâlâ 1 piksel kayıyor.** "TV ekranı gibi sabit olsun" kuralı sayısal olarak
   tutmuyor; 1 piksel bile tarayıcıya kaydırma çubuğu koydurur.
3. **Ana sayfa ve katalog tam genişlikte başlıyor** (sol kenar 0). Ana sayfada bu bilinçli bir
   kahraman görseli; katalogda sadece gri bir başlık şeridi ve çerçeveyi bozuyor.

### Adım şeridi

4. **Tasarım ekranında şerit taşıyor.** Kroma satırı orada daha uzun (`← Odaya dön · Salon
   tasarımı · Hazır · 1 sürüm · 3 kredi`) ve on adım sığmıyor: "Satın al" kartın dışına, iki
   satıra düşüyor. 1440 pikselde kırık bir şerit.
5. **8, 9 ve 10. adımlar aynı ekrana gidiyor.** Render, 360 ve Satın al bağlantılarının üçü de
   tasarım ekranını aynı hâlde açıyor; adres çıpası yok sayılıyor. Şerit on adım vadediyor,
   dördü tek ekran.
6. **6 ve 7 ayırt edilemiyor.** İkisi de plan sayfasına gidiyor; "3B"ye tıklayınca ekran
   "Kayıt" adımında olduğunu söylüyor.
7. **Tik mantığı ekrandan ekrana değişiyor.** Oda ekranında "2 Eşyalar" numaralı dururken,
   aynı oda için tasarım ekranında ✓ görünüyor. Aynı soruya iki cevap.

### Adımların içi

8. **2. adım, konuştuğu odayı göstermiyor.** Plaka üretilmeden önce ekranda yalnızca
   "Eşyaları kaldırayım mı?" sorusu var; müşterinin az önce yüklediği fotoğraf yok ve altta
   630 piksel boşluk kalıyor.
9. **1. adım ekranın alt yarısını harcıyor.** Yükleme alanı ince bir şerit, altında 380
   piksel boşluk.
10. **3. adımda uydurma ölçü gerçek gibi duruyor.** Form boşken ve rehber "Ölçüleri sen
    söyle" derken sahnenin köşesinde "4.00 × 5.00 m · tavan 2.70 m" yazıyor. Ölçülmemiş bir
    sayı ölçülmüş gibi görünüyor.
11. **3. adımda başka ekrana ait metin var.** Sahnenin altındaki balonda "Odan boş. Sağdan
    ürün ekle ya da 'Tasarıma göre yerleştir' de" yazıyor; o ekranda sağda ürün listesi yok,
    o cümle 6. adımın cümlesi.
12. **3. adımın altı kesiliyor.** "Radyatör, kolon, şömine gibi sabitler de varsa söyle"
    satırı ve "Ekle" düğmesi ekranın alt kenarında yarıda kalıyor.
13. **Tasarım hazır olduğunda alt yazı kesiliyor.** Görselin altındaki iki satırlık açıklama
    ekranın dışında kalıyor.

### Stüdyo dışı

14. **Hesap sayfasında hâlâ sol sütun var** ve üst menüyü tekrarlıyor ("Projelerim" iki
    yerde).
15. **Sepet boşken çıplak.** Tek cümle ve bir bağlantı; kart yok, düğme yok, 500 piksel
    boşluk. Diğer bütün sayfalar kart kullanıyor.

## İyileştirme planı

Dört aşama. Hepsi arayüz işi; hiçbiri sağlayıcıya para harcatmaz. Her aşamanın sonunda
yürüyüş yeniden koşulur ve kareler karşılaştırılır — düzelttiğimizi görmenin yolu bu.

### Aşama 1 — Tek çerçeve (en ucuz, en görünür)

- Tek bir sayfa kabuğu: aynı üst boşluk, aynı oluk, aynı ilk satır yüksekliği. Hedef: üç
  gruptaki 73 / 89 / 129 sayısının tek bir sayıya inmesi.
- Stüdyonun taşmasını sıfıra indir; yürüyüş bunu her koşuda ölçsün ve sıfırdan büyükse
  testi düşürsün.
- Katalogun tam genişlikteki başlık şeridini çerçeveye al; tam genişlik yalnızca ana
  sayfanın kahraman görselinde kalsın.

Kazanç: sayfadan sayfaya zıplama biter, "tüm sayfaları eşitlemedin" kapanır.

### Aşama 2 — Şerit doğruyu söylesin

- Şeridi 1440 pikselde taşmayacak biçimde kur: tasarım ekranındaki uzun kroma satırını
  kısalt ya da şeridi kendi satırına al. Kırık şerit kalmasın.
- 8, 9 ve 10 için ayrı ekran durumları: Render paneli, 360 paneli, satın alma listesi.
  Bugün üçü de aynı yere gidiyor; ya ayrı görünsünler ya da şeritte tek adım olsunlar.
- 6 ve 7'yi ayır: "3B" düzenleme, "Kayıt" kaydedilmiş hâl. Aynı sayfaysa şerit hangisinde
  olduğunu doğru söylesin.
- Tik durumunu tek yerden hesapla; oda ekranı ile tasarım ekranı aynı cevabı versin.

Kazanç: on adım vaadi tutar; müşteri nerede olduğunu şeritten okuyabilir.

### Aşama 3 — Her adım kendi konusunu göstersin

- 2. adımda fotoğrafı göster; plaka gelmeden de müşteri neyin temizleneceğini görsün.
- 3. adımda ölçü onaylanmadan sahne köşesinde sayı yazma; "ölçü bekleniyor" de.
- 3. adımdaki balon metnini o adıma ait cümleyle değiştir.
- 1. adımda yükleme alanını ekranın boyuna yay; boş alan bırakma.

Kazanç: ekran, sorduğu şeyi gösterir. Sahibinin "boş yere yer kaplayan ne varsa kaldır"
kuralı.

### Aşama 4 — Hiçbir şey kesilmesin

- Her adım sabit ekrana sığsın: 3. adımın sabit öğeler satırı ve tasarım ekranının alt
  yazısı kesilmesin. Sığmayan ikincil denetimler sahnenin yan sütununa taşınsın.
- Hesap sayfasının sol sütununu kaldır, üst menü zaten aynı bağlantıları taşıyor.
- Sepet boş hâlini kart ve düğmeye çevir.

Kazanç: "mouse ile aşağı inmek zorunda kalıyorum" tamamen biter.

## Yapıldı (17 Eylül 2026)

Dört aşamanın hepsi uygulandı. Yürüyüş her koşuda aynı tabloyu basıyor; aşağıdaki sayılar
düzeltme sonrası koşudan.

| Ekran grubu | Sol kenar | Üst kroma | Taşma | Şerit |
|---|---|---|---|---|
| Hepsi (22 ekran) | 48 px | 105 px | stüdyoda 0 | stüdyoda 1344 px |

### Aşama 1 — tek çerçeve

- `--rc-page-top`, `--rc-page-bottom` ve `--rc-header` tokenları; her sayfanın dış sarmalayıcısı
  artık `.rc-page`. Üç ayrı yükseklik (73 / 89 / 129) tek sayıya indi: 105.
- Stüdyonun yüksekliği başlığın 1 piksellik çizgisini de sayıyor; taşma sıfır.
- Katalogun tam genişlikteki gri şeridi çerçeveye alındı.

### Aşama 2 — şerit

- Şerit üç stüdyo ekranında da kendi satırında ve tam genişlikte; on adımın adı her zaman
  okunuyor. Kroma satırının uzunluğu artık şeridi etkilemiyor.
- Tikler tek kurala bağlandı: bitmiş en ileri adımdan öncesi bitmiş sayılır. Ekranların
  ortadaki adımlar hakkında anlaşması gerekmiyor.
- 8, 9 ve 10 gerçekten ayrı ekranlar: adres çıpası aşamayı açıyor, aşama da çıpayı yazıyor.
- 6 ve 7 ayrıldı: `#duzenle` ve `#kayit`.
- Tasarımı olmayan bir odada dört adımın bağlantısı odaya dönüyor; eskiden çift çıpalı bozuk
  adres üretiliyordu.

### Aşama 3 — her adım kendi konusu

- 2. adım fotoğrafı gösteriyor; plaka gelmeden de.
- 3. adım ölçü yokken "Ölçü bekleniyor" diyor, uydurma ölçü yazmıyor.
- 3. adımın balonu o adıma ait cümleyi söylüyor.
- 1. adımın yükleme alanı ekranı dolduruyor.

### Aşama 4 — kesilme yok

- Sabit öğeler formu odanın yan sütununa taşındı.
- Render altındaki iki satır için yer açıldı.
- Hesap sayfasının sol sütunu tek satır sekmeye dönüştü.
- Boş sepet kart, açıklama ve düğme oldu.

## Açık kalan

- **Seyrek bir sıçrama.** Yoğun bir test koşusunda, oda adımında sabit öğe formu doldurulurken
  ekran kendiliğinden 4. adıma (İstekler) geçebiliyor. Tek başına art arda üç koşuda
  üretilemedi; düzeltmelerden önce de aynı biçimde görülmüştü, yani bu denetimin getirdiği bir
  gerileme değil. Yürüyüş ve yolculuk testleri onu yakalıyor; ayrı ele alınacak.
