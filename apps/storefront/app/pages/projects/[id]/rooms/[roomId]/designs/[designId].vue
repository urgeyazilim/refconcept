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
 * Progress is polled while anything is running, and the bar is driven by which stage the
 * engine last announced rather than by elapsed time. A bar fed by real durations jumps
 * about as providers vary; one fed by stage boundaries moves predictably, which is what
 * somebody watching a render for fifty seconds actually wants from it.
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
const branchQuality = ref<'draft' | 'premium'>('draft')

interface VersionProgress {
  status: string
  is_finished: boolean
  stage: string | null
  stage_label: string | null
  progress_bps: number
  failure_reason: string | null
  events: Array<{ stage: string, label: string, status: string, message: string, at: string }>
}

interface MatchRow {
  id: string
  rank: number
  status: string
  status_label: string
  product: { id: string, name: string | null, slug: string | null, image_url: string | null }
  sku: { id: string, variant: string | null, seller: string | null, width_mm: number | null }
  price: { amount_minor: number, currency: string }
  current_price_minor: number | null
  price_has_moved: boolean
  score_bps: number
  similarity_bps: number | null
  reason: string | null
}

interface PlacementGroup {
  index: number
  category: string | null
  name: string | null
  wall: string | null
  max_width_mm: number | null
  matches: MatchRow[]
  /**
   * How many of whichever product is chosen here.
   *
   * Six matching dining chairs are one decision and one product bought six times, not six
   * separate suggestions — which is what they were until the plan learned to say so.
   */
  quantity: number
  /** Why this planned item has no suggestion, when it has none. */
  unavailable_reason: string | null
}

interface ShoppingList {
  placements: PlacementGroup[]
  total_minor: number
  currency: string
  verdicts: Array<{ value: string, label: string }>
}

const shoppingList = ref<ShoppingList | null>(null)
const listBusy = ref(false)
const listMessage = ref<string | null>(null)

const addingToCart = ref(false)
const cartMessage = ref<{ tone: 'success' | 'danger', text: string } | null>(null)

/** Before and after, side by side — the reason a photograph was uploaded at all. */
const comparison = ref<'after' | 'before'>('after')

const progress = ref<Record<string, VersionProgress>>({})
const pollFailures = ref(0)
const pollStalled = ref(false)
let poller: ReturnType<typeof setInterval> | undefined

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

/**
 * The version whose shopping list is shown.
 *
 * The one the customer is looking at, not the newest — going back to an earlier version
 * is the point of keeping a tree, and its list has to come back with it.
 */
const shownVersionId = computed(() => design.value?.current_version?.id ?? null)

async function loadShoppingList() {
  const versionId = shownVersionId.value

  if (!versionId) {
    shoppingList.value = null

    return
  }

  try {
    const response = await api.get<{ data: ShoppingList }>(`${base}/versions/${versionId}/matches`)
    shoppingList.value = response.data
  } catch {
    // A design without a shopping list is still a design. Failing the whole page over
    // the suggestions would take away the thing the customer actually came for.
    shoppingList.value = null
  }
}

await loadShoppingList()

watch(shownVersionId, () => { void loadShoppingList() })

async function rebuildList() {
  if (!shownVersionId.value) return

  listBusy.value = true
  listMessage.value = null

  try {
    const response = await api.post<{ message: string }>(
      `${base}/versions/${shownVersionId.value}/matches/rebuild`,
    )

    listMessage.value = response.message
    await loadShoppingList()
  } catch (error) {
    listMessage.value = error instanceof ApiError ? error.message : 'Öneriler yenilenemedi.'
  } finally {
    listBusy.value = false
  }
}

async function chooseMatch(match: MatchRow) {
  if (!shownVersionId.value) return

  listBusy.value = true

  try {
    await api.post(`${base}/versions/${shownVersionId.value}/matches/${match.id}/choose`)
    await loadShoppingList()
  } catch (error) {
    listMessage.value = error instanceof ApiError ? error.message : 'Seçim kaydedilemedi.'
  } finally {
    listBusy.value = false
  }
}

/**
 * Tells us what was wrong with a suggestion.
 *
 * The only honest signal about whether matching works — everything else is the system
 * marking its own homework. The reasons are short on purpose: a list of twelve is a list
 * nobody reads to the end.
 */
