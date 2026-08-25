<script setup lang="ts">
/**
 * Paying.
 *
 * The page opens a session on arrival, and that single act is what freezes the price: from
 * here on the seller may reprice all they like and this customer pays what they were
 * shown. It is also what takes the stock hold, which is why leaving the page without
 * paying gives the goods back rather than keeping them off the market for a quarter of an
 * hour.
 *
 * Everything the customer is agreeing to is on one screen — the lines, the address, the
 * total and the tax inside it — because the last place to discover a surprise is the page
 * after the one with the button on it.
 */
import type { Address, BankAccountOption, CheckoutPurpose, CheckoutSession, PaymentMethodOption } from '@refconcept/ui/types'

definePageMeta({ middleware: ['auth', 'verified'] })
useHead({ title: 'Ödeme' })

const api = useApi()
const route = useRoute()
const router = useRouter()

const purpose = computed<CheckoutPurpose>(() => (route.query.purpose === 'credits' ? 'credits' : 'cart'))

const session = ref<CheckoutSession | null>(null)
const addresses = ref<Address[]>([])
const methods = ref<PaymentMethodOption[]>([])

const loading = ref(true)
const paying = ref(false)
const loadError = ref<string | null>(null)
const payError = ref<string | null>(null)

const selectedAddressId = ref<string | null>(null)

/*
 * Which test card to use.
 *
 * Only rendered when the gateway in use is the built-in test provider, which is not
 * enabled anywhere real money moves. It exists so that a decline and a 3DS challenge are
 * things anybody can try in a minute rather than things only a test suite ever sees.
 */
const testToken = ref('tok_success')

const testCards = [
  { value: 'tok_success', label: 'Başarılı ödeme' },
  { value: 'tok_3ds', label: '3D Secure adımı ister' },
  { value: 'tok_decline', label: 'Banka reddeder' },
  { value: 'tok_timeout', label: 'Sağlayıcı yanıt vermez' },
]

const usingTestGateway = computed(() => methods.value.some(method => method.gateway === 'fake'))
const transferAvailable = computed(() => methods.value.some(method => method.gateway === 'bank_transfer'))

/** Which method the customer picked. Card unless they say otherwise. */
const chosenGateway = ref<'card' | 'bank_transfer'>('card')

const bankAccounts = ref<BankAccountOption[]>([])
const chosenAccountId = ref<string | null>(null)

async function loadBankAccounts() {
  try {
    const response = await api.get<{ data: BankAccountOption[] }>('/api/v1/bank-transfers/accounts')
    bankAccounts.value = response.data
    chosenAccountId.value = response.data[0]?.id ?? null
  } catch {
    // The page still works without the list; the option simply will not offer a choice
    // of account, and the server picks the default one.
  }
}

async function open() {
  loading.value = true
  loadError.value = null

  try {
    const [openedSession, methodList] = await Promise.all([
      purpose.value === 'credits'
        ? api.get<{ data: CheckoutSession | null }>('/api/v1/checkout', { purpose: 'credits' })
        : api.post<{ data: CheckoutSession }>('/api/v1/checkout', {
            shipping_address_id: selectedAddressId.value,
          }),
      api.get<{ data: PaymentMethodOption[] }>('/api/v1/checkout/methods'),
    ])

    session.value = openedSession.data
    methods.value = methodList.data

    if (session.value?.shipping_address) {
      selectedAddressId.value = (session.value.shipping_address.id as string | null) ?? null
    }
  } catch (error) {
    loadError.value = error instanceof ApiError ? error.message : 'Ödeme adımı açılamadı.'
  } finally {
    loading.value = false
  }
}

async function loadAddresses() {
  if (purpose.value === 'credits') {
    // Nothing is shipped, so there is nothing to ask.
    return
  }

  try {
    const response = await api.get<{ data: Address[] }>('/api/v1/addresses')
    addresses.value = response.data
  } catch {
    // A checkout that already has an address snapshot does not need this list to work;
    // failing to fetch it should not take the payment page down with it.
  }
}

await Promise.all([open(), loadAddresses(), loadBankAccounts()])

/** Re-opens the session against a different address, re-snapshotting it. */
async function changeAddress(id: string) {
  selectedAddressId.value = id
  await open()
}

