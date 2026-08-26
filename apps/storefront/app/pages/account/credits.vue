<script setup lang="ts">
/**
 * A customer's credits.
 *
 * Three things, in the order somebody actually wants them: what I have, what is about to
 * disappear, and where it all went. The expiry warning is above the statement rather
 * than buried in it — losing credits you did not know had a deadline is the complaint
 * this page exists to prevent, and it cannot prevent it from the bottom of a list.
 */
definePageMeta({ layout: 'account', middleware: 'auth' })
useSeo({ title: 'Kredilerim', noindex: true })

interface ExpiringLot {
  credits: number
  expires_at: string | null
  source: string
  source_label: string
}

interface Wallet {
  balance: number
  reserved: number
  available: number
  lifetime: { purchased: number, granted: number, consumed: number, expired: number }
  expiring_soon: ExpiringLot[]
  expiring_total: number
  last_movement_at: string | null
}

interface Movement {
  id: string
  type: string
  type_label: string
  amount: number
  balance_after: number
  description: string
  reason: string | null
  created_at: string
}

interface Package {
  id: string
  code: string
  name: string
  description: string | null
  credits: number
  bonus_credits: number
  total_credits: number
  price: { amount_minor: number, currency: string }
  validity_days: number | null
  is_featured: boolean
}

const api = useApi()

const wallet = ref<Wallet | null>(null)
const movements = ref<Movement[]>([])
const packages = ref<Package[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)

const code = ref('')
const redeeming = ref(false)
const redeemError = ref<string | null>(null)
const redeemSuccess = ref<string | null>(null)

async function load() {
  loading.value = true
  loadError.value = null

  try {
    const [balance, history, catalogue] = await Promise.all([
      api.get<{ data: Wallet }>('/api/v1/credits'),
      api.get<{ data: Movement[] }>('/api/v1/credits/transactions'),
      api.get<{ data: Package[] }>('/api/v1/credits/packages'),
    ])

    wallet.value = balance.data
    movements.value = history.data
    packages.value = catalogue.data
  } catch (error) {
    loadError.value = error instanceof ApiError ? error.message : 'Kredi bilgileri yüklenemedi.'
  } finally {
    loading.value = false
  }
}

await load()

const buying = ref<string | null>(null)
const buyError = ref<string | null>(null)

/**
 * Opens a checkout for one package and hands over to the payment page.
 *
 * The same session, the same intent and the same webhook path a basket uses. A top-up
 * that took its own route through the payment code would need its own defence against
 * duplicate confirmations, and would eventually get one that was subtly weaker.
 */
async function buy(id: string) {
  buying.value = id
  buyError.value = null

  try {
    await api.post('/api/v1/checkout/credits', { package_id: id })
    await navigateTo('/checkout?purpose=credits')
  } catch (error) {
    buyError.value = error instanceof ApiError ? error.message : 'Ödeme adımı açılamadı.'
  } finally {
    buying.value = null
  }
}

async function redeem() {
  if (!code.value.trim()) return

  redeeming.value = true
  redeemError.value = null
  redeemSuccess.value = null

  try {
    const response = await api.post<{ message: string }>('/api/v1/credits/redeem', {
      code: code.value.trim(),
    })

    redeemSuccess.value = response.message
    code.value = ''
    await load()
  } catch (error) {
    redeemError.value = error instanceof ApiError ? error.message : 'Kod kullanılamadı.'
  } finally {
    redeeming.value = false
  }
}

/** How long until a batch disappears, in the words somebody would use. */
function daysUntil(iso: string | null): string {
  if (!iso) return ''

  const days = Math.ceil((new Date(iso).getTime() - Date.now()) / 86_400_000)

  if (days <= 0) return 'bugün'
  if (days === 1) return 'yarın'

  return `${days} gün içinde`
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('tr-TR', { day: 'numeric', month: 'long', year: 'numeric' })
}
</script>

