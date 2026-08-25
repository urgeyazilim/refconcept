<script setup lang="ts">
/**
 * Coming back from the bank.
 *
 * The page **asks** rather than assumes, and that is the whole point of it. A customer
 * arriving here proves only that a browser was redirected — not that a payment succeeded,
 * not that it failed, and certainly not that our database has heard about it yet. The
 * webhook may land a second before this page loads or a minute after, and half the time
 * the browser gets here first.
 *
 * So it polls, briefly, and says what it actually knows at each moment: still waiting,
 * done, or refused with the bank's own reason. Guessing from the redirect alone is how a
 * page tells somebody their payment failed while the money is already gone.
 */
import type { PaymentSummary } from '@refconcept/ui/types'

definePageMeta({ middleware: ['auth', 'verified'] })
useHead({ title: 'Ödeme sonucu' })

const api = useApi()
const route = useRoute()

const paymentId = computed(() => String(route.query.payment ?? ''))

const payment = ref<PaymentSummary | null>(null)
const loadError = ref<string | null>(null)

/*
 * Twenty attempts, two seconds apart — about forty seconds.
 *
 * Long enough for a webhook that is merely queued behind something, short enough that a
 * customer is not left staring at a spinner. When it runs out the page says the payment
 * is still being confirmed rather than that it failed, because those are different
 * sentences and only one of them is true.
 */
const MAX_ATTEMPTS = 20
const INTERVAL_MS = 2000

const attempts = ref(0)
const settled = computed(() =>
  payment.value !== null
  && ['captured', 'partially_refunded', 'refunded', 'failed', 'cancelled', 'expired'].includes(payment.value.status),
)

const waiting = computed(() => !settled.value && attempts.value < MAX_ATTEMPTS)
const timedOut = computed(() => !settled.value && attempts.value >= MAX_ATTEMPTS)

const succeeded = computed(() =>
  payment.value !== null && ['captured', 'partially_refunded'].includes(payment.value.status),
)

async function check() {
  if (paymentId.value === '') {
    loadError.value = 'Ödeme bilgisi bulunamadı.'

    return
  }

  try {
    const response = await api.get<{ data: { payment: PaymentSummary } }>(
      `/api/v1/payments/${paymentId.value}`,
    )

    payment.value = response.data.payment
  } catch (error) {
    loadError.value = error instanceof ApiError ? error.message : 'Ödeme durumu okunamadı.'
  } finally {
    attempts.value += 1
  }
}

await check()

let timer: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  if (settled.value || loadError.value !== null) {
    return
  }

  timer = setInterval(async () => {
    await check()

    if ((settled.value || attempts.value >= MAX_ATTEMPTS) && timer !== null) {
      clearInterval(timer)
      timer = null
    }
  }, INTERVAL_MS)
})

onBeforeUnmount(() => {
  if (timer !== null) {
    clearInterval(timer)
  }
})

function money(minor: number, currency = 'TRY'): string {
  return new Intl.NumberFormat('tr-TR', { style: 'currency', currency }).format(minor / 100)
}
</script>

<template>
  <div class="rc-container py-16">
    <div class="mx-auto max-w-lg text-center">
      <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

      <template v-else-if="payment">
        <template v-if="succeeded">
          <h1 class="text-2xl font-medium">Ödemeniz alındı</h1>
          <p class="mt-3 text-sm text-ink-secondary">
            {{ money(payment.captured_minor || payment.amount_minor, payment.currency) }} tutarındaki
            ödemeniz onaylandı.
          </p>
          <!-- The reference is what both a bank statement and our support desk refer to. -->
          <p v-if="payment.reference" class="mt-1 text-xs text-muted">
            İşlem numarası: {{ payment.reference }}
          </p>

          <div class="mt-8 flex flex-wrap justify-center gap-3">
            <RcButton to="/account/credits" variant="ghost">Kredilerim</RcButton>
            <RcButton to="/catalog">Alışverişe devam et</RcButton>
          </div>
        </template>

        <template v-else-if="waiting">
          <h1 class="text-2xl font-medium">Ödemeniz doğrulanıyor</h1>
          <p class="mt-3 text-sm text-ink-secondary">
            Bankanızdan onay bekleniyor. Bu sayfayı kapatmayın; birkaç saniye sürebilir.
          </p>
          <p class="mt-6 text-xs text-muted">Durum: {{ payment.status_label }}</p>
        </template>

        <template v-else-if="timedOut">
          <!--
            Not "failed". The payment may well have succeeded and the confirmation may
            simply be behind something; telling somebody it failed when it did not is how
            they pay a second time.
          -->
          <h1 class="text-2xl font-medium">Ödemeniz hâlâ doğrulanıyor</h1>
          <p class="mt-3 text-sm text-ink-secondary">
            Bankanızdan yanıt beklenmeye devam ediyor. Tutar hesabınızdan çekildiyse
            siparişiniz kısa süre içinde onaylanacak; ikinci bir ödeme yapmayın.
          </p>
          <RcButton class="mt-6" variant="ghost" @click="check">Yeniden kontrol et</RcButton>
        </template>

        <template v-else>
          <h1 class="text-2xl font-medium">Ödeme tamamlanamadı</h1>
          <p class="mt-3 text-sm text-ink-secondary">
            {{ payment.failure_message ?? 'Bankanız ödemeyi onaylamadı.' }}
          </p>
          <p class="mt-2 text-xs text-muted">
            Sepetiniz ve adres bilgileriniz duruyor; başka bir kartla tekrar deneyebilirsiniz.
          </p>

          <div class="mt-8 flex flex-wrap justify-center gap-3">
            <RcButton to="/cart" variant="ghost">Sepete dön</RcButton>
            <RcButton to="/checkout">Tekrar dene</RcButton>
          </div>
        </template>
      </template>
    </div>
  </div>
</template>
