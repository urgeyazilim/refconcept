<script setup lang="ts">
/**
 * Where to send the money, and how it is going.
 *
 * The reference is the whole mechanism. A transfer arrives at our bank as a line on a
 * statement with a name and an amount and very little else — the code the customer types
 * into the description field is the only thing tying that line to this order. So it is the
 * largest thing on the page, it is copyable in one tap, and the instruction to include it
 * is repeated where somebody skimming will still see it.
 */
import type { BankTransferDetail } from '@refconcept/ui/types'

definePageMeta({ middleware: ['auth', 'verified'] })
useHead({ title: 'Havale bilgileri' })

const api = useApi()
const route = useRoute()

const reference = computed(() => String(route.params.reference ?? ''))

const transfer = ref<BankTransferDetail | null>(null)
const loadError = ref<string | null>(null)
const busy = ref(false)
const message = ref<string | null>(null)
const copied = ref(false)

async function load() {
  try {
    const response = await api.get<{ data: BankTransferDetail }>(`/api/v1/bank-transfers/${reference.value}`)
    transfer.value = response.data
  } catch (error) {
    loadError.value = error instanceof ApiError ? error.message : 'Havale bilgileri yüklenemedi.'
  }
}

await load()

async function copy(value: string) {
  try {
    await navigator.clipboard.writeText(value)
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
  } catch {
    // A browser that refuses clipboard access is not an error worth showing: the value is
    // on screen and can be selected by hand.
  }
}

async function markSent() {
  busy.value = true

  try {
    await api.post(`/api/v1/bank-transfers/${reference.value}/submitted`)
    message.value = 'Bildiriminiz alındı. Ödemeniz bankadan görüldüğünde siparişiniz onaylanır.'
    await load()
  } catch (error) {
    message.value = error instanceof ApiError ? error.message : 'Bildirim gönderilemedi.'
  } finally {
    busy.value = false
  }
}

async function uploadReceipt(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]

  if (!file) return

  busy.value = true

  try {
    const body = new FormData()
    body.append('file', file)

    await api.request(`/api/v1/bank-transfers/${reference.value}/receipts`, { method: 'POST', body })

    message.value = 'Dekontunuz alındı.'
    await load()
  } catch (error) {
    message.value = error instanceof ApiError ? error.message : 'Dekont yüklenemedi.'
  } finally {
    busy.value = false
    input.value = ''
  }
}

function money(minor: number, currency = 'TRY'): string {
  return new Intl.NumberFormat('tr-TR', { style: 'currency', currency }).format(minor / 100)
}

const tone = computed(() => {
  switch (transfer.value?.status) {
    case 'confirmed':
    case 'over_paid':
      return 'success'
    case 'rejected':
    case 'expired':
      return 'danger'
    case 'short_paid':
      return 'warning'
    default:
      return 'info'
  }
})

const stillOwed = computed(() =>
  transfer.value?.status === 'short_paid' ? transfer.value.shortfall_minor ?? 0 : 0,
)
</script>

