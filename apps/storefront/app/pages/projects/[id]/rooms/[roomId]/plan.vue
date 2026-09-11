<script setup lang="ts">
/**
 * The room, in three dimensions, with the furniture where somebody put it.
 *
 * The screen opens with a question rather than a plan. Measurements come from a photograph
 * read by a model, and those are usually close and occasionally wrong by half a metre —
 * everything after this point rests on them, so the customer is asked to agree to them
 * before anything is drawn against them. "Bu ölçüler doğru mu?" is the storyboard's wording
 * and is deliberately not softened: it is a question, not a notification.
 *
 * Saving is automatic and a second or so behind the last change. Not on every drag, which is
 * sixty requests a second; not on a button either, because a plan somebody spent twenty
 * minutes on and lost to a closed tab is a plan they do not make again.
 */
import type { LayoutItem, RoomGeometry, RoomOpening } from '~/room3d/types'

definePageMeta({ middleware: ['auth', 'verified'], layout: 'account' })

interface GeometryVersion {
  id: string
  version: number
  source: string
  width_mm: number
  length_mm: number
  height_mm: number
  floor_area_m2: number
  confidence_percent: number | null
  is_confirmed: boolean
}

interface LayoutPayload {
  id: string
  version: number
  status: string
  has_collisions: boolean
  items: LayoutItem[]
}

const route = useRoute()
const api = useApi()

const projectId = route.params.id as string
const roomId = route.params.roomId as string

const base = `/api/v1/projects/${projectId}/rooms/${roomId}`

const confirmed = ref<GeometryVersion | null>(null)
const pending = ref<GeometryVersion[]>([])
const openings = ref<RoomOpening[]>([])
const items = ref<LayoutItem[]>([])

const loading = ref(true)
const loadError = ref<string | null>(null)
const saveError = ref<string | null>(null)
const confirming = ref(false)

/** The 3D scene, for the picture the renderer works from. */
const scene = ref<{ snapshot: () => string | null } | null>(null)

const composing = ref(false)
const composeNotice = ref<string | null>(null)

/** Set when the server refuses to overwrite an arrangement somebody already made. */
const overwrite = ref(false)

/** Shown when the customer says the measurements are wrong. Centimetres, like a tape. */
const correcting = ref(false)
const correction = reactive({ width: '', length: '', height: '' })

const geometry = computed<RoomGeometry | null>(() =>
  confirmed.value === null
    ? null
    : {
        id: confirmed.value.id,
        width_mm: confirmed.value.width_mm,
        length_mm: confirmed.value.length_mm,
        height_mm: confirmed.value.height_mm,
      },
)

/**
 * A line of measurements for the footer.
 *
 * Built here rather than in the template because a template cannot assert that `confirmed`
 * is non-null, and the `v-if` that guarantees it is on a different element.
 */
const summary = computed(() => {
  const version = confirmed.value

  if (version === null) {
    return ''
  }

  return `${(version.width_mm / 1000).toFixed(2)} × ${(version.length_mm / 1000).toFixed(2)} m · ${version.floor_area_m2.toFixed(1)} m²`
})

/** The newest proposal. The one the question is about. */
const proposal = computed(() => pending.value[0] ?? null)

async function load(): Promise<void> {
  loading.value = true

  try {
    const response = await api.get<{
      data: {
        geometry: GeometryVersion | null
        pending_geometry: GeometryVersion[]
        openings: RoomOpening[]
        layout: LayoutPayload | null
      }
    }>(`${base}/layout`)

    confirmed.value = response.data.geometry
    pending.value = response.data.pending_geometry
    openings.value = response.data.openings
    items.value = response.data.layout?.items ?? []

    const source = response.data.geometry ?? response.data.pending_geometry[0]

    if (source !== undefined) {
      correction.width = String(Math.round(source.width_mm / 10))
      correction.length = String(Math.round(source.length_mm / 10))
      correction.height = String(Math.round(source.height_mm / 10))
    }
  }
  catch (error) {
    loadError.value = error instanceof Error ? error.message : 'Oda planı yüklenemedi.'
  }
  finally {
    loading.value = false
  }
}

