# RefConcept — Master Product, Software Architecture, AI, Marketplace, Payment, Admin & Test Specification

# RefConcept Brand & Execution Lock

> **Current project/brand name is RefConcept.**  
> `RefOne`, `REFONE`, or `refone` must not be used in namespaces, UI text, package names,
> environment keys, database seed content, documentation, generated assets or new code.
> If an old reference is discovered during implementation, migrate it to `RefConcept`,
> `REFCONCEPT`, or `refconcept` as appropriate.

> **Delivery strategy is WEB-FIRST.** The complete web product must reach
> `WEB_RELEASE_APPROVED` before Flutter/mobile/AR implementation begins.

---

> **Belge tipi:** Tek kaynaklı ürün + yazılım + AI geliştirme spesifikasyonu  
> **Amaç:** RefConcept projesinin fikir aşamasından production yayına kadar tek bir ana doküman üzerinden geliştirilebilmesi  
> **Hedef kullanım:** İnsan yazılım ekibi veya tek/çoklu AI coding agent sistemi  
> **Başlangıç pazarı:** Türkiye  
> **Temel iş modeli:**  
> 1. Son kullanıcıdan AI tasarım üretimi için kredi/tasarım başı ücret  
> 2. Satıcılardan gerçekleşen ürün satışları üzerinden komisyon  
> 3. İleri aşamada premium üyelik, profesyonel hizmet komisyonu, öne çıkarma/reklam, API/B2B gelirleri  
> **Ana ilke:** RefConcept yalnızca “AI ile oda görseli üreten uygulama” değil; **tasarla → gerçek ürünle eşleştir → bütçele → satın al → teslim et → kur → projeyi tamamla** zincirini yöneten bir Interior Commerce Platform'dur.

---

# 0. TEK CÜMLELİK ÜRÜN TANIMI

**RefConcept; kullanıcının gerçek yaşam alanını fotoğraf, plan veya oda taramasıyla sisteme aktardığı; yapay zekâ ile tasarım ürettiği; tasarımdaki nesneleri gerçek satıcı ürünleriyle eşleştirdiği; bütçe oluşturduğu; ürünleri çoklu satıcılardan satın aldığı; ödeme, sipariş, kargo, kurulum ve proje süreçlerini tek platformda yönettiği; satıcıların da ürün, fiyat, stok, sipariş ve hakedişlerini yönettiği çok taraflı bir AI destekli marketplace platformudur.**

---

# 1. TEMEL ÜRÜN PRENSİPLERİ

1. **AI model isimleri kod içine sabit yazılmaz.**
   - `ai_providers`
   - `ai_models`
   - `ai_task_routes`
   - `prompt_templates`
   - `prompt_versions`
   tablolarından yönetilir.

2. **Ödeme sağlayıcı kodu domain mantığına gömülmez.**
   - `PaymentGatewayInterface`
   - `IyzicoGateway`
   - `QnbGateway`
   - `BankTransferGateway`
   şeklinde adapter/strategy uygulanır.

3. **Satıcı hakedişi ile müşteriden ödeme alma birbirinden ayrıdır.**
   - Payment
   - Marketplace Ledger
   - Seller Settlement
   farklı domain'lerdir.

4. **Para hiçbir yerde float/double tutulmaz.**
   - `BIGINT amount_minor`
   - `CHAR(3) currency`
   - Örn. 1.250,50 TL → `125050 TRY`

5. **Komisyon yüzdesi float tutulmaz.**
   - Basis point: `%12,50 = 1250 bps`

6. **Sipariş anındaki fiyat/komisyon snapshot olarak saklanır.**
   Sonradan ürün fiyatı veya satıcı komisyonu değişse bile geçmiş sipariş değişmez.

7. **AI üretimi asenkron queue ile çalışır.**
   Web isteği AI sonucunu beklemez.

8. **Her AI üretimi versiyonlanır.**
   Orijinal oda asla ezilmez.

9. **Her kritik finansal hareket immutable ledger kaydı üretir.**

10. **Tüm kritik admin/satıcı işlemleri audit log'a yazılır.**

11. **Kart verisi RefConcept sunucusunda saklanmaz.**
   PCI kapsamı mümkün olduğunca ödeme sağlayıcıya bırakılır.

12. **Tüm dış servislerde idempotency uygulanır.**

13. **Her geliştirme fazı test edilmeden sonraki faz tamamlanmış sayılmaz.**

---

# 2. KULLANICI TİPLERİ VE ROLLER

## 2.1 Son Kullanıcı

Yetkiler:
- kayıt/giriş
- profil
- adresler
- oda/proje oluşturma
- görsel/plan yükleme
- kredi satın alma
- AI tasarım üretme
- tasarım versiyonlama
- ürün eşleştirme
- favoriler
- sepet
- kupon
- ödeme
- banka havalesi bildirimi
- sipariş takibi
- kargo takibi
- iade/iptal talebi
- destek talebi
- bildirimler
- AR/3D görüntüleme
- tasarım paylaşma
- hesabını/verisini silme talebi

## 2.2 Satıcı

Satıcı organizasyon hesabı:
- başvuru
- şirket/KYC bilgileri
- banka/IBAN
- mağaza bilgileri
- kullanıcı/ekip yönetimi
- ürün yönetimi
- kategori/marka eşleme
- varyant
- medya
- fiyat
- stok
- teslim süresi
- kampanya
- sipariş
- kargo
- iade
- hakediş
- komisyon
- ödeme raporu
- ürün performans analitiği
- API/XML/CSV/Excel ürün aktarımı
- webhook/API anahtarları
- 3D asset yükleme

## 2.3 RefConcept Operasyon Kullanıcısı

Örnek roller:
- Support Agent
- Seller Operations
- Catalog Moderator
- Finance Operator
- Logistics Operator
- AI Operations
- Content Manager
- Risk/Fraud Operator
- Analyst

## 2.4 Super Admin

Sistemdeki tüm tenant, kullanıcı, satıcı, ürün, sipariş, ödeme, komisyon, kredi, AI, sistem, log, entegrasyon ve güvenlik ayarlarını yönetir.

## 2.5 Profesyonel Hizmet Sağlayıcı — V2+

- iç mimar
- mimar
- dekoratör
- uygulamacı
- montaj ekibi
- teknik servis
- ölçüm personeli

---

# 3. İŞ MODELİ

## 3.1 Son Kullanıcı — Kredi / Tasarım Başına Ücret

### Temel Model

Kullanıcı kredi paketi satın alır:

Örnek:
- 5 kredi
- 15 kredi
- 50 kredi
- 150 kredi

AI görevlerinin kredi maliyeti Super Admin'den ayarlanır:

| AI İşlemi | Örnek Kredi |
|---|---:|
| Oda analizi | 0 veya 1 |
| Draft tasarım | 1 |
| HD tasarım | 2 |
| Premium render | 4 |
| Tasarım revizyonu | 1 |
| 4K render | 5 |
| Alternatif ürün üretme | 0 |
| AI chat | pakete göre ücretsiz/kotalı |

> Sayılar örnektir. Veritabanından yönetilir.

### Kredi Kuralları

- Üretim başlamadan önce kredi **reserve** edilir.
- AI başarısız olursa reserve geri bırakılır.
- Başarılı üretimde reserve → consumed.
- Kullanıcı iptal ederse iş başlamadıysa iade edilir.
- Aynı idempotency key ile ikinci kez kredi düşmez.
- Admin kredi ekleyebilir/çıkarabilir; sebep zorunludur.
- Kampanya/promosyon kredileri ayrı son kullanım tarihine sahip olabilir.
- Ücretsiz kredi ile ücretli kredi ayrı ledger satırlarında izlenebilir.
- Kullanılacak kredide expiry önceliği uygulanabilir.
- Negatif bakiye normal kullanıcıda yasaktır.

## 3.2 Satıcı — Satış Komisyonu

RefConcept ürün satışından komisyon alır.

Komisyon öncelik sırası:

1. Sipariş kalemine özel snapshot
2. Kampanya komisyonu
3. Satıcı + kategori komisyonu
4. Satıcı özel komisyonu
5. Kategori komisyonu
6. Platform varsayılan komisyonu

Örnek:
- ürün brüt: 100.000 TL
- RefConcept komisyon: %12
- RefConcept komisyon tutarı: 12.000 TL
- satıcı brüt hakediş: 88.000 TL
- ödeme sağlayıcı maliyeti / kargo / iade / stopaj vb. muhasebe kuralları ayrı kalemler olarak ledger'da izlenir.

**Gerçek muhasebe ve vergisel dağılım, şirket sözleşmeleri ve Türkiye'deki güncel mevzuat için mali müşavir/hukuk danışmanı tarafından onaylanmalıdır. Yazılım mimarisi bu kuralların konfigüre edilebilir olmasını sağlamalıdır.**

## 3.3 İleri Gelir Modelleri

- Premium son kullanıcı üyeliği
- Tasarımcı üyeliği
- Satıcı aylık SaaS paketi
- Öne çıkan ürün
- Sponsorlu koleksiyon
- Lead komisyonu
- Profesyonel hizmet komisyonu
- API kullanımı
- B2B white-label
- 3D modelleme hizmeti
- Lojistik/kurulum hizmet komisyonu

---

# 4. ÖNERİLEN TEKNOLOJİ YIĞINI

## 4.1 Backend

- PHP 8.4+  
- Laravel 13
- Laravel Sanctum
- Laravel Horizon
- Laravel Reverb
- Laravel Scheduler
- Laravel Notifications
- Laravel Policies/Gates
- OpenAPI/Swagger dokümantasyonu
- Composer
- PHPUnit veya Pest
- PHPStan + Larastan
- PHP-CS-Fixer/Pint

## 4.2 Web

- Vue 3
- Nuxt — implementation anındaki güncel stable
- TypeScript
- Pinia
- Tailwind CSS
- Zod benzeri schema validation
- Playwright E2E
- Vitest unit/component
- ESLint
- Prettier

Ayrı uygulamalar:
- `apps/storefront`
- `apps/seller`
- `apps/admin`

İlk aşamada aynı Nuxt workspace içinde route/domain bazlı ayrılabilir; büyümede ayrıştırılabilir.

## 4.3 Mobil

- Flutter
- Dart
- Riverpod veya Bloc
- Dio
- Freezed/json_serializable
- go_router
- Firebase/APNs push abstraction
- integration_test
- native bridge:
  - iOS Swift
  - Android Kotlin

## 4.4 Veritabanı

- PostgreSQL
- pgvector
- PostGIS
- `pg_trgm`
- full-text search

## 4.5 Cache / Queue / Event

- Redis
- Laravel Queue
- Horizon
- Redis Streams sadece gerçekten event stream gerektiğinde

Queue'lar:
- `critical`
- `payment`
- `ai-fast`
- `ai-render`
- `ai-analysis`
- `media`
- `3d`
- `catalog-import`
- `notification`
- `integration`
- `analytics`
- `low`

## 4.6 Object Storage

S3-compatible storage:
- originals
- room scans
- renders
- masks
- product media
- 3D assets
- invoices
- seller docs
- bank receipts

Dosya erişimi:
- private by default
- signed URL
- CDN
- virus/malware scan
- MIME/type validation

## 4.7 AI

### Provider bağımsız mimari

- OpenAI
- Google Gemini
- ileride başka provider/local model

Kullanılacak görev türleri:
- chat/reasoning
- image understanding
- image generation/editing
- embeddings
- segmentation/object extraction
- product tagging
- moderation/safety
- recommendation/ranking support

**Model ID hiçbir zaman hard-code edilmez.**
Deployment anında sağlayıcının güncel model kataloğundan seçilir.

## 4.8 AI/CV Microservice

İlk MVP için zorunlu değil; fakat profesyonel full sürüm için hazırlanır:

- Python
- FastAPI
- PyTorch
- OpenCV
- Pillow
- NumPy
- ONNX Runtime gerektiğinde

Görevler:
- image preprocessing
- segmentation
- masks
- depth
- visual embeddings
- image quality validation
- 3D/CV pipeline

## 4.9 3D / AR

Web:
- Three.js
- glTF/GLB

iOS:
- Swift
- RoomPlan
- ARKit
- RealityKit
- USDZ

Android:
- Kotlin
- ARCore
- Depth API
- GLB/glTF

## 4.10 DevOps

- Docker
- Docker Compose local
- Nginx
- Git
- CI/CD
- GitHub Actions veya eşdeğer
- staging / production
- Terraform — production altyapı büyüdüğünde
- managed PostgreSQL
- managed Redis
- S3-compatible storage
- WAF/CDN
- secret manager
- automated backups

## 4.11 Observability

- Sentry
- OpenTelemetry
- Prometheus/Grafana veya managed eşdeğeri
- Laravel Horizon
- Laravel Pulse
- central logs
- uptime checks
- alerting

---


# 4A. GELİŞTİRME STRATEJİSİ — ÖNCE WEB, SONRA APP

RefConcept geliştirme sırası kesin olarak **WEB-FIRST** olacaktır.

Ana prensip:

```text
1. Backend/API + Web Storefront + Seller Portal + Super Admin
2. Web üzerinde gerçek kullanıcı/satıcı/ödeme/AI akışlarının tamamlanması
3. Web production/beta stabilizasyonu
4. API contract freeze / versioning
5. Daha sonra Flutter mobil uygulama
6. Son aşamada native AR/RoomPlan/ARCore özellikleri
```

