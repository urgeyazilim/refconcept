<script setup lang="ts">
import type { Option, Paginated, ProjectSummary } from '@refconcept/ui/types'

/**
 * A customer's projects.
 *
 * The screen a signed-in customer lands on, and the entry point to everything the
 * product actually does. It is deliberately not a dashboard of statistics: somebody
 * with one flat and one living room should see their living room, not a chart about it.
 */
definePageMeta({ middleware: ['auth', 'verified'], layout: 'account' })
useSeo({ title: 'Projelerim', noindex: true })

const api = useApi()

const projects = ref<ProjectSummary[]>([])
const projectTypes = ref<Option[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)

const creating = ref(false)
const form = reactive({ name: '', project_type: 'home', budget: '' })
const saving = ref(false)
const formError = ref<string | null>(null)
const errors = ref<Record<string, string[]>>({})

async function load() {
  loading.value = true
  loadError.value = null

  try {
    const response = await api.get<Paginated<ProjectSummary> & { meta: { project_types: Option[] } }>(
      '/api/v1/projects',
    )

    projects.value = response.data
    projectTypes.value = response.meta.project_types
  } catch (error) {
    loadError.value = error instanceof ApiError ? error.message : 'Projeler yüklenemedi.'
  } finally {
    loading.value = false
  }
}

await load()

async function create() {
  saving.value = true
  formError.value = null
  errors.value = {}

  try {
    const payload: Record<string, unknown> = {
      name: form.name,
      project_type: form.project_type,
    }

    // Budget is typed the Turkish way and crosses the wire as integer minor units,
    // like every amount in RefConcept.
    const budget = inputToMinor(form.budget)

    if (budget !== null) payload.budget_minor = budget

    const response = await api.post<{ data: ProjectSummary }>('/api/v1/projects', payload)

    await navigateTo(`/projects/${response.data.id}`)
  } catch (error) {
    if (error instanceof ApiError && error.isValidation) {
      errors.value = error.errors
    } else {
      formError.value = error instanceof ApiError ? error.message : 'Proje oluşturulamadı.'
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="space-y-8">
    <header class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 class="text-2xl font-medium">Projelerim</h1>
        <p class="mt-1.5 max-w-[62ch] text-sm leading-relaxed text-ink-secondary">
          Bir proje, üzerinde çalıştığınız ev ya da mekândır. İçine odalarınızı ekler,
          fotoğraflarını yükler ve her oda için tasarımlar üretirsiniz.
        </p>
      </div>

      <RcButton v-if="!creating" @click="creating = true">Yeni proje</RcButton>
    </header>

    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <!-- Create -->
    <section v-if="creating" class="rc-card p-6 sm:p-8">
      <h2 class="text-lg font-medium">Yeni proje</h2>

      <RcAlert v-if="formError" tone="danger" class="mt-4">{{ formError }}</RcAlert>

      <form class="mt-5 space-y-5" @submit.prevent="create">
        <RcField
          v-model="form.name"
          label="Proje adı"
          name="name"
          placeholder="Örn. Kadıköy Dairesi"
          :errors="errors.name"
          required
        />

        <div>
          <label for="project_type" class="mb-1.5 block text-sm font-medium">Mekân türü</label>
          <select
            id="project_type"
            v-model="form.project_type"
            class="w-full rounded-sm border border-line bg-surface px-4 py-2.5 text-sm"
          >
            <option v-for="type in projectTypes" :key="type.value" :value="type.value">
              {{ type.label }}
            </option>
          </select>
          <p class="mt-1.5 text-xs text-muted">
            Kiralık bir evde sabit mobilya önerilmez; tür seçimi tasarımı etkiler.
          </p>
        </div>

        <RcField
          v-model="form.budget"
          label="Bütçe (₺)"
          name="budget"
          placeholder="Örn. 150.000"
          hint="İsteğe bağlı. Tasarım önerileri bütçenize göre şekillenir."
          :errors="errors.budget_minor"
        />

        <div class="flex items-center gap-3">
          <RcButton type="submit" :loading="saving" :disabled="saving">Projeyi oluştur</RcButton>
          <RcButton variant="ghost" @click="creating = false">Vazgeç</RcButton>
        </div>
      </form>
    </section>

    <p v-if="loading" class="text-sm text-muted">Yükleniyor…</p>

    <!-- Empty -->
    <div v-else-if="projects.length === 0 && !creating" class="rc-card p-12 text-center">
      <RcFeatureIcon
        class="mx-auto"
        size="lg"
        icon="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1z"
      />
      <h2 class="mt-5 text-lg font-medium">İlk projenizi oluşturun</h2>
      <p class="mx-auto mt-3 max-w-[52ch] leading-relaxed text-ink-secondary">
        Odanızın fotoğrafını yükleyin, ölçülerini girin; yapay zekâ o odaya uygun bir
        tasarım hazırlasın. Fotoğraflarınız yalnızca size aittir ve kimseyle paylaşılmaz.
      </p>
      <RcButton class="mt-7" @click="creating = true">Yeni proje</RcButton>
    </div>

    <!-- List -->
    <div v-else-if="projects.length > 0" class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
      <NuxtLink
        v-for="project in projects"
        :key="project.id"
        :to="`/projects/${project.id}`"
        class="rc-card flex flex-col p-6 transition-shadow hover:shadow-md"
      >
        <div class="flex items-start justify-between gap-3">
          <h2 class="text-base font-medium">{{ project.name }}</h2>
          <RcStatusPill
            v-if="project.status !== 'active'"
            :status="project.status"
            :label="project.status_label"
            size="sm"
          />
        </div>

        <p class="mt-1 text-xs text-muted">{{ project.project_type_label }}</p>

        <dl class="mt-5 flex flex-wrap gap-x-6 gap-y-2 text-sm">
          <div>
            <dt class="text-xs text-muted">Oda</dt>
            <dd class="tabular-nums">{{ project.room_count }}</dd>
          </div>
          <div v-if="project.budget">
            <dt class="text-xs text-muted">Bütçe</dt>
            <dd class="tabular-nums">{{ project.budget.formatted }}</dd>
          </div>
          <div v-if="project.member_count > 0">
            <dt class="text-xs text-muted">Paylaşılan</dt>
            <dd class="tabular-nums">{{ project.member_count }} kişi</dd>
          </div>
        </dl>

        <p v-if="!project.is_owner" class="mt-auto pt-5 text-xs text-gold">
          Sizinle paylaşıldı · {{ project.can_edit ? 'düzenleyebilirsiniz' : 'görüntüleyebilirsiniz' }}
        </p>
      </NuxtLink>
    </div>
  </div>
</template>