<template>
  <div class="rc-container py-10 lg:py-14">
    <h1 class="text-2xl font-medium">Havale ile ödeme</h1>

    <RcAlert v-if="loadError" tone="danger" class="mt-6">{{ loadError }}</RcAlert>

    <div v-else-if="transfer" class="mt-8 grid gap-6 lg:grid-cols-[1fr_320px]">
      <div class="space-y-6">
        <RcAlert :tone="tone">{{ transfer.message }}</RcAlert>
        <p v-if="message" class="text-sm text-ink-secondary">{{ message }}</p>

        <!--
          The reference above the account details, not below them. It is the part people
          forget, and a payment with no reference is a payment nobody can match.
        -->
        <section class="rc-card p-5 sm:p-6">
          <h2 class="text-sm font-medium">Açıklama alanına yazılacak referans</h2>

          <div class="mt-3 flex flex-wrap items-center gap-3">
            <p data-testid="transfer-reference" class="font-mono text-2xl tracking-wider tabular-nums">{{ transfer.reference }}</p>
            <RcButton size="sm" variant="ghost" @click="copy(transfer.reference)">
              {{ copied ? 'Kopyalandı' : 'Kopyala' }}
            </RcButton>
          </div>

          <p class="mt-3 text-sm text-ink-secondary">
            Açıklama alanına <strong>yalnızca bu kodu</strong> yazın. Başka bir açıklama
            yazarsanız ödemeniz siparişinizle eşleşmeyebilir.
          </p>
        </section>

        <section v-if="transfer.bank_account" class="rc-card p-5 sm:p-6">
          <h2 class="text-sm font-medium">Hesap bilgileri</h2>

          <dl class="mt-4 space-y-3 text-sm">
            <div class="flex flex-wrap justify-between gap-2">
              <dt class="text-muted">Banka</dt>
              <dd>{{ transfer.bank_account.bank_name }}</dd>
            </div>
            <div class="flex flex-wrap justify-between gap-2">
              <dt class="text-muted">Alıcı</dt>
              <dd>{{ transfer.bank_account.account_holder }}</dd>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2">
              <dt class="text-muted">IBAN</dt>
              <dd class="flex items-center gap-2">
                <span class="font-mono tabular-nums">{{ transfer.bank_account.iban }}</span>
                <RcButton size="sm" variant="ghost" @click="copy(transfer.bank_account.iban)">Kopyala</RcButton>
              </dd>
            </div>
          </dl>

          <p v-if="transfer.bank_account.note" class="mt-4 rounded-sm bg-bg-muted px-3 py-2 text-xs text-ink-secondary">
            {{ transfer.bank_account.note }}
          </p>
        </section>

        <section v-if="transfer.status === 'awaiting_transfer' || transfer.status === 'short_paid'" class="rc-card p-5 sm:p-6">
          <h2 class="text-sm font-medium">Gönderdiyseniz</h2>

          <p class="mt-2 text-sm text-ink-secondary">
            Dekont yüklemek zorunlu değil ama eşleştirmeyi hızlandırır.
          </p>

          <div class="mt-4 flex flex-wrap items-center gap-3">
            <label>
              <span
                id="receipt-upload"
                class="inline-flex cursor-pointer items-center rounded-sm border border-line-strong px-4 py-2 text-sm hover:bg-bg-muted"
              >
                {{ busy ? 'Yükleniyor…' : 'Dekont yükle' }}
              </span>
              <input
                type="file"
                class="sr-only"
                accept="application/pdf,image/jpeg,image/png,image/webp"
                :disabled="busy"
                @change="uploadReceipt"
              >
            </label>

            <RcButton variant="ghost" :loading="busy" @click="markSent">Gönderdim</RcButton>
          </div>

          <p v-if="transfer.receipt_count > 0" class="mt-3 text-xs text-muted">
            {{ transfer.receipt_count }} dekont yüklendi.
          </p>
        </section>
      </div>

      <aside class="rc-card h-fit p-5 sm:p-6">
        <h2 class="text-sm font-medium">Tutar</h2>

        <p class="mt-3 text-2xl font-medium tabular-nums">
          {{ money(transfer.expected_minor, transfer.currency) }}
        </p>

        <!--
          A shortfall is stated with the figure still owed, not as "eksik ödeme" alone. A
          customer told only that something is missing has to work out what, and most of
          them will simply give up and ask.
        -->
        <div v-if="stillOwed > 0" class="mt-4 rounded-sm bg-warning-subtle px-3 py-2 text-sm text-warning-strong">
          <p>Gönderilen: {{ money(transfer.received_minor ?? 0, transfer.currency) }}</p>
          <p class="mt-1 font-medium">Kalan: {{ money(stillOwed, transfer.currency) }}</p>
          <p class="mt-1 text-xs">Farkı aynı referansla gönderin.</p>
        </div>

        <p v-if="transfer.expires_at" class="mt-4 text-xs text-muted">
          Ürünleriniz {{ new Date(transfer.expires_at).toLocaleString('tr-TR') }} tarihine
          kadar sizin için ayrıldı.
        </p>

        <RcButton class="mt-5 w-full" variant="ghost" to="/account">Hesabıma dön</RcButton>
      </aside>
    </div>
  </div>
</template>