## Neden Web-First?

- Marketplace, satıcı onboarding, ürün yönetimi, Super Admin ve finans operasyonları web üzerinde daha hızlı geliştirilebilir.
- iyzico, QNB, banka havalesi, komisyon, ledger, settlement ve reconciliation akışları mobil uygulamadan bağımsız olarak önce backend/web tarafında doğrulanabilir.
- AI tasarım, kredi, ürün eşleştirme ve checkout iş mantığı önce tek bir production ortamında stabil hale gelir.
- Mobil uygulama sıfırdan ayrı business logic yazmaz; mevcut versioned API'yi tüketir.
- Mobil geliştirme başladığında ürün gereksinimleri büyük ölçüde sabitlenmiş olur.
- AR/3D gibi daha maliyetli native özellikler, web ürün-pazar uyumu doğrulandıktan sonra eklenir.

## Web Fazı Kapsamı

Web tamamlanmadan App fazına geçilmez. Web tarafında aşağıdakilerin production-ready olması gerekir:

- Public Storefront
- Son kullanıcı kayıt/giriş/profil
- Proje ve oda yönetimi
- AI tasarım oluşturma ve revizyon
- Kredi satın alma ve kredi ledger
- Ürün eşleştirme
- Katalog / arama / filtre
- Favoriler
- Sepet
- Checkout
- iyzico
- QNB
- Banka havalesi
- Multi-seller order
- Komisyon
- Seller ledger
- Settlement / payout
- Sipariş / kargo / iade / refund
- Seller onboarding
- Seller portal
- Ürün manuel yükleme
- CSV/XLSX/API import
- Stok ve fiyat
- Product moderation
- Super Admin
- Finans / reconciliation
- AI Control Center
- Audit / security / observability
- Web E2E testleri
- Staging
- Web production release

## Web Production Gate

Mobil geliştirme başlamadan önce:

- [ ] Web backend API versioned ve dokümante
- [ ] OpenAPI güncel
- [ ] Kritik endpoint contract'ları stabil
- [ ] User E2E PASS
- [ ] Seller E2E PASS
- [ ] Super Admin E2E PASS
- [ ] iyzico sandbox/prod readiness PASS
- [ ] QNB test/prod readiness PASS
- [ ] Bank transfer PASS
- [ ] Credit concurrency PASS
- [ ] Ledger invariants PASS
- [ ] Multi-seller commission PASS
- [ ] Refund/settlement PASS
- [ ] Security P0/P1 = 0
- [ ] Test Agent WEB_RELEASE_APPROVED
- [ ] Web production/beta ortamı stabil

## App Fazı Kapsamı

Mobil uygulama web tamamlandıktan sonra başlar.

Flutter uygulama ilk etapta şu mevcut API'leri tüketir:

```text
Auth API
Profile API
Project API
Room API
AI Design API
Credit API
Catalog API
Search API
Favorites API
Cart API
Checkout API
Payment API
Order API
Notification API
```

Mobil uygulamada business rule tekrar yazılmaz.

Örneğin:

```text
YANLIŞ:
Flutter kendi komisyonunu hesaplar.
Flutter kendi kredi bakiyesini düşer.
Flutter kendi sipariş totalini hesaplar.

DOĞRU:
Flutter → Laravel API
Laravel domain engine → authoritative result
Flutter → sadece sonucu gösterir.
```

## Mobil Aşamalar

### APP-1 — Flutter Foundation
- auth
- environment config
- API client
- secure token storage
- navigation
- localization
- analytics
- crash reporting
- push altyapısı

### APP-2 — Core Consumer App
- profil
- proje
- oda
- görsel yükleme
- AI tasarım
- tasarım geçmişi
- kredi
- ürün eşleştirme
- katalog
- favori

### APP-3 — Commerce
- sepet
- checkout
- ödeme yönlendirmeleri
- sipariş
- kargo
- iade/refund takip

### APP-4 — Native Spatial
- iOS RoomPlan
- iOS ARKit/RealityKit
- Android ARCore
- Depth API
- 3D ürün yerleştirme
- capability fallback

### APP-5 — Mobile Release
- device matrix
- performance
- accessibility
- App Store/Google Play build
- privacy permissions
- release tests
- staged rollout

## Seller ve Super Admin Mobil Uygulaması

İlk ürün kapsamında **zorunlu değildir**.

Seller Portal ve Super Admin **web-first ve web-primary** kalır.

Gelecekte ihtiyaç oluşursa:
- seller companion app
- order notification app
- warehouse/scanner app
ayrı ürün olarak değerlendirilebilir.




# 4B. TASARIM REFERANSLARI VE UI YÖNÜ — REFCONCEPT

RefConcept için tasarım yönü kullanıcı tarafından sağlanan onaylı referans görsellerden türetilmiştir.
Bu referanslar yazılım geliştirme sürecinin **source of truth**'larından biridir.

## Tasarım Referans Dosyaları

- `design_refs/brand_colors.jpg`
- `design_refs/dashboard.jpg`
- `design_refs/hero_room.jpg`
- `design_refs/mobile_ai_marketplace.jpg`
- `design_refs/mobile_ops_ar.jpg`
- `design_refs/ui_inspiration.jpg`
- `design_refs/refconcept_assets_montage.png`

## Tasarım Karakteri

Arayüz dili:
- premium
- sade
- modern
- sıcak
- güven veren
- geniş boşluk kullanan
- nötr tonlu
- “quiet luxury” hissi veren

## Renk Sistemi

Onaylı marka paleti:
- Charcoal `#111111`
- Warm Gray `#F5F3F0`
- Sand `#DCCE86`
- Taupe `#A89E8E`
- Gold Accent `#C9A86A`

## UI Kuralı

Storefront, Seller Portal ve Super Admin; işlevsel olarak farklı olsalar bile aynı tasarım ailesinden gelmelidir.

Beklenen ortak özellikler:
- yumuşak radius'lu kartlar
- ince çizgi ikonlar
- temiz tipografi
- nötr zeminler
- koyu CTA butonları
- premium iç mekan görselleri
- AI akışında kart tabanlı yönlendirme
- bütçe ekranlarında donut/ring görselleştirme

## Detaylı Tasarım Dokümanları

Bu master spec'e ek olarak şu dosyalar da zorunludur:
- `21_DESIGN_SYSTEM_UI_SPEC.md`
- `22_SCREEN_BLUEPRINTS_INFORMATION_ARCHITECTURE.md`

Bu iki doküman, görselleri doğrudan implement edilebilir UI kurallarına dönüştürür.

# 5. YÜKSEK SEVİYE MİMARİ

```text
                           ┌─────────────────────┐
                           │ CDN / WAF / LB      │
                           └─────────┬───────────┘
                                     │
              ┌──────────────────────┴──────────────────────┐
              │                                             │
        Nuxt Web Apps                                  Flutter
 Storefront / Seller / Admin                         iOS / Android
              │                                             │
              └──────────────────────┬──────────────────────┘
                                     │
                                 API v1
                                     │
                     ┌───────────────▼────────────────┐
                     │      Laravel Modular Core      │
                     └───────────────┬────────────────┘
                                     │
      ┌───────────────┬──────────────┼─────────────┬──────────────┐
      │               │              │             │              │
 PostgreSQL         Redis         Object S3      Reverb       Search
 pgvector/PostGIS   Queue/Cache                  WebSocket     Engine
      │               │              │
      └───────────────┴──────┬───────┘
                              │
                        AI Orchestrator
                              │
          ┌───────────────────┼────────────────────┐
          │                   │                    │
       OpenAI              Gemini           Python AI/CV
          │                   │                    │
  LLM/Vision/Image      Vision/Image       CV/Segmentation
  Embeddings                              Depth/Visual Match
                              │
                ┌─────────────┴─────────────┐
                │                           │
          Payment Gateway             Integrations
     iyzico / QNB / Transfer       ERP / Shipping / etc.
```

---

# 6. REPOSITORY YAPISI

Önerilen monorepo:

```text
refconcept/
├── apps/
│   ├── api/                     # Laravel
│   ├── storefront/              # Nuxt
│   ├── seller-portal/           # Nuxt
│   ├── admin-panel/             # Nuxt
│   └── mobile/                  # Flutter
├── services/
│   └── ai-cv/                   # FastAPI
├── packages/
│   ├── ui/
│   ├── api-client/
│   ├── contracts/
│   └── shared-types/
├── infra/
│   ├── docker/
│   ├── nginx/
│   ├── terraform/
│   └── monitoring/
├── docs/
│   ├── ADR/
│   ├── api/
│   ├── db/
│   ├── security/
│   ├── payments/
│   ├── testing/
│   └── operations/
├── scripts/
├── .github/workflows/
├── docker-compose.yml
├── README.md
├── ARCHITECTURE.md
├── CHANGELOG.md
└── REFCONCEPT_MASTER_SPEC.md
```

---

# 7. LARAVEL DOMAIN/MODULE YAPISI

```text
app/
└── Domains/
    ├── Identity/
    ├── Organizations/
    ├── Customers/
    ├── Sellers/
    ├── Catalog/
    ├── Brands/
    ├── Products/
    ├── Inventory/
    ├── Pricing/
    ├── Media/
    ├── Projects/
    ├── Rooms/
    ├── Designs/
    ├── AI/
    ├── Search/
    ├── Recommendations/
    ├── Credits/
    ├── Cart/
    ├── Checkout/
    ├── Orders/
    ├── Payments/
    ├── Ledger/
    ├── Commissions/
    ├── Settlements/
    ├── Shipping/
    ├── Returns/
    ├── Refunds/
    ├── Promotions/
    ├── Reviews/
    ├── Favorites/
    ├── Notifications/
    ├── Messaging/
    ├── Support/
    ├── Professionals/
    ├── Appointments/
    ├── Installations/
    ├── Integrations/
    ├── Analytics/
    ├── Audit/
    ├── FeatureFlags/
    └── Administration/
```

Her domain içinde mümkün olduğunca:

```text
Actions/
DTOs/
Enums/
Events/
Exceptions/
Jobs/
Listeners/
Models/
Policies/
Queries/
Repositories/
Services/
ValueObjects/
Http/
Tests/
```

---

# 8. VERİTABANI TASARIM PRENSİPLERİ

- Primary key: `UUIDv7`
- zaman: UTC sakla; UI'da timezone'a çevir
- soft delete sadece gerekli domainlerde
- para: minor units
- status: enum yerine gerektiğinde string/value object; DB check constraint kullanılabilir
- JSONB: değişken özelliklerde kullanılabilir ancak relational verinin yerine rastgele kullanılmaz
- PII alanları sınıflandırılır
- lookup/reference tablolarında slug
- tüm dış sistem kayıtlarında:
  - `provider`
  - `external_id`
  - `raw_response` kontrollü/maskeleme
- finansal ledger satırları immutable
- audit log immutable
- kritik update'lerde optimistic locking/version

---

# 9. ANA TABLO ENVANTERİ

Aşağıdaki liste production full sürümün temel tablo haritasıdır. Migration'lar domain bazlı oluşturulmalıdır.

## 9.1 Identity & Security

1. `users`
2. `user_profiles`
3. `user_emails`
4. `user_phones`
5. `user_addresses`
6. `user_devices`
7. `user_sessions`
8. `personal_access_tokens`
9. `password_reset_tokens`
10. `email_verification_tokens`
11. `two_factor_methods`
12. `roles`
13. `permissions`
14. `role_permissions`
15. `user_roles`
16. `login_attempts`
17. `blocked_entities`
18. `consents`
19. `privacy_requests`

## 9.2 Organizations & Seller

20. `organizations`
21. `organization_users`
22. `organization_roles`
23. `organization_user_roles`
24. `organization_settings`
25. `seller_applications`
26. `sellers`
27. `seller_legal_entities`
28. `seller_contacts`
29. `seller_addresses`
30. `seller_bank_accounts`
31. `seller_tax_profiles`
32. `seller_documents`
33. `seller_agreements`
34. `seller_agreement_acceptances`
35. `seller_onboarding_steps`
36. `seller_risk_profiles`
37. `seller_status_history`
38. `seller_api_keys`
39. `seller_webhooks`
40. `seller_webhook_deliveries`

## 9.3 Catalog

41. `categories`
42. `category_translations`
43. `category_attributes`
44. `attributes`
45. `attribute_values`
46. `brands`
47. `brand_translations`
48. `brand_sellers`
49. `collections`
50. `collection_products`
51. `materials`
52. `colors`
53. `styles`
54. `rooms_taxonomy`

## 9.4 Products / PIM

55. `products`
56. `product_translations`
57. `product_sellers`
58. `product_categories`
59. `product_attributes`
60. `product_variants`
61. `variant_attribute_values`
62. `product_skus`
63. `product_barcodes`
64. `product_dimensions`
65. `product_weights`
66. `product_media`
67. `product_documents`
68. `product_3d_assets`
69. `product_3d_asset_versions`
70. `product_tags`
71. `tags`
72. `product_status_history`
73. `product_moderation`
74. `product_quality_scores`
75. `product_import_batches`
76. `product_import_rows`
77. `product_external_mappings`

## 9.5 Pricing / Inventory

