# REFCONCEPT — COPY/PASTE ONE‑SHOT BUILD PROMPT

Aşağıdaki bloğu coding agent'a **tek prompt** olarak ver.

```text
REFCONCEPT AUTONOMOUS BUILD MODE.

Bu repository'nin kökündeki talimat dosyalarını oku ve RefConcept WEB platformunu
baştan sona tek bir otonom çalışma olarak geliştir.

SOURCE OF TRUTH:
1. AGENTS.md
2. REFCONCEPT_MASTER_SPEC.md
3. 01_AUTONOMOUS_ORCHESTRATOR.md
4. 02_AGENT_TEAM.md
5. 03_INDEPENDENT_TEST_AGENT.md
6. 04_WEB_PHASE_PLAN.md
7. 05_ARCHITECTURE_AND_CODE_RULES.md
8. 06_SECURITY_PAYMENT_FINANCE_RULES.md
9. 07_AI_ENGINE_RULES.md
10. 08_DATABASE_AND_DOMAIN_RULES.md
11. 09_FRONTEND_UX_RULES.md
12. 10_REPOSITORY_MEMORY_PROTOCOL.md
13. 11_FAILURE_RECOVERY.md
14. 12_FINAL_WEB_ACCEPTANCE.md
15. 21_DESIGN_SYSTEM_UI_SPEC.md
16. 22_SCREEN_BLUEPRINTS_INFORMATION_ARCHITECTURE.md
17. design_refs/README.md

AMAÇ:
RefConcept'un production seviyesinde WEB sürümünü tamamla:
- Laravel API/backend
- PostgreSQL + Redis
- Nuxt/Vue Storefront
- Seller Portal
- Super Admin
- AI tasarım sistemi
- kredi/tasarım başı ücret sistemi
- gerçek ürün eşleştirme
- çoklu satıcı marketplace
- iyzico
- QNB ödeme adapter'ı
- banka havalesi
- sipariş
- komisyon
- immutable/double-entry finansal ledger
- seller settlement/hakediş
- iade/refund
- kargo
- audit/security
- testler
- OpenAPI
- Docker
- CI/CD
- staging/production readiness

MUTLAK KURALLAR:
- Benden fazlar arasında onay isteme.
- Önce yalnızca WEB'i bitir.
- WEB_RELEASE_APPROVED alınmadan Flutter/App/RoomPlan/ARCore geliştirmesine başlama.
- RefOne ismini kullanma; marka ve proje adı RefConcept'tur.
- Eksik production secret/hesap/credential varsa doğru interface + sandbox/mock +
  test + env placeholder oluştur, harici go-live dependency olarak kaydet ve
  diğer geliştirmeye devam et.
- Kod yazmakla yetinme. Migration, authorization, validation, loading/error state,
  audit, logs, OpenAPI, test ve dokümantasyon olmadan feature tamamlanmış değildir.
- Finansal değerlerde float kullanma.
- Payment/credit/order/refund/settlement işlemlerinde idempotency zorunlu.
- Kart PAN/CVV saklama.
- Seller A hiçbir koşulda Seller B verisine erişememeli.
- AI provider/model ID'lerini hard-code etme; admin/config tabanlı routing kullan.
- AI işlemleri queue tabanlı async çalışsın.
- AI tasarım başlamadan kredi reserve et; success'te consume, failure'da release et.
- Ödeme sağlayıcı callback/webhook'u duplicate gelse finansal etki yalnızca bir kez oluşsun.
- Marketplace muhasebesini balanced double-entry ledger ile doğrula.
- Kritik finansal geçmişi update/delete ile düzeltme; reversal entry kullan.
- Test Agent bağımsız çalışsın ve başarısız testi gizlemek için testleri zayıflatmasın.
- Testler geçmeden faz kapatma.
- Her fazda implement -> test -> diagnose -> fix -> retest -> gate -> next phase döngüsünü uygula.
- Chat hafızasına güvenme; repository state dosyalarını kalıcı hafıza kabul et.
- Her atomik task sonrası 13_PROGRESS_STATE.md, 14_TASK_LEDGER.md ve gerektiğinde
  TEST_REPORT.md / ADR / CHANGELOG güncelle.
- Context/session biterse repository state üzerinden otomatik kaldığın yerden devam et.
- Projeyi yalnızca scaffold/demo bırakma; gerçek domain akışlarını uçtan uca bağla.
- Sana verilen design_refs içindeki görsellere sadık kal; UI'ı generic template gibi üretme.
- Renk, boşluk, kart yapısı, hero kompozisyonu, AI akışı ve dashboard dili 21/22 numaralı tasarım dokümanlarına göre uygulanmalı.

ÇALIŞMA SIRASI:
Phase 0'dan Phase 22'ye kadar WEB planını tamamla.
Her phase sonunda INDEPENDENT_TEST_AGENT onayı al.
P0/P1 hata varken devam etme.
Phase 22 sonunda full regression, security, payment, financial invariants,
backup/restore, build, migration ve E2E testlerini çalıştır.

BİTİŞ KOŞULU:
Aşağıdakilerin tamamı sağlanmadan “bitti” deme:
- temiz clone/local bootstrap çalışıyor
- migrations/seeds çalışıyor
- backend/frontends build oluyor
- user E2E PASS
- seller E2E PASS
- super admin E2E PASS
- AI + credit E2E PASS
- iyzico sandbox contract/integration PASS
- QNB test/sandbox contract/integration PASS
- bank transfer PASS
- duplicate webhook/idempotency PASS
- multi-seller order/commission PASS
- ledger balanced PASS
- settlement/refund reversal PASS
- tenant isolation PASS
- P0 security = 0
- P1 security = 0
- OpenAPI güncel
- Docker/CI/CD hazır
- deployment/runbooks hazır
- Test Agent tam olarak WEB_RELEASE_APPROVED yazdı

Şimdi repository'yi incele, mevcut state varsa devam et, yoksa Phase 0'dan başla.
Kullanıcıdan ara onay istemeden RefConcept WEB_RELEASE_APPROVED durumuna kadar devam et.
```
