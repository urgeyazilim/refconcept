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

/**
 * Where this room is in the studio's seven steps, from what the screen already knows.
 *
 * A photograph makes step 1 done; measurements on the room make 2; a plate makes 4; a
 * design makes 5; a ready design makes 7. Confirmation and editing live on the plan screen
 * and are counted there.
 */
const hasPhoto = computed(() => media.value.some(item => item.type === 'photo'))
const hasPlate = computed(() => media.value.some(item => item.type === 'plate'))
const recognised = computed(() => (room.value?.analysis !== null && room.value?.analysis.is_stale === false) || (room.value?.width_mm ?? null) !== null)

const studioDone = computed(() => ({
  photo: hasPhoto.value,
  recognise: recognised.value,
  plate: hasPlate.value,
  propose: designs.value.length > 0,
  render: designs.value.some(design => design.status === 'ready'),
}))

const studioCurrent = computed<'photo' | 'recognise' | 'plate' | 'propose'>(() => {
  if (!hasPhoto.value) return 'photo'
  if (!recognised.value) return 'recognise'
  if (!hasPlate.value) return 'plate'

  return 'propose'
})

/**
 * Reading the room from its photographs (step 2).
 *
 * Queued on the server after every upload, and here on request; the page reloads the room
 * until the reading appears, and stops asking after a couple of minutes rather than forever.
 */
const analysing = ref(false)
let analysingTimer: ReturnType<typeof setInterval> | null = null
let analysingSince = 0

function stopWatchingAnalysis() {
  analysing.value = false

  if (analysingTimer !== null) {
    clearInterval(analysingTimer)
    analysingTimer = null
  }
}

async function analyse(force = false) {
  actionError.value = null
  const before = room.value?.analysis?.id ?? null

  try {
    const response = await api.post<{ data: { status: 'queued' | 'ready' } }>(`${base}/analyse`, force ? { force: true } : {})

    if (response.data.status === 'ready') {
      await load()

      return
    }
  }
  catch (error) {
    actionError.value = error instanceof ApiError
      ? (error.fieldError('photos') ?? error.message)
      : 'Tanıma başlatılamadı.'

    return
  }

  analysing.value = true
  analysingSince = Date.now()

  analysingTimer = setInterval(async () => {
    await load()

    const now = room.value?.analysis

    const failed = room.value?.analysis_failure !== null && room.value?.analysis_failure !== undefined && (now === null || now === undefined || now.id === before)

    if ((now !== null && now !== undefined && now.id !== before && !now.is_stale) || failed || Date.now() - analysingSince > 180_000) {
      stopWatchingAnalysis()
    }
  }, 4_000)
}

onBeforeUnmount(stopWatchingAnalysis)

/**
 * What the guide says on this screen (docs/product/REHBER.md §3).
 *
 * One state at a time, worked out from what the room has: photographs, a reading, a plate,
 * designs. The guide card is the only place that tells the customer what comes next; the
 * sections below it are where it happens.
 */
type GuideIcon = 'sparkle' | 'camera' | 'eye' | 'broom' | 'ruler' | 'pencil' | 'light' | 'check' | 'home' | 'door'

const photoCount = computed(() => media.value.filter(item => item.type === 'photo').length)
const primaryPhoto = computed(() => media.value.find(item => item.is_primary && item.type === 'photo') ?? media.value.find(item => item.type === 'photo') ?? null)
const primaryPlate = computed(() => primaryPhoto.value === null ? null : (media.value.find(item => item.type === 'plate' && item.source_media_id === primaryPhoto.value!.id) ?? null))
const latestDesign = computed(() => designs.value[0] ?? null)

/** Which of the things the reading saw the customer wants taken out. Everything, at first. */
const removing = ref<Set<string>>(new Set())
const removingFor = ref<string | null>(null)

watch(() => room.value?.analysis?.id ?? null, (id) => {
  if (id !== removingFor.value) {
    removing.value = new Set((room.value?.analysis?.movable_objects ?? []).map(object => object.label))
    removingFor.value = id
  }
}, { immediate: true })

function toggleRemoval(label: string) {
  const next = new Set(removing.value)

  if (next.has(label)) next.delete(label)
  else next.add(label)

  removing.value = next
}

const clearingPlate = ref(false)
let plateTimer: ReturnType<typeof setInterval> | null = null

/** Asks for the plate with the customer's choice of what stays. */
async function clearPrimary() {
  const photo = primaryPhoto.value

  if (photo === null) return

  const keep = (room.value?.analysis?.movable_objects ?? []).map(object => object.label).filter(label => !removing.value.has(label))

  actionError.value = null
  clearingPlate.value = true

  try {
    await api.post(`${base}/media/${photo.id}/clear`, { keep })
  }
  catch (error) {
    clearingPlate.value = false
    actionError.value = error instanceof ApiError ? error.message : 'Oda boşaltılamadı.'

    return
  }

  plateTimer = setInterval(async () => {
    await load()

    if (primaryPlate.value !== null) {
      clearingPlate.value = false

      if (plateTimer !== null) {
        clearInterval(plateTimer)
        plateTimer = null
      }
    }
  }, 4_000)
}