<template>
  <div class="space-y-10">
    <header>
      <h1 class="text-2xl font-medium">Kredilerim</h1>
      <p class="mt-1.5 max-w-[60ch] text-sm leading-relaxed text-ink-secondary">
        Tasarım üretimi ve oda analizi kredi ile çalışır. Tamamlanamayan bir işlem için
        kredi düşülmez.
      </p>
    </header>

    <p v-if="loadError" class="rounded-sm border border-danger/30 bg-danger/5 px-4 py-3 text-sm">
      {{ loadError }}
    </p>

    <p v-else-if="loading" class="text-sm text-muted">Yükleniyor…</p>

    <template v-else-if="wallet">
      <section class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-sm border border-line bg-surface p-5">
          <p class="text-[11px] tracking-wide text-muted uppercase">Kullanılabilir</p>
          <p class="mt-1 text-3xl font-medium tabular-nums">{{ wallet.available }}</p>
        </div>

        <div class="rounded-sm border border-line bg-surface p-5">
          <p class="text-[11px] tracking-wide text-muted uppercase">Toplam bakiye</p>
          <p data-testid="credit-balance" class="mt-1 text-3xl font-medium tabular-nums">{{ wallet.balance }}</p>
          <!--
            Only shown when there is something held. A permanent "0 bloke" line teaches
            people to ignore the number, which is the opposite of what it is for.
          -->
          <p v-if="wallet.reserved > 0" class="mt-1 text-xs text-muted">
            {{ wallet.reserved }} kredi süren işlemler için ayrıldı
          </p>
        </div>

        <div class="rounded-sm border border-line bg-surface p-5">
          <p class="text-[11px] tracking-wide text-muted uppercase">Bugüne kadar kullanılan</p>
          <p class="mt-1 text-3xl font-medium tabular-nums">{{ wallet.lifetime.consumed }}</p>
        </div>
      </section>

      <!--
        Above the statement, not inside it. Somebody losing credits they did not know had
        a deadline is the complaint this page exists to prevent.
      -->
      <section
        v-if="wallet.expiring_total > 0"
        class="rounded-sm border border-warning/40 bg-warning/5 px-5 py-4"
      >
        <p class="text-sm font-medium">
          {{ wallet.expiring_total }} kredinin süresi yakında doluyor
        </p>
        <ul class="mt-2 space-y-1">
          <li
            v-for="(lot, index) in wallet.expiring_soon"
            :key="index"
            class="text-sm text-ink-secondary"
          >
            {{ lot.credits }} kredi · {{ lot.source_label }} ·
            <span class="text-ink">{{ daysUntil(lot.expires_at) }}</span>
          </li>
        </ul>
      </section>

      <section class="rounded-sm border border-line bg-surface p-5">
        <h2 class="text-sm font-medium">Kampanya kodu</h2>
        <p class="mt-1 text-sm text-ink-secondary">Elinizde bir kod varsa buraya girin.</p>

        <form class="mt-3 flex flex-wrap gap-2" @submit.prevent="redeem">
          <input
            v-model="code"
            type="text"
            autocomplete="off"
            placeholder="HOSGELDIN"
            class="w-full max-w-xs rounded-sm border border-line bg-bg px-3 py-2 text-sm tracking-wide text-ink uppercase focus:border-ink focus:outline-none"
          >
          <button
            type="submit"
            class="rounded-sm bg-charcoal px-4 py-2 text-sm text-inverse transition-opacity hover:opacity-90 disabled:opacity-50"
            :disabled="redeeming || !code.trim()"
          >
            {{ redeeming ? 'Kontrol ediliyor…' : 'Kullan' }}
          </button>
        </form>

        <p v-if="redeemSuccess" class="mt-2 text-sm text-success">{{ redeemSuccess }}</p>
        <p v-if="redeemError" class="mt-2 text-sm text-danger">{{ redeemError }}</p>
      </section>

      <section v-if="packages.length > 0">
        <h2 class="text-sm font-medium">Kredi paketleri</h2>

        <p v-if="buyError" class="mt-2 text-sm text-danger">{{ buyError }}</p>

        <div class="mt-3 grid gap-3 sm:grid-cols-3">
          <article
            v-for="item in packages"
            :key="item.id"
            class="rounded-sm border bg-surface p-5"
            :class="item.is_featured ? 'border-ink' : 'border-line'"
          >
            <p class="text-sm font-medium">{{ item.name }}</p>
            <p class="mt-1 text-2xl font-medium tabular-nums">
              {{ item.credits }}
              <span v-if="item.bonus_credits > 0" class="text-sm font-normal text-success">
                + {{ item.bonus_credits }} hediye
              </span>
            </p>
            <p class="mt-2 text-sm text-ink-secondary">
              {{ formatMinor(item.price.amount_minor, item.price.currency) }}
            </p>
            <p v-if="item.validity_days" class="mt-1 text-xs text-muted">
              {{ item.validity_days }} gün geçerli
            </p>

            <RcButton
              class="mt-4 w-full"
              size="sm"
              :loading="buying === item.id"
              :disabled="buying !== null"
              @click="buy(item.id)"
            >
              Satın al
            </RcButton>
          </article>
        </div>
      </section>

      <section>
        <h2 class="text-sm font-medium">Hareketler</h2>

        <p v-if="movements.length === 0" class="mt-3 text-sm text-muted">
          Henüz bir hareket yok.
        </p>

        <ul v-else class="mt-3 divide-y divide-line rounded-sm border border-line bg-surface">
          <li
            v-for="movement in movements"
            :key="movement.id"
            class="flex items-start justify-between gap-4 px-5 py-3.5"
          >
            <div class="min-w-0">
              <p class="truncate text-sm">{{ movement.description }}</p>
              <p class="mt-0.5 text-xs text-muted">
                {{ formatDate(movement.created_at) }} · {{ movement.type_label }}
              </p>
              <!-- A hand-made correction says why. A customer is entitled to that. -->
              <p v-if="movement.reason" class="mt-0.5 text-xs text-ink-secondary">
                {{ movement.reason }}
              </p>
            </div>

            <div class="shrink-0 text-right">
              <p
                class="text-sm tabular-nums"
                :class="movement.amount > 0 ? 'text-success' : 'text-ink'"
              >
                {{ movement.amount > 0 ? '+' : '' }}{{ movement.amount }}
              </p>
              <p class="text-xs text-muted tabular-nums">{{ movement.balance_after }}</p>
            </div>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>
