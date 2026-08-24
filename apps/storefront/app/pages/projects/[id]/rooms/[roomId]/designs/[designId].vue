<script setup lang="ts">
import type { DesignDetail, DesignTreeNode } from '@refconcept/ui/types'

/**
 * One design and its version tree.
 *
 * The tree is drawn as a tree rather than flattened into a list, because the shape
 * carries the meaning: "this one came from that one" is what lets somebody go back to
 * the version they liked and take a different direction from it. A list of five images
 * in date order tells you nothing about how they relate.
 *
 * Generation itself arrives with the AI gateway in Phase 6 and the design engine in
 * Phase 8. Until then a version is honestly reported as queued rather than dressed up
 * with a fake progress bar.
 */
definePageMeta({ middleware: ['auth', 'verified'], layout: 'account' })

const route = useRoute()
const api = useApi()

const projectId = route.params.id as string
const roomId = route.params.roomId as string
const designId = route.params.designId as string

const design = ref<DesignDetail | null>(null)
const canEdit = ref(false)
const loadError = ref<string | null>(null)
const actionError = ref<string | null>(null)
const working = ref(false)

const branchingFrom = ref<DesignTreeNode | null>(null)
const branchPrompt = ref('')

const base = `/api/v1/projects/${projectId}/rooms/${roomId}/designs/${designId}`

async function load() {
  try {
    const [designResponse, projectResponse] = await Promise.all([
      api.get<{ data: DesignDetail }>(base),
      api.get<{ data: { can_edit: boolean } }>(`/api/v1/projects/${projectId}`),
    ])

    design.value = designResponse.data
    canEdit.value = projectResponse.data.can_edit
  } catch (error) {
    loadError.value = error instanceof ApiError
      ? ({ 403: 'Bu tasarıma erişim yetkiniz yok.', 404: 'Bu tasarım bulunamadı.' }[error.status] ?? error.message)
      : 'Tasarım yüklenemedi.'
  }
}

await load()

useHead(() => ({ title: design.value?.name ?? 'Tasarım' }))

/** Flattens the tree for rendering while keeping each node's depth. */
function flatten(nodes: DesignTreeNode[], depth = 0): Array<{ node: DesignTreeNode, depth: number }> {
  return nodes.flatMap(node => [
    { node, depth },
    ...flatten(node.children, depth + 1),
  ])
}

const rows = computed(() => (design.value ? flatten(design.value.tree) : []))

async function branch() {
  if (!branchingFrom.value) return

  working.value = true
  actionError.value = null

  try {
    await api.post(`${base}/branch`, {
      parent_version_id: branchingFrom.value.id,
      user_prompt: branchPrompt.value,
    })

    branchingFrom.value = null
    branchPrompt.value = ''

    await load()
  } catch (error) {
    actionError.value = error instanceof ApiError
      ? (error.fieldError('parent_version_id') ?? error.message)
      : 'Yeni sürüm oluşturulamadı.'
  } finally {
    working.value = false
  }
}

async function setCurrent(node: DesignTreeNode) {
  working.value = true
  actionError.value = null

  try {
    await api.patch(`${base}/current`, { version_id: node.id })
    await load()
  } catch (error) {
    actionError.value = error instanceof ApiError
      ? (error.fieldError('version_id') ?? error.message)
      : 'Sürüm seçilemedi.'
  } finally {
    working.value = false
  }
}

const statusTone: Record<string, string> = {
  pending: 'in_review',
  generating: 'in_review',
  ready: 'approved',
  failed: 'rejected',
}
</script>