78. `price_lists`
79. `prices`
80. `price_history`
81. `tax_classes`
82. `inventory_locations`
83. `inventory_stocks`
84. `inventory_reservations`
85. `inventory_movements`
86. `lead_times`
87. `shipping_profiles`

## 9.6 Projects / Rooms / Designs

88. `projects`
89. `project_members`
90. `project_addresses`
91. `project_status_history`
92. `rooms`
93. `room_dimensions`
94. `room_media`
95. `room_scans`
96. `room_scan_objects`
97. `room_constraints`
98. `designs`
99. `design_versions`
100. `design_assets`
101. `design_objects`
102. `design_object_constraints`
103. `design_prompts`
104. `design_feedback`
105. `design_shares`
106. `design_favorites`

## 9.7 AI

107. `ai_providers`
108. `ai_models`
109. `ai_task_types`
110. `ai_task_routes`
111. `ai_provider_credentials`
112. `prompt_templates`
113. `prompt_versions`
114. `ai_jobs`
115. `ai_requests`
116. `ai_responses`
117. `ai_usage`
118. `ai_cost_rates`
119. `ai_failures`
120. `ai_safety_events`
121. `embeddings`
122. `product_embeddings`
123. `design_embeddings`
124. `visual_embeddings`
125. `product_matches`
126. `product_match_feedback`

## 9.8 Credit System

127. `credit_wallets`
128. `credit_packages`
129. `credit_package_prices`
130. `credit_transactions`
131. `credit_reservations`
132. `credit_expiration_batches`
133. `credit_promotions`
134. `credit_promo_redemptions`
135. `ai_task_credit_costs`

## 9.9 Commerce

136. `carts`
137. `cart_items`
138. `cart_item_options`
139. `wishlists`
140. `wishlist_items`
141. `checkout_sessions`
142. `orders`
143. `seller_orders`
144. `order_items`
145. `order_item_snapshots`
146. `order_addresses`
147. `order_status_history`
148. `seller_order_status_history`
149. `order_notes`
150. `order_documents`

## 9.10 Promotions

151. `coupons`
152. `coupon_rules`
153. `coupon_redemptions`
154. `campaigns`
155. `campaign_products`
156. `campaign_sellers`
157. `campaign_categories`

## 9.11 Payments

158. `payment_methods`
159. `payment_gateways`
160. `payment_gateway_configs`
161. `payment_intents`
162. `payment_transactions`
163. `payment_attempts`
164. `payment_webhooks`
165. `payment_webhook_events`
166. `payment_idempotency_keys`
167. `payment_reconciliation_runs`
168. `payment_reconciliation_items`
169. `bank_accounts`
170. `bank_transfer_orders`
171. `bank_transfer_receipts`
172. `bank_statement_imports`
173. `bank_statement_rows`

## 9.12 Marketplace Ledger & Commissions

174. `commission_rules`
175. `commission_rule_scopes`
176. `order_item_commissions`
177. `ledger_accounts`
178. `ledger_entries`
179. `ledger_entry_lines`
180. `seller_balances`
181. `seller_balance_movements`
182. `settlement_periods`
183. `seller_settlements`
184. `seller_settlement_items`
185. `seller_payouts`
186. `seller_payout_attempts`
187. `seller_payout_adjustments`

## 9.13 Refund / Return

188. `return_requests`
189. `return_items`
190. `return_status_history`
191. `refund_requests`
192. `refunds`
193. `refund_transactions`
194. `disputes`
195. `chargebacks`

## 9.14 Shipping

196. `shipments`
197. `shipment_items`
198. `shipment_packages`
199. `shipment_tracking_events`
200. `shipping_carriers`
201. `shipping_rates`
202. `delivery_appointments`

## 9.15 Reviews / Support / Messaging

203. `reviews`
204. `review_media`
205. `review_votes`
206. `support_tickets`
207. `support_ticket_messages`
208. `conversations`
209. `conversation_members`
210. `messages`
211. `message_attachments`

## 9.16 Notifications

212. `notification_templates`
213. `notification_preferences`
214. `notifications`
215. `notification_deliveries`
216. `push_tokens`

## 9.17 Professionals — V2+

217. `professional_profiles`
218. `professional_services`
219. `professional_portfolios`
220. `professional_service_areas`
221. `professional_availability`
222. `professional_bookings`
223. `professional_reviews`
224. `installation_jobs`
225. `installation_job_tasks`

## 9.18 System / Admin

226. `system_settings`
227. `feature_flags`
228. `feature_flag_rules`
229. `audit_logs`
230. `admin_notes`
231. `scheduled_jobs`
232. `integration_configs`
233. `integration_logs`
234. `webhook_outbox`
235. `webhook_inbox`
236. `failed_jobs`
237. `job_batches`
238. `data_exports`
239. `data_imports`
240. `translations`
241. `currencies`
242. `countries`
243. `cities`
244. `districts`

Bu tablo listesi “her tablo mutlaka ilk gün oluşturulacak” anlamına gelmez. Migration'lar faz bazlı uygulanır; ancak mimari isim/ilişki çakışmalarını önlemek için master şema baştan tanımlanır.

---

# 10. KRİTİK TABLO ŞEMALARI

## 10.1 users

```text
id uuidv7 PK
email varchar unique nullable
phone varchar unique nullable
password_hash varchar nullable
status varchar
locale varchar default tr
timezone varchar default Europe/Istanbul
email_verified_at timestamp nullable
phone_verified_at timestamp nullable
last_login_at timestamp nullable
created_at
updated_at
deleted_at nullable
```

## 10.2 sellers

```text
id uuidv7
organization_id uuid FK
seller_code varchar unique
display_name varchar
status varchar
onboarding_status varchar
risk_status varchar
default_commission_bps int nullable
iyzico_submerchant_key encrypted nullable
qnb_merchant_reference encrypted nullable
approved_at nullable
approved_by nullable
suspended_at nullable
created_at
updated_at
```

## 10.3 products

```text
id uuidv7
canonical_brand_id
name
slug
product_type
status
moderation_status
primary_category_id
style_id nullable
description
seo_title nullable
seo_description nullable
search_document tsvector/derived
created_by
created_at
updated_at
deleted_at
```

## 10.4 product_skus

```text
id
product_id
seller_id
variant_id nullable
sku
barcode nullable
status
currency
list_price_minor
sale_price_minor nullable
tax_rate_bps
stock_policy
lead_time_days
created_at
updated_at
unique(seller_id, sku)
```

## 10.5 projects

```text
id
user_id
name
project_type
status
currency
budget_minor nullable
address_id nullable
created_at
updated_at
```

## 10.6 rooms

```text
id
project_id
name
room_type
measurement_quality enum[estimated,manual,scanned,verified]
width_mm nullable
length_mm nullable
height_mm nullable
original_media_id nullable
created_at
updated_at
```

## 10.7 design_versions

```text
id
design_id
parent_version_id nullable
version_number int
status
style_prompt
user_prompt
render_media_id nullable
thumbnail_media_id nullable
ai_job_id nullable
credit_cost int
created_at
```

## 10.8 credit_wallets

```text
id
user_id unique
available_credits int
reserved_credits int
lifetime_purchased int
lifetime_consumed int
version int
updated_at
```

> Bakiye alanları performans cache/snapshot niteliğindedir. Gerçek kaynak `credit_transactions` ledger'ıdır.

## 10.9 credit_transactions

```text
id
wallet_id
type enum[purchase,bonus,reserve,release,consume,refund,admin_adjustment,expire]
amount int signed
reference_type
reference_id
expires_at nullable
idempotency_key unique
created_at
created_by nullable
metadata jsonb
```

## 10.10 orders

```text
id
order_no unique
user_id
status
payment_status
fulfillment_status
currency
subtotal_minor
discount_minor
shipping_minor
tax_minor
grand_total_minor
created_at
paid_at nullable
cancelled_at nullable
```

## 10.11 seller_orders

Bir müşteri siparişi birden fazla satıcıya bölünür.

```text
id
order_id
seller_id
seller_order_no
status
subtotal_minor
discount_minor
shipping_minor
tax_minor
grand_total_minor
commission_minor
seller_net_minor
currency
created_at
```

## 10.12 order_items

```text
id
order_id
seller_order_id
seller_id
product_id
sku_id
quantity
unit_price_minor
line_subtotal_minor
discount_minor
tax_minor
line_total_minor
commission_bps_snapshot
commission_minor
seller_net_minor
currency
product_snapshot jsonb
variant_snapshot jsonb
created_at
```

## 10.13 payment_intents

```text
id
order_id nullable
credit_purchase_id nullable
user_id
gateway
purpose enum[order,credit,service]
status
currency
amount_minor
idempotency_key unique
external_reference nullable
expires_at nullable
created_at
updated_at
```

## 10.14 ledger_entries

Double-entry ledger tavsiye edilir.

```text
id
entry_no
entry_type
reference_type
reference_id
currency
occurred_at
posted_at
status
description
created_at
```

## 10.15 ledger_entry_lines

```text
id
entry_id
ledger_account_id
seller_id nullable
debit_minor
credit_minor
currency
metadata jsonb
```

Her entry için:
`sum(debit) == sum(credit)` zorunlu test edilir.

---

# 11. SON KULLANICI ÜYELİK AKIŞI

```text
Landing
  ↓
Kayıt
  ↓
Email/Telefon doğrulama
  ↓
KVKK/Aydınlatma/Rıza
  ↓
Profil
  ↓
İlk proje oluştur
  ↓
Oda ekle
  ↓
Fotoğraf/plan yükle
  ↓
AI tasarım sayfası
```

## Registration Endpoint

```http
POST /api/v1/auth/register
```

Payload:

```json
{
  "email": "user@example.com",
  "password": "strong-password",
  "locale": "tr",
  "consents": [
    {"type": "privacy_notice", "version": "2026-01"},
    {"type": "terms", "version": "2026-01"}
  ]
}
```

Kurallar:
- rate limit
- breached/common password policy
- email verify
- suspicious signup detection
- no automatic seller rights

---

# 12. KREDİ SATIN ALMA AKIŞI

```text
Kullanıcı
 ↓
Kredi paketleri
 ↓
Paket seç
 ↓
PaymentIntent oluştur
 ↓
iyzico / QNB / Havale
 ↓
Ödeme confirmed
 ↓
CreditPurchase paid
 ↓
Credit ledger +N
 ↓
Wallet balance güncelle
 ↓
Receipt/notification
```

## Kritik Kural

**Webhook / ödeme sorgulaması kesinleşmeden kredi yüklenmez.**

Client redirect “success” ekranı tek başına ödeme kanıtı değildir.

## Payment Callback Idempotency

Aynı callback 10 kere gelse:
- tek `payment_transaction`
- tek kredi yükleme
- duplicate etkisiz

---

# 13. AI TASARIM AKIŞI

## 13.1 Akış

```text
Proje
 ↓
Oda
 ↓
Orijinal görsel/scan
 ↓
Room Analysis
 ↓
Constraint Extraction
 ↓
Style + Budget + User Prompt
 ↓
Credit reservation
 ↓
AI Design Job
 ↓
Render
 ↓
Validation
 ↓
Başarılı?
 ├─ Hayır → retry / release credit
 └─ Evet
      ↓
   consume credit
      ↓
   Design Version oluştur
      ↓
   Object extraction
      ↓
   Product matching
      ↓
   Real catalog overlay
```

## 13.2 Room Analysis Çıktısı

Structured schema:

```json
{
  "room_type": "living_room",
  "confidence": 0.96,
  "style": ["modern"],
  "dominant_colors": ["warm_white", "oak"],
  "fixed_elements": [
    {"type": "window", "preserve": true},
    {"type": "tv", "preserve": true}
  ],
  "movable_objects": [],
  "surfaces": {
    "floor": {"material": "wood", "change_allowed": false}
  },
  "measurement_quality": "estimated",
  "warnings": []
}
```

## 13.3 AI Task Routing

```text
ROOM_ANALYSIS
DESIGN_PLAN
IMAGE_RENDER_DRAFT
IMAGE_RENDER_PREMIUM
IMAGE_EDIT
OBJECT_EXTRACTION
PRODUCT_TAGGING
PRODUCT_QUERY_REWRITE
PRODUCT_MATCH_RERANK
BUDGET_OPTIMIZE
SUPPORT_CHAT
CATALOG_ENRICHMENT
```

Her task için:
- primary provider/model
- fallback provider/model
- max retries
- timeout
- credit cost
- internal monetary cost limit
- concurrency
- safety policy
- prompt version

---

# 14. PRODUCT MATCHING ENGINE

AI render içindeki nesneyi gerçek katalog ürününe eşleştirmek RefConcept'ın ana ticari motorlarından biridir.

## Pipeline

```text
Design Render
 ↓
Object Detection / Segmentation
 ↓
Object Crop
 ↓
Structured Attributes
 ↓
Text Embedding
 ↓
Visual Embedding
 ↓
Candidate Retrieval
 ↓
Hard Filters
 ↓
Reranking
 ↓
Inventory/Price/Location Check
 ↓
Top Matches
```

## Candidate Score Örneği

```text
total_score =
  text_similarity * 0.25 +
  visual_similarity * 0.35 +
  dimension_fit * 0.15 +
  style_fit * 0.10 +
  color_fit * 0.05 +
  availability_score * 0.05 +
  commercial_score * 0.05
```

