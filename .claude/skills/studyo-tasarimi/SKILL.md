---
name: studyo-tasarimi
description: RefConcept oda stüdyosunun arayüz sözleşmesi. Stüdyo ekranlarına (oda, plan, tasarım), rehbere, adım göstergesine, sayfa çerçevesine ya da ürün metinlerine dokunan her işte önce bunu oku. Yeni ekran, akış değişikliği, buton ya da cümle yazımı dahil.
---

# Oda stüdyosunun tasarım sözleşmesi

Ürün sahibinin tekrar tekrar söylediği şeyler. Her biri bir kez ihlal edildi ve geri
alındı; liste onun için var.

## Akış

**Dört adım, bir döngü.** Fotoğraf → Eşyalar → İstekler → Tasarım. Sonrası çizgi değil:
tasarımı beğenmezse "yerleşimi değiştir" açılır, 3B'de taşır, yeni tasarım gelir. Beğenene
kadar döner.

**3B ileri bir basamak değildir.** Beğenmeyenin açtığı kapıdır. Adım şeridinde beşinci
kutu olarak durmaz.

**Ölçü ve açıklık sorusu 3B'nin eşiğindedir.** Tasarım fotoğrafın üstüne çizilir, ölçü
gerektirmez. Ölçü ancak gerçek boyutlu ürünü odaya koyarken gerekir. Beğenip 3B'ye hiç
girmeyen müşteri o soruyu hiç görmez.

**Adım atlatma yok, geri dönüş her zaman açık.** Seçilen adım adreste tutulur; sayfa
yenilenince müşteri yerini kaybetmez.

## Adım göstergesi

**Numaralı sihirbaz şeridi amatördür.** "1 Fotoğraf — 2 Eşyalar — 3 Oda" biçiminde on
kutuluk bir şerit sıkıcıdır ve ürün sahibi bunu böyle adlandırdı. İlerleme *hissettirilir*,
sayılmaz.

**Gösterilecek olan:** nerede olduğun, ne kadar kaldığı, bir sonrakinin adı. Hepsi bu.
Onu da ince bir çizgi ve tek bir ad taşır; on tane numaralı daire değil.

**Geçişler akıcı olur.** Önce rehberin cümlesi değişir, sonra sahne. Sert kesme yok.

## Ekran

**Sabit ekran.** Stüdyo televizyon gibidir: pencereye sığar, aşağı kaymaz. Taşma sıfır
piksel, bir piksel bile değil. `--rc-header` başlığın çizgisini de sayar.

**Tek çerçeve.** Her sayfa aynı yükseklikte başlar (`--rc-page-top`), aynı oluğu kullanır.
Sitenin hiçbir yerinde ikinci bir üst boşluk değeri yoktur.

**Sol sütun yok.** Rehber bir bant, sütun değil. Hesap sayfasının menüsü de bant.

**Boş yer kaplayan kart yok.** Yalnızca başlığı olan panel, rehberin cümlesini tekrarlayan
metin, iki aynı işi yapan düğme: hepsi çıkar.

**Hiçbir şey alt kenarda kesilmez.** Sığmayan ikincil denetim sahnenin yan sütununa
taşınır; o sütun kendi içinde kayar.

## Rehber

**Tek ses.** Rehber birinci tekil konuşur, bir cümle söyler, bir şey ister. "Odanı
okuyorum", "Eşyaları kaldırayım mı?", "Tasarımın hazır."

**Ekranda tek soru olur.** İki soru aynı anda sorulmaz.

**Rehber ne yaptığını söyler, yaptığını sandığını değil.** "Kapı ve pencereleri yana
koydum" cümlesi, koymadığı hâlde yazılmıştı. Sayı ver: "2 kapı/pencere buldum."

## Ekranın dürüstlüğü

**Her adım kendi konusunu gösterir.** Eşya adımı fotoğrafı gösterir. Oda adımı odayı
gösterir. Sorduğu şeyi göstermeyen ekran yoktur.

**Ölçülmemiş sayı gerçek gibi yazılmaz.** Sahne varsayılan bir kutu çiziyorsa köşede
"Ölçü bekleniyor" yazar, uydurma metre değil.

**Başka ekranın cümlesi kullanılmaz.** "Sağdan ürün ekle" cümlesi, sağında ürün listesi
olmayan ekranda yazılmaz.

## Müşteriye iş yaptırma

**Sistemin yapabileceğini müşteriye sordurma.** Hangi fotoğraftan tasarım çizileceğini
sistem seçer. Kapı ve pencereyi okuma bulur ve duvara kendisi koyar. Müşteri yalnızca
düzeltir.

**Düzeltme her zaman tek dokunuşluk olur.** Sürükle, türünü değiştir, kaldır.

**Müşterinin kendi seçimi ezilmez.** Elle seçtiği şey bir sonraki yüklemede değişmez.

## Metin

**Ürün metinleri Türkçe.** Kod yorumları ve teknik belgeler İngilizce.

**Düğme ne yaptığını söyler.** "Ekle" iki yerde birden kullanılmaz. "Bunu kullan" gibi
neyin arasında seçim yapıldığı belirsiz metin yazılmaz.

## Para

**Sağlayıcıya para harcatan hiçbir şey, sahibi açıkça "evet, harca" demeden çalışmaz.**
Otomatik tetiklenen pahalı iş yoktur; düğme vardır, düğmenin yanında fiyat vardır.

**Harcama dürüstçe raporlanır.** Tahmin değil, ölçülen tutar.

## Doğrulama

Stüdyoya dokunan her değişiklikten sonra `tests/e2e/studio-walkthrough.spec.ts` koşulur.
Her ekranın karesini alır ve şu üç sayıyı basar: soldan başlangıç, üst kroma, taşma.
Üçü de bütün ekranlarda aynı olmalı, taşma sıfır olmalı.