<template>
  <div class="space-y-8">
    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <template v-else-if="design">
      <header>
        <NuxtLink
          :to="`/projects/${projectId}/rooms/${roomId}`"
          class="text-sm text-ink-secondary hover:text-ink"
        >
          ← Odaya dön
        </NuxtLink>

        <div class="mt-3 flex flex-wrap items-center gap-3">
          <h1 class="text-2xl font-medium">{{ design.name }}</h1>
          <RcStatusPill
            :status="design.status === 'ready' ? 'approved' : design.status === 'failed' ? 'rejected' : 'in_review'"
            :label="design.status_label"
          />
        </div>

        <p class="mt-1.5 text-sm text-ink-secondary">
          {{ design.version_count }} sürüm
          <span v-if="design.total_credit_cost > 0"> · toplam {{ design.total_credit_cost }} kredi</span>
        </p>
      </header>

      <RcAlert v-if="actionError" tone="danger">{{ actionError }}</RcAlert>

      <RcAlert v-if="design.status === 'generating'" tone="info">
        Tasarım motoru henüz devrede değil — sürümleriniz sırada bekliyor ve motor
        açıldığında işlenecek. Bu ekranda ağacın kendisi ve sürümler arasında gezinme
        şimdiden çalışıyor.
      </RcAlert>

      <!-- The tree -->
      <section class="rc-card p-6 sm:p-8">
        <h2 class="text-lg font-medium">Sürümler</h2>
        <p class="mt-1.5 max-w-[62ch] text-sm leading-relaxed text-ink-secondary">
          Her değişiklik yeni bir sürüm oluşturur; öncekiler kaybolmaz. Beğendiğiniz bir
          sürüme dönüp oradan başka bir yöne gidebilirsiniz.
        </p>

        <ul class="mt-6 space-y-2">
          <li
            v-for="row in rows"
            :key="row.node.id"
            class="rounded-md border p-4"
            :class="row.node.is_current ? 'border-charcoal bg-bg-muted' : 'border-line'"
            :style="{ marginLeft: `${row.depth * 24}px` }"
          >
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="font-medium">v{{ row.node.version_number }}</span>
                  <RcStatusPill
                    :status="statusTone[row.node.status] ?? 'draft'"
                    :label="row.node.status_label"
                    size="sm"
                  />
                  <span v-if="row.node.is_current" class="text-xs text-gold">Görüntülenen</span>
                </div>

                <p v-if="row.node.user_prompt" class="mt-1.5 text-sm text-ink-secondary">
                  “{{ row.node.user_prompt }}”
                </p>
                <p v-else class="mt-1.5 text-sm text-muted">İlk deneme</p>

                <p class="mt-1 text-xs text-muted">
                  {{ row.node.created_at ? new Date(row.node.created_at).toLocaleString('tr-TR') : '' }}
                  <span v-if="row.node.credit_cost > 0"> · {{ row.node.credit_cost }} kredi</span>
                </p>
              </div>

              <div v-if="canEdit" class="flex flex-wrap gap-1.5">
                <button
                  v-if="row.node.status === 'ready' && !row.node.is_current"
                  type="button"
                  class="rounded-sm border border-line px-2.5 py-1.5 text-xs text-ink-secondary hover:bg-bg-muted disabled:opacity-40"
                  :disabled="working"
                  @click="setCurrent(row.node)"
                >
                  Bunu göster
                </button>

                <button
                  v-if="row.node.status === 'ready'"
                  type="button"
                  class="rounded-sm border border-line px-2.5 py-1.5 text-xs text-ink-secondary hover:bg-bg-muted disabled:opacity-40"
                  :disabled="working"
                  @click="branchingFrom = row.node"
                >
                  Buradan devam et
                </button>
              </div>
            </div>

            <!-- Refine from this version -->
            <form
              v-if="branchingFrom?.id === row.node.id"
              class="mt-4 space-y-3 rounded-sm bg-surface p-4"
              @submit.prevent="branch"
            >
              <label :for="`prompt-${row.node.id}`" class="block text-sm font-medium">
                v{{ row.node.version_number }} üzerinden ne değişsin?
              </label>
              <textarea
                :id="`prompt-${row.node.id}`"
                v-model="branchPrompt"
                rows="2"
                placeholder="Örn. Kanepeyi daha koyu yap, halıyı değiştir"
                class="w-full rounded-sm border border-line bg-surface px-3 py-2 text-sm"
              />
              <div class="flex items-center gap-3">
                <RcButton
                  type="submit"
                  size="sm"
                  :loading="working"
                  :disabled="working || branchPrompt.trim().length < 3"
                >
                  Yeni sürüm oluştur
                </RcButton>
                <RcButton size="sm" variant="ghost" @click="branchingFrom = null">Vazgeç</RcButton>
              </div>
            </form>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>