/** "Evet, devam et" — the measurements are adopted and the room is drawn. */
async function confirm(version: GeometryVersion): Promise<void> {
  confirming.value = true
  saveError.value = null

  try {
    await api.post(`${base}/geometry/${version.id}/confirm`)
    await load()
  }
  catch (error) {
    saveError.value = error instanceof Error ? error.message : 'Ölçüler onaylanamadı.'
  }
  finally {
    confirming.value = false
  }
}

/**
 * "Düzelt" — the customer's own figures, recorded and confirmed in one go.
 *
 * Confirmed immediately because they are the one holding the tape measure. Asking somebody
 * to type a measurement and then asking whether they meant it is a dialogue box, not a
 * question.
 */
async function saveCorrection(): Promise<void> {
  confirming.value = true
  saveError.value = null

  try {
    // Centimetres in the form because that is how people measure rooms, millimetres on the
    // wire because that is what everything else in the system speaks. Converted once, here.
    const created = await api.post<{ data: GeometryVersion }>(`${base}/geometry`, {
      width_mm: Math.round(Number(correction.width) * 10),
      length_mm: Math.round(Number(correction.length) * 10),
      height_mm: Math.round(Number(correction.height) * 10),
      source: 'user',
    })

    await api.post(`${base}/geometry/${created.data.id}/confirm`)

    correcting.value = false
    await load()
  }
  catch (error) {
    saveError.value = error instanceof Error ? error.message : 'Ölçüler kaydedilemedi.'
  }
  finally {
    confirming.value = false
  }
}

/**
 * Sends a picture of the plan, for the renderer to follow.
 *
 * This is what the 3D view is ultimately for. A photorealistic model handed a photograph and
 * a list of furniture rearranges the room to make a better picture — it has narrowed a
 * doorway and invented a sofa — and the fix is not a sterner prompt but a scene that is no
 * longer underdetermined. The room the customer has already agreed to, drawn, goes with the
 * next render as the structure to follow.
 *
 * Throttled rather than sent on every save: a drag is sixty saves and each picture is a few
 * hundred kilobytes, and the renderer only ever reads the most recent one.
 */
let snapshotTimer: ReturnType<typeof setTimeout> | null = null

function scheduleSnapshot(): void {
  if (snapshotTimer !== null) {
    clearTimeout(snapshotTimer)
  }

  snapshotTimer = setTimeout(async () => {
    const image = scene.value?.snapshot()

    if (typeof image !== 'string' || image === '') {
      return
    }

    try {
      await api.post(`${base}/layout/snapshot`, { image })
    }
    catch {
      /*
       * Deliberately silent.
       *
       * The layout itself is saved, and the snapshot is an optimisation for a render that
       * has not been asked for yet. Telling somebody their furniture might not have saved,
       * when it has, would be worse than the missing reference.
       */
    }
  }, 4_000)
}

/**
 * Writes the arrangement the editor has settled on.
 *
 * The response is deliberately not fed back into `items`: the server recomputes the collision
 * states and returns them, and replacing the list mid-session would clear the editor's undo
 * history and drop the selection under whoever is working.
 */
async function save(next: LayoutItem[]): Promise<void> {
  saveError.value = null

  try {
    await api.put(`${base}/layout`, {
      items: next.map(item => ({
        id: item.id,
        product_id: item.product_id,
        sku_id: item.sku_id,
        position_x_mm: item.position_x_mm,
        position_y_mm: item.position_y_mm,
        position_z_mm: item.position_z_mm,
        rotation_y_deg: item.rotation_y_deg,
        locked: item.locked,
      })),
    })

    scheduleSnapshot()
  }
  catch (error) {
    // Said out loud rather than retried silently. A plan the customer believes is saved and
    // is not is worse than one they know they have to save again.
    saveError.value = error instanceof Error ? error.message : 'Yerleşim kaydedilemedi.'
  }
}

