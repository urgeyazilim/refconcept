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
import { type DoorSwing, type OpeningKind, describeKind, hasSwing, hingeIsLeft, kindsFor, opensIn, otherJamb, otherWay, swingOf, variantOf } from '~/room3d/openings'
import type { LayoutItem, RoomGeometry, RoomOpening, WallName } from '~/room3d/types'

definePageMeta({ middleware: ['auth', 'verified'], layout: 'default' })

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

/**
 * What is standing in the room right now, as the editor has it.
 *
 * Separate from `items`, which is what was loaded or composed and is bound to the scene as a
 * prop — writing the live list back into that prop would hand it to the editor again and
 * clear the undo history under whoever is working. This copy is for the page: the buttons
 * that need to know whether the room is empty, and the placement request, which depends on
 * what is in the room *now* rather than on what was last written a second and a half ago.
 */
const liveItems = ref<LayoutItem[]>([])

const loading = ref(true)
const loadError = ref<string | null>(null)
const saveError = ref<string | null>(null)
const confirming = ref(false)

/** What the room itself says: its typed size and whether it has a photograph. */
const roomFacts = ref<{ width_mm: number | null, length_mm: number | null, height_mm: number | null, photo_count: number } | null>(null)

/**
 * Where this screen sits in the studio's steps: confirming the room until the geometry is
 * confirmed, editing once it is.
 */
const studioCurrent = computed<'confirm' | 'edit'>(() => (confirmed.value === null ? 'confirm' : 'edit'))
const studioDone = computed(() => ({
  photo: (roomFacts.value?.photo_count ?? 0) > 0,
  recognise: confirmed.value !== null || pending.value.length > 0 || (roomFacts.value?.width_mm ?? null) !== null,
  confirm: confirmed.value !== null,
  edit: liveItems.value.length > 0,
}))

/** The 3D scene, for the picture the renderer works from and for adding products to. */
const scene = ref<{
  snapshot: () => string | null
  add: (item: LayoutItem) => void
} | null>(null)

/** One catalogue result, reduced to what a plan needs: a name, a size and a price. */
interface Candidate {
  id: string
  name: string
  category: string | null
  sku_id: string
  width_mm: number | null
  height_mm: number | null
  depth_mm: number | null
  price: string
  image_url: string | null
  // Carried so a just-added product is drawn as a mesh straight away rather than as a
  // cut-out until the next load. `model_source` is what makes the editor say "temsilî".
  model_url: string | null
  model_source: 'seller' | 'ai' | null
}

const search = ref('')
const searching = ref(false)
const candidates = ref<Candidate[]>([])
const searched = ref(false)

/**
 * The categories that belong in this room, for browsing rather than searching.
 *
 * Somebody who knows they want a bookcase types "kitaplık". Somebody furnishing a room does
 * not know yet, and a search box is a blank page for them — the list is the difference
 * between shopping and having to name what you want first.
 */
interface RoomCategory {
  slug: string
  name: string
}

const categories = ref<RoomCategory[]>([])
const activeCategory = ref<string | null>(null)
const roomType = ref<string | null>(null)

/**
 * What the analysis found, and where in the photograph it found it.
 *
 * Fetched with the layout; the signed link to the photograph is asked for separately and only
 * when there is something to draw on it, because a link is a deliberate request that runs the
 * ownership check and expires in five minutes.
 */
interface Detection {
  media_id: string | null
  regions: Array<{ kind: string, label: string | null, box: number[] }>
  warnings: string[]
}

const detected = ref<Detection | null>(null)
const photoUrl = ref<string | null>(null)

/** The design a final image would be made from, when the room has one. */
const design = ref<{ design_id: string, version_id: string, version_number: number } | null>(null)
const rendering = ref(false)

const composing = ref(false)
const composeNotice = ref<string | null>(null)

const adding = ref(false)
const cartNotice = ref<string | null>(null)

// --- doors and windows -----------------------------------------------------------

/**
 * The openings, corrected by hand.
 *
 * The analysis reads them off a photograph and is right to within a hand's width most of
 * the time; the plan is where somebody slides the window to where it actually is, adds the
 * door the photograph did not show, or removes the "window" that was a mirror. Every change
 * goes to the room's constraints — the same rows the layout rules and the render read — so
 * the 3D scene, the collision rules and the final picture all see the same wall.
 */
const openingNotice = ref<string | null>(null)
const openingBusy = ref(false)

