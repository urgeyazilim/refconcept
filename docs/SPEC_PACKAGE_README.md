# RefConcept AI One‑Shot Software Factory Pack

Bu paket, RefConcept WEB platformunun tek bir kullanıcı komutundan sonra coding agent tarafından
fazlara ayrılarak geliştirilmesi, test edilmesi, hataların düzeltilmesi ve release gate'e kadar
ilerletilmesi için hazırlanmıştır.

## Başlatma

1. Bu klasördeki tüm dosyaları hedef repository'nin köküne koyun.
2. Coding agent'a `00_COPY_THIS_PROMPT.md` içindeki prompt'u verin.
3. Agent `WEB_RELEASE_APPROVED` durumuna kadar repository içindeki state dosyalarıyla ilerler.

## En Önemli Dosyalar

- `REFCONCEPT_MASTER_SPEC.md` — ürün ve yazılım master şartnamesi
- `AGENTS.md` — genel agent sözleşmesi
- `01_AUTONOMOUS_ORCHESTRATOR.md` — otonom yürütme
- `02_AGENT_TEAM.md` — uzman roller
- `03_INDEPENDENT_TEST_AGENT.md` — bağımsız test/release gate
- `04_WEB_PHASE_PLAN.md` — Phase 0–22
- `12_FINAL_WEB_ACCEPTANCE.md` — final kabul
- `13_PROGRESS_STATE.md` — kalıcı state
- `14_TASK_LEDGER.md` — kalıcı task listesi

## Strateji

**Önce WEB, sonra APP.**

Web:
- müşteri
- satıcı
- super admin
- AI
- kredi
- marketplace
- ödeme
- finans
- sipariş
- test
- production readiness

Mobil/AR ayrı ikinci milestone'dır.


## Tasarım Referansları

Bu pakete ayrıca kullanıcı tarafından verilen ve onaylanan tasarım referansları eklendi:

- `design_refs/brand_colors.jpg`
- `design_refs/dashboard.jpg`
- `design_refs/hero_room.jpg`
- `design_refs/mobile_ai_marketplace.jpg`
- `design_refs/mobile_ops_ar.jpg`
- `design_refs/ui_inspiration.jpg`
- `design_refs/refconcept_assets_montage.png`

Bunları yazılıma döken dokümanlar:

- `21_DESIGN_SYSTEM_UI_SPEC.md`
- `22_SCREEN_BLUEPRINTS_INFORMATION_ARCHITECTURE.md`

Yani artık coding agent sadece backend ve akışları değil, **arayüz dilini de** bu referanslara göre uygulamak zorunda.
