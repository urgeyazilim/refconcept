<script setup lang="ts">
definePageMeta({ layout: false, middleware: 'guest' })
useHead({ title: 'Yönetim girişi' })

const { login } = useAuth()
const route = useRoute()

const form = reactive({ email: '', password: '' })
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

async function onSubmit() {
  errors.value = {}
  generalError.value = null
  submitting.value = true

  try {
    await login(form.email, form.password)

    // Same-origin paths only; an absolute URL here would be an open redirect.
    const target = String(route.query.redirect ?? '/')
    await navigateTo(target.startsWith('/') ? target : '/')
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
  <div data-rc-theme="operational" class="min-h-screen bg-bg">
    <RcAuthCard
      title="Yönetim girişi"
      subtitle="RefConcept süper admin paneline hoş geldiniz."
    >
      <form class="space-y-5" novalidate @submit.prevent="onSubmit">
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

        <RcField
          v-model="form.password"
          label="Parola"
          name="password"
          type="password"
          autocomplete="current-password"
          required
          :errors="errors.password"
        />

        <RcButton type="submit" block size="lg" :loading="submitting">Giriş yap</RcButton>
      </form>

      <template #footer>
        Bu panel yalnızca platform ekibi içindir.
      </template>
    </RcAuthCard>
  </div>
</template>
