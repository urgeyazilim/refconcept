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

/**
 * What the form was last filled with from the server, to tell a typed value from a stale one.
 *
 * While the photographs are being read the screen reloads every four seconds, and each
 * reload used to write the room's saved size back over the boxes — so a customer typing
 * their own measurements watched the numbers vanish mid-word and got "Genişlik ve uzunluk
 * gerekli" when they pressed save. A field is only refilled while it still holds what was
 * put there; the moment it holds something of the customer's, it is theirs.
 */
const seeded = reactive({ width: '', length: '', height: '', quality: 'estimated' })
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

/**
 * The customer has put something of their own in the size form.
 *
 * The form otherwise closes the moment the reading proposes a size, which would take a
 * half-typed measurement off the screen with it. Somebody who has started typing is
 * answering the question; the reading can wait its turn.
 */
const typingSize = computed(() => (['width', 'length', 'height'] as const).some(field => sizeForm[field] !== seeded[field]))

/** Puts a value in the form unless the customer has typed over it. */
function fill(field: 'width' | 'length' | 'height' | 'quality', value: string) {
  if (sizeForm[field] === seeded[field]) sizeForm[field] = value

  seeded[field] = value
}

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
    fill('width', roomResponse.data.width_mm ? String(roomResponse.data.width_mm / 10) : '')
    fill('length', roomResponse.data.length_mm ? String(roomResponse.data.length_mm / 10) : '')
    fill('height', roomResponse.data.height_mm ? String(roomResponse.data.height_mm / 10) : '')
    fill('quality', roomResponse.data.measurement_quality)
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

/**
 * Any emptied photograph at all. The server makes an emptied photograph the primary one,
 * but a room emptied before it did, or one where somebody chose another primary since,
 * still answered step two: the furniture question is not asked again.
 */
const anyPlate = computed(() => media.value.some(item => item.type === 'plate'))
const latestDesign = computed(() => designs.value[0] ?? null)

type StudioStep = 'photo' | 'plate' | 'recognise' | 'propose' | 'design' | 'edit' | 'save' | 'render' | 'video' | 'buy'

/**
 * The ten steps in the product owner's order: photographs, the furniture out, the room
 * understood, what you want, the design, the 3D arrangement, saved, the render, the 360
 * tour, the purchase. The first four are this screen's; the plan and the design screens
 * carry the rest, and the guide links across.
 */
const STEP_ORDER: StudioStep[] = ['photo', 'plate', 'recognise', 'propose', 'design', 'edit', 'save', 'render', 'video', 'buy']
const ON_ROOM: StudioStep[] = ['photo', 'plate', 'recognise', 'propose']

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
  // Done when the room is emptied, when there was nothing to empty, or when the customer
  // said to leave it as it is.
  plate: anyPlate.value || plateSkipped.value || (recognised.value && (room.value?.analysis?.movable_objects.length ?? 0) === 0),
  // The room is understood once its size is agreed — the reading proposes, the customer says yes.
  recognise: confirmedGeometry.value !== null || measured.value,
  propose: designs.value.length > 0,
  design: designs.value.some(design => design.status === 'ready'),
  edit: placedCount.value > 0,
  save: placedCount.value > 0,
  // The steps after the arrangement are the design screen's to tick; from here they are ahead.
}))

/**
 * The first step not done, in the guide's order — where the customer is going.
 *
 * The reading has no screen of its own: while the photographs are being read the customer
 * stays with them (and may add a corner), and when the reading lands the guide asks about
 * the furniture. The steps after this screen's four are reached through the guide's link.
 */
const autoStep = computed<StudioStep>(() => {
  const first = STEP_ORDER.find(step => studioDone.value[step] !== true) ?? 'buy'

  if (!recognised.value && (first === 'plate' || first === 'recognise')) {
    return 'photo'
  }

  return first
})

/** A step the customer opened from the strip, to look back or ahead. */
const chosenStep = ref<StudioStep | null>(null)
const activeStep = computed<StudioStep>(() => chosenStep.value ?? autoStep.value)

/**
 * The four steps this screen owns, by the anchor the strip links each of them to.
 *
 * Kept in the address so that the step survives a reload: somebody who refreshes, or comes
 * back to a tab, lands where they were rather than wherever the guide would start them. The
 * design screen and the plan already work this way; this one did not, and a page that reloads
 * itself for any reason threw the customer back to the beginning mid-sentence.
 */
