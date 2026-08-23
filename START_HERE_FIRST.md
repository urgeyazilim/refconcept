# REFCONCEPT — START HERE FIRST

Bu klasör RefConcept WEB yazılımını tek bir kullanıcı komutuyla bir coding agent'a
geliştirtmek için hazırlanmış **tam yazılım üretim paketidir**.

## Tek yapacağın

Coding agent'a bu repository/klasörü aç ve:

1. `00_COPY_THIS_PROMPT.md` dosyasını aç.
2. İçindeki prompt'u tek seferde ver.
3. Agent `WEB_RELEASE_APPROVED` durumuna kadar kendi içinde:
   - planlar
   - agent'lara böler
   - kodlar
   - test eder
   - hata düzeltir
   - tekrar test eder
   - state kaydeder
   - sonraki faza geçer

## İçerik

Bu paket şunların tamamını içerir:

- RefConcept master yazılım şartnamesi
- Laravel/Nuxt/PostgreSQL/Redis mimarisi
- AI motoru ve provider routing
- kredi/tasarım başı ücret sistemi
- satıcı onboarding
- ürün/SKU/varyant/PIM
- marketplace
- çoklu satıcı checkout
- iyzico
- QNB
- banka havalesi
- komisyon
- double-entry ledger
- seller settlement/hakediş
- iade/refund
- kargo
- Super Admin
- Seller Portal
- Storefront
- güvenlik
- DevOps
- CI/CD
- OpenAPI
- bağımsız Test Agent
- Phase 0–22 web geliştirme planı
- final release gate
- tasarım sistemi
- ekran blueprintleri
- kullanıcının verdiği tasarım referans görselleri
- repository memory/progress sistemi

## Kritik kural

**Önce WEB.**
Flutter/App/RoomPlan/ARCore ancak `WEB_RELEASE_APPROVED` sonrasında başlar.

## Gerçekçilik notu

Bu paket doğrudan kaynak kodun tamamlanmış hali değildir.
Bu paket, coding AI'nın kaynak kodun tamamını tek kullanıcı komutundan sonra
otonom olarak üretmesi için hazırlanmış kapsamlı specification + agent + test + UI paketidir.

Canlı ödeme anahtarları, production merchant hesapları ve hukuki/vergisel onaylar
harici go-live bağımlılıklarıdır.
