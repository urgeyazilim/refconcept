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
definePageMeta({ middleware: ['auth', 'verified'], layout: 'default', chrome: 'studio' })

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
const photoCount = computed(() => media.value.filter(item => item.type === 'photo').length)
const primaryPhoto = computed(() => media.value.find(item => item.is_primary && item.type === 'photo') ?? media.value.find(item => item.type === 'photo') ?? null)
const primaryPlate = computed(() => primaryPhoto.value === null ? null : (media.value.find(item => item.type === 'plate' && item.source_media_id === primaryPhoto.value!.id) ?? null))
const latestDesign = computed(() => designs.value[0] ?? null)

type StudioStep = 'photo' | 'recognise' | 'confirm' | 'plate' | 'propose' | 'edit' | 'render'

// The order the guide walks: read, ask about the furniture, then the size, then design.
const STEP_ORDER: StudioStep[] = ['photo', 'recognise', 'plate', 'confirm', 'propose', 'edit', 'render']

/** "Hayır, kalsın": the customer wants the room as it is; the plate step is behind them. */
const plateSkipped = ref(false)

const hasPhoto = computed(() => media.value.some(item => item.type === 'photo'))
const recognised = computed(() => room.value?.analysis !== null && room.value?.analysis !== undefined && room.value.analysis.is_stale === false)
const measured = computed(() => (room.value?.width_mm ?? null) !== null && (room.value?.length_mm ?? null) !== null)

/**
 * What the plan knows that the room does not: the proposed size waiting for a yes, the
 * confirmed one, and whether anything has been placed. Read alongside the room so the
 * size can be confirmed here, on the step it belongs to, without leaving the screen.
 */
interface GeometryVersion { id: string, width_mm: number, length_mm: number, height_mm: number, is_confirmed: boolean }
const pendingGeometry = ref<GeometryVersion | null>(null)
const confirmedGeometry = ref<GeometryVersion | null>(null)
const placedCount = ref(0)

async function loadLayout() {
  try {
    const response = await api.get<{ data: { geometry: GeometryVersion | null, pending_geometry: GeometryVersion[], layout: { items: unknown[] } | null } }>(`${base}/layout`)

    confirmedGeometry.value = response.data.geometry
    pendingGeometry.value = response.data.pending_geometry[0] ?? null
    placedCount.value = response.data.layout?.items.length ?? 0
  }
  catch {
    // The room screen still works without the plan's view of it.
  }
}

await loadLayout()

const studioDone = computed<Partial<Record<StudioStep, boolean>>>(() => ({
  photo: hasPhoto.value,
  recognise: recognised.value,
  confirm: confirmedGeometry.value !== null || measured.value,
  // Done when the room is emptied, when there was nothing to empty, or when the customer
  // said to leave it as it is.
  plate: primaryPlate.value !== null || plateSkipped.value || (recognised.value && (room.value?.analysis?.movable_objects.length ?? 0) === 0),
  propose: designs.value.length > 0,
  edit: placedCount.value > 0,
  render: designs.value.some(design => design.status === 'ready'),
}))

/**
 * The first step not done, in the guide's order — where the customer is going.
 *
 * The reading is not a screen of its own: while the photographs are being read the
 * customer stays with them (and may add a corner), and when the reading lands the guide
 * moves straight on to its first question. "Tanıma" in the strip ticks itself.
 */
const autoStep = computed<StudioStep>(() => {
  const first = STEP_ORDER.find(step => studioDone.value[step] !== true) ?? 'render'

  return first === 'recognise' ? 'photo' : first
})

/** A step the customer opened from the strip, to look back or ahead. */
const chosenStep = ref<StudioStep | null>(null)
const activeStep = computed<StudioStep>(() => chosenStep.value ?? autoStep.value)