> Ağırlıklar admin/config üzerinden değişebilir ve A/B test edilmelidir.

## Hard Filters

- oda fiziksel ölçüleri
- kullanıcı maksimum fiyat
- teslimat lokasyonu
- aktif ürün
- aktif satıcı
- stok/üretilebilirlik
- kategori
- gerekli varyant
- yasak/engelli ürün

## Feedback Loop

Kayıt:
- önerildi
- görüldü
- tıklandı
- tasarıma eklendi
- alternatif istendi
- sepete eklendi
- satın alındı
- iade edildi

Bu sinyaller ileri ranking modelini besler.

---

# 15. TASARIMDAN SEPETE AKIŞ

Bir `design_object` gerçek `product_match` ile ilişkilendirilir.

Kullanıcı:
- önerilen ürünü kabul eder
- alternatif açar
- bütçe filtresi uygular
- farklı renk/varyant seçer
- sepete ekler

Sepete ekleme anında:
- güncel fiyat tekrar kontrol edilir
- stok kontrol edilir
- satıcı aktif mi kontrol edilir
- SKU aktif mi kontrol edilir

Checkout sırasında tekrar doğrulanır.

---

# 16. SEPET VE CHECKOUT

## Çoklu Satıcı Sepeti

```text
Cart
 ├─ Seller A
 │   ├─ Sofa
 │   └─ Chair
 └─ Seller B
     └─ Lamp
```

Checkout:
1. adres
2. teslimat
3. kupon/kampanya
4. toplam hesap
5. stok reservation
6. payment intent
7. ödeme
8. order create/confirm
9. seller order split
10. notification

## Race Condition

Aynı son stok iki kullanıcı tarafından alınmaya çalışılırsa:
- transaction
- row-level lock veya atomic stock reserve
- reservation TTL
- payment failure → release

---

# 17. SATICI ÜYELİK / ONBOARDING AKIŞI

```text
Satıcı Başvur
 ↓
Email/telefon doğrula
 ↓
Firma tipi seç
 ↓
Ticari bilgiler
 ↓
Yetkili kişi
 ↓
Vergi bilgileri
 ↓
Adres
 ↓
IBAN
 ↓
Sözleşmeler
 ↓
Belgeler
 ↓
iyzico submerchant onboarding (iyzico kullanılıyorsa)
 ↓
Risk/Kontrol
 ↓
RefConcept operasyon inceleme
 ↓
Onay
 ↓
Mağaza aktif
```

## Firma Tipleri

DB'de provider bağımsız normalize edilir:
- şahıs
- şahıs işletmesi
- limited
- anonim
- diğer

iyzico adapter gerektiğinde kendi submerchant type mapping'ini uygular.

## Onboarding State Machine

```text
draft
email_verified
company_info_pending
documents_pending
payment_onboarding_pending
review_pending
approved
rejected
suspended
closed
```

State geçişleri servis ile yapılır, controller doğrudan status yazmaz.

---

# 18. SATICI PORTALI

## Dashboard

- günlük/aylık satış
- sipariş sayısı
- bekleyen sipariş
- hazırlanıyor
- kargoda
- iade
- ürün görüntülenme
- dönüşüm
- toplam hakediş
- bekleyen hakediş
- ödenmiş hakediş
- komisyon
- kritik stok
- ürün kalite uyarıları

## Menü

```text
Dashboard
Ürünler
  Tüm ürünler
  Yeni ürün
  Taslaklar
  Moderasyonda
  Reddedilenler
  Toplu aktarım
  3D dosyalar
Kategoriler/Eşlemeler
Fiyatlar
Stoklar
Siparişler
Kargolar
İadeler
Finans
  Bakiye
  Hakediş
  Komisyon
  Ödemeler
  Ekstre
  Faturalar/Belgeler
Analitik
Mağaza Ayarları
Kullanıcılar/Roller
API & Entegrasyon
Destek
```

---

# 19. SATICI ÜRÜN YÜKLEME

## 19.1 Manuel

Wizard:

1. ürün tipi/kategori
2. marka
3. ürün adı
4. açıklama
5. stil
6. malzeme
7. renk
8. ölçü
9. varyant
10. SKU
11. fiyat
12. KDV/tax class
13. stok veya üretim tipi
14. teslim süresi
15. fotoğraflar
16. video
17. 3D asset
18. teknik belge
19. SEO
20. ön izleme
21. moderasyona gönder

## 19.2 CSV/XLSX

- template download
- column mapping
- dry-run
- validation
- preview errors
- partial import policy
- import batch
- row error report
- duplicate SKU detection

## 19.3 API/XML

Connector abstraction:
```text
CatalogSourceInterface
 ├─ RestApiSource
 ├─ XmlFeedSource
 ├─ CsvSource
 └─ ErpConnectorSource
```

Schedule:
- stock: sık
- price: sık
- product master: daha seyrek
- image: değişince

## 19.4 Product Moderation

AI ön kontrol:
- görsel kalitesi
- kategori tahmini
- eksik özellik
- duplike ürün
- yasak içerik
- watermark/bozuk resim
- description quality

İnsan/moderatör:
- approve
- reject
- request changes

Ürün onaylanmadan storefront'ta yayınlanmaz.

---

# 20. PIM — MOBİLYA ÖZEL ÜRÜN MODELİ

Örnek Sofa:

```text
Brand
Collection
Model
Category
Style
Width
Depth
Height
Seat Height
Arm Height
Material
Fabric Collection
Fabric Code
Color
Leg Material
Leg Color
Module
Orientation
Seating Capacity
Weight
Package Dimensions
Assembly Required
Warranty
Lead Time
Stock
3D GLB
USDZ
Technical PDF
```

Variantlar ayrı SKU üretir.

Örnek:
```text
SOFA-X-280-BEIGE-BLACK-LEFT
```

AI ürünü SKU düzeyinde eşleyebilmelidir.

---

# 21. SUPER ADMIN PANELİ

Super Admin yalnızca CRUD ekranı değildir; RefConcept operasyonunun kontrol merkezidir.

## 21.1 Dashboard

- GMV
- net revenue
- commission revenue
- credit revenue
- payment success rate
- refund rate
- order count
- active users
- new sellers
- pending seller applications
- pending product moderation
- AI requests
- AI cost
- AI gross margin
- render failure rate
- queue lag
- webhook failures
- payout pending
- reconciliation differences
- critical security alerts

## 21.2 Kullanıcı Yönetimi

- kullanıcı liste/arama
- durum
- doğrulamalar
- adres
- projeler
- tasarımlar
- kredi cüzdanı
- kredi ledger
- siparişler
- ödemeler
- iadeler
- ticket
- cihaz/oturum
- ban/suspend
- admin note
- data export
- delete/anonymize request
- impersonation varsa yüksek güvenlik/audit ve sınırlı kullanım

## 21.3 Satıcı Yönetimi

- başvurular
- belgeler
- KYC
- ödeme onboarding
- mağaza durumu
- komisyon
- kategori yetkileri
- risk durumu
- kullanıcılar
- API keys
- ürünler
- siparişler
- iade oranı
- hakediş
- payout
- manuel adjustment
- suspend
- terminate
- sözleşme versiyonu

## 21.4 Ürün Yönetimi

- global catalog
- seller listing
- merge duplicate
- category mapping
- brand mapping
- moderation
- AI tags
- search score
- product quality
- price anomaly
- stock anomaly
- media
- 3D asset
- bulk operation

## 21.5 Sipariş Yönetimi

- master order
- seller order
- item
- timeline
- payment
- shipment
- refund
- return
- support
- manual intervention
- order note
- audit

## 21.6 Finans

- payment intents
- transaction
- successful/failed
- provider
- refunds
- chargeback
- bank transfer
- reconciliation
- commissions
- ledger
- seller balances
- settlement periods
- payouts
- adjustment
- exports

## 21.7 Kredi Sistemi

- packages
- prices
- promotional credits
- AI task credit costs
- user wallet
- manual credit adjustment
- expired credits
- credit revenue
- cost/margin analysis

## 21.8 AI Control Center

- providers
- credentials
- models
- task routing
- fallback
- prompts
- versions
- credit cost
- actual provider cost
- request logs
- failure
- latency
- safety
- daily budget
- per-user rate limit
- per-org rate limit
- emergency kill switch
- A/B routes

## 21.9 System

- countries/currencies
- feature flags
- translations
- email templates
- push templates
- bank accounts
- payment configs
- shipping configs
- tax settings
- system maintenance mode
- cron
- queue
- failed jobs
- webhooks
- API health
- audit logs

---

# 22. ÖDEME MİMARİSİ

## 22.1 Temel Interface

```php
interface PaymentGatewayInterface
{
    public function createPayment(PaymentRequest $request): PaymentResult;
    public function retrievePayment(string $externalId): PaymentResult;
    public function cancelPayment(CancelRequest $request): CancelResult;
    public function refund(RefundRequest $request): RefundResult;
    public function parseWebhook(array $headers, string $body): WebhookEvent;
}
```

Marketplace yeteneği ayrı interface:

```php
interface MarketplaceSettlementGatewayInterface
{
    public function createSubMerchant(SubMerchantData $data): SubMerchantResult;
    public function updateSubMerchant(SubMerchantData $data): SubMerchantResult;
    public function approveItem(string $paymentTransactionId): ApprovalResult;
    public function disapproveItem(string $paymentTransactionId): ApprovalResult;
}
```

Böylece QNB kart tahsilatı ile iyzico marketplace settlement kabiliyeti birbirine karıştırılmaz.

---

# 23. IYZICO ENTEGRASYONU

RefConcept marketplace yapısı için iyzico entegrasyonunda **Marketplace/Submerchant** akışı desteklenmelidir.

## Mantık

```text
Satıcı RefConcept'a üye
 ↓
RefConcept iyzico onboarding API
 ↓
subMerchantKey
 ↓
DB'de şifreli sakla
 ↓
Müşteri sipariş verir
 ↓
basket item → seller/submerchant mapping
 ↓
iyzico marketplace payment
 ↓
payment transaction id'leri saklanır
 ↓
teslim/iade kurallarına göre approval
 ↓
settlement
```

## Zorunlu Tasarım Noktaları

- submerchant create/update
- submerchant key mapping
- item bazlı payout/commission bilgileri
- 3DS
- payment retrieval
- refund
- cancel
- approval/disapproval
- reconciliation
- webhook/event idempotency
- sandbox test

## Teslim Sonrası Approval

Config:
```text
seller_settlement_policy:
  after_delivery_days: N
  hold_if_return_open: true
```

İade talebi varsa approval yapılmaz.

---

# 24. QNB PAYMENT GATEWAY / QNBPAY

QNB tarafı ayrı adapter olarak uygulanır.

```text
QnbGateway
```

Desteklenecek:
- 3D Secure
- ödeme
- işlem sorgulama
- iptal
- iade
- taksit/BIN kabiliyeti sözleşme ve API'ye göre
- test ortamı/test kartları
- response code mapping
- reconciliation

## Kritik Mimari Fark

QNB Sanal POS ile ödeme alınması, RefConcept'ın marketplace satıcı ledger/settlement motorunu ortadan kaldırmaz.

Önerilen yapı:

```text
Customer
 ↓
QNB Payment
 ↓
RefConcept merchant collection
 ↓
Internal order-item commission split
 ↓
Seller balance
 ↓
Settlement period
 ↓
Seller payout
```

Satıcıya otomatik split/payout özelliği QNB sözleşmesi/ürünü tarafından sağlanmıyorsa RefConcept **internal ledger + payout** ile yönetir.

Bu özellik sözleşme/prod entegrasyonu öncesi QNB ile ticari ve teknik olarak doğrulanmalıdır.

---

# 25. BANKA HAVALESİ

## Kullanıcı Akışı

Checkout:
- ödeme yöntemi: Havale/EFT
- RefConcept banka hesabı
- benzersiz referans kodu
- tutar
- süre

```text
ORDER-RF-202600123
```

Kullanıcı:
- havale yapar
- opsiyonel dekont yükler

Order:
```text
payment_pending_bank_transfer
```

## Eşleştirme

### V1 Manuel

Finans admin:
- banka hareketini/dekontu görür
- siparişle eşleştirir
- `confirm`

### V2 Otomatik

Banka hesap hareketi API/ekstre entegrasyonu:
- amount
- reference
- sender
- date
eşleştirilir.

## Kurallar

- eksik ödeme
- fazla ödeme
- birden çok havale
- yanlış referans
- süresi geçen havale
- iptal sonrası gelen havale
- iade
ayrı state olarak ele alınır.

**Admin ödeme onayını doğrudan order tablosuna yazmaz.**
`BankTransferService.confirm()` finansal entry + payment transaction + order event oluşturur.

---

# 26. PAYMENT STATE MACHINE

```text
created
pending
requires_action
authorized
paid
failed
cancelled
partially_refunded
refunded
chargeback
```

Order state ödeme state'den ayrıdır.

```text
order:
pending_payment
confirmed
processing
partially_shipped
shipped
delivered
completed
cancelled
```

---

# 27. WEBHOOK TASARIMI

Her provider webhook'u:

1. raw request alınır
2. signature/auth doğrulanır
3. `payment_webhook_events` kayıt
4. unique provider event id kontrol
5. queue'ya atılır
6. hızlı 2xx cevap
7. worker işler
8. idempotent domain command
9. status/log

