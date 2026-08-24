<script setup lang="ts">
import { ApiError } from '~/composables/useApi'

definePageMeta({ layout: 'account', middleware: 'auth' })
useHead({ title: 'Hesabım' })

const api = useApi()
const { user, fetchUser, displayName } = useAuth()

const form = reactive({
  first_name: user.value?.profile?.first_name ?? '',
  last_name: user.value?.profile?.last_name ?? '',
  display_name: user.value?.profile?.display_name ?? '',
  marketing_opt_in: user.value?.profile?.marketing_opt_in ?? false,
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const saved = ref(false)
const submitting = ref(false)

async function onSubmit() {
  errors.value = {}
  generalError.value = null
  saved.value = false
  submitting.value = true

  try {
    await api.patch('/api/v1/profile', {
      first_name: form.first_name || null,
      last_name: form.last_name || null,
      display_name: form.display_name || null,
      marketing_opt_in: form.marketing_opt_in,
    })

    await fetchUser()
    saved.value = true
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

const memberSince = computed(() => {
  if (!user.value?.created_at) return null

  return new Date(user.value.created_at).toLocaleDateString('tr-TR', {
    year: 'numeric',
    month: 'long',
  })
})
</script>

<template>
  <div class="space-y-6">
    <!-- Unverified accounts can read their profile but cannot act; say so plainly
         instead of letting them discover it at the first blocked action. -->
    <RcAlert v-if="user && !user.email_verified" tone="warning" title="E-postanız doğrulanmadı">
      Proje oluşturmak ve adres eklemek için
      <NuxtLink to="/auth/verify-email" class="underline underline-offset-2">
        e-posta adresinizi doğrulayın
      </NuxtLink>.
    </RcAlert>

    <section class="rc-card p-6 sm:p-8">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 class="text-xl font-medium">{{ displayName }}</h2>
          <p class="mt-1 text-sm text-muted">{{ user?.email }}</p>
        </div>
        <span
          class="rounded-pill px-3 py-1 text-xs font-medium"
          :class="user?.email_verified ? 'bg-success-subtle text-success-strong' : 'bg-warning-subtle text-warning-strong'"
        >
          {{ user?.status_label }}
        </span>
      </div>

      <dl v-if="memberSince" class="mt-6 border-t border-line pt-5 text-sm">
        <div class="flex justify-between">
          <dt class="text-muted">Üyelik başlangıcı</dt>
          <dd>{{ memberSince }}</dd>
        </div>
      </dl>
    </section>

    <section class="rc-card p-6 sm:p-8">
      <h3 class="mb-6 text-lg font-medium">Profil bilgileri</h3>

      <form class="space-y-5" novalidate @submit.prevent="onSubmit">
        <RcAlert v-if="generalError" tone="danger">{{ generalError }}</RcAlert>
        <RcAlert v-if="saved" tone="success">Profiliniz güncellendi.</RcAlert>

        <div class="grid gap-5 sm:grid-cols-2">
          <RcField v-model="form.first_name" label="Ad" name="first_name" :errors="errors.first_name" />
          <RcField v-model="form.last_name" label="Soyad" name="last_name" :errors="errors.last_name" />
        </div>

        <RcField
          v-model="form.display_name"
          label="Görünen ad"
          name="display_name"
          hint="Boş bırakırsanız ad ve soyadınız kullanılır."
          :errors="errors.display_name"
        />

        <RcField
          v-model="form.marketing_opt_in"
          type="checkbox"
          label="Kampanya ve yeniliklerden e-posta ile haberdar olmak istiyorum."
          name="marketing_opt_in"
        />

        <div class="flex justify-end">
          <RcButton type="submit" :loading="submitting">Kaydet</RcButton>
        </div>
      </form>
    </section>
  </div>
</template>
