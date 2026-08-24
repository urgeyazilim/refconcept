<script setup lang="ts">
/**
 * Accepting an invitation to somebody's project.
 *
 * The link carries a one-time token, and the API refuses it unless the signed-in
 * account's e-mail matches the address it was sent to. That check is what stops a
 * forwarded link letting anybody who received it into a stranger's home, and the
 * message here says so plainly rather than reporting a generic failure.
 */
definePageMeta({ middleware: ['auth', 'verified'], layout: 'account' })
useHead({ title: 'Davet' })

const route = useRoute()
const api = useApi()

const memberId = route.query.member as string | undefined
const token = route.query.token as string | undefined

const state = ref<'ready' | 'working' | 'accepted' | 'failed'>('ready')
const message = ref<string | null>(null)
const projectId = ref<string | null>(null)

if (!memberId || !token) {
  state.value = 'failed'
  message.value = 'Bu bağlantı eksik görünüyor. Sizi davet eden kişiden yeniden göndermesini isteyin.'
}

async function accept() {
  state.value = 'working'
  message.value = null

  try {
    const response = await api.post<{ data: { project_id: string } }>(
      '/api/v1/projects/invitations/accept',
      { member_id: memberId, token },
    )

    projectId.value = response.data.project_id
    state.value = 'accepted'
  } catch (error) {
    state.value = 'failed'

    message.value = error instanceof ApiError
      ? (error.fieldError('token') ?? error.message)
      : 'Davet kabul edilemedi.'
  }
}
</script>

<template>
  <div class="mx-auto max-w-lg">
    <section class="rc-card p-8 text-center">
      <template v-if="state === 'accepted'">
        <RcFeatureIcon class="mx-auto" size="lg" icon="m5 13 4 4L19 7" />
        <h1 class="mt-5 text-xl font-medium">Davet kabul edildi</h1>
        <p class="mt-3 leading-relaxed text-ink-secondary">
          Proje artık projeleriniz arasında görünüyor.
        </p>
        <RcButton :to="`/projects/${projectId}`" class="mt-7">Projeyi aç</RcButton>
      </template>

      <template v-else-if="state === 'failed'">
        <h1 class="text-xl font-medium">Davet kullanılamadı</h1>
        <p class="mt-3 leading-relaxed text-ink-secondary">{{ message }}</p>
        <p class="mt-3 text-sm leading-relaxed text-muted">
          Davetler yalnızca gönderildikleri e-posta adresiyle giriş yapan kişi
          tarafından kullanılabilir ve iki hafta sonra geçerliliğini yitirir.
        </p>
        <RcButton to="/projects" variant="secondary" class="mt-7">Projelerime dön</RcButton>
      </template>

      <template v-else>
        <RcFeatureIcon
          class="mx-auto"
          size="lg"
          icon="M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20M10 11.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z"
        />
        <h1 class="mt-5 text-xl font-medium">Bir projeye davet edildiniz</h1>
        <p class="mt-3 leading-relaxed text-ink-secondary">
          Kabul ettiğinizde projeye ve odalarının fotoğraflarına erişebileceksiniz.
        </p>
        <RcButton class="mt-7" :loading="state === 'working'" :disabled="state === 'working'" @click="accept">
          Daveti kabul et
        </RcButton>
      </template>
    </section>
  </div>
</template>
