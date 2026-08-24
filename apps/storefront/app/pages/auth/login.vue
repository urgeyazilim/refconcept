<script setup lang="ts">
import { ApiError } from '~/composables/useApi'

definePageMeta({ middleware: 'guest' })
useHead({ title: 'Giriş yap' })

const { login } = useAuth()
const route = useRoute()

const form = reactive({ email: '', password: '' })
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

/** Shown after a completed password reset so the user knows why they are here. */
const notice = computed(() => {
  if (route.query.reset === '1') return 'Parolanız güncellendi. Yeni parolanızla giriş yapın.'
  if (route.query.verified === '1') return 'E-posta adresiniz doğrulandı. Artık giriş yapabilirsiniz.'

  return null
})

async function onSubmit() {
  errors.value = {}
  generalError.value = null
  submitting.value = true

  try {
    await login(form.email, form.password)

    // Only same-origin paths are honoured; an absolute URL in ?redirect would make
    // this an open redirect that phishing could point anywhere.
    const target = String(route.query.redirect ?? '/account')
    await navigateTo(target.startsWith('/') ? target : '/account')
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
  <RcAuthCard title="Giriş yap" subtitle="Projelerinize ve tasarımlarınıza devam edin.">
    <form class="space-y-5" novalidate @submit.prevent="onSubmit">
      <RcAlert v-if="notice" tone="success">{{ notice }}</RcAlert>
      <RcAlert v-if="generalError" tone="danger">{{ generalError }}</RcAlert>

      <RcField
        v-model="form.email"
        label="E-posta"
        name="email"
        type="email"
        autocomplete="email"
        required
        :errors="errors.email"
      />

      <div>
        <RcField
          v-model="form.password"
          label="Parola"
          name="password"
          type="password"
          autocomplete="current-password"
          required
          :errors="errors.password"
        />
        <div class="mt-2 text-right">
          <NuxtLink
            to="/auth/forgot-password"
            class="text-xs text-muted underline-offset-4 hover:text-ink hover:underline"
          >
            Parolamı unuttum
          </NuxtLink>
        </div>
      </div>

      <RcButton type="submit" block size="lg" :loading="submitting">Giriş yap</RcButton>
    </form>

    <template #footer>
      Hesabınız yok mu?
      <NuxtLink to="/auth/register" class="font-medium text-ink underline underline-offset-4">
        Ücretsiz oluşturun
      </NuxtLink>
    </template>
  </RcAuthCard>
</template>
