<script setup lang="ts">
import type { Product, ProductModelRef } from '@refconcept/ui/types'

/**
 * The product's 3D model.
 *
 * Optional, and worth asking for: a manufacturer's own glTF file is the shape of the thing,
 * where the model made from a photograph is a likeness whose far side was never photographed.
 * A file uploaded here outranks anything generated, and the planner stops calling the
 * product a representation.
 *
 * No re-review. A model is not a claim about the product the way a photograph is — it is the
 * geometry of a listing already approved — and sending a live listing back into the queue for
 * it would punish a seller for improving it.
 */
const props = defineProps<{
  productId: string
  model: ProductModelRef | null
  disabled?: boolean
}>()

const emit = defineEmits<{ updated: [Product] }>()

const api = useApi()

const busy = ref(false)
const error = ref<string | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)

const MAX_MB = 20

async function onFileSelected(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]

  if (file === undefined) return

  error.value = null
  busy.value = true

  try {
    const body = new FormData()
    body.append('file', file)

    const response = await api.request<{ data: Product }>(
      `/api/v1/seller/products/${props.productId}/model`,
      { method: 'POST', body },
    )

    emit('updated', response.data)
  }
  catch (caught) {
    error.value = caught instanceof ApiError
      ? (caught.fieldError('file') ?? caught.message)
      : '3B model yüklenemedi.'
  }
  finally {
    busy.value = false
    input.value = ''
  }
}

async function discardGenerated() {
  busy.value = true
  error.value = null

  try {
    const response = await api.delete<{ data: Product }>(
      `/api/v1/seller/products/${props.productId}/model/generated`,
    )

    emit('updated', response.data)
  }
  catch (caught) {
    error.value = caught instanceof ApiError ? caught.message : 'Model kaldırılamadı.'
  }
  finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="rc-card p-6 sm:p-8">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h2 class="text-lg font-medium">3B model</h2>
        <p class="mt-1.5 max-w-[60ch] text-sm leading-relaxed text-ink-secondary">
          Müşteriler ürünü kendi odalarına 3B olarak yerleştirir. Üreticinizden aldığınız
          glTF/GLB dosyası ürünün gerçek biçimidir; yoksa fotoğraflardan yaklaşık bir model
          üretiriz. Sizin dosyanız her zaman önceliklidir. GLB, en fazla {{ MAX_MB }} MB.
        </p>
      </div>

      <span
        v-if="model"
        class="rounded-pill px-2.5 py-1 text-[11px]"
        :class="model.source === 'seller' ? 'bg-charcoal text-white' : 'bg-bg-muted text-ink-secondary'"
      >
        {{ model.source === 'seller' ? 'Sizin dosyanız' : 'Fotoğraftan üretildi' }}
      </span>
    </header>

    <RcAlert v-if="error" tone="danger" class="mt-5">{{ error }}</RcAlert>

    <div class="mt-6 flex flex-wrap items-center gap-3">
      <input
        ref="fileInput"
        type="file"
        accept=".glb,model/gltf-binary"
        class="sr-only"
        :disabled="disabled || busy"
        @change="onFileSelected"
      >

      <button
        type="button"
        class="rounded-sm border border-line px-4 py-2.5 text-sm text-ink-secondary transition-colors hover:bg-bg-muted disabled:cursor-not-allowed disabled:opacity-50"
        :disabled="disabled || busy"
        @click="fileInput?.click()"
      >
        <span v-if="busy">Yükleniyor…</span>
        <span v-else-if="model?.source === 'seller'">Dosyayı değiştir</span>
        <span v-else>GLB dosyası yükle</span>
      </button>

      <a
        v-if="model"
        :href="model.url"
        class="text-sm text-ink-secondary underline-offset-4 hover:underline"
        download
      >
        Mevcut modeli indir
      </a>

      <button
        v-if="model?.source === 'ai'"
        type="button"
        class="ml-auto rounded-sm px-3 py-2 text-sm text-danger transition-colors hover:bg-danger-subtle disabled:opacity-40"
        :disabled="disabled || busy"
        @click="discardGenerated"
      >
        Üretilen modeli kaldır
      </button>
    </div>

    <p v-if="!model" class="mt-4 text-xs text-muted">
      Henüz model yok. Ürün onaylandığında fotoğraflarından otomatik üretilir; görsellerin
      hangi yönden çekildiğini işaretlerseniz sonuç belirgin biçimde iyileşir.
    </p>
  </section>
</template>