async function sendFeedback(match: MatchRow, verdict: string) {
  if (!shownVersionId.value) return

  listBusy.value = true

  try {
    await api.post(`${base}/versions/${shownVersionId.value}/matches/${match.id}/feedback`, { verdict })
    listMessage.value = 'Geri bildiriminiz için teşekkürler.'
    await loadShoppingList()
  } catch (error) {
    listMessage.value = error instanceof ApiError ? error.message : 'Geri bildirim gönderilemedi.'
  } finally {
    listBusy.value = false
  }
}

function money(minor: number, currency: string): string {
  return new Intl.NumberFormat('tr-TR', { style: 'currency', currency }).format(minor / 100)
}

/** Versions the engine is still working on. */
const running = computed(() => rows.value
  .filter(row => ['pending', 'generating'].includes(row.node.status))
  .map(row => row.node.id))

/**
 * Asks after each unfinished version.
 *
 * One request per running version rather than one for the whole design: the endpoint is
 * deliberately small, and a customer normally has exactly one render in flight.
 *
 * A single failed poll is ignored — a dropped request while a laptop wakes up is not
 * worth an error message. A run of them is not ignored: polling stops and the page says
 * so, because a spinner that has quietly stopped asking is worse than one that admits it.
 */
async function pollProgress() {
  const ids = running.value

  if (ids.length === 0) return

  const answers = await Promise.all(ids.map(id =>
    api.get<{ data: VersionProgress }>(`${base}/versions/${id}/progress`)
      .then(response => [id, response.data] as const)
      .catch(() => null),
  ))

  if (answers.every(answer => answer === null)) {
    pollFailures.value += 1

    if (pollFailures.value >= 5) {
      stopPolling()
      pollStalled.value = true
    }

    return
  }

  pollFailures.value = 0

  let anyFinished = false

  for (const answer of answers) {
    if (answer === null) continue

    progress.value[answer[0]] = answer[1]

    if (answer[1].is_finished) anyFinished = true
  }

  // A finished version changes the tree — its status, its image, possibly which one is
  // current — so the page is reloaded rather than patched in place.
  if (anyFinished) await load()
}

function stopPolling() {
  if (poller) {
    clearInterval(poller)
    poller = undefined
  }
}

onMounted(() => {
  void pollProgress()

  // Two seconds: fast enough that a stage change feels immediate, slow enough that a
  // minute-long render is thirty requests rather than three hundred.
  poller = setInterval(() => { void pollProgress() }, 2_000)
})

onBeforeUnmount(stopPolling)

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
      render_quality: branchQuality.value,
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

/** The version on screen: whichever one the design currently points at. */
const shownVersion = computed<DesignTreeNode | null>(() => {
  const id = shownVersionId.value

  if (!design.value || id === null) {
    return null
  }

  // Reuses the same flattening the version tree draws with, so the picture on screen and
  // the row highlighted in the tree can never disagree about which version is current.
  return flatten(design.value.tree).find(row => row.node.id === id)?.node ?? null
})

/**
 * What the customer has actually chosen, with how many of each.
 *
 * The quantity belongs to the placement, not the suggestion: "six dining chairs" is one
 * decision about the room and one product bought six times. Carried alongside the match
 * because both the total and the basket need it, and reading it back off the group at each
 * call site is how one of them ends up forgetting.
 */
const chosen = computed<Array<{ match: MatchRow, quantity: number }>>(() =>
  (shoppingList.value?.placements ?? []).flatMap(group =>
    group.matches
      .filter(match => match.status === 'accepted')
      .map(match => ({ match, quantity: Math.max(1, group.quantity) })),
  ),
)

const chosenTotal = computed(() =>
  chosen.value.reduce((total, row) => total + row.match.price.amount_minor * row.quantity, 0),
)

/**
 * Puts the chosen products in the basket.
 *
 * One at a time rather than one request with a list: the cart endpoint is the same one the
 * catalogue uses, and a second endpoint that takes a batch would be a second place for the
 * stock and price rules to be applied — and to drift.
 *
 * A product that cannot be added does not stop the rest. Somebody who chose six things and
 * can have five wants the five, and to be told plainly about the one.
 */