Webhook endpoint payment business logic çalıştırmamalı.

---

# 28. REFUND / RETURN

## Return

```text
requested
reviewing
approved
rejected
shipping_back
received
inspection
completed
```

## Refund

```text
requested
approved
submitted_to_gateway
processing
succeeded
failed
```

Partial refund item/quantity bazlı desteklenir.

Komisyon/hakediş ters kayıt ile ledger'a işlenir; eski kayıt silinmez.

---

# 29. SELLER SETTLEMENT / HAKEDİŞ

## Ledger Tabanlı

Order item paid:
- platform payable seller artar
- commission revenue oluşur

Delivery + hold period:
- settlement eligible

Return:
- eligible durdurulur veya reverse

Settlement run:
```text
2026-W34
```

Satıcı için:
- gross sales
- discount allocation
- refund
- commission
- payment fee policy
- shipping adjustment
- manual adjustment
- net payout

## Payout States

```text
draft
calculated
approved
queued
sent
confirmed
failed
reversed
```

Payout manuel banka transferi ile başlayabilir; ileride bankacılık API'si eklenebilir.

---

# 30. ÇİFT TARAFLI LEDGER

Önerilen hesaplar:

```text
ASSET:CASH_PROVIDER
ASSET:BANK
LIABILITY:CUSTOMER_REFUND
LIABILITY:SELLER_PAYABLE:{seller_id}
REVENUE:COMMISSION
REVENUE:CREDIT
EXPENSE:PAYMENT_GATEWAY
EXPENSE:AI
CLEARING:PAYMENT
CLEARING:PAYOUT
```

Test:
- her journal balanced
- finansal geçmiş delete/update edilmez
- düzeltme reversal entry ile

---

# 31. AI COST & PROFITABILITY

Her AI request:

```text
provider
model
task
input unit
output unit
images
resolution
provider_cost_minor
currency
credits_charged
estimated_revenue_minor
latency_ms
status
```

Dashboard:
- kredi geliri
- AI maliyeti
- brüt AI marjı
- model bazlı
- task bazlı
- kullanıcı bazlı abuse

Daily cost caps:
- global
- provider
- user
- organization

---

# 32. AI PROVIDER GATEWAY

```php
interface AiProviderInterface
{
    public function text(AiTextRequest $request): AiTextResult;
    public function vision(AiVisionRequest $request): AiVisionResult;
    public function generateImage(AiImageRequest $request): AiImageResult;
    public function editImage(AiImageEditRequest $request): AiImageResult;
    public function embed(AiEmbeddingRequest $request): AiEmbeddingResult;
}
```

Routing:

```php
$router->for(AiTask::ROOM_ANALYSIS)
       ->primary('openai', $configuredModel)
       ->fallback('gemini', $configuredFallbackModel);
```

---

# 33. PROMPT MANAGEMENT

Prompt kod içine gömülmez.

`prompt_templates`
- key
- task
- status

`prompt_versions`
- template_id
- version
- system_prompt
- user_template
- output_schema
- changelog
- created_by
- active_at

Her `ai_request` hangi prompt version kullandığını saklar.

---

# 34. DESIGN VALIDATION

AI render sonrası:

1. image generated mı
2. fixed elements korunmuş mu
3. unsafe/invalid content var mı
4. room consistency
5. product count
6. physical constraints
7. budget constraint
8. product availability
9. image quality

Geometri doğruluğu için yalnızca LLM'e güvenilmez.

Measurement confidence:
- estimated
- manual
- scanned
- verified

Kullanıcıya uygun uyarı gösterilir.

---

# 35. ROOMPLAN / ARCORE

## iOS

RoomPlan:
- desteklenen cihaz capability check
- tarama
- dimensions
- doors/windows
- detected objects
- export
- backend room scan upload

## Android

ARCore:
- supported device check
- Depth API capability
- placement
- occlusion
- scale

Cihaz desteklemiyorsa normal 2D/3D fallback.

---

# 36. 3D ASSET PIPELINE

```text
Seller upload
 ↓
Virus/type validation
 ↓
Metadata
 ↓
Scale/dimension validation
 ↓
Geometry optimization
 ↓
Texture optimization
 ↓
Thumbnail
 ↓
GLB
 ↓
USDZ conversion/validation
 ↓
QA
 ↓
Moderation
 ↓
Publish
```

Metadata:
- file size
- poly count
- texture resolution
- dimensions
- unit
- bounding box
- version

---

# 37. SEARCH

Aşama 1:
- PostgreSQL full text
- pg_trgm
- pgvector
- filters

Aşama 2 gerekirse:
- OpenSearch/Elasticsearch

Hybrid search:

```text
lexical score
+ vector score
+ popularity
+ conversion
+ availability
+ seller quality
+ personalization
```

Sponsorlu ürünler açıkça ayrı business rule ile işlenir; organik relevance tamamen bozulmamalı.

---

# 38. RECOMMENDATION

Sinyaller:
- room type
- user style
- budget
- clicked products
- favorites
- design usage
- cart
- purchase
- return
- geo availability

Cold start:
- style + category + budget + popularity

Warm user:
- personalized embedding/ranking

---

# 39. NOTIFICATION

Channels:
- in-app
- email
- push
- SMS adapter opsiyonel

Events:
- verify
- payment success/fail
- credit added
- render ready
- order confirmed
- seller new order
- shipment
- delivered
- return
- refund
- seller payout
- moderation
- support

Notification delivery retry ve dedupe zorunlu.

---

# 40. API STANDARTLARI

Base:
```text
/api/v1
```

Response:

```json
{
  "data": {},
  "meta": {},
  "errors": []
}
```

Error:
```json
{
  "errors": [
    {
      "code": "CREDIT_INSUFFICIENT",
      "message": "Yetersiz kredi.",
      "field": null,
      "trace_id": "..."
    }
  ]
}
```

Kurallar:
- versioning
- pagination
- filtering
- sorting
- rate limiting
- request id
- idempotency header kritik POST'larda
- OpenAPI
- auth policy
- consistent error codes

---

# 41. EVENT-DRIVEN DOMAIN EVENTS

Örnek:

```text
UserRegistered
SellerApplicationSubmitted
SellerApproved
ProductSubmittedForModeration
ProductApproved
CreditPurchased
CreditReserved
AiJobStarted
DesignGenerated
ProductMatched
OrderPaid
SellerOrderCreated
ShipmentCreated
OrderDelivered
ReturnRequested
RefundCompleted
SellerSettlementApproved
SellerPayoutSent
```

Event → listeners:
- notification
- analytics
- ledger
- integrations

Finansal event'lerde transactional outbox kullanılması önerilir.

---

# 42. OUTBOX / INBOX PATTERN

External integration güvenliği:

Transaction içinde:
- business row
- outbox event

Worker:
- outbox → provider

Incoming:
- inbox dedupe

Bu sayede network failure yüzünden order/payment state kaybolmaz.

---

# 43. SECURITY

## Authentication
- secure password hash
- email verify
- optional social login
- 2FA seller/admin zorunluya yakın
- session/device management

## Authorization
- RBAC
- Policies
- organization scoped permissions

## Data
- TLS
- encryption at rest
- sensitive columns app-level encryption
- secrets vault/env secret manager
- signed storage URL

## Upload
- allowed MIME
- magic bytes
- max size
- malware scan
- image re-encode
- EXIF strip gerektiğinde

## API
- rate limit
- bot protection
- CSRF SPA policy
- CORS allowlist
- idempotency
- request size limit

## Payments
- raw card saklama yok
- provider hosted/tokenized flow tercih
- webhook authentication
- replay prevention
- payment audit

## Admin
- 2FA
- IP/device risk
- privileged audit
- sensitive actions re-auth
- optional approval workflow:
  - seller payout
  - large refund
  - commission override

---

# 44. PRIVACY / COMPLIANCE

RefConcept ev içi fotoğraf ve oda verisi tutacağı için privacy kritik.

Fonksiyonlar:
- consent versioning
- privacy notice versioning
- data export
- account deletion
- anonymization
- media delete workflow
- retention policy
- audit
- seller contract acceptance history

**KVKK, e-ticaret, marketplace, ödeme kuruluşu, muhasebe/e-belge, mesafeli satış ve satıcı hakediş süreçleri production öncesinde güncel Türkiye mevzuatı açısından hukuk/mali müşavir tarafından onaylanmalıdır.**

---

# 45. FEATURE FLAGS

Örnek:
- `ai_premium_render`
- `google_ai_provider`
- `qnb_payment`
- `bank_transfer`
- `seller_self_onboarding`
- `roomplan`
- `arcore`
- `professional_marketplace`

Rollout:
- global
- user percentage
- seller
- country
- environment

---

# 46. ADMIN KONFİGÜRASYONLARI

DB/admin üzerinden:
- platform default commission
- category commission
- seller commission
- credit package
- AI task credit
- payment gateway enable
- bank accounts
- return period
- payout hold days
- seller approval mode
- product moderation mode
- AI daily cost cap
- allowed file size
- maintenance flags
- email templates
- currencies

Config değişikliği audit log'a yazılır.

---

# 47. ANALYTICS EVENT ŞEMASI

```text
page_view
signup_started
signup_completed
project_created
room_uploaded
credit_package_viewed
credit_purchase_started
credit_purchase_completed
design_generate_clicked
design_generated
design_failed
product_match_shown
product_match_clicked
product_added_to_design
add_to_cart
checkout_started
payment_succeeded
order_created
order_delivered
return_requested
seller_signup_started
seller_approved
product_created
product_approved
```

Her event:
- anonymous/user id
- session
- timestamp
- source
- properties
- consent status

---

# 48. BACKUP / DISASTER RECOVERY

- PostgreSQL automated backup
- PITR
- object storage versioning
- encrypted backups
- Redis persistent data kritik kaynak kabul edilmez
- restore test
- documented RPO/RTO
- quarterly restore drill

---

# 49. ENVIRONMENTS

## Local
Docker Compose

## Test
CI ephemeral DB

## Staging
Production-like
- sandbox iyzico
- test QNB
- mock bank transfer
- AI limited budget

## Production
- real keys
- secrets manager
- backups
- monitoring
- alerts
- WAF

Prod secrets local `.env.example` içine yazılmaz.

---

# 50. CI/CD PIPELINE

Pull Request:

```text
install
↓
lint
↓
static analysis
↓
unit tests
↓
feature/API tests
↓
frontend typecheck
↓
frontend unit
↓
build
↓
security dependency scan
↓
container build
```

Main:
```text
all checks
↓
deploy staging
↓
migration dry/check
↓
E2E
↓
smoke
↓
manual/automated release gate
↓
production deploy
↓
post-deploy smoke
```

Rollback:
- app rollback
- backward compatible migrations
- feature flags

---

# 51. MIGRATION STRATEGY

**Expand/contract.**

Yanlış:
```text
rename/drop column + deploy same second
```

Doğru:
1. yeni alan ekle
2. dual write
3. backfill
4. read switch
5. eski alanı daha sonra kaldır

Zero/low downtime.

---

# 52. TEST STRATEJİSİ — ZORUNLU

Bu projede test son faz değildir. Her fazın çıkış kriteridir.

## 52.1 Test Pyramid

### Unit
- money
- commission
- credits
- state machines
- budget
- matching score
- policy

### Integration
- database
- Redis
- queue
- S3
- provider adapters mocked/sandbox

### API Feature
- auth
- seller
- product
- project
- cart
- checkout
- payment
- refund

### Contract
- OpenAPI
- provider response mapping
- webhook schemas

### E2E Web
Playwright:
- user signup → credit → design → cart → checkout
- seller signup → product → order
- admin approval

### Mobile
- Flutter widget
- integration tests
- native bridge mocked
- selected physical device AR tests

### Security
- auth bypass
- IDOR
- seller cross-tenant access
- privilege escalation
- webhook forgery
- rate limit
- upload
- SQL/XSS
- CSRF
- secret leakage

### Payment
- duplicate callback
- timeout
- retry
- declined
- 3DS fail
- refund
- partial refund
- bank transfer
- reconciliation

### AI
- provider timeout
- provider malformed output
- schema invalid
- image failure
- fallback
- duplicate job
- credit reserve/release
- cost limit
- prompt regression

### Performance
- catalog search
- checkout
- queue throughput
- concurrent stock
- AI job burst

### Disaster
- backup restore
- Redis loss
- webhook retry
- provider outage

---

# 53. TEST AGENT — ZORUNLU BAĞIMSIZ AGENT

## Rol

**QA/Test Agent**, kod yazan agentlardan bağımsız çalışır.

## Yetki

Test Agent:
- implementation'ı değiştirebilir ancak bug fix'i ayrı commit/patch olarak işaretlemeli
- requirement'tan test case çıkarmalı
- happy path kadar failure path yazmalı
- test geçmeden fazı onaylamamalı
- “çalışıyor gibi” kabul etmemeli

## Test Agent Prompt