const WALL_LABELS: Record<string, string> = { north: 'kuzey', east: 'doğu', south: 'güney', west: 'batı' }

function asOpening(raw: Record<string, unknown>): RoomOpening {
  return {
    id: String(raw.id),
    type: String(raw.type),
    variant: typeof raw.variant === 'string' ? raw.variant : null,
    swing: typeof raw.swing === 'string' ? raw.swing : null,
    wall: (raw.wall as RoomOpening['wall']) ?? null,
    offset_mm: typeof raw.offset_mm === 'number' ? raw.offset_mm : null,
    width_mm: typeof raw.width_mm === 'number' ? raw.width_mm : null,
    height_mm: typeof raw.height_mm === 'number' ? raw.height_mm : null,
    sill_height_mm: typeof raw.sill_height_mm === 'number' ? raw.sill_height_mm : null,
  }
}

async function moveOpening(id: string, offsetMm: number, wall: WallName): Promise<void> {
  const previous = openings.value

  // Moved on screen first, put back if the server refuses: a window that snaps back a second
  // after being dragged is honest, and a window that waits a second to move feels broken.
  openings.value = openings.value.map(opening => (opening.id === id ? { ...opening, offset_mm: offsetMm, wall } : opening))

  try {
    await api.patch(`${base}/constraints/${id}`, { offset_mm: offsetMm, wall, notes: 'Sizin düzelttiğiniz.' })
  }
  catch (error) {
    openings.value = previous
    openingNotice.value = error instanceof ApiError ? error.message : 'Açıklık taşınamadı.'
  }
}

/** A door or window made wider or narrower by one of its ends on the plan. */
async function resizeOpening(id: string, offsetMm: number, widthMm: number): Promise<void> {
  const previous = openings.value

  openings.value = openings.value.map(opening => (opening.id === id ? { ...opening, offset_mm: offsetMm, width_mm: widthMm } : opening))

  try {
    await api.patch(`${base}/constraints/${id}`, { offset_mm: offsetMm, width_mm: widthMm, notes: 'Sizin düzelttiğiniz.' })
  }
  catch (error) {
    openings.value = previous
    openingNotice.value = error instanceof ApiError ? error.message : 'Açıklık boyutlandırılamadı.'
  }
}

/**
 * A door or window from the palette: put into the room at the size such a thing usually is,
 * on a wall with room for it, to be dragged where it belongs. Nobody types where a door is.
 */
async function addOpening(kind: OpeningKind): Promise<void> {
  openingBusy.value = true
  openingNotice.value = null

  const width = kind.width_mm
  const geometryNow = confirmed.value

  const spanOf = (wall: WallName): number => (wall === 'north' || wall === 'south' ? geometryNow?.width_mm ?? 4_000 : geometryNow?.length_mm ?? 5_000)
  const wall = (['north', 'east', 'south', 'west'] as WallName[])
    .find(candidate => spanOf(candidate) >= width + 600 && openings.value.every(opening => opening.wall !== candidate)) ?? 'north'

  try {
    const response = await api.post<{ data: Record<string, unknown> }>(`${base}/constraints`, {
      type: kind.type,
      variant: kind.variant,
      label: describeKind({ type: kind.type, variant: kind.variant, width_mm: width, sill_height_mm: kind.sill_height_mm }),
      wall,
      offset_mm: Math.max(0, Math.round((spanOf(wall) - width) / 2)),
      width_mm: width,
      height_mm: kind.height_mm,
      sill_height_mm: kind.sill_height_mm,
      notes: 'Sizin eklediğiniz.',
    })

    openings.value = [...openings.value, asOpening(response.data)]
  }
  catch (error) {
    openingNotice.value = error instanceof ApiError ? error.message : 'Açıklık eklenemedi.'
  }
  finally {
    openingBusy.value = false
  }
}

/**
 * The same opening, another kind: a single window that is really a double. Its place and
 * width stay — the customer has already put it where it is — only the drawing changes,
 * except that a French balcony goes to the floor and a window off it comes back up.
 */
