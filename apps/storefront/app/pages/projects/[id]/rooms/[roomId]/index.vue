<script setup lang="ts">
import type {
  DesignSummary,
  Option,
  ProjectDetail,
  RoomDetail,
  RoomMediaItem,
} from '@refconcept/ui/types'

/**
 * One room: its photographs, its measurements, what furniture has to work around, and
 * the designs made from it.
 *
 * The checklist at the top is the point of the screen. A customer should never have to
 * guess why the "design" button is off, and "add a photograph" is a far better answer
 * than a disabled button with no explanation.
 *
 * Measurements are entered in centimetres because that is how people measure rooms,
 * and converted to the millimetres the API stores at exactly one place — on submit.
 */
definePageMeta({ middleware: ['auth', 'verified'], layout: 'account' })

const route = useRoute()
const api = useApi()

const projectId = route.params.id as string
const roomId = route.params.roomId as string

const project = ref<ProjectDetail | null>(null)
const room = ref<RoomDetail | null>(null)
const media = ref<RoomMediaItem[]>([])
const designs = ref<DesignSummary[]>([])
const qualities = ref<Option[]>([])
const constraintTypes = ref<Array<{ value: string, label: string, blocks: boolean }>>([])

const loadError = ref<string | null>(null)
const actionError = ref<string | null>(null)

const sizeForm = reactive({ width: '', length: '', height: '', quality: 'estimated' })
const savingSize = ref(false)

const addingConstraint = ref(false)
const constraintForm = reactive({ type: 'window', label: '', wall: 'north', offset: '', width: '', sill: '' })
const savingConstraint = ref(false)

const creatingDesign = ref(false)
const designForm = reactive({ prompt: '' })

/**
 * Whether to fall back to the free-text form.
 *
 * Set when the wizard reports there is no published question set for this room type. Not a
 * preference the customer expresses — they should never be asked to choose between two
 * shapes of the same form.
 */
const briefUnavailable = ref(false)
const savingDesign = ref(false)

const base = `/api/v1/projects/${projectId}/rooms/${roomId}`

async function load() {
  try {
    const [projectResponse, roomResponse, mediaResponse, designResponse] = await Promise.all([
      api.get<{ data: ProjectDetail }>(`/api/v1/projects/${projectId}`),
      api.get<{ data: RoomDetail, meta: { measurement_qualities: Option[], constraint_types: Array<{ value: string, label: string, blocks: boolean }> } }>(base),
      api.get<{ data: RoomMediaItem[] }>(`${base}/media`),
      api.get<{ data: DesignSummary[] }>(`${base}/designs`),
    ])

    project.value = projectResponse.data
    room.value = roomResponse.data
    qualities.value = roomResponse.meta.measurement_qualities
    constraintTypes.value = roomResponse.meta.constraint_types
    media.value = mediaResponse.data
    designs.value = designResponse.data

    // Millimetres on the wire, centimetres in the form: nobody measures a room in
    // millimetres, and asking them to would produce a decimal-point mistake per room.
    sizeForm.width = roomResponse.data.width_mm ? String(roomResponse.data.width_mm / 10) : ''
    sizeForm.length = roomResponse.data.length_mm ? String(roomResponse.data.length_mm / 10) : ''
    sizeForm.height = roomResponse.data.height_mm ? String(roomResponse.data.height_mm / 10) : ''
    sizeForm.quality = roomResponse.data.measurement_quality
  } catch (error) {
    loadError.value = error instanceof ApiError
      ? ({ 403: 'Bu odaya erişim yetkiniz yok.', 404: 'Bu oda bulunamadı.' }[error.status] ?? error.message)
      : 'Oda yüklenemedi.'
  }
}

await load()

useHead(() => ({ title: room.value?.name ?? 'Oda' }))

const canEdit = computed(() => project.value?.can_edit === true)

function cmToMm(value: string): number | null {
  const trimmed = value.trim().replace(',', '.')

  if (trimmed === '') return null

  const parsed = Number(trimmed)

  return Number.isFinite(parsed) ? Math.round(parsed * 10) : null
}