const STEP_HASH: Partial<Record<StudioStep, string>> = {
  photo: '#fotograf',
  plate: '#esyalar',
  recognise: '#oda',
  propose: '#istekler',
}

function stepFromHash(hash: string): StudioStep | null {
  const found = (Object.keys(STEP_HASH) as StudioStep[]).find(key => STEP_HASH[key] === hash)

  return found ?? null
}

onMounted(() => {
  const asked = stepFromHash(window.location.hash)

  if (asked !== null) chosenStep.value = asked

  window.addEventListener('hashchange', onHashChange)
})

onBeforeUnmount(() => {
  if (import.meta.client) window.removeEventListener('hashchange', onHashChange)
})

function onHashChange() {
  const asked = stepFromHash(window.location.hash)

  if (asked !== null) chosenStep.value = asked
}

/**
 * Opens a step, and stays on it.
 *
 * It used to forget the choice whenever it happened to match the step the guide would have
 * picked anyway — tidier, and wrong: the automatic step is "the first one not finished", so
 * a reading landing a second later could make an earlier step unfinished again and take the
 * screen with it. A customer typing their measurements on step three was dropped back onto
 * step two mid-word, because the reading had just found a sofa to ask about.
 *
 * The guide moves the screen by calling this itself, so nothing is lost by remembering.
 */
function goTo(step: StudioStep) {
  chosenStep.value = step
  editingSize.value = false

  const at = STEP_HASH[step]

  if (import.meta.client && at !== undefined && window.location.hash !== at) {
    history.replaceState(history.state, '', `${window.location.pathname}${window.location.search}${at}`)
  }

  window.scrollTo({ top: 0, behavior: 'smooth' })
}

/**
 * Moves on when a step's work is done and the customer said so.
 *
 * From the step the customer was on, passed in by callers that change state first: saying
 * yes to the size makes step 3 done, which moves the automatic step to 4 — and advancing
 * from *that* landed on 5, skipping the questions. Found by walking the steps.
 */
function advance(from: StudioStep = activeStep.value) {
  const at = STEP_ORDER.indexOf(from)
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
  const before = room.value?.analysis?.id ?? null

  await load()
  await loadLayout()

  // The upload queued a reading; follow it, so the guide moves on when it lands.
  if (hasPhoto.value && !recognised.value && (room.value?.analysis_failure ?? null) === null) {
    watchReading(before)
  }
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
  // What the photograph said the room is made of and painted. The room step drew every room
  // as the same cream box with a wooden floor until the reading's own answer was passed on.
  const surfaces = room.value?.analysis?.surfaces ?? null

  return {
    width_mm: g?.width_mm ?? room.value?.width_mm ?? 4_000,
    length_mm: g?.length_mm ?? room.value?.length_mm ?? 5_000,
    height_mm: g?.height_mm ?? room.value?.height_mm ?? 2_700,
    floor: surfaces?.floor ?? null,
    wall_color: surfaces?.wall_color ?? null,
    ceiling_color: surfaces?.ceiling_color ?? null,
    crown_molding: surfaces?.crown_molding ?? false,
  }
})

const OPENING_TYPES = ['door', 'balcony_door', 'window']
const otherFixtures = computed(() => (room.value?.constraints ?? []).filter(item => !OPENING_TYPES.includes(item.type)))

/**
 * How many doors and windows are on the walls, so the guide can say so.
 *
 * It used to claim it had put them there whether or not it had. The reading had found a
 * three-metre window and a door and the screen said neither — a promise the room in front
 * of the customer did not keep.
 */
const openingCount = computed(() => (room.value?.constraints ?? []).filter(item => OPENING_TYPES.includes(item.type)).length)

/** What the guide says it did with the doors and windows, which is what it did. */
const openingsSaid = computed(() => (openingCount.value === 0
  ? 'Kapı ya da pencere seçemedim; soldaki simgelerden ekleyebilirsin'
  : `${openingCount.value} kapı/pencere buldum, duvarlara yerleştirdim`))

async function onOpeningsChanged() {
  await load()
  await loadLayout()
}

const editingSize = ref(false)
const confirmingSize = ref(false)