async function addChosenToCart() {
  if (chosen.value.length === 0) {
    return
  }

  addingToCart.value = true
  cartMessage.value = null

  const failed: string[] = []

  for (const { match, quantity } of chosen.value) {
    try {
      // The plan asked for six of this chair, so six go in the basket. Adding one and
      // leaving the customer to notice would be the design quietly not being the thing
      // they are buying.
      await api.post('/api/v1/cart/items', { sku_id: match.sku.id, quantity })
    } catch {
      failed.push(match.product.name ?? 'ürün')
    }
  }

  addingToCart.value = false

  if (failed.length === 0) {
    cartMessage.value = {
      tone: 'success',
      text: `${chosen.value.length} ürün sepetinize eklendi.`,
    }

    return
  }

  cartMessage.value = {
    tone: 'danger',
    text: failed.length === chosen.value.length
      ? 'Ürünler sepete eklenemedi. Stok durumu değişmiş olabilir.'
      : `${chosen.value.length - failed.length} ürün eklendi. Eklenemeyen: ${failed.join(', ')}.`,
  }
}

/**
 * The picture opened full-screen, or null.
 *
 * A before-and-after shown at card size is a thumbnail of somebody's living room, and the
 * whole point is to look at it — whether the sofa suits the light from that window is not a
 * question anybody answers at four hundred pixels wide. Held as which of the two is open
 * rather than as a URL, so the viewer can switch between them without closing: comparing
 * them is the reason both are on the page.
 */
const zoomed = ref<'before' | 'after' | null>(null)

const zoomedImage = computed<{ src: string, label: string } | null>(() => {
  if (zoomed.value === 'before' && design.value?.source_image_url) {
    return { src: design.value.source_image_url, label: 'İlk hâli' }
  }

  if (zoomed.value === 'after' && shownVersion.value?.image_url) {
    return { src: shownVersion.value.image_url, label: 'Son hâli' }
  }

  return null
})

/** Whether there is a second picture to flip to — there is not while a render is running. */
const canFlip = computed(() => Boolean(design.value?.source_image_url && shownVersion.value?.image_url))

function flipZoom() {
  zoomed.value = zoomed.value === 'before' ? 'after' : 'before'
}

const zoomDialog = ref<HTMLElement | null>(null)

/*
 * The same treatment the navigation drawer gets: Escape closes it and the page behind holds
 * still, because a full-screen picture that scrolls the list underneath it is a picture
 * somebody loses their place in.
 *
 * Focus moves into the overlay too. Without it the key handler sits on an element nothing is
 * focused on, so Escape goes to the page behind and the only way out is the mouse.
 */
watch(zoomed, async (open) => {
  if (import.meta.server) {
    return
  }

  document.body.style.overflow = open ? 'hidden' : ''

  if (open) {
    await nextTick()
    zoomDialog.value?.focus()
  }
})

onBeforeUnmount(() => {
  if (import.meta.client) {
    document.body.style.overflow = ''
  }
})

function onZoomKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    zoomed.value = null
  }

  if ((event.key === 'ArrowLeft' || event.key === 'ArrowRight') && canFlip.value) {
    event.preventDefault()
    flipZoom()
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

      <RcAlert v-if="pollStalled" tone="warning">
        Durum bilgisi alınamıyor. Tasarımınız arka planda çalışmaya devam ediyor;
        sayfayı yenileyerek son durumu görebilirsiniz.
      </RcAlert>


      <!--
        Before and after.

        First on the page, above the shopping list, because it is what the customer came
        for: they uploaded a photograph of their own room to see what it could look like.
        The render existed for weeks and was never shown — the page listed products and left
        the customer to imagine the rest, which is the one thing the product promised to do
        for them.
      -->
      <section v-if="design.source_image_url" class="rc-card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 p-6 pb-4 sm:px-8">
          <div>
            <h2 class="text-lg font-medium">Odanız</h2>
            <p class="mt-1 text-sm text-ink-secondary">
              {{ shownVersion?.image_url
                ? 'Soldaki fotoğrafınız, sağdaki aynı odanın önerilen ürünlerle hâli.'
                : 'Tasarım hazır olduğunda burada yan yana göreceksiniz.' }}
            </p>
          </div>

          <!-- On a phone the two do not fit side by side, so they are switched instead. -->
          <div v-if="shownVersion?.image_url" class="flex gap-1.5 sm:hidden">
            <button
              v-for="option in [{ value: 'before', label: 'İlk hâli' }, { value: 'after', label: 'Son hâli' }]"
              :key="option.value"
              type="button"
              class="rounded-pill border px-3 py-1 text-sm"
              :class="comparison === option.value ? 'border-ink bg-ink text-surface' : 'border-line'"
              @click="comparison = option.value as 'before' | 'after'"
            >
              {{ option.label }}
            </button>
          </div>
        </div>

        <div class="grid gap-px bg-line sm:grid-cols-2">
          <figure :class="comparison === 'before' ? '' : 'hidden sm:block'" class="bg-surface">
            <!--
              A button rather than an image with a click handler. It is genuinely a control
              — it opens something — and writing it as one gives it keyboard focus, Enter and
              Space, and a name a screen reader can read out, none of which a bare <img>
              has however many listeners are attached to it.
            -->
            <button
              type="button"
              class="block w-full cursor-zoom-in"
              aria-label="Odanızın ilk hâlini büyüt"
              @click="zoomed = 'before'"
            >
              <img
                :src="design.source_image_url"
                alt="Odanızın ilk hâli"
                class="aspect-[4/3] w-full object-cover"
              >
            </button>
            <figcaption class="px-5 py-3 text-sm text-muted">İlk hâli</figcaption>
          </figure>

          <figure :class="comparison === 'after' ? '' : 'hidden sm:block'" class="bg-surface">
            <button
              v-if="shownVersion?.image_url"
              type="button"
              class="block w-full cursor-zoom-in"
              aria-label="Odanızın önerilen ürünlerle hâlini büyüt"
              @click="zoomed = 'after'"
            >
              <img
                :src="shownVersion.image_url"
                alt="Odanızın önerilen ürünlerle hâli"
                class="aspect-[4/3] w-full object-cover"
                data-testid="design-render"
              >
            </button>

            <!--
              A version that is still running, or one that failed. Said in words rather than
              left as an empty frame: a blank box next to a photograph reads as a broken
              page, not as work in progress.
            -->
            <div
              v-else
              class="flex aspect-[4/3] w-full items-center justify-center bg-bg-muted px-6 text-center text-sm text-muted"
            >
              {{ shownVersion?.status === 'failed'
                ? (shownVersion.failure_reason ?? 'Bu sürüm tamamlanamadı.')
                : 'Tasarımınız hazırlanıyor…' }}
            </div>

            <figcaption class="px-5 py-3 text-sm text-muted">
              Son hâli
              <span v-if="shownVersion" class="text-ink-secondary">· v{{ shownVersion.version_number }}</span>
            </figcaption>
          </figure>
        </div>
      </section>

      <!--
        The shopping list.

        Above the version tree, because it is what the customer came for: the picture is
        the idea and this is the part they can act on.
      -->
      <section v-if="shoppingList && shoppingList.placements.length > 0" class="rc-card p-6 sm:p-8">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h2 class="text-lg font-medium">Alışveriş listesi</h2>
            <p class="mt-1.5 max-w-[62ch] text-sm leading-relaxed text-ink-secondary">
              Plandaki her parça için satın alınabilir, ölçüsü uyan ve stokta olan ürünler.
              Beğenmediklerinizi işaretlerseniz bir dahakine önerilmez.
            </p>
          </div>

          <RcButton size="sm" variant="ghost" :loading="listBusy" @click="rebuildList">
            Önerileri yenile
          </RcButton>
        </div>

        <p v-if="listMessage" class="mt-3 text-sm text-ink-secondary">{{ listMessage }}</p>

        <RcAlert v-if="cartMessage" :tone="cartMessage.tone" class="mt-4">{{ cartMessage.text }}</RcAlert>

        <!--
          What they have chosen, and the one button that turns a design into an order.
          Without it the page ends at "here are some products" and the customer has to find
          each one again in the catalogue — which is where a design stops being a design.
        -->
        <div
          v-if="chosen.length > 0"
          class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-md border border-line-strong bg-bg-muted p-4"
        >
          <p class="text-sm">
            {{ chosen.length }} ürün seçtiniz ·
            <span class="font-medium tabular-nums">
              {{ money(chosenTotal, shoppingList.currency) }}
            </span>
          </p>

          <RcButton :loading="addingToCart" data-testid="add-design-to-cart" @click="addChosenToCart">
            Seçtiklerimi sepete ekle
          </RcButton>
        </div>

        <p v-else-if="shoppingList.total_minor > 0" class="mt-4 text-sm text-ink-secondary">
          Beğendiklerinizi <span class="text-ink">Bunu seç</span> ile işaretleyin, hepsini
          birlikte sepete ekleyin.
        </p>

        <div class="mt-6 space-y-8">
          <div v-for="group in shoppingList.placements" :key="group.index">
            <h3 class="text-sm font-medium">
              {{ group.category }}
              <!--
                Six matching dining chairs are one decision bought six times, not six
                suggestions. Said on the heading because it changes what the prices below
                mean — and because the plan used to repeat the placement instead, which
                left a six-person table with one chair and five empty groups.
              -->
              <span v-if="group.quantity > 1" class="font-normal text-ink-secondary">
                × {{ group.quantity }}
              </span>
              <span v-if="group.max_width_mm" class="font-normal text-muted">
                · en fazla {{ Math.round(group.max_width_mm / 10) }} cm
              </span>
            </h3>

            <ul class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              <li
                v-for="match in group.matches"
                :key="match.id"
                class="rounded-md border p-3"
                :class="match.status === 'accepted'
                  ? 'border-charcoal bg-bg-muted'
                  : (match.status === 'rejected' ? 'border-line opacity-50' : 'border-line')"
              >
                <!--
                  The picture and the name go to the product.

                  They were dead: a customer looking at a suggested sofa could accept it or
                  reject it and had no way to read what it was — no dimensions, no other
                  photographs, no seller — which is a strange thing to ask of somebody about
                  to spend five thousand lira. In a new tab on purpose, because choosing is
                  a comparison and losing the design to read about one item ends it.

                  Only when there is a slug to go to. A match whose product has since been
                  delisted still shows, and its card should not be a link to a 404.
                -->
                <component
                  :is="match.product.slug ? 'a' : 'div'"
                  v-bind="match.product.slug
                    ? { href: `/catalog/${match.product.slug}`, target: '_blank', rel: 'noopener' }
                    : {}"
                  class="block"
                  :class="match.product.slug ? 'group' : ''"
                >
                  <div class="aspect-[4/3] overflow-hidden rounded-sm bg-bg-muted">
                    <img
                      v-if="match.product.image_url"
                      :src="match.product.image_url"
                      :alt="match.product.name ?? ''"
                      class="size-full object-cover transition-transform group-hover:scale-105"
                      loading="lazy"
                    >
                  </div>

                  <p class="mt-2.5 line-clamp-2 text-sm group-hover:underline">
                    {{ match.product.name }}
                  </p>
                </component>
                <p v-if="match.sku.seller" class="text-xs text-muted">{{ match.sku.seller }}</p>

                <p class="mt-1.5 text-sm font-medium tabular-nums">
                  {{ money(match.price.amount_minor, match.price.currency) }}
                </p>

                <!--
                  A price that has moved since the list was built. Said out loud rather
                  than silently repriced: the difference is the most useful thing this card
                  can tell a customer who came back a week later.
                -->
                <p v-if="match.price_has_moved && match.current_price_minor" class="text-xs text-warning">
                  Güncel fiyat: {{ money(match.current_price_minor, match.price.currency) }}
                </p>

                <p v-if="match.reason" class="mt-1.5 line-clamp-2 text-xs text-ink-secondary">
                  {{ match.reason }}
                </p>

                <div v-if="canEdit" class="mt-3 flex flex-wrap gap-1.5">
                  <button
                    v-if="match.status !== 'accepted'"
                    type="button"
                    class="rounded-sm border border-line px-2.5 py-1 text-xs text-ink-secondary transition-colors hover:bg-bg-muted hover:text-ink disabled:opacity-40"
                    :disabled="listBusy"
                    @click="chooseMatch(match)"
                  >
                    Bunu seç
                  </button>
                  <span v-else class="rounded-sm bg-charcoal px-2.5 py-1 text-xs text-inverse">Seçildi</span>

                  <select
                    v-if="match.status !== 'rejected'"
                    class="rounded-sm border border-line bg-surface px-2 py-1 text-xs text-ink-secondary"
                    :disabled="listBusy"
                    @change="sendFeedback(match, ($event.target as HTMLSelectElement).value)"
                  >
                    <option value="">Geri bildirim…</option>
                    <option v-for="verdict in shoppingList.verdicts" :key="verdict.value" :value="verdict.value">
                      {{ verdict.label }}
                    </option>
                  </select>
                </div>
              </li>
            </ul>

            <!--
              Why there is nothing here, in terms somebody can act on.

              These groups did not appear at all until now — the list was built from the
              matches, so a planned item nothing was found for simply vanished, while the
              render drew it anyway. Four products in the list, nine pieces of furniture in
              the picture, and nothing on the page joining the two. "We stock none of these"
              and "we stock one and it is 90cm where you have room for 80" are different
              answers and only one of them is worth coming back for.
            -->
            <p v-if="group.matches.length === 0" class="mt-3 text-sm text-muted">
              {{ group.unavailable_reason ?? 'Bu parça için uygun ürün bulunamadı.' }}
            </p>
          </div>
        </div>
      </section>

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

                <!--
                  Progress, while there is any. The bar is driven by which stage the engine
                  last announced rather than by elapsed time, so it moves predictably
                  instead of jumping about as providers vary.
                -->
                <div v-if="progress[row.node.id] && !progress[row.node.id]!.is_finished" class="mt-3 max-w-sm">
                  <div class="flex items-center justify-between gap-3 text-xs text-ink-secondary">
                    <span>{{ progress[row.node.id]!.stage_label ?? 'Hazırlanıyor' }}</span>
                    <span class="tabular-nums text-muted">%{{ Math.round(progress[row.node.id]!.progress_bps / 100) }}</span>
                  </div>
                  <div class="mt-1.5 h-1 overflow-hidden rounded-full bg-bg-muted">
                    <div
                      class="h-full bg-charcoal transition-all duration-500"
                      :style="{ width: `${progress[row.node.id]!.progress_bps / 100}%` }"
                    />
                  </div>
                </div>

                <p v-if="row.node.status === 'failed' && row.node.failure_reason" class="mt-2 max-w-[60ch] text-xs text-danger">
                  {{ row.node.failure_reason }}
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
              <fieldset class="flex flex-wrap gap-3">
                <legend class="sr-only">Görsel kalitesi</legend>
                <!--
                  Two levels rather than a slider: the difference somebody can actually
                  perceive is "quick look" versus "the one I show people", and a third
                  option in between would be a choice nobody can make confidently.
                -->
                <label class="flex items-center gap-2 text-sm">
                  <input v-model="branchQuality" type="radio" value="draft" class="accent-charcoal">
                  Hızlı önizleme
                </label>
                <label class="flex items-center gap-2 text-sm">
                  <input v-model="branchQuality" type="radio" value="premium" class="accent-charcoal">
                  Yüksek kalite
                </label>
              </fieldset>

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

    <!--
      The picture, full-screen.

      `object-contain` inside the viewport rather than a fixed size: the render matches the
      customer's photograph, which might be a wide living room or a tall corner, and either
      has to arrive whole. Cropping the thing somebody opened in order to see it properly
      would be a peculiar way to answer the click.

      The backdrop closes it, Escape closes it, and the arrow keys move between the two —
      flipping between before and after in place is how the difference actually reads.

      Above the sticky header, at the layer the navigation drawer already uses, and opaque.
      A translucent backdrop let the site navigation read straight through it and across the
      caption — the logo and "Ürünler" sitting over the words "Son hâli". Nothing is gained
      by seeing the page behind: this exists so somebody can look at one picture properly.
    -->
    <div
      v-if="zoomedImage"
      ref="zoomDialog"
      class="fixed inset-0 z-[70] flex flex-col bg-black p-4 sm:p-8"
      role="dialog"
      aria-modal="true"
      :aria-label="zoomedImage.label"
      tabindex="-1"
      @click.self="zoomed = null"
      @keydown="onZoomKeydown"
    >
      <div class="flex items-center justify-between gap-4 pb-4 text-white">
        <p class="text-sm">{{ zoomedImage.label }}</p>

        <div class="flex items-center gap-2">
          <button
            v-if="canFlip"
            type="button"
            class="rounded-pill border border-white/40 px-4 py-1.5 text-sm hover:bg-white/10"
            @click="flipZoom"
          >
            {{ zoomed === 'after' ? 'İlk hâline bak' : 'Son hâline bak' }}
          </button>

          <button
            type="button"
            class="rounded-pill border border-white/40 px-4 py-1.5 text-sm hover:bg-white/10"
            aria-label="Kapat"
            @click="zoomed = null"
          >
            Kapat
          </button>
        </div>
      </div>

      <img
        :src="zoomedImage.src"
        :alt="zoomedImage.label"
        class="min-h-0 flex-1 object-contain"
        @click="canFlip && flipZoom()"
      >
    </div>
  </div>
</template>
