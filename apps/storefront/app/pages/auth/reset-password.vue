<script setup lang="ts">
import { ApiError } from '~/composables/useApi'

definePageMeta({ middleware: 'guest' })
useHead({ title: 'Yeni parola belirle' })

const route = useRoute()
const api = useApi()

const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''))

const form = reactive({ password: '', password_confirmation: '' })
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

async function onSubmit() {
  errors.value = {}
  generalError.value = null
  submitting.value = true

  try {
    await api.post('/api/v1/auth/password/reset', {
      token: token.value,
      password: form.password,
      password_confirmation: form.password_confirmation,
    })

    // Every session was revoked server-side, so signing in again is the only path.
    await navigateTo('/auth/login?reset=1')
  } catch (error) {
    if (error instanceof ApiError && error.isValidation) {
      errors.value = error.errors
      generalError.value = error.fieldError('token') ?? null
    } else if (error instanceof ApiError) {
      generalError.value = error.message
    } else {
      generalError.value = 'Beklenmeyen bir hata oluştu.'
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <RcAuthCard
    v-if="token"
    title="Yeni parola belirle"
    subtitle="Yeni parolanızı girin; ardından tekrar giriş yapmanız gerekecek."
  >
    <form class="space-y-5" novalidate @submit.prevent="onSubmit">
      <RcAlert v-if="generalError" tone="danger">{{ generalError }}</RcAlert>

      <RcField
        v-model="form.password"
        label="Yeni parola"
        name="password"
        type="password"
        autocomplete="new-password"
        required
        hint="En az 12 karakter; büyük/küçük harf ve rakam içermeli."
        :errors="errors.password"
      />

      <RcField
        v-model="form.password_confirmation"
        label="Yeni parola tekrar"
        name="password_confirmation"
        type="password"
        autocomplete="new-password"
        required
      />

      <RcButton type="submit" block size="lg" :loading="submitting">Parolamı güncelle</RcButton>
    </form>
  </RcAuthCard>

  <RcAuthCard
    v-else
    title="Bağlantı eksik"
    subtitle="Bu sayfaya e-postanızdaki bağlantı üzerinden ulaşmanız gerekiyor."
  >
    <RcButton to="/auth/forgot-password" block>Yeni bağlantı iste</RcButton>
  </RcAuthCard>
</template>
