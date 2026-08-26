<script setup lang="ts">
/**
 * The customer's returns.
 *
 * Each row says where the goods are and, separately, where the money is. Those are two
 * different journeys — a return can be accepted while the refund is still with the bank —
 * and collapsing them into one line is how somebody concludes their money has vanished.
 */
import type { ReturnDetail } from '@refconcept/ui/types'

definePageMeta({ layout: 'account', middleware: ['auth', 'verified'] })
useSeo({ title: 'İadelerim', noindex: true })

const api = useApi()

const returns = ref<ReturnDetail[]>([])
const loadError = ref<string | null>(null)
const busy = ref(false)
const message = ref<string | null>(null)

async function load() {
  try {
    const response = await api.get<{ data: ReturnDetail[] }>('/api/v1/returns')
    returns.value = response.data
  } catch (error) {
    loadError.value = error instanceof ApiError ? error.message : 'İadeler yüklenemedi.'
  }
}

await load()

async function act(operation: () => Promise<unknown>, success: string) {
  busy.value = true
  message.value = null

  try {
    await operation()
    message.value = success
    await load()
  } catch (error) {
    message.value = error instanceof ApiError ? error.message : 'İşlem tamamlanamadı.'
  } finally {
    busy.value = false
  }
}

const markSent = (row: ReturnDetail) => act(
  () => api.post(`/api/v1/returns/${row.reference}/sent`),
  'Bildiriminiz alındı.',
)

const cancel = (row: ReturnDetail) => act(
  () => api.delete(`/api/v1/returns/${row.reference}`),
  'İade talebiniz iptal edildi.',
)

function money(minor: number, currency = 'TRY'): string {
  return new Intl.NumberFormat('tr-TR', { style: 'currency', currency }).format(minor / 100)
}

function when(value: string | null): string {
  return value === null ? '' : new Date(value).toLocaleDateString('tr-TR')
}

const tone = (status: string): string => {
  switch (status) {
    case 'completed':
      return 'bg-success-subtle text-success-strong'
    case 'rejected':
    case 'cancelled':
      return 'bg-bg-muted text-ink-secondary'
    default:
      return 'bg-info-subtle text-info-strong'
  }
}
</script>

<template>
  <div>
    <h1 class="text-xl font-medium">İadelerim</h1>

    <RcAlert v-if="loadError" tone="danger" class="mt-6">{{ loadError }}</RcAlert>
    <p v-if="message" class="mt-4 text-sm text-ink-secondary">{{ message }}</p>

    <div v-if="returns.length === 0" class="rc-card mt-6 p-10 text-center">
      <p class="text-sm text-muted">Henüz iade talebiniz yok.</p>
      <RcButton class="mt-4" variant="ghost" to="/account/orders">Siparişlerime git</RcButton>
    </div>

    <ul v-else class="mt-6 space-y-4">
      <li v-for="row in returns" :key="row.id" class="rc-card p-5 sm:p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
          <div>
            <p class="font-mono text-sm">{{ row.reference }}</p>
            <p class="mt-1 text-xs text-muted">
              {{ row.seller_name ?? 'Satıcı' }} · {{ when(row.created_at) }}
            </p>
          </div>
          <span class="rounded-pill px-3 py-1 text-xs" :class="tone(row.status)">
            {{ row.status_label }}
          </span>
        </div>

        <p class="mt-3 text-sm text-ink-secondary">{{ row.message }}</p>

        <p v-if="row.decision_note" class="mt-2 rounded-sm bg-bg-muted px-3 py-2 text-sm text-ink-secondary">
          Satıcı notu: {{ row.decision_note }}
        </p>

        <ul class="mt-4 divide-y divide-line text-sm">
          <li v-for="item in row.items" :key="item.id" class="flex justify-between gap-4 py-2">
            <span>{{ item.product_name }}</span>
            <span class="tabular-nums text-muted">
              {{ item.quantity }} adet
              <template v-if="item.approved_quantity > 0 && item.approved_quantity !== item.quantity">
                · {{ item.approved_quantity }} adet kabul edildi
              </template>
            </span>
          </li>
        </ul>

        <!--
          Where the money is, said separately from where the goods are. A return can be
          accepted while the refund is still with the bank, and one line for both is how
          somebody concludes their money has vanished.
        -->
        <div v-if="row.refund" class="mt-4 rounded-sm bg-bg-muted px-3 py-2 text-sm">
          <p class="font-medium">
            {{ money(row.refund.amount_minor, row.refund.currency) }} — {{ row.refund.status_label }}
          </p>
          <p class="mt-0.5 text-xs text-ink-secondary">{{ row.refund.message }}</p>
        </div>

        <div v-if="row.status === 'approved'" class="mt-4 flex flex-wrap gap-3">
          <RcButton size="sm" :loading="busy" @click="markSent(row)">Kargoya verdim</RcButton>
          <RcButton size="sm" variant="ghost" :disabled="busy" @click="cancel(row)">Vazgeç</RcButton>
        </div>

        <div v-else-if="row.status === 'requested'" class="mt-4">
          <RcButton size="sm" variant="ghost" :disabled="busy" @click="cancel(row)">
            Talebi geri çek
          </RcButton>
        </div>
      </li>
    </ul>
  </div>
</template>