/** Says yes to the proposed size: the plan's proposal when there is one, else the reading's. */
async function acceptProposal() {
  const from = activeStep.value
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
    advance(from)
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
  const from = activeStep.value
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
    advance(from)
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

/**
 * The photograph on its own, while the room is still being emptied.
 *
 * The step asks "shall I take the furniture out?" about a room the screen was not showing:
 * before a plate existed there was the question, and then six hundred pixels of nothing.
 * Somebody is being asked to decide about a picture; the picture has to be on the screen.
 */
const photoLink = ref<string | null>(null)

watch([activeStep, () => primaryPlate.value?.id ?? null, () => primaryPhoto.value?.id ?? null], async ([step, plateId, photoId]) => {
  if (step !== 'plate' || photoId === null || typeof photoId !== 'string') {
    plateLinks.value = null
    photoLink.value = null

    return
  }

  try {
    const before = await api.get<{ data: { url: string } }>(`${base}/media/${photoId}/link`)

    photoLink.value = before.data.url

    if (plateId === null || typeof plateId !== 'string') {
      plateLinks.value = null

      return
    }

    const after = await api.get<{ data: { url: string } }>(`${base}/media/${plateId}/link`)

    plateLinks.value = { before: before.data.url, after: after.data.url }
  }
  catch {
    plateLinks.value = null
    photoLink.value = null
  }
}, { immediate: true })

/**
 * Reading the room from its photographs (step 2).
 *
 * Queued on the server after every upload, and here on request; the page reloads the room
 * until the reading appears, and stops asking after a couple of minutes rather than forever.
 */
/** The reading has been under way far longer than it should; the guide offers a way on. */
const readingStalled = ref(false)

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

  watchReading(before)
}

/**
 * Follows a reading that is under way until it lands, fails or takes too long.
 *
 * Called after an upload as well as after "Yeniden oku". It was only called for the latter,
 * so the guide said "okuyorum… bitince devam ederiz" after a photograph and then nothing
 * happened until the customer reloaded the page — found by walking the ten steps as a
 * customer, not by any test.
 *
 * @param before the reading on the room when the wait began; a new one ends it
 */
function watchReading(before: string | null): void {
  stopWatchingAnalysis()

  analysing.value = true
  readingStalled.value = false
  analysingSince = Date.now()

  analysingTimer = setInterval(async () => {
    await load()

    const now = room.value?.analysis

    const failed = room.value?.analysis_failure !== null && room.value?.analysis_failure !== undefined && (now === null || now === undefined || now.id === before)

    const tooLong = Date.now() - analysingSince > 90_000

    if ((now !== null && now !== undefined && now.id !== before && !now.is_stale) || failed || tooLong) {
      readingStalled.value = tooLong && (now === null || now === undefined || now.id === before)
      stopWatchingAnalysis()
      await loadLayout()
    }
  }, 4_000)
}

onBeforeUnmount(stopWatchingAnalysis)

// A room whose photographs are being read when the page opens: follow that reading too.
// In the browser only — a timer started during server rendering is a 500 page.
onMounted(() => {
  if (hasPhoto.value && !recognised.value && (room.value?.analysis_failure ?? null) === null) {
    watchReading(room.value?.analysis?.id ?? null)
  }
})

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

/** How long a plate is waited for before the guide says it could not be made. */
const PLATE_WAIT_MS = 150_000