```text
Sen REFCONCEPT_TEST_AGENT'sın.

Görevin:
1. REFCONCEPT_MASTER_SPEC.md belgesini tek kaynak kabul et.
2. İlgili fazın acceptance criteria'larını çıkar.
3. Unit, integration, API, E2E, security, payment ve regression testlerini yaz.
4. Kodun yalnızca happy path'ini değil race condition, retry, duplicate webhook,
   idempotency, authorization ve failure senaryolarını test et.
5. Finansal işlemlerde para kaybı/çift kayıt riskini özellikle ara.
6. Her seller isolation senaryosunda Seller A'nın Seller B verisine erişemediğini test et.
7. Her kredi işleminde reserve/consume/release/idempotency test et.
8. Her payment provider için sandbox/mock contract tests yaz.
9. Test başarısızken fazı PASS raporlama.
10. Her faz sonunda TEST_REPORT.md güncelle:
   - total tests
   - passed
   - failed
   - skipped
   - coverage
   - security findings
   - blockers
11. Final release için tüm P0/P1 testleri PASS olmadan onay verme.
```

---

# 54. ÖZELLİK BAZLI ZORUNLU TESTLER

## Credits

- purchase success → exact credits
- payment callback duplicate → no duplicate credits
- AI reserve → reserved increments
- AI success → consume
- AI fail → release
- retry success → one consume
- simultaneous generations → atomic
- admin adjustment audit
- expired credits

## Commission

- seller default
- category override
- campaign override
- snapshot
- partial return
- full refund
- quantity > 1
- coupon allocation

## Order

- multi seller split
- stock race
- payment fail release
- partial shipment
- cancellation

## Settlement

- delivered eligible
- return hold
- refund reversal
- manual adjustment audit
- duplicate settlement protection

---

# 55. PERFORMANCE HEDEFLERİ

Başlangıç hedefleri:

- normal API p95 < 400ms — dış servis hariç
- product search p95 < 700ms
- cart operation p95 < 300ms
- payment webhook acknowledge hızlı
- AI request creation < 500ms, AI sonucu async
- queue lag alert
- DB slow query monitoring

Hedefler load test ile doğrulanır ve gerçek trafikle revize edilir.

---

# 56. OBSERVABILITY TRACE

Her request:
```text
trace_id
request_id
user_id
seller_id
order_id
payment_intent_id
ai_job_id
```

PII loglama minimize edilir.

Payment/AI provider payload'ları maskelenir.

---

# 57. API RATE LIMIT

Örnek policy:
- auth login
- register
- password reset
- AI generation
- search
- seller import
- webhook endpoints provider authentication

AI rate limit kredi sisteminden ayrı olmalı. Kredi sahibi olmak sınırsız parallel job hakkı vermez.

---

# 58. BACKGROUND JOB PRENSİPLERİ

Her job:
- idempotent
- timeout
- retry count
- backoff
- unique key gerekirse
- structured logs
- failure event
- DLQ/failed jobs

Payment job'larında sonsuz retry yok.

---

# 59. SATICI KALİTE SKORU

Future ranking input:

- cancellation rate
- on-time shipment
- return rate
- product completeness
- support response
- review score
- stock accuracy

Komisyonla organik kalite skoru birbirine karıştırılmaz.

---

# 60. PRODUCT QUALITY SCORE

AI + rules:

```text
required attributes complete
image count
image resolution
background quality
dimensions
variant completeness
3D asset
description
category confidence
duplicate risk
```

Moderation dashboard.

---

# 61. FRAUD / RISK

Başlangıç rules:
- high velocity payments
- multiple failed cards
- suspicious credit purchases
- refund abuse
- seller abnormal order spike
- duplicate bank receipts

Provider'ın fraud/3DS özellikleri kullanılır; internal risk flag ayrıca tutulur.

---

# 62. INTERNATIONALIZATION

Başlangıç:
- TR
- EN altyapısı

DB:
- translations
- locale aware product text

Money:
- TRY first
- multi-currency-ready architecture

Payment provider availability country-aware.

---

# 63. SEO / STOREFRONT

- SSR
- category pages
- product pages
- canonical URL
- sitemap
- structured data
- seller page
- collection
- image optimization
- Core Web Vitals monitoring

AI render private kullanıcı verisi varsayılan olarak indexlenmez.

---

# 64. SATICI API

API key:
- hashed
- scopes
- rotate
- revoke

Scopes:
```text
products:read
products:write
prices:write
inventory:write
orders:read
orders:update
shipments:write
```

Webhook:
```text
order.created
order.cancelled
return.created
settlement.created
```

Signature:
HMAC + timestamp + replay protection.

---

# 65. ERP ENTEGRASYON KATMANI

```php
interface SellerErpConnectorInterface
{
    public function pullProducts(): iterable;
    public function pullPrices(): iterable;
    public function pullStocks(): iterable;
    public function pushOrder(SellerOrder $order): ExternalOrderResult;
    public function pullShipmentUpdates(): iterable;
}
```

RefConcept internal canonical model sabit kalır.

ERP provider mapping:
- external product id
- external SKU
- external order no
- sync cursor

---

# 66. PROJE / ODA DOSYA YAPISI

Object key örneği:

```text
users/{user_uuid}/projects/{project_uuid}/rooms/{room_uuid}/originals/{uuid}.jpg
users/{user_uuid}/projects/{project_uuid}/rooms/{room_uuid}/renders/{design_version_uuid}.webp
products/{product_uuid}/media/{uuid}.webp
products/{product_uuid}/3d/{asset_version_uuid}.glb
```

DB her zaman storage key tutar, public raw URL değil.

---

# 67. IMAGE PIPELINE

Upload:
1. validate
2. virus scan
3. strip metadata where required
4. normalize orientation
5. preserve original private
6. generate web variants
7. thumbnail
8. quality score
9. AI processing

---

# 68. AI FAILURE UX

Kullanıcı “AI failed” görmemeli.

States:
```text
queued
analyzing
planning
rendering
validating
matching_products
completed
retrying
failed
```

Fail:
- kredi otomatik geri bırak
- açıklayıcı mesaj
- retry butonu
- support trace id

---

# 69. ORDER NUMBERING

Internal PK UUID.
Human:
```text
RF-2026-00000123
```

Seller order:
```text
RF-S00042-2026-001928
```

Number generator transaction safe.

---

# 70. BANKA HAVALE REFERANSI

```text
RFH-8K4M2Q
```

- kısa
- unique
- insan okuyabilir
- order ile mapped
- açıklama alanında kullanılabilir

---

# 71. ADMIN APPROVAL WORKFLOWS

Dual approval opsiyonel:
- yüksek tutar iade
- yüksek tutar seller payout
- kritik komisyon değişikliği
- seller reactivation

Tables:
- approval_requests
- approval_actions

İleri sürümde eklenebilir.

---

# 72. CUSTOMER SUPPORT

Ticket:
- category
- order
- payment
- seller
- design
- AI
- priority
- SLA
- attachments

AI support yalnızca yardımcı; finansal refund/payout kararını yetkisiz AI otomatik yapmaz.

---

# 73. LOGGING

Structured JSON.

Asla loglama:
- card PAN
- CVV
- raw password
- provider secret
- full sensitive documents

Mask:
- email
- phone
- IBAN gerektiğinde

---

# 74. ERROR TAXONOMY

```text
AUTH_*
VALIDATION_*
SELLER_*
PRODUCT_*
CREDIT_*
AI_*
CART_*
ORDER_*
PAYMENT_*
REFUND_*
SETTLEMENT_*
INTEGRATION_*
SYSTEM_*
```

Her error:
- stable code
- localized user message
- internal details server log

---

# 75. DOMAIN SERVICE ÖRNEĞİ — CREDIT RESERVATION

Pseudo PHP:

```php
DB::transaction(function () use ($walletId, $jobId, $cost, $idempotencyKey) {
    $wallet = CreditWallet::query()
        ->whereKey($walletId)
        ->lockForUpdate()
        ->firstOrFail();

    if (CreditTransaction::where('idempotency_key', $idempotencyKey)->exists()) {
        return;
    }

    if ($wallet->available_credits < $cost) {
        throw new InsufficientCreditException();
    }

    CreditTransaction::create([
        'wallet_id' => $wallet->id,
        'type' => 'reserve',
        'amount' => -$cost,
        'reference_type' => 'ai_job',
        'reference_id' => $jobId,
        'idempotency_key' => $idempotencyKey,
    ]);

    $wallet->available_credits -= $cost;
    $wallet->reserved_credits += $cost;
    $wallet->save();
});
```

Production implementasyonu:
- ledger invariants
- retry-safe
- tests
- domain events
ile tamamlanmalıdır.

---

# 76. DOMAIN SERVICE ÖRNEĞİ — COMMISSION

```php
final class CommissionCalculator
{
    public function calculate(OrderItemPricingContext $ctx): CommissionResult
    {
        $rule = $this->resolver->resolve($ctx);

        $commission = intdiv(
            $ctx->commissionBaseMinor * $rule->rateBps,
            10_000
        );

        return new CommissionResult(
            rateBps: $rule->rateBps,
            commissionMinor: $commission,
            ruleId: $rule->id,
        );
    }
}
```

Order item'a:
- rate snapshot
- amount snapshot
- rule id
kaydedilir.

---

# 77. PAYMENT SERVICE ÖRNEĞİ

```php
final class PaymentService
{
    public function pay(PaymentIntent $intent): PaymentResult
    {
        $gateway = $this->gatewayRegistry->for($intent->gateway);

        return $gateway->createPayment(
            PaymentRequest::fromIntent($intent)
        );
    }
}
```

Gateway business order state değiştirmez.
Provider sonucu domain service/event üzerinden işlenir.

---

# 78. STATE MACHINE SERVİSLERİ

Status alanına controller'dan:

```php
$order->status = 'delivered';
$order->save();
```

YASAK.

Yerine:

```php
$orderWorkflow->markDelivered($order, $context);
```

Bu servis:
- transition validate
- authorization
- ledger/settlement event
- notification
- audit
yapar.

---

# 79. TEST DATA / FACTORIES

Factories:
- UserFactory
- SellerFactory
- ProductFactory
- SkuFactory
- ProjectFactory
- DesignFactory
- CreditWalletFactory
- CartFactory
- OrderFactory
- PaymentFactory
- SettlementFactory

Seed:
- categories
- styles
- colors
- sample products
- credit packages
- admin role
- AI tasks
- payment sandbox configs

Production seed secret içermez.

---

# 80. AI MOCK PROVIDER

Test için:

```text
FakeAiProvider
```

Deterministic output:
- predictable room JSON
- fixture image
- controlled failure
- timeout simulation
- malformed JSON
- rate limit error

CI gerçek AI harcaması yapmamalı.

Ayrı nightly sandbox AI contract tests olabilir.

---

# 81. PAYMENT MOCK PROVIDER

```text
FakePaymentGateway
```

Scenarios:
- success
- fail
- requires action
- timeout
- duplicate webhook
- partial refund
- full refund

CI dış bankaya bağlı olmadan çalışır.

---

# 82. DEVELOPMENT PHASES — START TO FINISH

**ZORUNLU SIRA: Önce tüm WEB platformu tamamlanır ve release gate'i geçer; yalnızca bundan sonra APP geliştirmesi başlar.**

Her fazın sonunda:
1. kod
2. migration
3. docs
4. tests
5. Test Agent report
6. no P0/P1 bug
olmadan sonraki faz tamamlanmış kabul edilmez.

---

# PHASE 0 — PROJECT BOOTSTRAP

## İşler
- monorepo
- Docker
- Laravel
- Nuxt apps
- Flutter
- FastAPI skeleton
- PostgreSQL
- Redis
- S3 local emulator
- CI
- coding standards
- env example
- health endpoints
- OpenAPI skeleton

## Test
- all apps boot
- DB migration
- Redis connectivity
- storage
- CI green

---

# PHASE 1 — IDENTITY & RBAC

- user
- auth
- verify
- reset
- profile
- addresses
- roles
- permissions
- organizations
- audit

### Test
- auth
- invalid login
- permissions
- seller/admin isolation
- rate limiting

---

# PHASE 2 — SELLER ONBOARDING

- application
- legal
- contacts
- bank
- documents
- agreement
- approval
- status workflow
- seller portal shell

### Test
- incomplete application
- reject
- approve
- suspend
- cross seller access

---

# PHASE 3 — CATALOG / PIM

- taxonomy
- brand
- product
- SKU
- attributes
- media
- variants
- dimensions
- moderation

### Test
- required attributes
- duplicate SKU
- variant
- moderation
- seller ownership

---

# PHASE 4 — IMPORT / INVENTORY / PRICE

- CSV/XLSX
- import batch
- stock
- price
- price history
- API foundation

### Test
- invalid rows
- duplicate
- concurrency
- stock updates
- import retry

---

# PHASE 5 — PROJECT / ROOM / DESIGN MODEL

- projects
- rooms
- uploads
- design
- versions
- constraints

### Test
- ownership
- media security
- version tree
- deletion/retention

---

# PHASE 6 — AI GATEWAY

- providers
- model routing
- prompts
- jobs
- usage
- fake provider
- async queue
- fallback

### Test
- schema
- timeout
- retry
- fallback
- duplicate job
- cost recording

---

# PHASE 7 — CREDIT SYSTEM

- wallet
- packages
- purchase
- transaction ledger
- reservation
- consume/release
- admin adjustment

### Test
- concurrency
- duplicate callback
- insufficient credits
- failure refund
- expiration

---

# PHASE 8 — AI ROOM & DESIGN