async function pay() {
  paying.value = true
  payError.value = null

  const attemptKey = `pay-${session.value?.id ?? 'none'}-${crypto.randomUUID()}`

  try {
    const response = await api.request<{ data: { payment: NonNullable<CheckoutSession['payment']>, session: CheckoutSession } }>(
      '/api/v1/checkout/pay',
      {
        method: 'POST',
        body: {
          purpose: purpose.value,
          gateway: chosenGateway.value === 'bank_transfer' ? 'bank_transfer' : undefined,
          bank_account_id: chosenGateway.value === 'bank_transfer' ? chosenAccountId.value : undefined,
          payment_token: chosenGateway.value === 'card' && usingTestGateway.value ? testToken.value : undefined,
        },
        /*
         * A key the client generates once per attempt. If the connection drops and the
         * app retries, the server replays its first answer instead of starting a second
         * payment — the one mistake on this page that costs real money.
         *
         * Generated when the attempt begins rather than per request, so a retry of *this*
         * attempt carries the same key while a fresh press of the button gets a new one.
         */
        headers: { 'Idempotency-Key': attemptKey },
      },
    )

    const payment = response.data.payment

    if (chosenGateway.value === 'bank_transfer' && payment.reference) {
      // No bank to redirect to: the customer is shown where to send the money and what
      // to write in the description.
      await router.push(`/checkout/transfer/${payment.reference}`)

      return
    }

    if (payment.status === 'requires_action' && payment.redirect_url) {
      // Off to the bank. The answer comes back as a webhook whether or not the browser
      // ever returns, which is why the return page asks rather than assumes.
      window.location.href = payment.redirect_url

      return
    }

    await router.push(`/checkout/return?payment=${payment.id}`)
  } catch (error) {
    payError.value = error instanceof ApiError ? error.message : 'Ödeme başlatılamadı.'
    await open()
  } finally {
    paying.value = false
  }
}

async function cancel() {
  paying.value = true

  try {
    await api.request('/api/v1/checkout', { method: 'DELETE', query: { purpose: purpose.value } })
    await router.push(purpose.value === 'credits' ? '/account/credits' : '/cart')
  } catch (error) {
    payError.value = error instanceof ApiError ? error.message : 'İptal edilemedi.'
  } finally {
    paying.value = false
  }
}

function money(minor: number, currency = 'TRY'): string {
  return new Intl.NumberFormat('tr-TR', { style: 'currency', currency }).format(minor / 100)
}

const addressLine = (address: Record<string, string | null> | null): string =>
  address === null
    ? ''
    : [address.address_line1, address.district, address.city].filter(Boolean).join(', ')
</script>