async function rekindOpening(id: string, kind: OpeningKind): Promise<void> {
  const previous = openings.value
  const current = previous.find(opening => opening.id === id)

  if (!current) {
    return
  }

  const toFloor = kind.sill_height_mm === 0 && (current.sill_height_mm ?? 0) > 0
  const offFloor = kind.sill_height_mm > 0 && (current.sill_height_mm ?? 0) === 0
  const changes = {
    variant: kind.variant,
    label: describeKind({ type: current.type, variant: kind.variant, width_mm: current.width_mm, sill_height_mm: kind.sill_height_mm }),
    ...(toFloor || offFloor ? { sill_height_mm: kind.sill_height_mm, height_mm: kind.height_mm } : {}),
  }

  openings.value = previous.map(opening => (opening.id === id ? { ...opening, ...changes } : opening))

  try {
    await api.patch(`${base}/constraints/${id}`, { ...changes, notes: 'Sizin düzelttiğiniz.' })
  }
  catch (error) {
    openings.value = previous
    openingNotice.value = error instanceof ApiError ? error.message : 'Tür değiştirilemedi.'
  }
}

/** A door hung on the other jamb, or opening the other way. */
async function setSwing(id: string, swing: DoorSwing): Promise<void> {
  const previous = openings.value

  openings.value = previous.map(opening => (opening.id === id ? { ...opening, swing } : opening))

  try {
    await api.patch(`${base}/constraints/${id}`, { swing, notes: 'Sizin düzelttiğiniz.' })
  }
  catch (error) {
    openings.value = previous
    openingNotice.value = error instanceof ApiError ? error.message : 'Kapının yönü değiştirilemedi.'
  }
}

async function removeOpening(id: string): Promise<void> {
  const previous = openings.value

  openings.value = openings.value.filter(opening => opening.id !== id)

  try {
    await api.delete(`${base}/constraints/${id}`)
  }
  catch (error) {
    openings.value = previous
    openingNotice.value = error instanceof ApiError ? error.message : 'Açıklık kaldırılamadı.'
  }
}

/**
 * The design somebody arrived from, if they arrived from one.
 *
 * A customer who presses "3B planda aç" while looking at a particular render means that
 * render, not whichever version happens to be newest.
 */