onBeforeUnmount(() => {
  if (plateTimer !== null) clearInterval(plateTimer)
})

const colourWords: Record<string, string> = {
  gray: 'gri', grey: 'gri', black: 'siyah', white: 'beyaz', brown: 'kahverengi', beige: 'bej', oak: 'meşe',
  warm_white: 'kırık beyaz', wood: 'ahşap', blue: 'mavi', green: 'yeşil', cream: 'krem', navy: 'lacivert',
}

const mm = (value: number | null | undefined) => (value === null || value === undefined ? null : (value / 1000).toLocaleString('tr-TR', { maximumFractionDigits: 1 }))

const guide = computed<{
  icon: GuideIcon
  say: string
  detail: string | null
  action: { label: string, to?: string, busy?: boolean, note?: string } | null
  secondary: { label: string, to: string } | null
  tips: Array<{ icon: GuideIcon, label: string, hint?: string }>
  choices: Array<{ key: string, label: string, selected: boolean }>
  busy: boolean
}>(() => {
  const current = room.value
  const analysis = current?.analysis ?? null
  const plan = `/projects/${projectId}/rooms/${roomId}/plan`

  if (current === null) {
    return { icon: 'sparkle', say: 'Buradayım.', detail: null, action: null, secondary: null, tips: [], choices: [], busy: false }
  }

  if (photoCount.value === 0) {
    return {
      icon: 'camera',
      say: 'Hadi odanın fotoğrafını çekelim.',
      detail: 'Tek yön bana odanı anlatmaz. Kapıdan içeri bir kare, sonra sol köşe, sağ köşe ve karşı duvar — dördünü birden okuyup odanı tanıyacağım.',
      action: { label: 'Fotoğraf ekle', to: '#fotograf' },
      secondary: null,
      tips: [
        { icon: 'door', label: 'Kapıdan içeri', hint: 'Odanın tamamı görünsün' },
        { icon: 'camera', label: 'Sol köşeden', hint: 'Pencereyi de al' },
        { icon: 'camera', label: 'Sağ köşeden', hint: 'Kapı görünsün' },
        { icon: 'light', label: 'Gündüz ışığında', hint: 'Renkleri doğru okurum' },
      ],
      choices: [],
      busy: false,
    }
  }

  if (analysing.value || (analysis === null && current.analysis_failure === null)) {
    return {
      icon: 'eye',
      say: photoCount.value === 1 ? 'Bir kare aldım, okuyorum.' : `${photoCount.value} fotoğraf aldım, odanı okuyorum.`,
      detail: 'Bir dakika kadar sürer; buradayım. Bu arada bir köşe daha eklersen odayı daha iyi anlarım.',
      action: analysing.value ? null : { label: 'Şimdi oku' },
      secondary: null,
      tips: [],
      choices: [],
      busy: true,
    }
  }

  if (analysis === null) {
    return {
      icon: 'eye',
      say: 'Bunu okuyamadım. Bir daha deneyelim mi?',
      detail: current.analysis_failure,
      action: { label: 'Yeniden oku' },
      secondary: null,
      tips: [],
      choices: [],
      busy: false,
    }
  }

  const objects = analysis.movable_objects
  const dims = analysis.estimated_dimensions
  const measured = (current.width_mm ?? null) !== null
  const sizeLine = dims && dims.width_mm && dims.length_mm
    ? `Odanı ${mm(dims.width_mm)} × ${mm(dims.length_mm)} m okudum${dims.height_mm ? `, tavan ${mm(dims.height_mm)}` : ''}.`
    : null

  if (primaryPlate.value === null && objects.length > 0) {
    const seen = objects.slice(0, 5).map(object => object.label.toLocaleLowerCase('tr-TR')).join(', ')

    return {
      icon: 'broom',
      say: `Odanı gördüm: ${seen}${objects.length > 5 ? '…' : ''}.`,
      detail: `Hangilerini kaldırayım, hangileri kalsın? Seçtiklerini çıkarıp odanın boş hâlini hazırlayacağım. ${analysis.is_stale ? 'Fotoğraflar değişti; istersen önce yeniden okuyayım.' : ''}`.trim(),
      action: { label: clearingPlate.value ? 'Eşyaları kaldırıyorum…' : 'Eşyaları kaldır', busy: clearingPlate.value, note: 'Yaklaşık bir dakika' },
      secondary: sizeLine ? { label: 'Ölçülere bak', to: plan } : null,
      tips: [],
      choices: objects.map(object => ({ key: object.label, label: object.label, selected: removing.value.has(object.label) })),
      busy: clearingPlate.value,
    }
  }

  if (primaryPlate.value === null) {
    return {
      icon: 'check',
      say: 'Odan zaten boş, harika.',
      detail: sizeLine ? `${sizeLine} Doğruysa onayla, değilse düzelt; sonra tasarıma geçelim.` : 'Şimdi ölçülere bir bakalım, sonra tasarıma geçelim.',
      action: { label: 'Planı aç', to: plan },
      secondary: null,
      tips: [],
      choices: [],
      busy: false,
    }
  }

  if (!measured && sizeLine) {
    return {
      icon: 'ruler',
      say: sizeLine,
      detail: 'Doğruysa onayla, değilse düzelt. Ölçü doğru olursa önerdiğim her şey gerçekten sığar.',
      action: { label: 'Ölçüleri onayla', to: plan },
      secondary: null,
      tips: [],
      choices: [],
      busy: false,
    }
  }

  if (latestDesign.value === null) {
    const colours = analysis.dominant_colors.map(colour => colourWords[colour] ?? colour).slice(0, 3).join(', ')

    return {
      icon: 'pencil',
      say: 'Odan boş. Şimdi sen: ne istersin?',
      detail: `${colours ? `Renklerin ${colours}; ` : ''}stilini ve bütçeni söyle, ürünleri odana yerleştirip göstereyim.`,
      action: { label: 'Hadi tasarlayalım' },
      secondary: null,
      tips: [],
      choices: [],
      busy: false,
    }
  }

  if (latestDesign.value.status === 'generating' || latestDesign.value.status === 'draft') {
    return {
      icon: 'sparkle',
      say: 'Tasarımını çiziyorum.',
      detail: 'Ürünleri seçtim, odana yerleştiriyorum; bir-iki dakika.',
      action: { label: 'Tasarıma bak', to: `/projects/${projectId}/rooms/${roomId}/designs/${latestDesign.value.id}` },
      secondary: null,
      tips: [],
      choices: [],
      busy: true,
    }
  }

  return {
    icon: 'check',
    say: 'Tasarımın hazır.',
    detail: 'Beğenmediğin yeri söyle, değiştirelim; ya da 3B planda kendin taşı.',
    action: { label: 'Tasarıma bak', to: `/projects/${projectId}/rooms/${roomId}/designs/${latestDesign.value.id}` },
    secondary: { label: 'Yeni bir tasarım iste', to: '#tasarim' },
    tips: [],
    choices: [],
    busy: false,
  }
})

