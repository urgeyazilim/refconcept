<script setup lang="ts">
import { ApiError } from '~/composables/useApi'

useHead({ title: 'E-posta doğrulama' })

const route = useRoute()
const api = useApi()
const { user, isAuthenticated, fetchUser } = useAuth()

type State = 'idle' | 'verifying' | 'success' | 'failed'

const state = ref<State>('idle')
const message = ref<string | null>(null)
const resending = ref(false)
const resendNotice = ref<string | null>(null)

/**
 * The verification link lands here with ?token=... The exchange happens on the client
 * so the result is visible in the UI; the API never redirects the browser itself.
 */
onMounted(async () => {
  const token = route.query.token

  if (typeof token !== 'string' || token === '') {
    return
  }

  state.value = 'verifying'

  try {
    await api.post('/api/v1/auth/email/verify', { token })
    state.value = 'success'

    // Refresh the cached user so the header stops showing the unverified banner.
    if (isAuthenticated.value) {
      await fetchUser()
    }
  } catch (error) {
    state.value = 'failed'
    message.value = error instanceof ApiError
      ? (error.fieldError('token') ?? error.message)
      : 'Doğrulama tamamlanamadı.'
  }
})

async function resend() {
  resending.value = true
  resendNotice.value = null

  try {
    const response = await api.post<{ message: string }>('/api/v1/auth/email/resend')
    resendNotice.value = response.message
  } catch (error) {
    resendNotice.value = error instanceof ApiError
      ? error.message
      : 'E-posta gönderilemedi.'
  } finally {
    resending.value = false
  }
}
</script>

<template>
  <RcAuthCard
    v-if="state === 'success'"
    title="E-postanız doğrulandı"
    subtitle="Hesabınız etkinleşti."
  >
    <div class="space-y-5">
      <RcAlert tone="success">Artık tüm özellikleri kullanabilirsiniz.</RcAlert>
      <RcButton :to="isAuthenticated ? '/account' : '/auth/login?verified=1'" block size="lg">
        {{ isAuthenticated ? 'Hesabıma git' : 'Giriş yap' }}
      </RcButton>
    </div>
  </RcAuthCard>

  <RcAuthCard
    v-else-if="state === 'verifying'"
    title="Doğrulanıyor"
    subtitle="Bağlantınız kontrol ediliyor."
  >
    <div class="flex items-center gap-3 text-sm text-ink-secondary">
      <svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2.5" opacity="0.25" />
        <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
      </svg>
      Lütfen bekleyin…
    </div>
  </RcAuthCard>

  <RcAuthCard
    v-else-if="state === 'failed'"
    title="Bağlantı geçersiz"
    subtitle="Doğrulama bağlantısı kullanılmış veya süresi dolmuş olabilir."
  >
    <div class="space-y-5">
      <RcAlert tone="danger">{{ message }}</RcAlert>

      <template v-if="isAuthenticated">
        <RcAlert v-if="resendNotice" tone="info">{{ resendNotice }}</RcAlert>
        <RcButton block :loading="resending" @click="resend">Yeni bağlantı gönder</RcButton>
      </template>
      <RcButton v-else to="/auth/login" variant="secondary" block>Giriş yap</RcButton>
    </div>
  </RcAuthCard>

  <RcAuthCard
    v-else
    title="E-postanızı doğrulayın"
    :subtitle="user?.email ? `${user.email} adresine gönderdiğimiz bağlantıya tıklayın.` : 'Size gönderdiğimiz bağlantıya tıklayın.'"
  >
    <div class="space-y-5">
      <p class="text-sm leading-relaxed text-ink-secondary">
        Doğrulama tamamlanmadan proje oluşturamaz, adres ekleyemez ve sipariş veremezsiniz.
      </p>

      <template v-if="isAuthenticated">
        <RcAlert v-if="resendNotice" tone="info">{{ resendNotice }}</RcAlert>
        <RcButton block :loading="resending" @click="resend">Yeniden gönder</RcButton>
      </template>
      <RcButton v-else to="/auth/login" variant="secondary" block>Giriş yap</RcButton>
    </div>
  </RcAuthCard>
</template>