const fromDesign = computed(() => String(route.query.compose ?? ''))

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
        room_type: string | null
        room: { width_mm: number | null, length_mm: number | null, height_mm: number | null, photo_count: number }
        design: { design_id: string, version_id: string, version_number: number } | null
        detected: Detection | null
      }
    }>(`${base}/layout`)

    confirmed.value = response.data.geometry
    pending.value = response.data.pending_geometry
    openings.value = response.data.openings
    items.value = response.data.layout?.items ?? []
    roomType.value = response.data.room_type
    roomFacts.value = response.data.room
    design.value = response.data.design
    detected.value = response.data.detected

    /*
     * The photograph, only when there is something to draw on it and nothing agreed yet.
     *
     * A signed link is a deliberate request that runs the ownership check and expires in five
     * minutes; asking for one on every load of a screen nobody is looking at the picture on
     * would be issuing links for the sake of it.
     */
    const media = response.data.detected?.media_id ?? null

    if (media !== null && response.data.geometry === null && (response.data.detected?.regions.length ?? 0) > 0) {
      try {
        const link = await api.get<{ data: { url: string } }>(`${base}/media/${media}/link`)

        photoUrl.value = link.data.url
      }
      catch {
        // The numbers and the sketch still answer the question; a missing photograph is not
        // worth an error on a screen about measurements.
        photoUrl.value = null
      }
    }

    // The confirmed geometry, else the newest proposal, else what the customer typed on the
    // room screen — the form should never open empty when the room already has a size.
    const source = response.data.geometry ?? response.data.pending_geometry[0] ?? response.data.room

    if (source !== undefined && source.width_mm !== null && source.length_mm !== null && source.height_mm !== null) {
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
      meta: {
        unplaced: Array<{ category: string | null, reason?: 'scale' | 'doorway' | 'no_room' }>
        unmeasured: Array<{ category: string | null, reason?: undefined }>
      }
    }>(`${base}/layout/compose`, {
      ...(replace ? { replace: true } : {}),
      // Which design to arrange. Set when somebody came here from a render they were
      // looking at — the newest finished version is the right default and the wrong answer
      // for a customer who has just scrolled back to an older one.
      ...(fromDesign.value === '' ? {} : { design_version_id: fromDesign.value }),
    })

    items.value = response.data.items
    overwrite.value = false

    const missed = [...response.meta.unplaced, ...response.meta.unmeasured]

    if (missed.length > 0) {
      // Said rather than hidden, with the rule that stopped it. A layout that quietly drops
      // a product the customer chose is a layout that lies about the shopping list beside it.
      const why: Record<string, string> = {
        scale: 'oda için fazla büyük',
        doorway: 'kapının önüne denk geliyor',
        no_room: 'boş duvar kalmadı',
      }
      const names = missed
        .map(entry => `${entry.category ?? 'ürün'}${entry.reason && why[entry.reason] ? ` (${why[entry.reason]})` : ''}`)
        .join(', ')

      composeNotice.value = `Şunlar yerleştirilemedi: ${names}. Daha küçük bir ürün seçebilir ya da kendiniz yerleştirebilirsiniz.`
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

/**
 * Finds products the customer could put in this room.
 *
 * Only the ones with measurements. A plan is a promise that these things fit, and a variant
 * the seller never measured cannot be part of that promise — it would go in as a placeholder
 * box, collide with nothing, and mean nothing. Better to not offer it here at all; it is
 * still in the shop, where its size is not load-bearing.
 */
async function findProducts(category: string | null = activeCategory.value): Promise<void> {
  const term = search.value.trim()

  if (term.length < 2 && category === null) {
    return
  }

  searching.value = true
  searched.value = true
  activeCategory.value = category

  try {
    const response = await api.get<{ data: Array<Record<string, never>> }>('/api/v1/catalog/products', {
      // A category browse and a text search are the same request with different filters, so
      // the results list has one shape and one set of rules about what may be placed.
      ...(term.length >= 2 ? { search: term } : {}),
      ...(category === null ? {} : { category }),
      per_page: 12,
    })

    candidates.value = (response.data as unknown as CatalogProduct[])
      .map(toCandidate)
      .filter((candidate): candidate is Candidate => candidate !== null)
  }
  catch (error) {
    saveError.value = error instanceof Error ? error.message : 'Ürünler aranamadı.'
  }
  finally {
    searching.value = false
  }
}

/** The shape the catalogue returns, narrowed to the parts a plan reads. */
interface CatalogProduct {
  id: string
  name: string
  category: { slug: string } | null
  media?: Array<{ url: string | null, is_cover?: boolean }>
  model?: { url: string, source: 'seller' | 'ai' } | null
  skus?: Array<{
    id: string
    is_available: boolean
    effective_price?: { formatted?: string }
    dimensions: { width_mm: number | null, height_mm: number | null, depth_mm: number | null } | null
  }>
}

function toCandidate(product: CatalogProduct): Candidate | null {
  // The first variant that is both for sale and measured. A product whose only measured
  // variant is out of stock is not one to put in a plan somebody is about to order from.
  const sku = (product.skus ?? []).find(
    candidate => candidate.is_available && (candidate.dimensions?.width_mm ?? 0) > 0,
  )

  if (sku === undefined || sku.dimensions === null) {
    return null
  }

  return {
    id: product.id,
    name: product.name,
    category: product.category?.slug ?? null,
    sku_id: sku.id,
    width_mm: sku.dimensions.width_mm,
    height_mm: sku.dimensions.height_mm,
    depth_mm: sku.dimensions.depth_mm,
    price: sku.effective_price?.formatted ?? '',
    // The cover, which is the photograph the seller chose to represent the product —
    // position 0, but said rather than assumed.
    image_url: (product.media?.find(item => item.is_cover) ?? product.media?.[0])?.url ?? null,
    model_url: product.model?.url ?? null,
    model_source: product.model?.source ?? null,
  }
}

/**
 * Puts a chosen product in the room.
 *
 * The id is minted here so the piece has an identity before the server has seen it —
 * selection, undo and the drag all need one immediately. The server keeps it unless it is
 * already taken, in which case it mints its own and the next load corrects us.
 */
async function addProduct(candidate: Candidate): Promise<void> {
  /*
   * The server decides where it goes.
   *
   * Not because the browser cannot compute a free rectangle — it can, and that is exactly
   * the problem: the first free space nearest the middle of the floor is where nothing goes,
   * and five products added that way stand in a heap in the centre of the room. The rules
   * about where furniture belongs are written once, in the composer, and this asks them.
   *
   * One request per click, which is what a click can afford. If it fails the editor falls
   * back to its own free-spot search and the customer moves the piece, which is a worse
   * position rather than a lost product.
   */
  interface Placement {
    position_x_mm: number
    position_y_mm: number
    position_z_mm: number
    rotation_y_deg: number
  }

  let placement: Placement | null = null

  try {
    const response = await api.post<{ data: Placement | null }>(`${base}/layout/place`, {
      sku_id: candidate.sku_id,
      existing: liveItems.value.map(item => ({
        category: item.category,
        width_mm: item.width_mm ?? 0,
        depth_mm: item.depth_mm ?? 0,
        position_x_mm: item.position_x_mm,
        position_y_mm: item.position_y_mm,
        position_z_mm: item.position_z_mm,
        rotation_y_deg: item.rotation_y_deg,
      })),
    })

    placement = response.data
  }
  catch {
    placement = null
  }

  scene.value?.add({
    id: crypto.randomUUID(),
    product_id: candidate.id,
    sku_id: candidate.sku_id,
    name: candidate.name,
    category: candidate.category,
    // Zeroes when the server had nowhere for it: the editor reads that as "unplaced" and
    // finds a free spot of its own rather than standing the piece in the corner.
    position_x_mm: placement?.position_x_mm ?? 0,
    position_y_mm: placement?.position_y_mm ?? 0,
    position_z_mm: placement?.position_z_mm ?? 0,
    rotation_y_deg: placement?.rotation_y_deg ?? 0,
    locked: false,
    collision_state: 'ok',
    width_mm: candidate.width_mm,
    height_mm: candidate.height_mm,
    depth_mm: candidate.depth_mm,
    image_url: candidate.image_url,
    model_url: candidate.model_url,
    model_source: candidate.model_source,
  })
}

/**
 * Puts everything standing in the room into the basket.
 *
 * The end of the module. A plan is a list of real products at real sizes in a room they have
 * been checked against, so one press from being an order is the only sensible place for it to
 * end. What could not be added is named rather than skipped — a basket that quietly contains
 * four of the five things somebody planned is a basket they discover at the door.
 */
async function addLayoutToCart(): Promise<void> {
  adding.value = true
  saveError.value = null
  cartNotice.value = null

  try {
    const response = await api.post<{
      data: { added: number }
      meta: { refused: Array<{ name: string | null, reason: string }> }
    }>(`${base}/layout/cart`)

    cartNotice.value = response.meta.refused.length === 0
      ? `${response.data.added} ürün sepete eklendi.`
      : `${response.data.added} ürün sepete eklendi. Eklenemeyenler: ${response.meta.refused
        .map(entry => `${entry.name ?? 'ürün'} (${entry.reason})`)
        .join(' · ')}`
  }
  catch (error) {
    saveError.value = error instanceof Error ? error.message : 'Sepete eklenemedi.'
  }
  finally {
    adding.value = false
  }
}

/**
 * The categories that belong in this room.
 *
 * Read from the taxonomy rather than written out here, so a category added to the catalogue
 * appears in the planner without a deploy — and so a room type nobody has categorised falls
 * back to the search box rather than to a wrong list.
 */
async function loadCategories(): Promise<void> {
  if (roomType.value === null) {
    return
  }

  try {
    const response = await api.get<{ data: Array<{ slug: string, name: string, room_type: string | null, depth?: number }> }>(
      '/api/v1/catalog/categories',
    )

    categories.value = response.data
      .filter(category => category.room_type === roomType.value)
      .map(category => ({ slug: category.slug, name: category.name }))
      .slice(0, 10)
  }
  catch {
    // The search box still works, and a failed taxonomy fetch is not worth an error
    // message on a screen about furniture.
    categories.value = []
  }
}

/**
 * Makes a new design version from what is standing in the room.
 *
 * The plan is the structure; the renderer is handed a picture of it and asked to make the
 * photograph. Spending credits, so it says so on the button — and it goes to the design
 * screen afterwards, where the progress of a render is already shown properly.
 */
async function renderFinal(): Promise<void> {
  const current = design.value

  if (current === null) {
    return
  }

  rendering.value = true
  saveError.value = null

  try {
    const created = await api.post<{ data: { id: string } }>(
      `${base}/designs/${current.design_id}/branch`,
      {
        parent_version_id: current.version_id,
        user_prompt: 'Oda planındaki yerleşimi birebir uygula: her ürün plandaki konumunda ve yönünde dursun.',
      },
    )

    await navigateTo(`/projects/${projectId}/rooms/${roomId}/designs/${current.design_id}?version=${created.data.id}`)
  }
  catch (error) {
    saveError.value = error instanceof Error ? error.message : 'Final görsel başlatılamadı.'
  }
  finally {
    rendering.value = false
  }
}

onMounted(async () => {
  await load()
  await loadCategories()

  /*
   * Arranged straight away when somebody came from a design, and only into an empty room.
   *
   * They pressed a button that said "open it in the plan and arrange it"; making them press
   * another one on arrival is asking twice. Into an empty room only, because the server
   * refuses to overwrite an arrangement anyway and the refusal would arrive as a question
   * nobody asked for.
   */
  if (fromDesign.value !== '' && geometry.value !== null && liveItems.value.length === 0) {
    await composeLayout()
  }
})
</script>

<template>
  <div class="rc-container rc-container--wide space-y-3 py-4">
  <!--
    A workspace rather than a page: the room fills the height of the window and everything
    that acts on it stands in one column beside it. The first version stacked the stepper, a
    title, a banner, the scene, the selection panel, the product list, the openings and the
    catalogue one under the other, and the product owner's verdict was a screen full of empty
    space and a mouse wheel that never stopped. Nothing here needs the page to scroll.
  -->
    <div class="flex flex-wrap items-center justify-between gap-3">
      <StudioStepper :project-id="projectId" :room-id="roomId" :current="studioCurrent" :done="studioDone" class="min-w-0 flex-1" />

      <div class="flex items-center gap-4">
        <h1 class="sr-only">Oda planı</h1>
        <!--
          Sharing is a property of the project, not of this screen.

          A separate "share this plan" would be a second way to give somebody access to the
          inside of a home, with its own idea of who can see what. There is one, it is on the
          project, and this points at it.
        -->
        <NuxtLink :to="`/projects/${projectId}#paylasim`" class="text-sm text-ink-secondary hover:underline">
          Paylaş
        </NuxtLink>

        <NuxtLink :to="`/projects/${projectId}/rooms/${roomId}`" class="text-sm text-ink-secondary hover:underline">
          Odaya dön
        </NuxtLink>
      </div>
    </div>

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

        <!--
          Their own photograph, with what was found drawn on it.

          The measurement is derived from these: the width of the room is read off the door
          in this picture. Showing the box is showing the working, and a box around a mirror
          explains a wrong answer better than any confidence percentage.
        -->
        <RoomDetectionOverlay
          v-if="photoUrl !== null && detected !== null"
          class="mt-4"
          :url="photoUrl"
          :regions="detected.regions"
        />

        <!--
          The same numbers, drawn.

          Three measurements in a list are three numbers to agree with; the shape of the room
          is something somebody can recognise or fail to recognise at a glance, which is the
          judgement actually being asked for. A room that came back square when theirs is long
          is obvious here and invisible above.
        -->
        <div class="mt-4 h-64 overflow-hidden rounded-md border border-line">
          <RoomPlanSvg
            :geometry="{
              id: proposal.id,
              width_mm: proposal.width_mm,
              length_mm: proposal.length_mm,
              height_mm: proposal.height_mm,
            }"
            :openings="openings"
            :items="[]"
            :states="new Map()"
          />
        </div>

        <p class="mt-3 text-xs text-muted">
          {{ proposal.source === 'ai'
            ? `Fotoğraftan tahmin edildi${proposal.confidence_percent === null ? '' : ` (%${proposal.confidence_percent} güven)`}. Bir metre şerit varsa kontrol etmeye değer.`
            : 'Sizin girdiğiniz ölçüler.' }}
        </p>

        <!--
          What the analysis itself was unsure about, in its own words.

          Kept because it is the honest half of a confidence figure: "pencerenin alt kenarı
          görünmüyor" tells the customer which number to check, and 71% tells them nothing
          they can act on.
        -->
        <ul v-if="(detected?.warnings.length ?? 0) > 0" class="mt-2 space-y-0.5 text-xs text-muted">
          <li v-for="(warning, index) in detected?.warnings ?? []" :key="index">
            • {{ warning }}
          </li>
        </ul>

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
        The room, as tall as the window allows; beside it, in one scrolling column: what is
        selected, what is in the room and what it costs, then the doors and windows, then the
        catalogue. Arranging costs nothing — the design was paid for; this is arithmetic
        against the room the customer confirmed — so it is the first button in the column.
      -->
      <Room3DScene
        ref="scene"
        workspace
        class="h-[calc(100vh-11.5rem)] min-h-[480px]"
        :geometry="geometry"
        :openings="openings"
        :items="items"
        editable
        @save="save"
        @change="liveItems = $event"
        @move-opening="moveOpening"
        @add-opening="addOpening"
        @resize-opening="resizeOpening"
      >
        <!--
          The end of the module, beside the total: the final picture, which spends credits and
          says so, and the basket — a plan is a list of real products at real sizes in a room
          they have been checked against, and finding each again in the shop is doing the work
          twice.
        -->
        <template #actions>
          <button
            v-if="design !== null && liveItems.length > 0"
            type="button"
            class="rounded-pill bg-charcoal px-4 py-2 text-xs text-white disabled:opacity-50"
            :disabled="rendering"
            @click="renderFinal"
          >
            {{ rendering ? 'Render alınıyor…' : 'Render al' }}
          </button>
          <button
            v-if="liveItems.length > 0"
            type="button"
            class="rounded-pill border border-line px-4 py-2 text-xs hover:bg-bg-muted disabled:opacity-50"
            :disabled="adding"
            @click="addLayoutToCart"
          >
            Odadakileri sepete ekle
          </button>
        </template>

        <template #side-start>
          <div class="space-y-2 rounded-md border border-line bg-surface p-3">
            <button
              type="button"
              class="w-full rounded-pill bg-charcoal px-4 py-2 text-sm text-white disabled:opacity-50"
              :disabled="composing"
              @click="composeLayout()"
            >
              {{ liveItems.length === 0 ? 'Tasarıma göre yerleştir' : 'Yeniden yerleştir' }}
            </button>
    
            <p class="text-xs text-muted">
              Son tasarımda seçilen ürünler, odanın ölçülerine göre dizilir. Sonra
              istediğiniz gibi taşıyabilirsiniz.
            </p>
    
    
          </div>
    
          <p v-if="cartNotice" class="rounded-sm bg-bg-muted p-3 text-sm text-ink-secondary">
            {{ cartNotice }}
            <NuxtLink to="/cart" class="ml-1 underline">
              Sepete git
            </NuxtLink>
          </p>
    
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
    
        </template>

        <template #side>
          <!--
            Doors and windows, by hand.
    
            The analysis reads them off the photograph; this is where they get corrected. Drag one
            along its wall on the plan view, add the one the photograph did not show, remove the
            "window" that turned out to be a mirror. The 3D room, the collision rules and the
            final picture all read the same rows.
          -->
          <section class="rounded-md border border-line bg-surface p-4">
            <h2 class="text-sm font-medium text-ink">
              Kapılar ve pencereler
            </h2>
            <p class="mt-1 text-xs text-muted">
              Kapıyı ya da pencereyi tutup duvar boyunca kaydır; başka bir duvara da bırakabilirsin.
            </p>
    
            <p v-if="openingNotice" class="mt-3 rounded-sm bg-warning-subtle p-2 text-xs text-warning-strong">
              {{ openingNotice }}
            </p>
    
            <ul v-if="openings.length > 0" class="mt-3 divide-y divide-line text-sm">
              <li v-for="opening in openings" :key="opening.id" class="py-2">
                <div class="flex items-center justify-between gap-3">
                  <span>
                    {{ describeKind(opening) }} ·
                    {{ opening.wall ? WALL_LABELS[opening.wall] : '—' }} duvarı ·
                    {{ opening.offset_mm === null ? '?' : Math.round(opening.offset_mm / 10) }} cm'de,
                    {{ opening.width_mm === null ? '?' : Math.round(opening.width_mm / 10) }} cm geniş
                  </span>
                  <button type="button" class="text-xs text-danger hover:underline" @click="removeOpening(opening.id)">
                    Kaldır
                  </button>
                </div>
                <!-- The same opening as another kind: the reading said "window", the customer says "double". -->
                <div class="mt-1.5 flex flex-wrap gap-1" role="group" :aria-label="`${describeKind(opening)} türü`">
                  <button
                    v-for="kind in kindsFor(opening.type as OpeningKind['type'])"
                    :key="kind.variant"
                    type="button"
                    class="rounded-pill border px-2 py-0.5 text-[11px] transition-colors"
                    :class="variantOf(opening) === kind.variant ? 'border-charcoal bg-charcoal text-white' : 'border-line text-ink-secondary hover:bg-bg-muted'"
                    :aria-pressed="variantOf(opening) === kind.variant"
                    @click="rekindOpening(opening.id, kind)"
                  >
                    {{ kind.label }}
                  </button>
                </div>
                <!-- Which jamb it hangs on and which way it opens: the quarter of floor a door needs. -->
                <div v-if="hasSwing(opening)" class="mt-1.5 flex flex-wrap gap-1" role="group" :aria-label="`${describeKind(opening)} yönü`">
                  <button
                    v-if="variantOf(opening) !== 'double_door'"
                    type="button"
                    class="rounded-pill border border-line px-2 py-0.5 text-[11px] text-ink-secondary transition-colors hover:bg-bg-muted"
                    :title="'Menteşeyi öbür tarafa al'"
                    @click="setSwing(opening.id, otherJamb(swingOf(opening)))"
                  >
                    Menteşe {{ hingeIsLeft(opening.wall, swingOf(opening)) ? 'solda' : 'sağda' }} ⇄
                  </button>
                  <button
                    type="button"
                    class="rounded-pill border border-line px-2 py-0.5 text-[11px] text-ink-secondary transition-colors hover:bg-bg-muted"
                    :title="'Öbür yöne açılsın'"
                    @click="setSwing(opening.id, otherWay(swingOf(opening)))"
                  >
                    {{ opensIn(swingOf(opening)) ? 'İçeri açılır' : 'Dışarı açılır' }} ⇄
                  </button>
                </div>
              </li>
            </ul>
            <p v-else class="mt-3 text-xs text-muted">Bu odada kayıtlı kapı ya da pencere yok.</p>
    
            <p class="mt-3 text-xs text-muted">Yenisini eklemek için sahnenin solundaki simgeleri kullan; sonra tutup duvara sürükle.</p>
          </section>
    
          <!--
            The catalogue, in the room.
    
            Only measured variants are offered. A plan is a promise that these things fit, and a
            product whose size nobody recorded cannot be part of that promise — it would go in as
            a placeholder and mean nothing. It is still in the shop, where its size is not
            load-bearing.
          -->
          <section class="rounded-md border border-line bg-surface p-4">
            <h2 class="text-sm font-medium text-ink">
              Odaya ürün ekle
            </h2>
    
            <form class="mt-3 flex gap-2" @submit.prevent="findProducts(null)">
              <input
                v-model="search"
                type="search"
                placeholder="Kanepe, sehpa, kitaplık…"
                class="w-full rounded-sm border border-line bg-surface px-3 py-2 text-sm"
              >
              <button
                type="submit"
                class="shrink-0 rounded-pill bg-charcoal px-4 py-2 text-sm text-white disabled:opacity-50"
                :disabled="searching || search.trim().length < 2"
              >
                Ara
              </button>
            </form>
    
            <!--
              Browsing, for the customer who does not know what to call it yet.
    
              A search box is a blank page to somebody furnishing a room: they know they want
              "something for the corner", not "kitaplık". The categories come from the taxonomy
              for this room type, so a new one appears here without a deploy.
            -->
            <div v-if="categories.length > 0" class="mt-3 flex flex-wrap gap-1.5">
              <button
                v-for="category in categories"
                :key="category.slug"
                type="button"
                class="rounded-pill border px-3 py-1 text-xs transition-colors"
                :class="activeCategory === category.slug
                  ? 'border-charcoal bg-charcoal text-white'
                  : 'border-line text-ink-secondary hover:bg-bg-muted'"
                @click="findProducts(category.slug)"
              >
                {{ category.name }}
              </button>
            </div>
    
            <ul v-if="candidates.length > 0" class="mt-3 grid gap-2 sm:grid-cols-2">
              <li v-for="candidate in candidates" :key="candidate.sku_id">
                <button
                  type="button"
                  class="flex w-full items-center gap-3 rounded-sm border border-line p-2 text-left transition-colors hover:bg-bg-muted"
                  @click="addProduct(candidate)"
                >
                  <img
                    v-if="candidate.image_url"
                    :src="candidate.image_url"
                    alt=""
                    class="size-12 shrink-0 rounded-sm object-cover"
                  >
    
                  <span class="min-w-0">
                    <span class="block truncate text-sm text-ink">{{ candidate.name }}</span>
                    <span class="block text-xs text-muted tabular-nums">
                      {{ Math.round((candidate.width_mm ?? 0) / 10) }} × {{ Math.round((candidate.depth_mm ?? 0) / 10) }} cm
                      <template v-if="candidate.price"> · {{ candidate.price }}</template>
                    </span>
                  </span>
                </button>
              </li>
            </ul>
    
            <p v-else-if="searched && !searching" class="mt-3 text-xs text-muted">
              Ölçüsü girilmiş ürün bulunamadı. Plana ancak ölçüsü bilinen ürünler konabilir.
            </p>
          </section>
    
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
      </Room3DScene>
    </template>
  </div>
</template>
