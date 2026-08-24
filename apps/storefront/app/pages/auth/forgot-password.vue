<script setup lang="ts">
import { ApiError } from '~/composables/useApi'

definePageMeta({ middleware: 'guest' })
useHead({ title: 'Parolamı unuttum' })

const api = useApi()
const config = useRuntimeConfig()

const email = ref('')
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)
const sent = ref(false)

async function onSubmit() {
  errors.value = {}
  generalError.value = null
  submitting.value = true

  try {
    await api.post('/api/v1/auth/password/forgot', { email: email.value })

    // The API answers identically whether or not the address is registered, and so
    // does this screen: showing "no such account" here would leak the same fact.
    sent.value = true
  } catch (error) {
    if (error instanceof ApiError && error.isValidation) {
      errors.value = error.errors
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
    v-if="!sent"
    title="Parolanızı sıfırlayın"
    subtitle="E-posta adresinizi girin, size bir sıfırlama bağlantısı gönderelim."
  >
    <form class="space-y-5" novalidate @submit.prevent="onSubmit">
      <RcAlert v-if="generalError" tone="danger">{{ generalError }}</RcAlert>

      <RcField
        v-model="email"
        label="E-posta"
        name="email"
        type="email"
        autocomplete="email"
        required
        :errors="errors.email"
      />

      <RcButton type="submit" block size="lg" :loading="submitting">
        Sıfırlama bağlantısı gönder
      </RcButton>
    </form>

    <template #footer>
      <NuxtLink to="/auth/login" class="font-medium text-ink underline underline-offset-4">
        Giriş ekranına dön
      </NuxtLink>
    </template>
  </RcAuthCard>

  <RcAuthCard
    v-else
    title="E-postanızı kontrol edin"
    subtitle="Bu adres kayıtlıysa sıfırlama bağlantısını gönderdik."
  >
    <div class="space-y-5">
      <p class="text-sm leading-relaxed text-ink-secondary">
        Bağlantı 60 dakika geçerlidir. Sıfırlama tamamlandığında açık olan tüm
        oturumlarınız güvenlik gereği kapatılır.
      </p>

      <RcButton to="/auth/login" variant="secondary" block>Giriş ekranına dön</RcButton>

      <p v-if="config.public.mailUrl" class="text-center text-xs text-muted">
        Geliştirme ortamı:
        <a :href="config.public.mailUrl" target="_blank" rel="noopener" class="underline">
          gönderilen e-postaları görüntüle
        </a>
      </p>
    </div>
  </RcAuthCard>
</template>
