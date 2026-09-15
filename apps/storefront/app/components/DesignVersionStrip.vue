<script setup lang="ts">
import type { DesignTreeNode } from '@refconcept/ui/types'

/**
 * Every version of a design as a row of thumbnails (K26).
 *
 * The tree below the picture says what changed and when; this says what it *looked like*,
 * which is how a customer actually remembers versions — "the one with the darker sofa", not
 * "v3". Clicking one shows it; it changes nothing on the server. Picking a second one holds
 * it up beside the first.
 *
 * A version with no picture yet — running, or failed — keeps its place in the row so the
 * numbering never jumps, and says why it is blank.
 */
const props = defineProps<{
  versions: DesignTreeNode[]
  shownId: string | null
  compareId: string | null
}>()

const emit = defineEmits<{
  (event: 'show', id: string): void
  (event: 'compare', id: string | null): void
}>()

/** Whether the next click picks the version to compare with, rather than the one to show. */
const picking = ref(false)

function pick(version: DesignTreeNode) {
  if (picking.value) {
    picking.value = false

    if (version.image_url && version.id !== props.shownId) {
      emit('compare', version.id)
    }

    return
  }

  if (version.id === props.compareId) {
    emit('compare', null)
  }

  emit('show', version.id)
}

function toggleCompare() {
  if (props.compareId !== null) {
    emit('compare', null)
    picking.value = false

    return
  }

  picking.value = !picking.value
}

const comparable = computed(() => props.versions.filter(version => version.image_url && version.id !== props.shownId).length > 0)
</script>

<template>
  <section class="rc-card px-6 py-4 sm:px-8" aria-label="Sürümler">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <p class="text-sm text-ink-secondary">
        <template v-if="picking">Karşılaştırmak için ikinci sürümü seçin.</template>
        <template v-else-if="compareId">İki sürüm yan yana. Başka bir sürüme tıklayarak değiştirebilirsiniz.</template>
        <template v-else>Bir sürüme tıklayınca görsel ve ürün listesi ona döner.</template>
      </p>

      <button
        v-if="comparable"
        type="button"
        class="rounded-pill border px-3 py-1 text-xs transition-colors"
        :class="picking || compareId ? 'border-charcoal bg-charcoal text-white' : 'border-line text-ink-secondary hover:bg-bg-muted'"
        @click="toggleCompare"
      >
        {{ compareId ? 'Karşılaştırmayı kapat' : picking ? 'Vazgeç' : 'Karşılaştır' }}
      </button>
    </div>

    <ul class="mt-3 flex gap-3 overflow-x-auto pb-1">
      <li v-for="version in versions" :key="version.id" class="shrink-0">
        <button
          type="button"
          class="group relative block w-36 overflow-hidden rounded-md border-2 bg-bg-muted text-left transition-colors"
          :class="{
            'border-charcoal': version.id === shownId,
            'border-accent-600': version.id === compareId,
            'border-transparent hover:border-line': version.id !== shownId && version.id !== compareId,
          }"
          :aria-pressed="version.id === shownId"
          :aria-label="`v${version.version_number}${version.id === shownId ? ', görüntülenen' : ''}`"
          @click="pick(version)"
        >
          <div class="aspect-[16/10] w-full">
            <img
              v-if="version.image_url"
              :src="version.image_url"
              :alt="`v${version.version_number}`"
              class="size-full object-cover"
              draggable="false"
            >
            <div v-else class="flex size-full items-center justify-center px-2 text-center text-[11px] text-muted">
              {{ version.status === 'failed' ? 'Tamamlanamadı' : 'Hazırlanıyor' }}
            </div>
          </div>

          <div class="flex items-center justify-between px-2 py-1.5 text-[11px]">
            <span class="font-medium tabular-nums">v{{ version.version_number }}</span>
            <span v-if="version.id === shownId" class="text-ink-secondary">Görüntülenen</span>
            <span v-else-if="version.id === compareId" class="text-accent-700">Karşılaştırılan</span>
            <span v-else-if="version.is_current" class="text-muted">Geçerli</span>
          </div>
        </button>
      </li>
    </ul>
  </section>
</template>