/** What the guide's button does when it is not a link. */
function guideAct() {
  const current = room.value

  if (current === null) return

  if (current.analysis === null) {
    void analyse(current.analysis_failure !== null)

    return
  }

  if (primaryPlate.value === null && current.analysis.movable_objects.length > 0) {
    void clearPrimary()

    return
  }

  creatingDesign.value = true
  nextTick(() => document.getElementById('tasarim-olustur')?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
}
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
            Hadi tasarlayalım
          </RcButton>
        </div>
      </header>

      <StudioStepper :project-id="projectId" :room-id="roomId" :current="studioCurrent" :done="studioDone" />

      <!-- The guide: the one voice that says what comes next (REHBER.md). -->
      <StudioGuide
        :icon="guide.icon"
        :say="guide.say"
        :detail="guide.detail"
        :action="canEdit ? guide.action : null"
        :secondary="guide.secondary"
        :tips="guide.tips"
        :choices="canEdit ? guide.choices : []"
        :busy="guide.busy"
        @act="guideAct"
        @toggle="toggleRemoval"
      />

      <RcAlert v-if="actionError" tone="danger">{{ actionError }}</RcAlert>

      <!--
        Start a design.

        The guided brief first, and the textarea only when there is no question set for the
        room type — which is the honest fallback rather than a choice put to the customer.
        Asking somebody whether they would prefer to answer eight tapped questions or write
        a paragraph is asking them to make a decision about a form.
      -->
      <DesignBriefWizard
        v-if="creatingDesign && !briefUnavailable"
        id="tasarim-olustur"
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
      <section v-if="designs.length > 0" id="tasarim">
        <p class="text-xs text-muted">Adım 5 ve 7</p>
        <h2 class="mt-1 text-lg font-medium">Tasarımlar</h2>

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

      <div id="fotograf">
        <RoomPhotoGallery
          :project-id="projectId"
          :room-id="roomId"
          :media="media"
          :can-edit="canEdit"
          @changed="load"
        />
      </div>

      <!--
        What the reading found (step 2). Every photograph of the room is read as one room;
        the boxes on the picture are on the plan screen, the words are here.
      -->
      <section id="tanima" class="rc-card p-6 sm:p-8">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <p class="text-xs text-muted">Adım 2</p>
            <h2 class="mt-1 text-lg font-medium">Tanıma</h2>
            <p class="mt-1.5 max-w-[62ch] text-sm leading-relaxed text-ink-secondary">
              <template v-if="!hasPhoto">Fotoğraf yüklendiğinde oda otomatik olarak okunur.</template>
              <template v-else-if="analysing">Fotoğraflar okunuyor; yaklaşık bir dakika sürer, sayfada kalabilirsiniz.</template>
              <template v-else-if="room.analysis === null && room.analysis_failure !== null">Son okuma tamamlanamadı: {{ room.analysis_failure }}. Yeniden deneyebilirsiniz.</template>
              <template v-else-if="room.analysis === null">Fotoğraflar yüklendikten kısa süre sonra okunur. Beklemek istemezseniz şimdi başlatın.</template>
              <template v-else-if="room.analysis.is_stale">Fotoğraflar değişti; okuma {{ room.analysis.photo_count }} fotoğraf üzerinden yapılmıştı. Yeniden okutabilirsiniz.</template>
              <template v-else>{{ room.analysis.photo_count }} fotoğraf tek oda olarak okundu. Ölçüler ve açıklıklar plan ekranında onayınızı bekler.</template>
            </p>
          </div>

          <button
            v-if="canEdit && hasPhoto && !analysing"
            type="button"
            class="rounded-pill px-4 py-2 text-sm"
            :class="room.analysis === null || room.analysis.is_stale ? 'bg-charcoal text-white' : 'border border-line text-ink-secondary hover:bg-bg-muted'"
            @click="analyse(room.analysis !== null)"
          >
            {{ room.analysis === null ? 'Odayı tanı' : 'Yeniden tanı' }}
          </button>
          <span v-else-if="analysing" class="text-sm text-muted">Okunuyor…</span>
        </div>

        <div v-if="room.analysis !== null" class="mt-5 grid gap-4 sm:grid-cols-2">
          <div>
            <h3 class="text-xs font-medium uppercase tracking-wide text-muted">Odada bulunanlar</h3>
            <p v-if="room.analysis.movable_objects.length === 0" class="mt-1.5 text-sm text-muted">Taşınabilir eşya bulunmadı.</p>
            <ul v-else class="mt-1.5 flex flex-wrap gap-1.5">
              <li v-for="(object, at) in room.analysis.movable_objects" :key="`m-${at}`" class="rounded-pill bg-bg-muted px-2.5 py-1 text-xs text-ink-secondary">{{ object.label }}</li>
            </ul>
          </div>
          <div>
            <h3 class="text-xs font-medium uppercase tracking-wide text-muted">Sabit öğeler</h3>
            <p v-if="room.analysis.fixed_elements.length === 0" class="mt-1.5 text-sm text-muted">Sabit öğe bulunmadı.</p>
            <ul v-else class="mt-1.5 flex flex-wrap gap-1.5">
              <li v-for="(name, at) in room.analysis.fixed_elements" :key="`f-${at}`" class="rounded-pill border border-line px-2.5 py-1 text-xs text-ink-secondary">{{ name }}</li>
            </ul>
          </div>
          <p v-if="room.analysis.warnings.length > 0" class="text-xs leading-relaxed text-warning sm:col-span-2">
            {{ room.analysis.warnings.join(' · ') }}
          </p>
        </div>
      </section>

      <!-- Measurements -->
      <section id="olculer" class="rc-card p-6 sm:p-8">
        <p class="text-xs text-muted">Adım 2</p>
        <h2 class="mt-1 text-lg font-medium">Ölçüler</h2>
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

      <!--
        Confirming and editing happen on the plan screen: the reading proposes, the plan
        asks "bu ölçüler doğru mu?", and the room is furnished there. This card is the way in.
      -->
      <section class="rc-card p-6 sm:p-8">
        <p class="text-xs text-muted">Adım 3 ve 6</p>
        <h2 class="mt-1 text-lg font-medium">Onay ve düzenleme</h2>
        <p class="mt-1.5 max-w-[62ch] text-sm leading-relaxed text-ink-secondary">
          Okuduğum ölçüleri onayla, odanı üç boyutlu gör, ürünleri oklarla taşı, halkayla
          döndür. Hiçbir şey duvara giremez, kapının önüne konamaz.
        </p>

        <NuxtLink
          :to="`/projects/${projectId}/rooms/${roomId}/plan`"
          class="mt-6 inline-flex rounded-pill bg-charcoal px-4 py-2 text-sm text-white"
        >
          Planı aç
        </NuxtLink>
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