- room analysis
- design plan
- render/edit
- validation
- history
- UX progress

### Test
- preserved constraints
- failed render
- malformed response
- retry
- credit invariant

---

# PHASE 9 — PRODUCT MATCHING

- embeddings
- visual/text match
- hard filters
- ranking
- feedback

### Test
- correct category
- budget
- inactive seller removed
- out-of-stock behavior
- scoring regression fixtures

---

# PHASE 10 — CART / CHECKOUT

- cart
- multi-seller
- addresses
- coupon
- stock reserve
- checkout sessions

### Test
- price change
- stock race
- expired cart
- multi seller
- coupon abuse

---

# PHASE 11 — PAYMENT CORE

- gateway interface
- payment intent
- transaction
- webhook
- refund primitives
- fake gateway

### Test
- idempotency
- timeout
- duplicate webhook
- state machine

---

# PHASE 12 — IYZICO

- marketplace onboarding
- submerchant
- payment
- 3DS
- item transaction mapping
- approve/disapprove
- refund
- sandbox
- reconciliation

### Test
- official sandbox scenarios
- duplicate callbacks
- seller split
- refund
- delivery approval hold

---

# PHASE 13 — QNB

- QNB adapter
- 3DS
- payment
- query
- cancel/refund
- test environment
- response mapping

### Test
- test cards
- decline
- 3DS
- timeout
- refund
- replay/idempotency

---

# PHASE 14 — BANK TRANSFER

- account admin
- reference
- receipt
- manual confirm
- reconciliation model

### Test
- wrong amount
- duplicate receipt
- partial
- overpay
- expired
- manual audit

---

# PHASE 15 — ORDER / SELLER ORDER

- order create
- split
- timeline
- seller notification
- seller processing

### Test
- multi seller
- snapshot
- cancellation
- payment/order consistency

---

# PHASE 16 — COMMISSION / LEDGER / SETTLEMENT

- rules
- double-entry
- seller balance
- periods
- payout

### Test
- balance equality
- commission hierarchy
- refund reversal
- duplicate settlement
- payout approval

---

# PHASE 17 — SHIPPING / RETURN / REFUND

- shipment
- tracking
- return
- refund
- seller settlement holds

### Test
- partial shipment
- partial return
- return after payout
- refund gateway fail/retry

---

# PHASE 18 — SUPER ADMIN FULL

- dashboards
- users
- sellers
- products
- orders
- finance
- AI
- credits
- settings
- logs
- feature flags

### Test
- permission matrix
- high-risk actions
- audit completeness

---

# PHASE 19 — SELLER PORTAL FULL

- analytics
- products
- imports
- orders
- shipment
- returns
- finance
- API

### Test
- tenant isolation
- seller financial totals
- bulk import

---

# PHASE 20 — STOREFRONT FULL

- search
- category
- product
- design
- cart
- checkout
- order account
- SEO

### Test
- Playwright full user journeys
- accessibility
- responsive

---

# PHASE 21 — WEB SECURITY / PERFORMANCE / PRODUCTION READINESS

Bu faz bitmeden mobil uygulama başlatılmaz.

- OWASP hardening
- secrets review
- dependency/security scans
- access review
- penetration test fixes
- k6/load test
- DB index tuning
- queue scaling
- search tuning
- CDN/image optimization
- backup restore
- monitoring
- alerts
- runbooks
- payment production readiness
- migration rehearsal
- rollback rehearsal

### Test
- full web regression
- security P0/P1 = 0
- payment sandbox PASS
- financial invariants PASS
- load targets PASS
- backup restore PASS

---

# PHASE 22 — WEB GO LIVE / STABILIZATION

- production migration
- public storefront
- seller portal
- super admin
- payment providers
- AI
- limited rollout
- production monitoring
- seller onboarding
- gradual feature enable
- beta feedback fixes

### WEB RELEASE GATE
- TEST_AGENT must return `WEB_RELEASE_APPROVED`
- API contracts documented/versioned
- OpenAPI stable
- no P0/P1 blockers

**Only after this phase passes can mobile development start.**

---

# PHASE 23 — MOBILE FOUNDATION & CORE

- Flutter architecture
- API client
- auth
- profile
- project
- room
- AI design
- credits
- catalog
- favorites
- push

### Test
- widget
- integration
- API contract
- auth/session
- upload
- AI/credit E2E

---

# PHASE 24 — MOBILE COMMERCE

- cart
- checkout
- iyzico/QNB compatible mobile payment flow
- bank transfer display
- order
- shipping
- return/refund tracking
- notifications

### Test
- mobile checkout E2E
- payment return/deep link
- order lifecycle
- API parity

---

# PHASE 25 — MOBILE 3D / AR

- Three.js web assets reuse where appropriate
- GLB
- iOS RoomPlan
- ARKit
- RealityKit
- Android ARCore
- Depth API
- product placement
- device capability fallback

### Test
- physical device matrix
- unsupported fallback
- permissions
- scale accuracy
- performance

---

# PHASE 26 — APP RELEASE

- final mobile security
- performance
- crash monitoring
- privacy permission texts
- store assets
- App Store build
- Google Play build
- staged rollout
- post-release smoke

### APP RELEASE GATE
- TEST_AGENT = `APP_RELEASE_APPROVED`
- no mobile P0/P1
- API backward compatibility verified
- payment flows verified
- crash-free target monitored

---

# 83. DEFINITION OF DONE — HER FEATURE

Bir feature Done değildir eğer:
- acceptance criteria yoksa
- authorization yoksa
- validation yoksa
- error handling yoksa
- audit gerektiği halde yoksa
- unit/feature test yoksa
- payment/finance ise idempotency yoksa
- docs güncellenmediyse
- logging/metrics yoksa
- lint/static analysis fail ise

---

# 84. RELEASE GATE

Production release için:

- backend tests PASS
- frontend tests PASS
- mobile critical tests PASS
- E2E PASS
- payment sandbox PASS
- seller isolation PASS
- security P0/P1 = 0
- financial invariant PASS
- migration test PASS
- backup restore PASS
- monitoring active
- runbook complete
- rollback plan
- Test Agent = APPROVED

---

# 85. TEST REPORT FORMAT

```markdown
# Test Report

## Build
Commit:
Environment:

## Summary
Total:
Passed:
Failed:
Skipped:

## Coverage
Backend:
Frontend:

## Critical Flows
- User signup: PASS
- Credit purchase: PASS
- AI design: PASS
- Multi seller checkout: PASS
- iyzico: PASS
- QNB: PASS
- Bank transfer: PASS
- Refund: PASS
- Seller payout: PASS

## Security
P0:
P1:
P2:

## Financial Invariants
- ledger balanced:
- duplicate payment protected:
- duplicate credit protected:
- settlement duplication protected:

## Blocking Issues
None / list

## Decision
APPROVED / REJECTED
```

---

# 86. AI AGENT ORGANİZASYONU

## Agent 0 — Orchestrator

Sadece planlar, iş dağıtır, fazları sırayla yürütür, Test Agent PASS olmadan faz kapatmaz.

## Agent 1 — Architect

- domain
- ADR
- boundaries
- standards
- dependency rules

## Agent 2 — Database

- migrations
- indexes
- constraints
- seed
- query performance

## Agent 3 — Backend Core

- Laravel
- API
- auth
- domain services
- events
- queues

## Agent 4 — Catalog/Seller

- seller
- PIM
- import
- inventory
- pricing

## Agent 5 — Commerce

- cart
- checkout
- orders
- returns
- shipping

## Agent 6 — Payment/Finance

- payment
- iyzico
- QNB
- bank transfer
- ledger
- commission
- settlement

## Agent 7 — AI

- gateway
- provider
- prompts
- design
- embedding
- matching

## Agent 8 — Web Storefront

## Agent 9 — Seller Portal

## Agent 10 — Super Admin

## Agent 11 — Mobile/AR

## Agent 12 — DevOps/Security

## Agent 13 — Documentation

## Agent 14 — **TEST AGENT**

Bağımsız release gate.

---

# 87. AGENT HANDOFF CONTRACT

Her agent çıktısında:

```text
STATUS
FILES_CHANGED
MIGRATIONS
API_CHANGED
EVENTS_ADDED
TESTS_ADDED
TESTS_RUN
KNOWN_ISSUES
SECURITY_NOTES
NEXT_AGENT_INPUT
```

zorunlu.

Agentlar birbirine chat hafızasıyla değil repository artifacts ile bilgi aktarır.

---

# 88. MASTER AI BUILD PROMPT — TEK HAMLEDE VERİLECEK TALİMAT

Aşağıdaki bölüm bir coding AI / agent orchestrator'a doğrudan verilebilir.

```text
YOU ARE THE REFCONCEPT MASTER ENGINEERING ORCHESTRATOR.

SOURCE OF TRUTH:
- REFCONCEPT_MASTER_SPEC.md
- Existing repository files
- ADR documents produced during execution

MISSION:
Build the complete RefConcept production-grade platform from START to FINISH.

NON-NEGOTIABLE RULES:
1. Do not skip phases.
2. Do not mark a phase complete until its tests pass.
3. Never store monetary values as floating point.
4. Never store raw card data.
5. All payment operations must be idempotent.
6. All credit operations must use an immutable ledger + atomic reservation.
7. All marketplace finance must be backed by balanced ledger entries.
8. All seller data access must be tenant/organization isolated.
9. All critical admin actions must be audited.
10. AI provider/model names must be configuration-driven, never hard-coded.
11. External services must have interfaces/adapters and fake providers for tests.
12. Every webhook must support verification, deduplication, idempotency and retry.
13. Every background job must define timeout, retry, backoff and failure behavior.
14. Never proceed with a failing P0/P1 test.
15. Never use production payment credentials in automated tests.
16. Do not create fake “completed” features. A feature is complete only if UI/API,
    persistence, auth, validation, tests and error handling are implemented.
17. Use transactions and row locks/atomic operations where financial or inventory
    race conditions exist.
18. Keep README, ARCHITECTURE, OpenAPI and TEST_REPORT synchronized with code.
19. Use feature flags for risky/incomplete rollout.
20. Prefer modular monolith; do not create unnecessary microservices.

EXECUTION MODEL:
Create and coordinate the following internal agents:
- ARCHITECT_AGENT
- DATABASE_AGENT
- BACKEND_AGENT
- SELLER_CATALOG_AGENT
- COMMERCE_AGENT
- PAYMENT_FINANCE_AGENT
- AI_AGENT
- STOREFRONT_AGENT
- SELLER_PORTAL_AGENT
- ADMIN_AGENT
- MOBILE_AR_AGENT
- DEVOPS_SECURITY_AGENT
- DOCUMENTATION_AGENT
- TEST_AGENT

IMPORTANT:
TEST_AGENT must be logically independent from implementation agents.
It must attempt to break the system and must reject phases with failed tests.

PHASE ORDER:
Execute Phase 0 through Phase 22 as the complete WEB platform first.
Do NOT start any Flutter/mobile implementation before Phase 22 WEB_RELEASE_APPROVED.
After web release/stabilization, execute Phase 23 through Phase 26 for APP/mobile.
This WEB-FIRST ordering is mandatory.

FOR EACH PHASE:
A. Read related requirements.
B. Create/update ADR if architectural choice is needed.
C. Implement DB/migrations.
D. Implement domain/backend.
E. Implement UI where phase requires it.
F. Implement external adapters using sandbox/fakes.
G. Add observability.
H. Add unit/integration/API/E2E tests.
I. Run lint/static analysis/tests.
J. Let TEST_AGENT independently validate.
K. Fix all blockers.
L. Update TEST_REPORT.md.
M. Commit/record phase completion.
N. Continue automatically to next phase.

IF AN EXTERNAL SECRET OR CONTRACT IS NOT AVAILABLE:
- Do not stop the whole project.
- Implement the production adapter contract.
- Implement sandbox/mock configuration.
- Add a clearly documented environment variable.
- Add integration test fixtures.
- Mark only live credential validation as an external go-live prerequisite.
- Continue all other work.

PAYMENT REQUIREMENTS:
Implement:
- PaymentGatewayInterface
- MarketplaceSettlementGatewayInterface where applicable
- Iyzico marketplace/submerchant payment adapter
- QNB payment gateway adapter
- Bank transfer gateway/workflow
- PaymentIntent
- PaymentTransaction
- Webhook inbox
- Idempotency
- Refunds
- Reconciliation
- Commission engine
- Double-entry marketplace ledger
- Seller settlements and payouts

CREDIT REQUIREMENTS:
- Credit packages
- Payment-linked purchase
- Wallet
- Immutable transactions
- Reservation before AI
- Consume on success
- Release on failure
- Expiration
- Promo credits
- Admin adjustments with audit
- Concurrency tests

AI REQUIREMENTS:
- Provider-independent AI gateway
- OpenAI adapter
- Google Gemini adapter
- Fake provider
- Model routing DB
- Prompt versioning
- Cost tracking
- Room analysis
- Design generation/edit
- Product matching
- Embeddings
- Fallback
- Async queues
- User progress events
- Validation
- Credit linkage
- Cost caps

SELLER REQUIREMENTS:
- Full onboarding
- Documents
- Agreements
- Bank details
- Payment/submerchant onboarding
- Approval/rejection/suspension
- Team/roles
- Product manual upload
- bulk import
- API
- variants/SKU
- media/3D
- price/stock
- moderation
- orders
- shipping
- returns
- finance/settlement analytics

SUPER ADMIN REQUIREMENTS:
Implement complete control over:
- users
- roles/permissions
- sellers
- seller documents/status
- products/moderation
- categories/brands
- orders
- payments
- bank transfers
- refunds
- commission rules
- ledger
- settlements
- payouts
- credits
- AI providers/models/prompts/routes/costs
- notifications
- feature flags
- system settings
- queues/jobs
- webhook failures
- audit logs
- analytics

TEST_AGENT MUST INCLUDE:
- unit
- integration
- API
- E2E
- tenant isolation
- concurrency
- payment duplicate callback
- AI retry/fallback
- credit atomicity
- ledger balance
- refund reversal
- settlement duplication
- security
- load smoke
- backup restore checklist

FINAL ACCEPTANCE:
The project has two mandatory release milestones:

MILESTONE A — WEB:
- Phase 0-22 complete
- TEST_AGENT = WEB_RELEASE_APPROVED
- Web is deployable/production-ready before any mobile work starts

MILESTONE B — APP:
- Phase 23-26 complete only after Milestone A
- TEST_AGENT = APP_RELEASE_APPROVED

The full project is complete only when:
1. All Phase 0-26 acceptance tests pass.
2. TEST_REPORT says APPROVED.
3. No P0/P1 issue remains.
4. Local environment boots with one documented command.
5. Staging deploy works.
6. Sandbox payment flows work.
7. User journey works end-to-end.
8. Seller journey works end-to-end.
9. Super Admin journey works end-to-end.
10. Financial reconciliation tests pass.
11. AI credits never double-consume.
12. Multi-seller order/commission calculation is correct.
13. OpenAPI documentation is generated.
14. DB migrations are reproducible.
15. Backup/restore process is documented/tested.
16. Monitoring and health checks exist.
17. Production checklist is generated.

FINAL OUTPUT:
Produce:
- working repository
- README.md
- ARCHITECTURE.md
- REFCONCEPT_MASTER_SPEC.md
- OPENAPI
- DB diagram/source
- ADRs
- TEST_REPORT.md
- SECURITY_CHECKLIST.md
- PAYMENT_RUNBOOK.md
- SELLER_ONBOARDING_RUNBOOK.md
- DEPLOYMENT.md
- PRODUCTION_CHECKLIST.md
- CHANGELOG.md

Do not ask for confirmation between phases.
Continue autonomously unless a requirement is logically impossible.
When a secret/credential is missing, create a safe placeholder and continue with fake/sandbox adapters.
```