async function saveSize() {
  savingSize.value = true
  actionError.value = null

  try {
    await api.patch(base, {
      measurement_quality: sizeForm.quality,
      width_mm: cmToMm(sizeForm.width),
      length_mm: cmToMm(sizeForm.length),
      height_mm: cmToMm(sizeForm.height),
    })

    await load()
  } catch (error) {
    actionError.value = error instanceof ApiError
      ? (error.fieldError('width_mm') ?? error.fieldError('length_mm') ?? error.message)
      : 'Ölçüler kaydedilemedi.'
  } finally {
    savingSize.value = false
  }
}

async function addConstraint() {
  savingConstraint.value = true
  actionError.value = null

  try {
    await api.post(`${base}/constraints`, {
      type: constraintForm.type,
      label: constraintForm.label || null,
      wall: constraintForm.wall,
      offset_mm: cmToMm(constraintForm.offset),
      width_mm: cmToMm(constraintForm.width),
      sill_height_mm: cmToMm(constraintForm.sill),
    })

    addingConstraint.value = false
    Object.assign(constraintForm, { type: 'window', label: '', wall: 'north', offset: '', width: '', sill: '' })

    await load()
  } catch (error) {
    actionError.value = error instanceof ApiError ? error.message : 'Kısıt eklenemedi.'
  } finally {
    savingConstraint.value = false
  }
}

async function removeConstraint(id: string) {
  try {
    await api.delete(`${base}/constraints/${id}`)
    await load()
  } catch (error) {
    actionError.value = error instanceof ApiError ? error.message : 'Kısıt kaldırılamadı.'
  }
}

/**
 * Starts a design from the guided brief, or from the free-text form behind it.
 *
 * Both paths land here and both are supported. The wizard is what almost everybody will
 * use; the textarea remains for a room type nobody has written questions for yet, and for
 * a customer who already knows exactly what they want to say.
 *
 * @param brief what the customer chose, or null when they wrote it instead
 */
async function createDesign(brief: Record<string, unknown> | null = null) {
  savingDesign.value = true
  actionError.value = null

  try {
    const response = await api.post<{ data: { id: string } }>(`${base}/designs`, {
      user_prompt: brief === null ? (designForm.prompt || null) : null,
      ...(brief === null ? {} : { brief }),
    })

    await navigateTo(`/projects/${projectId}/rooms/${roomId}/designs/${response.data.id}`)
  } catch (error) {
    actionError.value = error instanceof ApiError
      ? (error.fieldError('design') ?? error.message)
      : 'Tasarım başlatılamadı.'
  } finally {
    savingDesign.value = false
  }
}

const walls = [
  { value: 'north', label: 'Kuzey duvarı' },
  { value: 'east', label: 'Doğu duvarı' },
  { value: 'south', label: 'Güney duvarı' },
  { value: 'west', label: 'Batı duvarı' },
]
</script>

