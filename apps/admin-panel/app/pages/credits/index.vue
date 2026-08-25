<script setup lang="ts">
/**
 * Credit administration.
 *
 * Packages, promotions, and a way to look up one customer's wallet. Adjusting a balance
 * lives behind that lookup rather than on this list on purpose — a correction should be
 * something somebody arrives at after reading an account's history, not a field they can
 * fill in while scrolling past.
 */
definePageMeta({ middleware: 'auth' })
useHead({ title: 'Kredi yönetimi' })

interface PackageRow {
  id: string
  code: string
  name: string
  credits: number
  bonus_credits: number
  total_credits: number
  price_minor: number
  currency: string
  validity_days: number | null
  is_active: boolean
  is_featured: boolean
}

interface PromotionRow {
  id: string
  code: string
  name: string
  credits: number
  validity_days: number | null
  max_redemptions: number | null
  max_per_user: number
  redemption_count: number
  remaining_redemptions: number | null
  starts_at: string | null
  ends_at: string | null
  new_accounts_only: boolean
  is_active: boolean
  is_running: boolean
}

const api = useApi()

const packages = ref<PackageRow[]>([])
const promotions = ref<PromotionRow[]>([])
const loading = ref(true)
const banner = ref<{ tone: 'success' | 'danger', text: string } | null>(null)
const acting = ref<string | null>(null)

async function load() {
  loading.value = true

  try {
    const [packageList, promotionList] = await Promise.all([
      api.get<{ data: PackageRow[] }>('/api/v1/admin/credits/packages'),
      api.get<{ data: PromotionRow[] }>('/api/v1/admin/credits/promotions'),
    ])

    packages.value = packageList.data
    promotions.value = promotionList.data
  } catch (error) {
    banner.value = {
      tone: 'danger',
      text: error instanceof ApiError
        ? (error.status === 403 ? 'Bu alana erişim yetkiniz yok.' : error.message)
        : 'Kredi yapılandırması yüklenemedi.',
    }
  } finally {
    loading.value = false
  }
}

await load()

/**
 * Switching a package or a promotion off.
 *
 * The kill switch for a code that turned out to be too generous or leaked. Deactivating
 * rather than deleting: the redemptions already made are part of the financial record,
 * and a promotion nobody can look up is a set of grants nobody can explain.
 */
async function toggle(kind: 'packages' | 'promotions', row: { id: string, is_active: boolean }) {
  acting.value = row.id
  banner.value = null

  try {
    await api.patch(`/api/v1/admin/credits/${kind}/${row.id}`, { is_active: !row.is_active })
    banner.value = { tone: 'success', text: row.is_active ? 'Kapatıldı.' : 'Açıldı.' }
    await load()
  } catch (error) {
    banner.value = {
      tone: 'danger',
      text: error instanceof ApiError ? error.message : 'İşlem tamamlanamadı.',
    }
  } finally {
    acting.value = null
  }
}

function money(minor: number, currency: string): string {
  return new Intl.NumberFormat('tr-TR', { style: 'currency', currency }).format(minor / 100)
}

