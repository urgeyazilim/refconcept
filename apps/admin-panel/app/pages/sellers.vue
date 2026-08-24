<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
useHead({ title: 'Satıcılar' })

interface SellerRow {
  id: string
  seller_code: string
  display_name: string
  status: 'active' | 'suspended' | 'closed'
  status_label: string
  organization_id: string
  default_commission_bps: number | null
  effective_commission_bps: number
  approved_at: string | null
  suspended_at: string | null
}

const api = useApi()

const sellers = ref<SellerRow[]>([])
const loading = ref(true)
const banner = ref<{ tone: 'success' | 'danger' | 'info', text: string } | null>(null)
const search = ref('')
const acting = ref<string | null>(null)

async function load() {
  loading.value = true

  try {
    const query: Record<string, string> = {}
    if (search.value) query.search = search.value

    const response = await api.get<{ data: SellerRow[] }>('/api/v1/admin/sellers', query)
    sellers.value = response.data
  } catch (error) {
    banner.value = {
      tone: 'danger',
      text: error instanceof ApiError
        ? (error.status === 403 ? 'Bu alana erişim yetkiniz yok.' : error.message)
        : 'Satıcılar yüklenemedi.',
    }
  } finally {
    loading.value = false
  }
}

await load()

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(load, 350)
})

/**
 * Suspension and reactivation both demand a reason, and the prompt is not a
 * formality: the reason lands in seller_status_history and the audit log, and it is
 * what answers "why is this seller suspended" six months later.
 */
async function changeStatus(seller: SellerRow, action: 'suspend' | 'reactivate') {
  const label = action === 'suspend' ? 'askıya alma' : 'yeniden aktifleştirme'
  const reason = window.prompt(`${seller.display_name} için ${label} gerekçesi (en az 10 karakter):`) ?? ''

  if (reason.trim().length < 10) return

  acting.value = seller.id

  try {
    await api.post(`/api/v1/admin/sellers/${seller.id}/${action}`, { reason })
    await load()
    banner.value = { tone: 'success', text: 'Satıcı durumu güncellendi.' }
  } catch (error) {
    banner.value = {
      tone: 'danger',
      text: error instanceof ApiError ? error.message : 'İşlem tamamlanamadı.',
    }
  } finally {
    acting.value = null
  }
}

async function changeCommission(seller: SellerRow) {
  const raw = window.prompt(
    `${seller.display_name} için komisyon (baz puan, 1250 = %12,5):`,
    String(seller.default_commission_bps ?? ''),
  )

  if (raw === null) return

  const reason = window.prompt('Değişiklik gerekçesi (en az 10 karakter):') ?? ''

  if (reason.trim().length < 10) return

  acting.value = seller.id

  try {
    await api.patch(`/api/v1/admin/sellers/${seller.id}/commission`, {
      commission_bps: raw === '' ? null : Number(raw),
      reason,
    })

    await load()
    banner.value = { tone: 'success', text: 'Komisyon oranı güncellendi.' }
  } catch (error) {
    banner.value = {
      tone: 'danger',
      text: error instanceof ApiError ? error.message : 'İşlem tamamlanamadı.',
    }
  } finally {
    acting.value = null
  }
}

function percent(bps: number): string {
  return `%${(bps / 100).toLocaleString('tr-TR')}`
}
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-medium">Satıcılar</h1>
      <p class="mt-1.5 text-sm text-ink-secondary">
        Onaylanmış satıcılar. Askıya alma ve komisyon değişikliği gerekçe ister ve
        denetim kaydına düşer.
      </p>
    </header>

    <RcAlert v-if="banner" :tone="banner.tone">{{ banner.text }}</RcAlert>

    <input
      v-model="search"
      type="search"
      placeholder="Mağaza adı veya satıcı kodu ara"
      class="w-full max-w-sm rounded-sm border border-line bg-surface px-4 py-2.5 text-sm"
    >

    <p v-if="loading" class="text-sm text-muted">Yükleniyor…</p>

    <div v-else-if="sellers.length === 0" class="rc-card p-10 text-center">
      <p class="text-sm text-ink-secondary">Henüz onaylanmış satıcı yok.</p>
    </div>

    <div v-else class="rc-card overflow-hidden">
      <table class="w-full text-sm">
        <thead class="border-b border-line bg-bg-muted text-left">
          <tr>
            <th class="px-5 py-3 font-medium">Satıcı</th>
            <th class="px-5 py-3 font-medium">Kod</th>
            <th class="px-5 py-3 font-medium">Durum</th>
            <th class="px-5 py-3 font-medium">Komisyon</th>
            <th class="px-5 py-3" />
          </tr>
        </thead>

        <tbody>
          <tr
            v-for="seller in sellers"
            :key="seller.id"
            class="border-b border-line last:border-0"
          >
            <td class="px-5 py-4 font-medium">{{ seller.display_name }}</td>
            <td class="px-5 py-4 tabular-nums text-ink-secondary">{{ seller.seller_code }}</td>
            <td class="px-5 py-4">
              <span
                class="rounded-pill px-2.5 py-1 text-xs"
                :class="seller.status === 'active'
                  ? 'bg-success-subtle text-success-strong'
                  : 'bg-danger-subtle text-danger-strong'"
              >
                {{ seller.status_label }}
              </span>
            </td>
            <td class="px-5 py-4 tabular-nums">
              {{ percent(seller.effective_commission_bps) }}
              <span v-if="seller.default_commission_bps === null" class="text-xs text-muted">
                (varsayılan)
              </span>
            </td>
            <td class="px-5 py-4">
              <div class="flex justify-end gap-2">
                <RcButton size="sm" variant="ghost" @click="changeCommission(seller)">
                  Komisyon
                </RcButton>
                <RcButton
                  v-if="seller.status === 'active'"
                  size="sm"
                  variant="ghost"
                  :loading="acting === seller.id"
                  @click="changeStatus(seller, 'suspend')"
                >
                  Askıya al
                </RcButton>
                <RcButton
                  v-else
                  size="sm"
                  variant="ghost"
                  :loading="acting === seller.id"
                  @click="changeStatus(seller, 'reactivate')"
                >
                  Aktifleştir
                </RcButton>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