<template>
  <div class="rc-container py-10 lg:py-14">
    <h1 class="text-2xl font-medium">Ödeme</h1>

    <p v-if="loading" class="mt-6 text-sm text-muted">Yükleniyor…</p>

    <RcAlert v-else-if="loadError" tone="danger" class="mt-6">
      {{ loadError }}
      <NuxtLink to="/cart" class="ml-1 underline">Sepete dön</NuxtLink>
    </RcAlert>

    <RcAlert v-else-if="!session" tone="info" class="mt-6">
      Ödenecek bir şey bulunamadı.
      <NuxtLink to="/cart" class="ml-1 underline">Sepete dön</NuxtLink>
    </RcAlert>

    <div v-else class="mt-8 grid gap-8 lg:grid-cols-[1fr_340px]">
      <div class="space-y-6">
        <!-- Delivery: only when something is actually being delivered. -->
        <section v-if="session.purpose === 'cart'" class="rc-card p-5 sm:p-6">
          <h2 class="text-sm font-medium">Teslimat adresi</h2>

          <p v-if="session.shipping_address" class="mt-2 text-sm">
            <span class="font-medium">{{ session.shipping_address.recipient_name }}</span><br>
            <span class="text-ink-secondary">{{ addressLine(session.shipping_address) }}</span>
          </p>

          <div v-if="addresses.length > 1" class="mt-4">
            <label for="address" class="text-xs text-muted">Başka bir adres seçin</label>
            <select
              id="address"
              v-model="selectedAddressId"
              class="mt-1 w-full rounded-sm border border-line bg-surface px-3 py-2 text-sm"
              :disabled="paying"
              @change="changeAddress(String(selectedAddressId))"
            >
              <option v-for="address in addresses" :key="address.id" :value="address.id">
                {{ address.label || address.recipient_name }} — {{ address.city }}
              </option>
            </select>
          </div>

          <p v-else-if="addresses.length === 0" class="mt-3 text-sm text-muted">
            <NuxtLink to="/account/addresses" class="underline">Bir adres ekleyin</NuxtLink>.
          </p>
        </section>

        <section class="rc-card p-5 sm:p-6">
          <h2 class="text-sm font-medium">Sipariş özeti</h2>

          <ul class="mt-4 divide-y divide-line">
            <li v-for="(line, index) in session.lines" :key="index" class="flex justify-between gap-4 py-3">
              <div class="min-w-0">
                <p class="text-sm">{{ line.name }}</p>
                <p class="text-xs text-muted">
                  {{ line.quantity }} adet
                  <template v-if="line.credits">
                    · {{ line.credits }} kredi<template v-if="line.bonus_credits"> + {{ line.bonus_credits }} hediye</template>
                  </template>
                </p>
              </div>
              <p class="shrink-0 text-sm tabular-nums">
                {{ money(line.line_total_minor, session.currency) }}
              </p>
            </li>
          </ul>
        </section>

        <section class="rc-card p-5 sm:p-6">
          <h2 class="text-sm font-medium">Ödeme yöntemi</h2>

          <!-- Card or transfer. Two clear options rather than a hidden default. -->
          <fieldset v-if="transferAvailable" class="mt-3 space-y-2">
            <legend class="sr-only">Ödeme yöntemi</legend>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="chosenGateway" type="radio" value="card" :disabled="paying">
              Kredi/banka kartı
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="chosenGateway" type="radio" value="bank_transfer" :disabled="paying">
              Havale / EFT
            </label>
          </fieldset>

          <!--
            What a transfer actually costs the customer in time, said before they choose
            rather than after. A method that quietly takes two days is a support ticket.
          -->
          <div v-if="chosenGateway === 'bank_transfer'" class="mt-4 space-y-3">
            <p class="rounded-sm bg-bg-muted px-3 py-2 text-xs text-ink-secondary">
              Havale/EFT ile ödemede ürünleriniz iki gün boyunca sizin için ayrılır.
              Ödemeniz bankadan görüldüğünde siparişiniz onaylanır.
            </p>

            <div v-if="bankAccounts.length > 1">
              <label for="bank-account" class="text-xs text-muted">Hangi hesaba göndereceksiniz?</label>
              <select
                id="bank-account"
                v-model="chosenAccountId"
                class="mt-1 w-full rounded-sm border border-line bg-surface px-3 py-2 text-sm"
                :disabled="paying"
              >
                <option v-for="account in bankAccounts" :key="account.id" :value="account.id">
                  {{ account.bank_name }} — {{ account.iban }}
                </option>
              </select>
            </div>
          </div>

          <!--
            The test provider announces itself. A payment form that looks real but is not
            is the one thing on this page that would be genuinely dangerous to leave
            ambiguous.
          -->
          <template v-if="chosenGateway === 'card' && usingTestGateway">
            <p class="mt-2 rounded-sm bg-warning-subtle px-3 py-2 text-xs text-warning-strong">
              Bu ortamda test ödeme sağlayıcısı kullanılıyor. Gerçek kart bilgisi girilmez
              ve hiçbir tutar tahsil edilmez.
            </p>

            <fieldset class="mt-4 space-y-2">
              <legend class="sr-only">Test kartı</legend>
              <label v-for="card in testCards" :key="card.value" class="flex items-center gap-2 text-sm">
                <input v-model="testToken" type="radio" :value="card.value" :disabled="paying">
                {{ card.label }}
              </label>
            </fieldset>
          </template>

          <p v-else-if="chosenGateway === 'card'" class="mt-2 text-sm text-ink-secondary">
            Kart bilgileriniz ödeme sağlayıcısının kendi sayfasında alınır; RefConcept kart
            numaranızı hiçbir zaman görmez ve saklamaz.
          </p>
        </section>
      </div>

      <aside class="rc-card h-fit p-5 sm:p-6">
        <h2 class="text-sm font-medium">Toplam</h2>

        <dl class="mt-4 space-y-2 text-sm">
          <div class="flex justify-between gap-4">
            <dt class="text-ink-secondary">Ara toplam</dt>
            <dd class="tabular-nums">{{ money(session.totals.subtotal_minor, session.currency) }}</dd>
          </div>
          <div v-if="session.totals.shipping_minor > 0" class="flex justify-between gap-4">
            <dt class="text-ink-secondary">Kargo</dt>
            <dd class="tabular-nums">{{ money(session.totals.shipping_minor, session.currency) }}</dd>
          </div>
          <!--
            KDV is contained in the price in Turkey, not added to it. Listing it as an
            extra line to be summed would inflate every total by a fifth.
          -->
          <div class="flex justify-between gap-4 text-muted">
            <dt>Dâhil KDV</dt>
            <dd class="tabular-nums">{{ money(session.totals.tax_minor, session.currency) }}</dd>
          </div>
          <div class="flex justify-between gap-4 border-t border-line pt-2 text-base font-medium">
            <dt>Ödenecek</dt>
            <dd class="tabular-nums">{{ money(session.totals.grand_total_minor, session.currency) }}</dd>
          </div>
        </dl>

        <RcAlert v-if="payError" tone="danger" class="mt-4">{{ payError }}</RcAlert>

        <RcButton
          class="mt-4 w-full"
          :loading="paying"
          :disabled="paying || session.totals.grand_total_minor <= 0"
          @click="pay"
        >
          <template v-if="chosenGateway === 'bank_transfer'">Havale bilgilerini al</template>
          <template v-else>{{ money(session.totals.grand_total_minor, session.currency) }} öde</template>
        </RcButton>

        <RcButton class="mt-2 w-full" variant="ghost" :disabled="paying" @click="cancel">
          Vazgeç
        </RcButton>

        <p v-if="session.purpose === 'cart'" class="mt-4 text-xs text-muted">
          Ürünleriniz ödeme tamamlanana kadar sizin için ayrıldı. Vazgeçerseniz hemen
          serbest bırakılır.
        </p>
      </aside>
    </div>
  </div>
</template>