---

# 89. ORCHESTRATOR ÇALIŞMA KURALI

Tek hamle talebinin güvenilir olması için AI agent'ın “bir kerede bütün kodu yazıp bitti demesi” hedeflenmemelidir.

**Tek kullanıcı komutu → çok fazlı otonom execution** hedeflenir.

Yani kullanıcı bir kez başlatır fakat agent içeride:

```text
Plan
→ Implement
→ Test
→ Fix
→ Test
→ Phase Gate
→ Next Phase
...
→ Final Acceptance
```

şeklinde çalışır.

Bu, gerçek production yazılım geliştirmede daha güvenilir yöntemdir.

---

# 90. KRİTİK END-TO-END SENARYOLAR

## E2E-01 — Son Kullanıcı / AI / Kredi

```text
Register
→ verify
→ credit package
→ iyzico/QNB payment
→ credits added
→ project
→ room upload
→ generate
→ credit reserve
→ AI complete
→ credit consume
→ design visible
```

## E2E-02 — AI Failure

```text
credit 10
→ generate cost 2
→ reserve
→ provider timeout
→ retry fail
→ job failed
→ release
→ balance again 10
```

## E2E-03 — Seller Product

```text
seller apply
→ admin approve
→ seller product
→ moderation
→ admin approve
→ storefront
→ searchable
→ AI matching candidate
```

## E2E-04 — Multi Seller Order

```text
Seller A sofa
Seller B lamp
→ cart
→ pay
→ Order
→ SellerOrder A
→ SellerOrder B
→ commission snapshots
→ ledger balanced
```

## E2E-05 — iyzico Marketplace

```text
seller submerchant
→ user pay
→ item transaction
→ delivered
→ hold
→ approve
→ settlement
```

## E2E-06 — QNB

```text
user QNB pay
→ callback/query
→ order paid
→ internal seller payable
→ settlement
→ payout
```

## E2E-07 — Bank Transfer

```text
order
→ reference
→ bank payment
→ admin/reconciliation match
→ payment paid
→ order confirmed
```

## E2E-08 — Partial Refund

```text
2 items
→ refund 1 item
→ provider refund
→ commission reverse
→ seller payable reverse
→ order partially_refunded
→ ledger balanced
```

---

# 91. GO-LIVE HARİCİ BAĞIMLILIKLAR

Kodun tamamlanmasına engel olmamalı; fakat production açılışından önce gerekir:

- iyzico ticari hesap + Marketplace yetkisi
- iyzico production credentials
- QNB/QNBpay sanal POS sözleşmesi
- QNB production API credentials
- platform banka hesabı
- satıcı sözleşmeleri
- privacy/terms
- KVKK review
- mali müşavir muhasebe/komisyon/hakediş modeli review
- e-belge/fatura planı
- e-posta domain/DKIM/SPF
- push credentials
- production cloud
- domain/DNS
- WAF/CDN
- monitoring accounts

---

# 92. OPERASYON RUNBOOK'LARI

Mutlaka dokümante edilir:

1. Payment provider outage
2. AI provider outage
3. Seller payout failure
4. Duplicate webhook
5. Failed migration
6. Queue backlog
7. Redis outage
8. DB failover
9. Object storage incident
10. Large refund approval
11. Seller suspension
12. Fraud spike
13. AI cost spike
14. Restore from backup

---

# 93. PROD PAYMENT RECONCILIATION

Her gün:
- provider payment list
- RefConcept payment transactions
- ledger
- bank settlement
karşılaştırılır.

Mismatch:
```text
missing_internal
missing_provider
amount_mismatch
status_mismatch
duplicate
refund_mismatch
```

Finance admin queue'ya düşer.

---

# 94. AI QUALITY REGRESSION DATASET

Kendi fixture dataset'in oluşturulur:
- living room
- bedroom
- office
- kitchen
- different styles
- fixed TV
- fixed floor
- budget constraints
- difficult rooms

Her prompt/model değişiminde:
- schema success
- constraint preservation
- product detection
- visual score
karşılaştırılır.

---

# 95. PRODUCT MATCH BENCHMARK

Labelled benchmark:
```text
render_object_id
expected_product/category/style
acceptable_candidates
```

Metric:
- Precision@K
- Recall@K
- click/conversion online

---

# 96. FINANSAL INVARIANT TESTLER

Her CI'da:

```text
sum ledger debit == sum ledger credit
seller balance == eligible ledger aggregate
order grand total == line totals + shipping - discount + tax policy
refund <= captured payment
credit wallet == ledger aggregate
reserved credits >= 0
available credits >= 0
stock reservation >= 0
```

---

# 97. DATABASE INDEX REHBERİ

Örnek:
- users email/phone unique
- product sku seller+sku unique
- product status/category
- inventory sku/location
- order user/date
- seller_order seller/status/date
- payment external reference
- webhook provider event unique
- credit idempotency unique
- vector HNSW/uygun index
- full-text indexes
- JSONB sadece sorgulanan path'lerde

Her index query plan ile doğrulanır.

---

# 98. CACHE

Cache edilebilir:
- category tree
- public product detail
- search facets
- system settings
- AI routing
- exchange/country reference

Cache edilmemesi/çok dikkat:
- stock final checkout
- user credit final decision
- payment status
- seller balance authoritative value

---

# 99. IDEMPOTENCY KAPSAMI

Zorunlu:
- credit purchase confirm
- credit reserve
- AI job create
- checkout create order
- provider payment
- webhook process
- refund
- seller settlement
- payout
- ERP order push

---

# 100. SON MİMARİ KARAR

RefConcept ilk production sürümünde **Modular Monolith + ayrı AI/CV service** ile kurulmalıdır.

Mikroservise ayrılabilecek gelecekteki sınırlar:
- AI/CV
- Search
- Media/3D
- Notifications
- Analytics
- Payment/Finance — çok büyürse

Ancak ilk günden 20 mikroservis oluşturmak geliştirme ve operasyon riskini yükseltir.

---

# 101. RESMİ TEKNİK REFERANSLAR

Bu mimari tasarlanırken doğrulanması gereken ana resmi kaynaklar:

## Laravel
- Laravel 13 release notes: https://laravel.com/docs/13.x/releases
- Sanctum: https://laravel.com/docs/13.x/sanctum
- Queue: https://laravel.com/docs/13.x/queues
- Horizon: https://laravel.com/docs/13.x/horizon
- Reverb: https://laravel.com/docs/13.x/reverb

## PostgreSQL / Vector
- PostgreSQL: https://www.postgresql.org/docs/
- pgvector: https://github.com/pgvector/pgvector

## iyzico
- Marketplace implementation: https://docs.iyzico.com/en/products/marketplace/marketplace-implementation
- Submerchant: https://docs.iyzico.com/en/products/marketplace/marketplace-implementation/submerchant
- Approval: https://docs.iyzico.com/en/products/marketplace/marketplace-implementation/approval

## QNB
- QNB Payment Gateway integration: https://vpos.qnb.com.tr/Help/apiIntegrationDocument
- QNB Payment Gateway FAQ: https://vpos.qnb.com.tr/Help/FAQ
- QNB test cards: https://vpos.qnb.com.tr/Help/testCards

## OpenAI
- Docs: https://platform.openai.com/docs/
- Image generation: https://platform.openai.com/docs/guides/image-generation
- Embeddings: https://platform.openai.com/docs/guides/embeddings
- Function calling: https://platform.openai.com/docs/guides/function-calling

## Google Gemini
- Models: https://ai.google.dev/gemini-api/docs/models
- Image generation: https://ai.google.dev/gemini-api/docs/image-generation
- Image understanding: https://ai.google.dev/gemini-api/docs/image-understanding

## Apple
- RoomPlan: https://developer.apple.com/augmented-reality/roomplan/
- RoomPlan documentation: https://developer.apple.com/documentation/roomplan

## Google ARCore
- ARCore: https://developers.google.com/ar
- Depth API: https://developers.google.com/ar/develop/depth

> Entegrasyon geliştirilirken bu dokümandaki örnek endpoint/model isimlerinden daha güncel resmi provider dokümanı varsa resmi doküman esas alınmalıdır.

---

# 102. SONUÇ

RefConcept'ın savunulabilir değeri yalnızca generative AI değildir.

Platformun esas varlıkları:

1. oda/proje verisi
2. gerçek ürün kataloğu
3. mobilya varyant/ölçü veri modeli
4. gerçek stok/fiyat
5. AI tasarım + kontrollü edit
6. render → gerçek ürün matching
7. müşteri bütçe/satın alma davranışı
8. seller marketplace ağı
9. ödeme/komisyon/hakediş motoru
10. 3D asset kütüphanesi
11. sipariş/lojistik/kurulum verisi
12. recommendation/ranking feedback loop

Bu master spec'in amacı RefConcept'ı **AI oda görselleştirme aracı** olmaktan çıkarıp, **AI destekli Interior Commerce Operating System** olarak production seviyesinde kurmaktır.

---

# 103. İLK ÇALIŞTIRMA KOMUTU İÇİN HEDEF

Repository tamamlandığında geliştirici için mümkün olduğunca:

```bash
git clone <repo>
cp .env.example .env
docker compose up -d
make bootstrap
make test
```

ile local sistem ayağa kalkmalıdır.

`make bootstrap`:
- composer install
- npm install
- flutter pub get
- migrations
- seed
- storage init
- local keys
- build

`make test`:
- backend
- frontend
- API
- integration
- selected E2E
çalıştırmalıdır.

---

# 104. FINAL CHECKLIST

- [ ] Repository boot ediyor
- [ ] Auth tamam
- [ ] RBAC tamam
- [ ] Seller onboarding tamam
- [ ] Catalog/PIM tamam
- [ ] Product upload/import tamam
- [ ] Product moderation tamam
- [ ] Project/room tamam
- [ ] AI gateway tamam
- [ ] Credit sistemi tamam
- [ ] Design generation/edit tamam
- [ ] Product matching tamam
- [ ] Search tamam
- [ ] Cart tamam
- [ ] Checkout tamam
- [ ] iyzico sandbox tamam
- [ ] QNB test tamam
- [ ] Bank transfer tamam
- [ ] Multi-seller order tamam
- [ ] Commission tamam
- [ ] Ledger balanced
- [ ] Settlement tamam
- [ ] Seller payout workflow tamam
- [ ] Return/refund tamam
- [ ] Seller portal tamam
- [ ] Super Admin tamam
- [ ] Storefront tamam
- [ ] Mobile tamam
- [ ] AR/3D hedef kapsam tamam
- [ ] Security tests tamam
- [ ] Performance tests tamam
- [ ] Backup restore tamam
- [ ] Test Agent APPROVED
- [ ] Production checklist tamam

**END OF REFCONCEPT MASTER SPEC**