function goTo(step: StudioStep) {
  chosenStep.value = step === autoStep.value ? null : step
  editingSize.value = false
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

/** Moves on when a step's work is done and the customer said so. */
function advance() {
  const at = STEP_ORDER.indexOf(activeStep.value)
  const next = STEP_ORDER[at + 1]

  if (next !== undefined) goTo(next)
}

/*
 * The guide asks, it does not wait to be pressed: arriving at the proposal step with no
 * design yet opens the questions straight away — "şimdi sen: ne istersin?" is the screen,
 * not a button in front of it.
 */
watch(activeStep, (step) => {
  if (step === 'propose' && designs.value.length === 0) {
    creatingDesign.value = true
  }
}, { immediate: true })

/** After an upload or a deletion: reload, and if the reading is underway, watch it. */
async function onMediaChanged() {
  await load()
  await loadLayout()
}

/** The size the reading proposed, as the plan holds it or as the analysis said it. */
const proposedSize = computed(() => {
  const pending = pendingGeometry.value
  const estimate = room.value?.analysis?.estimated_dimensions ?? null
  const width = pending?.width_mm ?? estimate?.width_mm ?? null
  const length = pending?.length_mm ?? estimate?.length_mm ?? null
  const height = pending?.height_mm ?? estimate?.height_mm ?? null

  if (width === null || length === null) return null

  return {
    width,
    length,
    height,
    text: `${mm(width)} × ${mm(length)} m${height ? `, tavan ${mm(height)} m` : ''}`,
  }
})

/** The room the openings editor draws: the agreed size, else the proposal, else the room's own. */
const editorGeometry = computed(() => {
  const g = confirmedGeometry.value ?? pendingGeometry.value

  return {
    width_mm: g?.width_mm ?? room.value?.width_mm ?? 4_000,
    length_mm: g?.length_mm ?? room.value?.length_mm ?? 5_000,
    height_mm: g?.height_mm ?? room.value?.height_mm ?? 2_700,
  }
})

const OPENING_TYPES = ['door', 'balcony_door', 'window']
const otherFixtures = computed(() => (room.value?.constraints ?? []).filter(item => !OPENING_TYPES.includes(item.type)))

async function onOpeningsChanged() {
  await load()
  await loadLayout()
}

const editingSize = ref(false)
const confirmingSize = ref(false)

/** Says yes to the proposed size: the plan's proposal when there is one, else the reading's. */
async function acceptProposal() {
  const size = proposedSize.value

  if (size === null) return

  confirmingSize.value = true
  actionError.value = null

  try {
    let id = pendingGeometry.value?.id ?? null

    if (id === null) {
      const created = await api.post<{ data: GeometryVersion }>(`${base}/geometry`, {
        width_mm: size.width,
        length_mm: size.length,
        height_mm: size.height ?? 2_700,
        source: 'ai',
      })

      id = created.data.id
    }

    await api.post(`${base}/geometry/${id}/confirm`)
    await load()
    await loadLayout()
    advance()
  }
  catch (error) {
    actionError.value = error instanceof ApiError ? error.message : 'Ölçüler onaylanamadı.'
  }
  finally {
    confirmingSize.value = false
  }
}

/** Saves the size the customer typed and confirms it in one go. */
async function submitSize() {
  const width = cmToMm(sizeForm.width)
  const length = cmToMm(sizeForm.length)
  const height = cmToMm(sizeForm.height)

  if (width === null || length === null) {
    actionError.value = 'Genişlik ve uzunluk gerekli.'

    return
  }

  confirmingSize.value = true
  actionError.value = null

  try {
    // The room's own record first (quality travels with the numbers, or the update is
    // refused), then the geometry the plan works from, confirmed.
    await api.patch(base, {
      measurement_quality: sizeForm.quality === 'unknown' ? 'manual' : sizeForm.quality,
      width_mm: width,
      length_mm: length,
      height_mm: height,
    })

    const created = await api.post<{ data: GeometryVersion }>(`${base}/geometry`, {
      width_mm: width,
      length_mm: length,
      height_mm: height ?? 2_700,
      source: 'user',
    })

    await api.post(`${base}/geometry/${created.data.id}/confirm`)

    editingSize.value = false
    await load()
    await loadLayout()
    advance()
  }
  catch (error) {
    actionError.value = error instanceof ApiError
      ? (error.fieldError('width_mm') ?? error.fieldError('length_mm') ?? error.message)
      : 'Ölçüler kaydedilemedi.'
  }
  finally {
    confirmingSize.value = false
  }
}

/** Signed links for the before/after on the plate step, asked for only while it is shown. */
const plateLinks = ref<{ before: string, after: string } | null>(null)

watch([activeStep, () => primaryPlate.value?.id ?? null], async ([step, plateId]) => {
  if (step !== 'plate' || plateId === null || primaryPhoto.value === null) {
    plateLinks.value = null

    return
  }

  try {
    const [before, after] = await Promise.all([
      api.get<{ data: { url: string } }>(`${base}/media/${primaryPhoto.value.id}/link`),
      api.get<{ data: { url: string } }>(`${base}/media/${plateId}/link`),
    ])

    plateLinks.value = { before: before.data.url, after: after.data.url }
  }
  catch {
    plateLinks.value = null
  }
}, { immediate: true })

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

/**
 * Asks for the plate with the customer's choice of what stays; `remake` asks for it again
 * with the current choice, replacing the plate that is there.
 */
async function clearPrimary(remake = false) {
  const photo = primaryPhoto.value

  if (photo === null || (primaryPlate.value !== null && !remake)) return

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

/**
 * What the guide says on this screen (docs/product/REHBER.md §3), for the step on screen.
 *
 * One state at a time. The step decides the subject; what the room has decides the
 * sentence, the button and whether the guide is busy.
 */
type GuideIcon = 'sparkle' | 'camera' | 'eye' | 'broom' | 'ruler' | 'pencil' | 'light' | 'check' | 'home' | 'door'

interface GuideState {
  icon: GuideIcon
  say: string
  detail: string | null
  action: { label: string, to?: string, busy?: boolean, note?: string } | null
  secondary: { label: string, to?: string } | null
  tips: Array<{ icon: GuideIcon, label: string, hint?: string }>
  choices: Array<{ key: string, label: string, selected: boolean }>
  busy: boolean
}

const quiet = (state: Partial<GuideState> & Pick<GuideState, 'icon' | 'say'>): GuideState => ({
  detail: null,
  action: null,
  secondary: null,
  tips: [],
  choices: [],
  busy: false,
  ...state,
})

const guide = computed<GuideState>(() => {
  const current = room.value
  const analysis = current?.analysis ?? null
  const plan = `/projects/${projectId}/rooms/${roomId}/plan`
  const design = latestDesign.value

  if (current === null) {
    return quiet({ icon: 'sparkle', say: 'Buradayım.' })
  }

  switch (activeStep.value) {
    case 'photo':
      if (photoCount.value === 0) {
        return quiet({
          icon: 'camera',
          say: 'Hadi odanın fotoğrafını çekelim.',
          detail: 'Tek yön bana odanı anlatmaz. Kapıdan içeri bir kare, sonra sol köşe, sağ köşe ve karşı duvar — dördünü birden okuyup odanı tanıyacağım.',
          tips: [
            { icon: 'door', label: 'Kapıdan içeri', hint: 'Odanın tamamı görünsün' },
            { icon: 'camera', label: 'Sol köşeden', hint: 'Pencereyi de al' },
            { icon: 'camera', label: 'Sağ köşeden', hint: 'Kapı görünsün' },
            { icon: 'light', label: 'Gündüz ışığında', hint: 'Renkleri doğru okurum' },
          ],
        })
      }

      if (analysis === null && current.analysis_failure !== null && !analysing.value) {
        return quiet({
          icon: 'eye',
          say: 'Bunu okuyamadım. Bir daha deneyelim mi?',
          detail: current.analysis_failure,
          action: { label: 'Yeniden oku' },
        })
      }

      if (!recognised.value) {
        return quiet({
          icon: 'eye',
          say: photoCount.value === 1 ? 'Kareyi aldım, odanı okuyorum.' : `${photoCount.value} kareyi aldım, odanı okuyorum.`,
          detail: photoCount.value < 3
            ? 'Bir dakika kadar sürer. Bu arada bir-iki köşe daha eklersen odayı çok daha iyi anlarım; bitince devam ederiz.'
            : 'Bir dakika kadar sürer; buradayım. Bitince devam ederiz.',
          busy: true,
        })
      }

      return quiet({
        icon: 'camera',
        say: `${photoCount.value} kare var, odanı okudum.`,
        detail: 'Bir kare daha ekleyebilirsin; eklersen yeniden okurum. Yoksa devam edelim.',
        action: { label: 'Devam et' },
      })

    case 'recognise':
      if (analysing.value || (analysis === null && current.analysis_failure === null)) {
        return quiet({
          icon: 'eye',
          say: photoCount.value === 1 ? 'Kareyi okuyorum.' : `${photoCount.value} fotoğrafı okuyorum.`,
          detail: 'Bir dakika kadar sürer; buradayım. Duvarları, kapıyı, pencereyi ve odanda duranları çıkarıyorum.',
          secondary: photoCount.value < 4 ? { label: 'Bir kare daha ekle' } : null,
          busy: true,
        })
      }

      if (analysis === null) {
        return quiet({
          icon: 'eye',
          say: 'Bunu okuyamadım. Bir daha deneyelim mi?',
          detail: current.analysis_failure,
          action: { label: 'Yeniden oku' },
        })
      }

      {
        const seen = analysis.movable_objects.slice(0, 5).map(object => object.label.toLocaleLowerCase('tr-TR')).join(', ')

        return quiet({
          icon: 'eye',
          say: analysis.movable_objects.length > 0 ? `Odanı gördüm: ${seen}${analysis.movable_objects.length > 5 ? '…' : ''}.` : 'Odanı gördüm; içinde eşya yok.',
          detail: `${proposedSize.value ? `Ölçünü de okudum: ${proposedSize.value.text}. ` : ''}${analysis.is_stale ? 'Fotoğraflar değişti; istersen yeniden okuyayım. ' : ''}Devam edelim mi?`,
          action: { label: 'Devam et' },
          secondary: analysis.is_stale ? { label: 'Yeniden oku' } : null,
        })
      }

    case 'confirm':
      if (measured.value && !editingSize.value) {
        return quiet({
          icon: 'ruler',
          say: `Ölçüler tamam: ${mm(current.width_mm)} × ${mm(current.length_mm)} m.`,
          detail: 'Kapı ve pencereleri de aşağıda görüyorsun; yerleri yanlışsa planda sürükleyerek düzelt. Hazırsan devam edelim.',
          action: { label: 'Devam et' },
        })
      }

      if (proposedSize.value && !editingSize.value) {
        return quiet({
          icon: 'ruler',
          say: `Odanı ${proposedSize.value.text} okudum. Doğru mu?`,
          detail: 'Fotoğraftan tahmin ettim; kesin değil. Doğruysa onayla, değilse düzelt — ölçü doğru olunca önerdiğim her şey gerçekten sığar.',
          action: { label: 'Evet, doğru', busy: confirmingSize.value },
          secondary: { label: 'Düzelt' },
        })
      }

      return quiet({
        icon: 'ruler',
        say: 'Ölçüleri sen söyle.',
        detail: 'Genişlik, uzunluk ve tavan; santimetre cinsinden. Duvar diplerinden ölçmek en doğrusu.',
      })

    case 'plate':
      if (primaryPlate.value !== null) {
        return quiet({
          icon: 'check',
          say: 'Odan boş.',
          detail: 'Duvarların, zeminin, kapın ve penceren yerinde; mobilya yalnızca seçtiklerin olacak. Devam edelim mi?',
          action: { label: 'Devam et' },
          secondary: { label: 'Boş odayı yeniden yap' },
        })
      }

      if (analysis !== null && analysis.movable_objects.length > 0) {
        return quiet({
          icon: 'broom',
          say: 'Eşyaları kaldırayım mı?',
          detail: 'Kaldır dediklerimi çıkarıp odanın boş hâlini hazırlarım; kalsın dediklerin yerinde durur. Tasarımı o boş odaya yaparım.',
          action: { label: clearingPlate.value ? 'Kaldırıyorum…' : 'Evet, kaldır', busy: clearingPlate.value, note: 'Yaklaşık bir dakika' },
          secondary: clearingPlate.value ? null : { label: 'Hayır, hepsi kalsın' },
          choices: analysis.movable_objects.map(object => ({ key: object.label, label: object.label, selected: removing.value.has(object.label) })),
          busy: clearingPlate.value,
        })
      }

      return quiet({
        icon: 'broom',
        say: 'Odan zaten boş görünüyor.',
        detail: 'Yine de emin olmak için boş hâlini hazırlayabilirim; ya da doğrudan devam edelim.',
        action: { label: 'Devam et' },
        secondary: { label: 'Boş hâlini hazırla' },
      })

    case 'propose':
      if (design === null || creatingDesign.value) {
        const colours = (analysis?.dominant_colors ?? []).map(colour => colourWords[colour] ?? colour).slice(0, 3).join(', ')

        return quiet({
          icon: 'pencil',
          say: 'Şimdi sen: ne istersin?',
          detail: `${colours ? `Renklerin ${colours}; ` : ''}stilini ve bütçeni söyle, ürünleri odana yerleştirip göstereyim.`,
          action: creatingDesign.value ? null : { label: 'Hadi tasarlayalım' },
        })
      }

      if (design.status === 'generating' || design.status === 'draft') {
        return quiet({
          icon: 'sparkle',
          say: 'Tasarımını çiziyorum.',
          detail: 'Ürünleri seçtim, odana yerleştiriyorum; bir-iki dakika.',
          action: { label: 'Tasarıma bak', to: `/projects/${projectId}/rooms/${roomId}/designs/${design.id}` },
          busy: true,
        })
      }

      return quiet({
        icon: 'check',
        say: 'Tasarımın hazır.',
        detail: 'Beğenmediğin yeri söyle, değiştirelim; ya da yeni bir tasarım iste.',
        action: { label: 'Tasarıma bak', to: `/projects/${projectId}/rooms/${roomId}/designs/${design.id}` },
        secondary: { label: 'Yeni bir tasarım iste' },
      })

    case 'edit':
      return quiet({
        icon: 'pencil',
        say: 'Şimdi odanı düzenle.',
        detail: 'Planda ürünleri oklarla taşı, halkayla döndür; ben duvarları ve kapı önünü korurum.',
        action: { label: 'Planı aç', to: plan },
      })

    default:
      return quiet({
        icon: 'sparkle',
        say: design === null ? 'Render için önce bir tasarım gerek.' : 'Render hazır.',
        detail: design === null ? 'Öneri adımına dönüp bir tasarım isteyelim.' : 'Yerleştirdiğin gibi, gerçek ürünlerle. Sürümleri yan yana görebilirsin.',
        action: design === null ? { label: 'Öneriye dön' } : { label: 'Tasarıma bak', to: `/projects/${projectId}/rooms/${roomId}/designs/${design.id}` },
      })
  }
})

/** What the guide's primary button does when it is not a link. */
function guideAct() {
  const current = room.value

  if (current === null) return

  switch (activeStep.value) {
    case 'photo':
      if (current.analysis === null && current.analysis_failure !== null) void analyse(true)
      else goTo('plate')

      return
    case 'recognise':
      if (current.analysis === null) void analyse(current.analysis_failure !== null)
      else advance()

      return
    case 'confirm':
      if (measured.value) advance()
      else void acceptProposal()

      return
    case 'plate':
      if (primaryPlate.value !== null || current.analysis === null || current.analysis.movable_objects.length === 0) advance()
      else void clearPrimary()

      return
    case 'propose':
      creatingDesign.value = true
      nextTick(() => document.getElementById('tasarim-olustur')?.scrollIntoView({ behavior: 'smooth', block: 'start' }))

      return
    default:
      goTo('propose')
  }
}

/** The guide's quieter button, when it is not a link. */
function guideSecondary() {
  switch (activeStep.value) {
    case 'recognise':
      if (room.value?.analysis !== null && room.value?.analysis !== undefined && room.value.analysis.is_stale) void analyse(true)
      else goTo('photo')

      return
    case 'confirm':
      editingSize.value = true

      return
    case 'plate':
      if (primaryPlate.value !== null) {
        void clearPrimary(true)
      }
      else {
        plateSkipped.value = true
        advance()
      }

      return
    case 'propose':
      creatingDesign.value = true
  }
}
</script>

<template>
  <div class="rc-container rc-container--wide space-y-4 py-4">
  <!--
    A workspace: one line of chrome — where you came from, which room, which step — then the
    guide standing beside the step's work rather than above it. The first version stacked a
    back link, a title, a subtitle, the strip, the guide and the panel and put a footer under
    the lot; the product owner's verdict was "üst bölgeler gereksiz bilgilerle dolu, sürekli
    mouse ile aşağıya iniyorum". Nothing here needs the page to scroll on a laptop.
  -->
    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <template v-else-if="room && project">
      <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
        <div class="flex min-w-0 items-center gap-2 text-sm">
          <NuxtLink :to="`/projects/${projectId}`" class="shrink-0 text-ink-secondary hover:text-ink">
            ← {{ project.name }}
          </NuxtLink>
          <span class="text-muted" aria-hidden="true">·</span>
          <h1 class="truncate font-medium">{{ room.name }}</h1>
          <span v-if="room.floor_area_m2" class="shrink-0 text-xs text-muted">{{ room.floor_area_m2 }} m²</span>
        </div>

        <!--
          No shortcut here. A "Hadi tasarlayalım" button in the header jumped straight to
          the proposal and skipped the guide's questions — the empty room, the size — which
          is exactly the wizard-with-a-different-hat the owner asked us not to build. The
          guide is the only way forward; the strip is the way back.
        -->
        <StudioStepper
          class="min-w-0 flex-1"
          :project-id="projectId"
          :room-id="roomId"
          :current="activeStep"
          :done="studioDone"
          selectable
          @select="goTo"
        />
      </div>

      <div class="grid gap-4 lg:grid-cols-[minmax(320px,380px)_minmax(0,1fr)] lg:items-start">
        <!-- The guide: the one voice that says what comes next (REHBER.md). Beside the work, and it stays put while the work scrolls. -->
        <div class="space-y-3 lg:sticky lg:top-24">
          <StudioGuide
            :icon="guide.icon"
            :say="guide.say"
            :detail="guide.detail"
            :action="canEdit ? guide.action : null"
            :secondary="canEdit ? guide.secondary : null"
            :tips="guide.tips"
            :choices="canEdit ? guide.choices : []"
            :busy="guide.busy"
            @act="guideAct"
            @secondary="guideSecondary"
            @toggle="toggleRemoval"
          />

          <RcAlert v-if="actionError" tone="danger">{{ actionError }}</RcAlert>
        </div>

      <!--
        One step on the screen at a time. The strip says where we are, the guide says what
        to do, and the panel beside it is only that step's work — nothing to scroll past,
        nothing to discover at the bottom of the page.
      -->
      <Transition name="step" mode="out-in">
        <div :key="activeStep" class="min-w-0">
          <!-- 1 · Fotoğraf -->
          <div v-if="activeStep === 'photo'" id="fotograf">
            <RoomPhotoGallery
              :project-id="projectId"
              :room-id="roomId"
              :media="media"
              :can-edit="canEdit"
              @changed="onMediaChanged"
            />
          </div>

          <!-- 2 · Tanıma -->
          <section v-else-if="activeStep === 'recognise'" class="rc-card p-6 sm:p-8">
            <div class="flex flex-wrap items-start justify-between gap-4">
              <div>
                <h2 class="text-lg font-medium">Odanda gördüklerim</h2>
                <p class="mt-1.5 max-w-[62ch] text-sm leading-relaxed text-ink-secondary">
                  <template v-if="analysing || (room.analysis === null && room.analysis_failure === null)">Fotoğrafları okuyorum; bir dakika kadar sürer.</template>
                  <template v-else-if="room.analysis === null">Son okuma tamamlanamadı: {{ room.analysis_failure }}.</template>
                  <template v-else-if="room.analysis.is_stale">Fotoğraflar değişti; bu okuma {{ room.analysis.photo_count }} fotoğraf üzerinden. İstersen yeniden okuyayım.</template>
                  <template v-else>{{ room.analysis.photo_count }} fotoğrafı tek oda olarak okudum.</template>
                </p>
              </div>

              <button
                v-if="canEdit && hasPhoto && !analysing && room.analysis !== null"
                type="button"
                class="rounded-pill border border-line px-4 py-2 text-sm text-ink-secondary hover:bg-bg-muted"
                @click="analyse(true)"
              >
                Yeniden oku
              </button>
            </div>

            <div v-if="room.analysis !== null" class="mt-5 grid gap-4 sm:grid-cols-2">
              <div>
                <h3 class="text-xs font-medium uppercase tracking-wide text-muted">Odada duranlar</h3>
                <p v-if="room.analysis.movable_objects.length === 0" class="mt-1.5 text-sm text-muted">Taşınabilir eşya görmedim.</p>
                <ul v-else class="mt-1.5 flex flex-wrap gap-1.5">
                  <li v-for="(object, at) in room.analysis.movable_objects" :key="`m-${at}`" class="rounded-pill bg-bg-muted px-2.5 py-1 text-xs text-ink-secondary">{{ object.label }}</li>
                </ul>
              </div>
              <div>
                <h3 class="text-xs font-medium uppercase tracking-wide text-muted">Sabit olanlar</h3>
                <p v-if="room.analysis.fixed_elements.length === 0" class="mt-1.5 text-sm text-muted">Sabit öğe görmedim.</p>
                <ul v-else class="mt-1.5 flex flex-wrap gap-1.5">
                  <li v-for="(name, at) in room.analysis.fixed_elements" :key="`f-${at}`" class="rounded-pill border border-line px-2.5 py-1 text-xs text-ink-secondary">{{ name }}</li>
                </ul>
              </div>
              <div v-if="room.analysis.dominant_colors.length > 0">
                <h3 class="text-xs font-medium uppercase tracking-wide text-muted">Renklerin</h3>
                <p class="mt-1.5 text-sm text-ink-secondary">{{ room.analysis.dominant_colors.map(colour => colourWords[colour] ?? colour).join(', ') }}</p>
              </div>
              <div v-if="proposedSize">
                <h3 class="text-xs font-medium uppercase tracking-wide text-muted">Okuduğum ölçü</h3>
                <p class="mt-1.5 text-sm text-ink-secondary">{{ proposedSize.text }} — bir sonraki adımda onaylarsın.</p>
              </div>
              <p v-if="room.analysis.warnings.length > 0" class="text-xs leading-relaxed text-warning sm:col-span-2">
                {{ room.analysis.warnings.join(' · ') }}
              </p>
            </div>
          </section>

          <!-- 3 · Onay: the size, and the doors and windows -->
          <section v-else-if="activeStep === 'confirm'" class="rc-card p-6 sm:p-8">
            <h2 class="text-lg font-medium">Odanın ölçüleri</h2>

            <div v-if="measured && !editingSize" class="mt-4 flex flex-wrap items-center justify-between gap-4 rounded-md bg-bg-muted p-4">
              <p class="text-sm">
                <span class="font-medium">{{ mm(room.width_mm) }} × {{ mm(room.length_mm) }} m</span>
                <span v-if="room.height_mm" class="text-ink-secondary"> · tavan {{ mm(room.height_mm) }} m</span>
                <span v-if="room.floor_area_m2" class="text-muted"> · {{ room.floor_area_m2 }} m²</span>
              </p>
              <button v-if="canEdit" type="button" class="text-sm text-ink-secondary underline-offset-4 hover:underline" @click="editingSize = true">
                Düzelt
              </button>
            </div>

            <div v-else-if="proposedSize && !editingSize" class="mt-4 flex flex-wrap items-center justify-between gap-4 rounded-md bg-bg-muted p-4">
              <p class="text-sm">
                Okuduğum: <span class="font-medium">{{ proposedSize.text }}</span>
                <span class="text-muted"> — tahmin; onaylayınca kesinleşir.</span>
              </p>
              <div v-if="canEdit" class="flex items-center gap-2">
                <RcButton size="sm" :loading="confirmingSize" :disabled="confirmingSize" @click="acceptProposal">Evet, doğru</RcButton>
                <RcButton size="sm" variant="ghost" @click="editingSize = true">Düzelt</RcButton>
              </div>
            </div>

            <form v-if="editingSize || (!measured && !proposedSize)" class="mt-5 space-y-5" @submit.prevent="submitSize">
              <p class="max-w-[62ch] text-sm leading-relaxed text-ink-secondary">
                Santimetre cinsinden yaz; duvar diplerinden ölçmek en doğrusu. Ölçü doğru olunca
                önerdiğim her şey gerçekten sığar.
              </p>
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
              </div>

              <div class="flex items-center gap-3">
                <RcButton v-if="canEdit" type="submit" size="sm" :loading="savingSize || confirmingSize" :disabled="savingSize || confirmingSize">
                  Ölçüleri kaydet
                </RcButton>
                <RcButton v-if="editingSize" size="sm" variant="ghost" @click="editingSize = false">Vazgeç</RcButton>
              </div>
            </form>

            <!-- Doors and windows: dragged into place, added with a click (K6). -->
            <div class="mt-8 border-t border-line pt-6">
              <RoomOpeningsEditor
                :base="base"
                :geometry="editorGeometry"
                :constraints="room.constraints"
                :can-edit="canEdit"
                @changed="onOpeningsChanged"
              />

              <!-- Everything else that is fixed to the room: radiators, columns, built-ins. -->
              <div class="mt-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                  <p class="text-sm text-ink-secondary">Radyatör, kolon, şömine gibi sabitler de varsa söyle; önüne bir şey koymam.</p>
                  <RcButton
                    v-if="canEdit && !addingConstraint"
                    size="sm"
                    variant="secondary"
                    @click="addingConstraint = true"
                  >
                    Ekle
                  </RcButton>
                </div>

                <form v-if="addingConstraint" class="mt-4 space-y-5 rounded-md bg-bg-muted p-5" @submit.prevent="addConstraint">
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

                <ul v-if="otherFixtures.length > 0" class="mt-4 space-y-2">
                  <li
                    v-for="constraint in otherFixtures"
                    :key="constraint.id"
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-line pb-3 text-sm last:border-0"
                  >
                    <div>
                      <p>{{ constraint.description }}</p>
                      <p class="mt-0.5 text-xs text-muted">
                        {{ walls.find(w => w.value === constraint.wall)?.label ?? 'Konum belirtilmedi' }}
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
              </div>
            </div>
          </section>

          <!-- 4 · Boş oda -->
          <section v-else-if="activeStep === 'plate'" class="rc-card p-6 sm:p-8">
            <h2 class="text-lg font-medium">Boş oda</h2>
            <p class="mt-1.5 max-w-[62ch] text-sm leading-relaxed text-ink-secondary">
              Tasarımı odanın eşyaları kaldırılmış hâline yaparım: duvarlar, zemin, pencere ve
              kapı senin; mobilya yalnızca seçtiklerin. Kalsın dediklerin yerinde kalır.
            </p>

            <RoomPlateCompare
              v-if="plateLinks"
              class="mt-5"
              :before="plateLinks.before"
              :after="plateLinks.after"
            />
            <p v-else-if="clearingPlate" class="mt-5 text-sm text-muted">Eşyaları kaldırıyorum; bir dakika kadar sürer.</p>
            <p v-else-if="primaryPlate" class="mt-5 text-sm text-muted">Boş oda hazır; görüntüsü yükleniyor…</p>
          </section>

          <!-- 5 · Öneri -->
          <div v-else-if="activeStep === 'propose'" class="space-y-6">
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
              <h2 class="text-lg font-medium">Ne istersin?</h2>
              <p class="mt-1.5 max-w-[60ch] text-sm leading-relaxed text-ink-secondary">
                Kendi cümlelerinle yaz. Boş bırakırsan odanın türüne ve ölçülerine göre bir öneri hazırlarım.
              </p>

              <form class="mt-5 space-y-5" @submit.prevent="createDesign(null)">
                <div>
                  <label for="prompt" class="mb-1.5 block text-sm font-medium">İstediklerin</label>
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

            <section v-if="designs.length > 0" id="tasarim">
              <h2 class="text-lg font-medium">Tasarımların</h2>
              <div class="mt-4 grid gap-4 sm:grid-cols-2">
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
          </div>

          <!-- 6 · Düzenle and 7 · Render live on their own screens; the guide links to them. -->
          <section v-else class="rc-card p-6 sm:p-8">
            <h2 class="text-lg font-medium">{{ activeStep === 'edit' ? 'Odanı düzenle' : 'Render' }}</h2>
            <p class="mt-1.5 max-w-[62ch] text-sm leading-relaxed text-ink-secondary">
              <template v-if="activeStep === 'edit'">Odanı üç boyutlu gör; ürünleri oklarla taşı, halkayla döndür. Hiçbir şey duvara giremez, kapının önüne konamaz.</template>
              <template v-else>Yerleştirdiğin gibi, gerçek ürünlerle çizerim; her render bir sürümdür, ikisini yan yana görebilirsin.</template>
            </p>
            <NuxtLink
              :to="activeStep === 'edit' || latestDesign === null ? `/projects/${projectId}/rooms/${roomId}/plan` : `/projects/${projectId}/rooms/${roomId}/designs/${latestDesign.id}`"
              class="mt-6 inline-flex rounded-pill bg-charcoal px-4 py-2 text-sm text-white"
            >
              {{ activeStep === 'edit' ? 'Planı aç' : 'Tasarıma bak' }}
            </NuxtLink>
          </section>
        </div>
      </Transition>
      </div>
    </template>
  </div>
</template>

<style scoped>
.step-enter-active,
.step-leave-active {
  transition: opacity 160ms ease, transform 160ms ease;
}

.step-enter-from {
  opacity: 0;
  transform: translateY(8px);
}

.step-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}
</style>