/**
 * Arranges the products the latest design settled on.
 *
 * The server refuses when there is already a layout, and that refusal is the feature:
 * somebody who spent ten minutes moving furniture and pressed the wrong button gets a
 * question rather than their afternoon back in the shape the engine likes.
 */
async function composeLayout(replace = false): Promise<void> {
  composing.value = true
  saveError.value = null
  composeNotice.value = null

  try {
    const response = await api.post<{
      data: LayoutPayload
      meta: { unplaced: Array<{ category: string | null }>, unmeasured: Array<{ category: string | null }> }
    }>(`${base}/layout/compose`, replace ? { replace: true } : {})

    items.value = response.data.items
    overwrite.value = false

    const missed = [...response.meta.unplaced, ...response.meta.unmeasured]

    if (missed.length > 0) {
      // Said rather than hidden. A layout that quietly drops a product the customer chose is
      // a layout that lies about the shopping list beside it.
      const names = missed.map(entry => entry.category ?? 'ürün').join(', ')

      composeNotice.value = `Şunlar yerleştirilemedi: ${names}. Daha dar bir ürün seçebilir ya da kendiniz yerleştirebilirsiniz.`
    }
  }
  catch (error) {
    if (error instanceof ApiError && error.status === 409) {
      overwrite.value = true

      return
    }

    saveError.value = error instanceof Error ? error.message : 'Yerleşim oluşturulamadı.'
  }
  finally {
    composing.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-5xl space-y-6 p-6">
    <header class="flex items-center justify-between gap-4">
      <div>
        <h1 class="text-xl font-medium">
          Oda planı
        </h1>
        <p class="mt-1 text-sm text-muted">
          Ürünleri sürükleyerek yerleştirin. Mesafeler santimetre olarak yanınızda görünür.
        </p>
      </div>

      <NuxtLink :to="`/projects/${projectId}/rooms/${roomId}`" class="text-sm text-ink-secondary hover:underline">
        Odaya dön
      </NuxtLink>
    </header>

    <p v-if="loadError" class="rounded-sm bg-danger-subtle p-3 text-sm text-danger-strong">
      {{ loadError }}
    </p>

    <p v-else-if="loading" class="text-sm text-muted">
      Yükleniyor…
    </p>

    <!--
      The question, before anything is drawn against the answer. A measurement nobody agreed
      to is a guess with a decimal point on it.
    -->
    <section v-else-if="geometry === null" class="rounded-md border border-line bg-surface p-6">
      <template v-if="proposal !== null && !correcting">
        <h2 class="text-base font-medium">
          Bu ölçüler doğru mu?
        </h2>

        <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-3">
          <div>
            <dt class="text-muted">
              Genişlik
            </dt>
            <dd class="tabular-nums">
              {{ (proposal.width_mm / 1000).toFixed(2) }} m
            </dd>
          </div>
          <div>
            <dt class="text-muted">
              Uzunluk
            </dt>
            <dd class="tabular-nums">
              {{ (proposal.length_mm / 1000).toFixed(2) }} m
            </dd>
          </div>
          <div>
            <dt class="text-muted">
              Tavan
            </dt>
            <dd class="tabular-nums">
              {{ (proposal.height_mm / 1000).toFixed(2) }} m
            </dd>
          </div>
        </dl>

        <p class="mt-3 text-xs text-muted">
          {{ proposal.source === 'ai'
            ? `Fotoğraftan tahmin edildi${proposal.confidence_percent === null ? '' : ` (%${proposal.confidence_percent} güven)`}. Bir metre şerit varsa kontrol etmeye değer.`
            : 'Sizin girdiğiniz ölçüler.' }}
        </p>

        <div class="mt-4 flex flex-wrap gap-2">
          <button
            type="button"
            class="rounded-pill bg-charcoal px-4 py-2 text-sm text-white disabled:opacity-50"
            :disabled="confirming"
            @click="confirm(proposal)"
          >
            Evet, devam et
          </button>
          <button
            type="button"
            class="rounded-pill border border-line px-4 py-2 text-sm"
            @click="correcting = true"
          >
            Düzelt
          </button>
        </div>
      </template>

      <template v-else>
        <h2 class="text-base font-medium">
          Odanın ölçüleri
        </h2>
        <p class="mt-1 text-sm text-muted">
          Santimetre olarak girin. Duvar diplerinden ölçmek en doğrusu.
        </p>

        <div class="mt-4 grid gap-3 sm:grid-cols-3">
          <label v-for="field in (['width', 'length', 'height'] as const)" :key="field" class="block">
            <span class="mb-1.5 block text-sm">
              {{ field === 'width' ? 'Genişlik' : field === 'length' ? 'Uzunluk' : 'Tavan yüksekliği' }} (cm)
            </span>
            <input
              v-model="correction[field]"
              type="number"
              min="100"
              max="3000"
              class="w-full rounded-sm border border-line bg-surface px-3 py-2 text-sm"
            >
          </label>
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
          <button
            type="button"
            class="rounded-pill bg-charcoal px-4 py-2 text-sm text-white disabled:opacity-50"
            :disabled="confirming"
            @click="saveCorrection"
          >
            Kaydet ve devam et
          </button>
          <button
            v-if="proposal !== null"
            type="button"
            class="rounded-pill border border-line px-4 py-2 text-sm"
            @click="correcting = false"
          >
            Vazgeç
          </button>
        </div>
      </template>

      <p v-if="saveError" class="mt-3 text-sm text-danger-strong">
        {{ saveError }}
      </p>
    </section>

    <template v-else>
      <!--
        Arranging costs nothing. The design was paid for; this is arithmetic against the room
        the customer confirmed, so it is a button rather than a purchase.
      -->
      <div class="flex flex-wrap items-center gap-3 rounded-md border border-line bg-surface p-4">
        <button
          type="button"
          class="rounded-pill bg-charcoal px-4 py-2 text-sm text-white disabled:opacity-50"
          :disabled="composing"
          @click="composeLayout()"
        >
          {{ items.length === 0 ? 'Tasarıma göre yerleştir' : 'Yeniden yerleştir' }}
        </button>

        <p class="text-xs text-muted">
          Son tasarımda seçilen ürünler, odanın ölçülerine göre dizilir. Sonra
          istediğiniz gibi taşıyabilirsiniz.
        </p>
      </div>

      <!-- The question the 409 exists to ask. -->
      <div v-if="overwrite" class="rounded-md border border-line bg-warning-subtle p-4">
        <p class="text-sm text-warning-strong">
          Bu odada kayıtlı bir yerleşim var. Üzerine yazılsın mı?
        </p>

        <div class="mt-3 flex gap-2">
          <button type="button" class="rounded-pill bg-charcoal px-4 py-2 text-sm text-white" @click="composeLayout(true)">
            Evet, yeniden diz
          </button>
          <button type="button" class="rounded-pill border border-line px-4 py-2 text-sm" @click="overwrite = false">
            Vazgeç
          </button>
        </div>
      </div>

      <p v-if="composeNotice" class="rounded-sm bg-warning-subtle p-3 text-sm text-warning-strong">
        {{ composeNotice }}
      </p>

      <Room3DScene ref="scene" :geometry="geometry" :openings="openings" :items="items" editable @save="save" />

      <p v-if="saveError" class="rounded-sm bg-danger-subtle p-3 text-sm text-danger-strong">
        {{ saveError }}
      </p>

      <div class="flex items-center justify-between gap-4 text-xs text-muted">
        <p>
          Ölçüler: {{ summary }}
        </p>

        <button type="button" class="hover:underline" @click="correcting = true; confirmed = null">
          Ölçüleri düzelt
        </button>
      </div>
    </template>
  </div>
</template>