function window_(row: PromotionRow): string {
  if (!row.starts_at && !row.ends_at) return 'süresiz'

  const from = row.starts_at ? new Date(row.starts_at).toLocaleDateString('tr-TR') : '—'
  const to = row.ends_at ? new Date(row.ends_at).toLocaleDateString('tr-TR') : '—'

  return `${from} → ${to}`
}
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-medium">Kredi yönetimi</h1>
      <p class="mt-1.5 max-w-[70ch] text-sm leading-relaxed text-ink-secondary">
        Satıştaki paketler ve kampanya kodları. Bir müşterinin bakiyesini düzeltmek için
        önce cüzdanını açın; düzeltme her zaman gerekçe ister ve denetim kaydına düşer.
      </p>
    </header>

    <div
      v-if="banner"
      class="rounded-sm border px-4 py-3 text-sm"
      :class="banner.tone === 'success'
        ? 'border-success/30 bg-success/5 text-ink'
        : 'border-danger/30 bg-danger/5 text-ink'"
    >
      {{ banner.text }}
    </div>

    <div v-if="loading" class="rounded-sm border border-line bg-surface p-6 text-sm text-muted">
      Yükleniyor…
    </div>

    <template v-else>
      <section class="rounded-sm border border-line bg-surface">
        <h2 class="border-b border-line px-5 py-3.5 text-sm font-medium">Paketler</h2>

        <div class="overflow-x-auto">
          <table class="w-full min-w-[720px] text-sm">
            <thead>
              <tr class="border-b border-line text-left text-[11px] tracking-wide text-muted uppercase">
                <th class="px-5 py-2.5 font-medium">Paket</th>
                <th class="px-5 py-2.5 text-right font-medium">Kredi</th>
                <th class="px-5 py-2.5 text-right font-medium">Fiyat</th>
                <th class="px-5 py-2.5 text-right font-medium">Geçerlilik</th>
                <th class="px-5 py-2.5 font-medium">Durum</th>
                <th class="px-5 py-2.5" />
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in packages" :key="row.id" class="border-b border-line/60 last:border-b-0">
                <td class="px-5 py-3">
                  <p class="font-medium">{{ row.name }}</p>
                  <p class="text-[11px] text-muted">{{ row.code }}</p>
                </td>
                <td class="px-5 py-3 text-right tabular-nums">
                  {{ row.credits }}
                  <span v-if="row.bonus_credits > 0" class="text-success">+{{ row.bonus_credits }}</span>
                </td>
                <td class="px-5 py-3 text-right tabular-nums">{{ money(row.price_minor, row.currency) }}</td>
                <td class="px-5 py-3 text-right text-muted tabular-nums">
                  {{ row.validity_days ? `${row.validity_days} gün` : 'süresiz' }}
                </td>
                <td class="px-5 py-3">
                  <span
                    class="rounded-sm px-2 py-0.5 text-[11px]"
                    :class="row.is_active ? 'bg-success/10 text-success' : 'bg-bg-muted text-muted'"
                  >
                    {{ row.is_active ? 'satışta' : 'kapalı' }}
                  </span>
                </td>
                <td class="px-5 py-3 text-right">
                  <button
                    type="button"
                    class="rounded-sm border border-line px-2.5 py-1 text-xs text-ink-secondary transition-colors hover:bg-bg-muted hover:text-ink disabled:opacity-50"
                    :disabled="acting === row.id"
                    @click="toggle('packages', row)"
                  >
                    {{ row.is_active ? 'Kapat' : 'Aç' }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="rounded-sm border border-line bg-surface">
        <h2 class="border-b border-line px-5 py-3.5 text-sm font-medium">Kampanya kodları</h2>

        <p v-if="promotions.length === 0" class="px-5 py-4 text-sm text-muted">
          Tanımlı kampanya yok.
        </p>

        <div v-else class="overflow-x-auto">
          <table class="w-full min-w-[820px] text-sm">
            <thead>
              <tr class="border-b border-line text-left text-[11px] tracking-wide text-muted uppercase">
                <th class="px-5 py-2.5 font-medium">Kod</th>
                <th class="px-5 py-2.5 text-right font-medium">Kredi</th>
                <th class="px-5 py-2.5 text-right font-medium">Kullanım</th>
                <th class="px-5 py-2.5 font-medium">Dönem</th>
                <th class="px-5 py-2.5 font-medium">Kısıt</th>
                <th class="px-5 py-2.5 font-medium">Durum</th>
                <th class="px-5 py-2.5" />
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in promotions" :key="row.id" class="border-b border-line/60 last:border-b-0">
                <td class="px-5 py-3">
                  <p class="font-medium">{{ row.code }}</p>
                  <p class="text-[11px] text-muted">{{ row.name }}</p>
                </td>
                <td class="px-5 py-3 text-right tabular-nums">{{ row.credits }}</td>
                <td class="px-5 py-3 text-right tabular-nums">
                  {{ row.redemption_count }}<span v-if="row.max_redemptions"> / {{ row.max_redemptions }}</span>
                </td>
                <td class="px-5 py-3 text-muted">{{ window_(row) }}</td>
                <td class="px-5 py-3 text-[11px] text-muted">
                  kişi başı {{ row.max_per_user }}<span v-if="row.new_accounts_only"> · yeni hesap</span>
                </td>
                <td class="px-5 py-3">
                  <!--
                    Active and running are different questions: a campaign somebody left
                    switched on can still be out of budget or past its end date, and only
                    the second is why a customer is being told their code is invalid.
                  -->
                  <span
                    class="rounded-sm px-2 py-0.5 text-[11px]"
                    :class="row.is_running ? 'bg-success/10 text-success' : 'bg-bg-muted text-muted'"
                  >
                    {{ row.is_running ? 'geçerli' : (row.is_active ? 'dönem dışı' : 'kapalı') }}
                  </span>
                </td>
                <td class="px-5 py-3 text-right">
                  <button
                    type="button"
                    class="rounded-sm border border-line px-2.5 py-1 text-xs text-ink-secondary transition-colors hover:bg-bg-muted hover:text-ink disabled:opacity-50"
                    :disabled="acting === row.id"
                    @click="toggle('promotions', row)"
                  >
                    {{ row.is_active ? 'Kapat' : 'Aç' }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>