/** The last attempt produced no plate; the guide offers another go or the room as it is. */
const plateFailed = ref(false)

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

  plateFailed.value = false
  const since = Date.now()

  plateTimer = setInterval(async () => {
    await load()

    // Landed — or not landed in the time a plate takes. The job says nothing when the
    // provider's answer is thrown away, so the wait has to end on its own: found by walking
    // the steps, where "Kaldırıyorum…" spun for three minutes with nothing behind it.
    const landed = primaryPlate.value !== null
    const gaveUp = Date.now() - since > PLATE_WAIT_MS

    if (landed || gaveUp) {
      clearingPlate.value = false
      plateFailed.value = !landed

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
          /*
           * Sideways, first.
           *
           * A render is a wide picture of a room, so a tall narrow photograph hands the model
           * a strip of one: ceiling and floor, with the walls the furniture goes against cut
           * off at both sides. It was the product owner who worked out why some rooms came
           * back worse than others, which means the screen was not saying it.
           */
          tips: [
            { icon: 'camera', label: 'Telefonu yan çevir', hint: 'Yatay kare, oda tamamen girsin' },
            { icon: 'door', label: 'Kapıdan içeri', hint: 'Odanın tamamı görünsün' },
            { icon: 'camera', label: 'Sol ve sağ köşeden', hint: 'Pencere ve kapı görünsün' },
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

      /*
       * Waited long enough. A reading that never lands used to leave "odanı okuyorum" on the
       * screen for ever — the worker was busy with something else entirely and nothing on
       * this page ever said so. It says so now, and offers both ways on.
       */
      if (!recognised.value && readingStalled.value) {
        return quiet({
          icon: 'eye',
          say: 'Okuma uzun sürdü.',
          detail: 'Bir daha deneyeyim mi? Beklemek istemezsen ölçüleri sen söyleyip devam edebilirsin.',
          action: { label: 'Yeniden oku' },
          secondary: { label: 'Beklemeden devam et' },
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
      // Step 3: the room as read from every corner — its size, its doors and windows. One
      // question, the product owner's choice: "Doğru mu?"
      if (analysis === null && current.analysis_failure !== null && !analysing.value) {
        return quiet({
          icon: 'eye',
          say: 'Bunu okuyamadım. Bir daha deneyelim mi?',
          detail: current.analysis_failure,
          action: { label: 'Yeniden oku' },
          secondary: { label: 'Ölçüleri ben söyleyeyim' },
        })
      }

      if (measured.value && !editingSize.value) {
        return quiet({
          icon: 'ruler',
          say: `Odan ${mm(current.width_mm)} × ${mm(current.length_mm)} m.`,
          detail: `${photoCount.value} kareden okudum. ${openingsSaid.value} — yeri yanlışsa tutup sürükle. Hazırsan devam edelim.`,
          action: { label: 'Devam et' },
          secondary: analysis?.is_stale ? { label: 'Yeniden oku' } : null,
        })
      }

      if (proposedSize.value && !editingSize.value) {
        return quiet({
          icon: 'ruler',
          say: `Odanı ${proposedSize.value.text} okudum. Doğru mu?`,
          detail: `${photoCount.value} kareden çıkardım; ${openingsSaid.value}. Doğruysa onayla, değilse düzelt — ölçü doğru olunca önerdiğim her şey gerçekten sığar.`,
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
      // Step 2 waits for the reading: the question is about what the reading saw.
      if (analysing.value || (analysis === null && current.analysis_failure === null)) {
        return quiet({
          icon: 'eye',
          say: photoCount.value === 1 ? 'Kareye bakıyorum.' : `${photoCount.value} kareye bakıyorum.`,
          detail: 'Odanda ne duruyor, çıkarıyorum; bir dakika kadar sürer.',
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

      if (anyPlate.value) {
        return quiet({
          icon: 'check',
          say: 'Odan boş.',
          detail: 'Duvarların, zeminin, kapın ve penceren yerinde; mobilya yalnızca seçtiklerin olacak. Devam edelim mi?',
          action: { label: 'Devam et' },
          secondary: { label: 'Boş odayı yeniden yap' },
        })
      }

      if (plateFailed.value && !clearingPlate.value) {
        return quiet({
          icon: 'broom',
          say: 'Boş odayı hazırlayamadım.',
          detail: 'Bir daha deneyebilirim; ya da eşyalar yerinde kalsın, tasarımı öyle yaparım.',
          action: { label: 'Yine dene' },
          secondary: { label: 'Eşyalarla devam et' },
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
    case 'save':
      return quiet({
        icon: 'pencil',
        say: placedCount.value > 0 ? 'Ürünlerin 3B odanda.' : 'Şimdi odanı 3B görelim.',
        detail: placedCount.value > 0
          ? 'Tasarımdaki gibi yerleştirdim; beğenmediğini tut, taşı. Her hareketi kaydederim.'
          : 'Tasarımdaki ürünleri odana ben yerleştiririm; sen istersen taşırsın.',
        action: { label: 'Odayı aç', to: plan },
      })

    default:
      // Design, render, 360 and purchase live on the design screen; from here, the way there.
      if (design === null) {
        return quiet({
          icon: 'sparkle',
          say: 'Önce bir tasarım gerek.',
          detail: 'İstekler adımına dönelim; ne istediğini söyle, tasarlayayım.',
          action: { label: 'İsteklere dön' },
        })
      }

      return quiet({
        icon: 'sparkle',
        say: activeStep.value === 'render' ? 'Render tasarım ekranında.' : activeStep.value === 'video' ? '360 tur tasarım ekranında.' : activeStep.value === 'buy' ? 'Satın alma tasarım ekranında.' : 'Tasarımın tasarım ekranında.',
        detail: 'Oraya geçelim; devamını orada sorarım.',
        action: { label: 'Tasarıma geç', to: `/projects/${projectId}/rooms/${roomId}/designs/${design.id}` },
      })
  }
})

/** What the guide's primary button does when it is not a link. */
function guideAct() {
  const current = room.value

  if (current === null) return

  switch (activeStep.value) {
    case 'photo':
      if (readingStalled.value || (current.analysis === null && current.analysis_failure !== null)) void analyse(true)
      else goTo('plate')

      return
    case 'plate':
      if (current.analysis === null) void analyse(current.analysis_failure !== null)
      else if (anyPlate.value || current.analysis.movable_objects.length === 0) advance()
      else void clearPrimary()

      return
    case 'recognise':
      if (current.analysis === null && current.analysis_failure !== null && !measured.value && !proposedSize.value) void analyse(true)
      else if (measured.value) advance()
      else void acceptProposal()

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
    // 'Beklemeden devam et': the reading is taking too long, so go on and type the size.
    case 'photo':
      readingStalled.value = false
      stopWatchingAnalysis()
      goTo('recognise')
      editingSize.value = true

      return
    case 'recognise':
      if (room.value?.analysis?.is_stale === true && measured.value) void analyse(true)
      else editingSize.value = true

      return
    case 'plate':
      if (anyPlate.value) {
        void clearPrimary(primaryPlate.value !== null)
      }
      else {
        const from = activeStep.value
        plateSkipped.value = true
        advance(from)
      }

      return
    case 'propose':
      creatingDesign.value = true
  }
}
</script>

<template>
  <div class="rc-page rc-page--wide rc-page--workspace flex flex-col gap-3 lg:h-[calc(100vh-var(--rc-header))]">
  <!--
    A workspace: one line of chrome — where you came from, which room, which step — then the
    guide standing beside the step's work rather than above it. The first version stacked a
    back link, a title, a subtitle, the strip, the guide and the panel and put a footer under
    the lot; the product owner's verdict was "üst bölgeler gereksiz bilgilerle dolu, sürekli
    mouse ile aşağıya iniyorum". Nothing here needs the page to scroll on a laptop.
  -->
    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <template v-else-if="room && project">
      <div class="flex min-w-0 flex-wrap items-center gap-x-4 gap-y-2">
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
          class="w-full min-w-0"
          :project-id="projectId"
          :room-id="roomId"
          :current="activeStep"
          :done="studioDone"
          :design-id="latestDesign?.id ?? null"
          :own="ON_ROOM"
          selectable
          @select="goTo"
        />
      </div>

      <!-- The guide: the one voice that says what comes next (REHBER.md). One band, full width. -->
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

      <!--
        One step on the screen at a time. The strip says where we are, the guide says what
        to do, and the panel beside it is only that step's work — nothing to scroll past,
        nothing to discover at the bottom of the page.
      -->
      <Transition name="step" mode="out-in">
        <div :key="activeStep" class="flex min-h-0 min-w-0 flex-1 flex-col lg:overflow-y-auto">
          <!-- 1 · Fotoğraf -->
          <div v-if="activeStep === 'photo'" id="fotograf" class="flex min-h-0 flex-1 flex-col">
            <RoomPhotoGallery
              :project-id="projectId"
              :room-id="roomId"
              :media="media"
              :can-edit="canEdit"
              @changed="onMediaChanged"
            />
          </div>

          <!-- 3 · Oda: the size as read, and the doors and windows -->
          <!--
            The room itself, in three dimensions.

            It used to be a heading, the size written out with the same two buttons the guide
            band already carries, three lines of instructions and the room as a flat black
            rectangle. The product owner's verdict was "cin ali gibi" — a child's primer —
            and they were right: we have a 3D room and were not showing it. The size question
            is the band's; this is the room, with its doors and windows on it.
          -->
          <section v-else-if="activeStep === 'recognise'" id="oda" class="flex min-h-0 flex-col gap-3">
            <!-- The size, only when somebody is typing it, and on one line: the room below is the point. -->
            <form v-if="editingSize || typingSize || (!measured && !proposedSize)" class="rc-card flex flex-wrap items-end gap-3 p-3" @submit.prevent="submitSize">
              <label class="w-28">
                <span class="mb-1 block text-xs text-muted">Genişlik (cm)</span>
                <input id="width" v-model="sizeForm.width" :disabled="!canEdit" inputmode="numeric" class="w-full rounded-sm border border-line bg-surface px-3 py-2 text-sm tabular-nums">
              </label>
              <label class="w-28">
                <span class="mb-1 block text-xs text-muted">Uzunluk (cm)</span>
                <input id="length" v-model="sizeForm.length" :disabled="!canEdit" inputmode="numeric" class="w-full rounded-sm border border-line bg-surface px-3 py-2 text-sm tabular-nums">
              </label>
              <label class="w-28">
                <span class="mb-1 block text-xs text-muted">Tavan (cm)</span>
                <input id="height" v-model="sizeForm.height" :disabled="!canEdit" inputmode="numeric" class="w-full rounded-sm border border-line bg-surface px-3 py-2 text-sm tabular-nums">
              </label>
              <label class="w-44">
                <span class="mb-1 block text-xs text-muted">Nereden geliyor?</span>
                <select id="quality" v-model="sizeForm.quality" :disabled="!canEdit" class="w-full rounded-sm border border-line bg-surface px-3 py-2 text-sm">
                  <option v-for="quality in qualities" :key="quality.value" :value="quality.value">{{ quality.label }}</option>
                </select>
              </label>

              <RcButton v-if="canEdit" type="submit" size="sm" :loading="savingSize || confirmingSize" :disabled="savingSize || confirmingSize">Ölçüleri kaydet</RcButton>
              <RcButton v-if="editingSize" size="sm" variant="ghost" @click="editingSize = false">Vazgeç</RcButton>
            </form>

            <!-- Doors and windows, on the room itself: dragged into place, added with a click (K6). -->
            <div class="flex min-h-0 flex-1 flex-col">
              <RoomOpeningsEditor
                :base="base"
                :geometry="editorGeometry"
                :constraints="room.constraints"
                :can-edit="canEdit"
                :measured="measured || proposedSize !== null"
                @changed="onOpeningsChanged"
              >

                <template #side>
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
                      Sabit ekle
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
                </template>
              </RoomOpeningsEditor>
            </div>
          </section>

          <!--
            2 · Eşyalar: the room the question is about.

            The emptied room against the photograph once there is one; the photograph on its
            own until then. The panel used to wait for the plate, so the step asked whether to
            take the furniture out of a room it was not showing.
          -->
          <section v-else-if="activeStep === 'plate'" id="esyalar" class="flex min-h-0 flex-1 flex-col items-center justify-center">
            <RoomPlateCompare
              v-if="plateLinks"
              :before="plateLinks.before"
              :after="plateLinks.after"
            />

            <figure v-else-if="photoLink" class="flex min-h-0 flex-col items-center gap-2">
              <img :src="photoLink" alt="Odanın fotoğrafı" class="max-h-[calc(100vh-23rem)] w-auto rounded-lg border border-line object-contain">
              <figcaption class="text-xs text-muted">
                {{ clearingPlate ? 'Eşyaları kaldırıyorum; bir dakika kadar sürer.' : 'Kaldır dediklerin bu kareden çıkacak.' }}
              </figcaption>
            </figure>

            <p v-else class="text-sm text-muted">Fotoğrafın yükleniyor…</p>
          </section>

          <!-- 4 · İstekler -->
          <div v-else-if="activeStep === 'propose'" id="istekler" class="space-y-6">
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

          <!--
            Steps 5–10 live on the plan and the design screen. Nothing is drawn for them
            here: the guide band above already says where the step is and has the button
            that goes there, and a card repeating it in an empty screen is a dead end with
            a heading on it.
          -->
          <div v-else />
        </div>
      </Transition>
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
