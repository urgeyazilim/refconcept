<script setup lang="ts">
definePageMeta({ middleware: 'guest' })
useHead({ title: 'Hesap oluştur' })

const { register } = useAuth()
const config = useRuntimeConfig()

const form = reactive({
  first_name: '',
  last_name: '',
  email: '',
  password: '',
  password_confirmation: '',
  privacy: false,
  terms: false,
  marketing: false,
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)
const registeredEmail = ref<string | null>(null)

/**
 * Consent versions come from the API's configuration, echoed here so the accepted
 * version is recorded exactly as it was shown. Hard-coding a version in the client
 * would let the two drift and make the consent record unprovable.
 */
const consentVersion = '2026-01'

async function onSubmit() {
  errors.value = {}
  generalError.value = null

  // Checked separately from the API so the two mandatory boxes get inline messages
  // instead of one collective error on the consents array.
  if (!form.privacy) errors.value.privacy = ['Gizlilik bildirimini onaylamanız gerekiyor.']
  if (!form.terms) errors.value.terms = ['Kullanım koşullarını onaylamanız gerekiyor.']
  if (Object.keys(errors.value).length > 0) return

  submitting.value = true

  try {
    const consents = [
      { type: 'privacy_notice', version: consentVersion },
      { type: 'terms', version: consentVersion },
    ]

    if (form.marketing) {
      consents.push({ type: 'marketing', version: consentVersion })
    }

    await register({
      first_name: form.first_name || null,
      last_name: form.last_name || null,
      email: form.email,
      password: form.password,
      password_confirmation: form.password_confirmation,
      marketing_opt_in: form.marketing,
      consents,
    })

    registeredEmail.value = form.email
  } catch (error) {
    if (error instanceof ApiError && error.isValidation) {
      errors.value = error.errors

      // The API reports missing consent on the array; surface it where it is visible.
      if (error.errors.consents) {
        generalError.value = error.errors.consents[0] ?? null
      }
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
    v-if="!registeredEmail"
    title="Hesap oluştur"
    subtitle="Odanızı yükleyin, yapay zekâ size özel bir tasarım üretsin."
  >
    <form class="space-y-5" novalidate @submit.prevent="onSubmit">
      <RcAlert v-if="generalError" tone="danger">{{ generalError }}</RcAlert>

      <div class="grid gap-5 sm:grid-cols-2">
        <RcField
          v-model="form.first_name"
          label="Ad"
          name="first_name"
          autocomplete="given-name"
          :errors="errors.first_name"
        />
        <RcField
          v-model="form.last_name"
          label="Soyad"
          name="last_name"
          autocomplete="family-name"
          :errors="errors.last_name"
        />
      </div>

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
        autocomplete="new-password"
        required
        hint="En az 12 karakter; büyük/küçük harf ve rakam içermeli."
        :errors="errors.password"
      />

      <RcField
        v-model="form.password_confirmation"
        label="Parola tekrar"
        name="password_confirmation"
        type="password"
        autocomplete="new-password"
        required
      />

      <div class="space-y-3 border-t border-line pt-5">
        <RcField
          v-model="form.privacy"
          type="checkbox"
          label=""
          name="privacy"
          required
          :errors="errors.privacy"
        >
          <template #label>
            <NuxtLink to="/legal/privacy" class="underline underline-offset-2">Aydınlatma metnini</NuxtLink>
            okudum, kişisel verilerimin işlenmesini onaylıyorum.
          </template>
        </RcField>

        <RcField
          v-model="form.terms"
          type="checkbox"
          label=""
          name="terms"
          required
          :errors="errors.terms"
        >
          <template #label>
            <NuxtLink to="/legal/terms" class="underline underline-offset-2">Kullanım koşullarını</NuxtLink>
            kabul ediyorum.
          </template>
        </RcField>

        <!-- Kept separate and optional: bundling marketing consent with the mandatory
             ones is exactly what KVKK forbids. -->
        <RcField
          v-model="form.marketing"
          type="checkbox"
          label="Kampanya ve yeniliklerden e-posta ile haberdar olmak istiyorum. (isteğe bağlı)"
          name="marketing"
        />
      </div>

      <RcButton type="submit" block size="lg" :loading="submitting">
        Hesabımı oluştur
      </RcButton>
    </form>

    <template #footer>
      Zaten hesabınız var mı?
      <NuxtLink to="/auth/login" class="font-medium text-ink underline underline-offset-4">
        Giriş yapın
      </NuxtLink>
    </template>
  </RcAuthCard>

  <RcAuthCard
    v-else
    title="E-postanızı kontrol edin"
    :subtitle="`${registeredEmail} adresine bir doğrulama bağlantısı gönderdik.`"
  >
    <div class="space-y-5">
      <RcAlert tone="success">
        Hesabınız oluşturuldu. Bağlantıya tıkladığınızda hesabınız etkinleşecek.
      </RcAlert>

      <p class="text-sm leading-relaxed text-ink-secondary">
        E-posta birkaç dakika içinde gelmezse spam klasörünü kontrol edin.
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