<template>
  <div class="space-y-8">
    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <template v-else-if="room && project">
      <header>
        <NuxtLink :to="`/projects/${projectId}`" class="text-sm text-ink-secondary hover:text-ink">
          ← {{ project.name }}
        </NuxtLink>

        <div class="mt-3 flex flex-wrap items-center justify-between gap-4">
          <div>
            <h1 class="text-2xl font-medium">{{ room.name }}</h1>
            <p class="mt-1.5 text-sm text-ink-secondary">
              {{ room.room_type_label }}
              <span v-if="room.floor_area_m2"> · {{ room.floor_area_m2 }} m²</span>
            </p>
          </div>

          <RcButton
            v-if="canEdit && room.is_ready_for_design && !creatingDesign"
            @click="creatingDesign = true"
          >
            Tasarım oluştur
          </RcButton>
        </div>
      </header>

      <RcAlert v-if="actionError" tone="danger">{{ actionError }}</RcAlert>

      <!-- Readiness -->
      <RcAlert v-if="!room.is_ready_for_design" tone="warning">
        <p class="font-medium">Bu oda henüz tasarıma hazır değil</p>
        <ul class="mt-2 space-y-1">
          <li v-for="item in room.missing_for_design" :key="item">· {{ item }}</li>
        </ul>
      </RcAlert>

      <!--
        Start a design.

        The guided brief first, and the textarea only when there is no question set for the
        room type — which is the honest fallback rather than a choice put to the customer.
        Asking somebody whether they would prefer to answer eight tapped questions or write
        a paragraph is asking them to make a decision about a form.
      -->
      <DesignBriefWizard
        v-if="creatingDesign && !briefUnavailable"
        :project-id="projectId"
        :room-id="roomId"
        :budget-minor="project?.budget?.amount_minor ?? null"
        @cancel="briefUnavailable = true"
        @submit="createDesign"
      />

      <section v-else-if="creatingDesign" class="rc-card p-6 sm:p-8">
        <h2 class="text-lg font-medium">Yeni tasarım</h2>
        <p class="mt-1.5 max-w-[60ch] text-sm leading-relaxed text-ink-secondary">
          Nasıl bir sonuç istediğinizi kendi cümlelerinizle yazabilirsiniz. Boş
          bırakırsanız oda türüne ve ölçülerine göre bir öneri hazırlanır.
        </p>

        <form class="mt-5 space-y-5" @submit.prevent="createDesign(null)">
          <div>
            <label for="prompt" class="mb-1.5 block text-sm font-medium">İstekleriniz</label>
            <textarea
              id="prompt"
              v-model="designForm.prompt"
              rows="3"
              placeholder="Örn. İskandinav, açık renkler, çok fazla mobilya olmasın"
              class="w-full rounded-sm border border-line bg-surface px-4 py-3 text-sm leading-relaxed"
            />
          </div>

          <div class="flex items-center gap-3">
            <RcButton type="submit" :loading="savingDesign" :disabled="savingDesign">
              Başlat
            </RcButton>
            <RcButton variant="ghost" @click="creatingDesign = false">Vazgeç</RcButton>
          </div>
        </form>
      </section>

      <!-- Designs -->
      <section v-if="designs.length > 0">
        <h2 class="text-lg font-medium">Tasarımlar</h2>

        <div class="mt-5 grid gap-4 sm:grid-cols-2">
          <NuxtLink
            v-for="design in designs"
            :key="design.id"
            :to="`/projects/${projectId}/rooms/${roomId}/designs/${design.id}`"
            class="rc-card flex items-start justify-between gap-4 p-5 transition-shadow hover:shadow-md"
          >
            <div>
              <h3 class="font-medium">{{ design.name }}</h3>
              <p class="mt-1 text-xs text-muted">
                {{ design.version_count }} sürüm
                <span v-if="design.current_version_number"> · v{{ design.current_version_number }} görüntüleniyor</span>
              </p>
            </div>

            <RcStatusPill
              :status="design.status === 'ready' ? 'approved' : design.status === 'failed' ? 'rejected' : 'in_review'"
              :label="design.status_label"
              size="sm"
            />
          </NuxtLink>
        </div>
      </section>

      <RoomPhotoGallery
        :project-id="projectId"
        :room-id="roomId"
        :media="media"
        :can-edit="canEdit"
        @changed="load"
      />

      <!-- Measurements -->
      <section class="rc-card p-6 sm:p-8">
        <h2 class="text-lg font-medium">Ölçüler</h2>
        <p class="mt-1.5 max-w-[62ch] text-sm leading-relaxed text-ink-secondary">
          Ölçü girmek zorunlu değil, ama tasarımın gerçekten odanıza sığan mobilyalar
          önermesini sağlayan şey bu. Santimetre cinsinden yazın.
        </p>

        <form class="mt-6 space-y-5" @submit.prevent="saveSize">
          <div class="grid gap-4 sm:grid-cols-3">
            <RcField v-model="sizeForm.width" label="Genişlik (cm)" name="width" :disabled="!canEdit" />
            <RcField v-model="sizeForm.length" label="Uzunluk (cm)" name="length" :disabled="!canEdit" />
            <RcField v-model="sizeForm.height" label="Tavan yüksekliği (cm)" name="height" :disabled="!canEdit" />
          </div>

          <div>
            <label for="quality" class="mb-1.5 block text-sm font-medium">Ölçüler nereden geliyor?</label>
            <select
              id="quality"
              v-model="sizeForm.quality"
              :disabled="!canEdit"
              class="w-full max-w-sm rounded-sm border border-line bg-surface px-4 py-2.5 text-sm disabled:opacity-60"
            >
              <option v-for="quality in qualities" :key="quality.value" :value="quality.value">
                {{ quality.label }}
              </option>
            </select>
            <p class="mt-1.5 text-xs text-muted">
              Tahmini bir ölçüyle üretilen tasarım bir öneridir; elle ölçülmüş bir odada
              mobilyanın sığacağına güvenebilirsiniz.
            </p>
          </div>

          <RcButton v-if="canEdit" type="submit" size="sm" :loading="savingSize" :disabled="savingSize">
            Ölçüleri kaydet
          </RcButton>
        </form>
      </section>

      <!-- Constraints -->
      <section class="rc-card p-6 sm:p-8">
        <header class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h2 class="text-lg font-medium">Odadaki sabitler</h2>
            <p class="mt-1.5 max-w-[60ch] text-sm leading-relaxed text-ink-secondary">
              Pencere, kapı, radyatör, kolon… Nerede olduklarını yazdığınızda tasarım
              önünü kapatmayan bir yerleşim önerir. "Pencere var" demek yetmez; nerede
              olduğu 220 cm'lik bir kanepenin sığıp sığmadığını belirler.
            </p>
          </div>

          <RcButton
            v-if="canEdit && !addingConstraint"
            size="sm"
            variant="secondary"
            @click="addingConstraint = true"
          >
            Ekle
          </RcButton>
        </header>

        <form v-if="addingConstraint" class="mt-6 space-y-5 rounded-md bg-bg-muted p-5" @submit.prevent="addConstraint">
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label for="ctype" class="mb-1.5 block text-sm font-medium">Ne?</label>
              <select
                id="ctype"
                v-model="constraintForm.type"
                class="w-full rounded-sm border border-line bg-surface px-4 py-2.5 text-sm"
              >
                <option v-for="type in constraintTypes" :key="type.value" :value="type.value">
                  {{ type.label }}
                </option>
              </select>
            </div>

            <div>
              <label for="wall" class="mb-1.5 block text-sm font-medium">Hangi duvarda?</label>
              <select
                id="wall"
                v-model="constraintForm.wall"
                class="w-full rounded-sm border border-line bg-surface px-4 py-2.5 text-sm"
              >
                <option v-for="wall in walls" :key="wall.value" :value="wall.value">
                  {{ wall.label }}
                </option>
              </select>
            </div>
          </div>

          <div class="grid gap-4 sm:grid-cols-3">
            <RcField
              v-model="constraintForm.offset"
              label="Duvarın solundan uzaklık (cm)"
              name="offset"
            />
            <RcField v-model="constraintForm.width" label="Genişlik (cm)" name="cwidth" />
            <RcField v-model="constraintForm.sill" label="Yerden yükseklik (cm)" name="sill" />
          </div>

          <div class="flex items-center gap-3">
            <RcButton type="submit" size="sm" :loading="savingConstraint" :disabled="savingConstraint">
              Ekle
            </RcButton>
            <RcButton size="sm" variant="ghost" @click="addingConstraint = false">Vazgeç</RcButton>
          </div>
        </form>

        <ul v-if="room.constraints.length > 0" class="mt-6 space-y-2">
          <li
            v-for="constraint in room.constraints"
            :key="constraint.id"
            class="flex flex-wrap items-center justify-between gap-3 border-b border-line pb-3 text-sm last:border-0"
          >
            <div>
              <p>{{ constraint.description }}</p>
              <p class="mt-0.5 text-xs text-muted">
                {{ walls.find(w => w.value === constraint.wall)?.label ?? 'Konum belirtilmedi' }}
                <!-- Said plainly: an unplaced constraint is a note, not something the
                     engine can reason about. -->
                <span v-if="!constraint.is_placed"> · yerleşim için yeterli bilgi yok</span>
              </p>
            </div>

            <button
              v-if="canEdit"
              type="button"
              class="rounded-sm px-2.5 py-1.5 text-xs text-danger hover:bg-danger-subtle"
              @click="removeConstraint(constraint.id)"
            >
              Kaldır
            </button>
          </li>
        </ul>

        <p v-else-if="!addingConstraint" class="mt-6 text-sm text-ink-secondary">
          Henüz sabit eklemediniz.
        </p>
      </section>
    </template>
  </div>
</template>
