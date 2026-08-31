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
  /** The catalogue's own name for it, so a heading is not a database slug. */
  category_label: string | null
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
 * The version on screen.
 *
 * The one the customer is looking at, not the newest — going back to an earlier version is
 * the point of keeping a tree, and its shopping list has to come back with it.
 *
 * Except while the first one is still running, when there is no current version at all: a
 * design only points at a version once it has finished. Without the fallback the whole
 * before-and-after panel had nothing to follow, so the progress screen sat on "Başlıyoruz"
 * for the entire minute while the engine worked through every stage behind it.
 */
const shownVersionId = computed(() => {
  const current = design.value?.current_version?.id

  if (current) {
    return current
  }

  const newest = design.value ? flatten(design.value.tree).at(-1)?.node.id : null

  return newest ?? null
})

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

        <!--
          The name carries the page, and the metadata gets out of its way.
          A heading competing with a status pill and a credit count for the same line reads
          as three equal things, and only one of them is what somebody came to look at.
        -->
        <div class="mt-3 flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
          <div>
            <h1 class="text-3xl font-medium tracking-[-0.01em]">{{ design.name }}</h1>

            <p class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted">
              <span>{{ design.version_count }} sürüm</span>
              <span v-if="design.total_credit_cost > 0">· {{ design.total_credit_cost }} kredi</span>
              <span v-if="shoppingList" class="text-ink-secondary">
                · {{ shoppingList.placements.length }} parça planlandı
              </span>
            </p>
          </div>

          <RcStatusPill
            :status="design.status === 'ready' ? 'approved' : design.status === 'failed' ? 'rejected' : 'in_review'"
            :label="design.status_label"
          />
        </div>
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
      <!--
        The room, before and after, under one handle.

        Side by side was honest and small: two pictures at half width each, compared by
        looking back and forth. The thing worth seeing is that this is the *same* room, and
        that arrangement makes it hardest — the eye has to hold one image while it reads the
        other, and it cannot. Under a wipe the walls line up and dragging is the proof.
      -->
      <section v-if="design.source_image_url" class="rc-card overflow-hidden">
        <div class="flex flex-wrap items-end justify-between gap-4 p-6 pb-5 sm:px-8 sm:pt-8">
          <div>
            <h2 class="text-xl font-medium">Odanız</h2>
            <p class="mt-1.5 max-w-[52ch] text-sm leading-relaxed text-ink-secondary">
              {{ shownVersion?.image_url
                ? 'Ortadaki çubuğu sağa sola sürükleyin: solda odanızın ilk hâli, sağda önerilen ürünlerle hâli.'
                : 'Tasarım hazır olduğunda odanızı burada karşılaştırabileceksiniz.' }}
            </p>
          </div>

          <p v-if="shownVersion" class="text-sm text-muted">
            v{{ shownVersion.version_number }}
          </p>
        </div>

        <div class="px-6 pb-6 sm:px-8 sm:pb-8">
          <RcBeforeAfter
            v-if="shownVersion?.image_url"
            :before-src="design.source_image_url"
            :after-src="shownVersion.image_url"
            @expand="zoomed = $event"
          />

          <!--
            A version that failed. Said plainly, in the frame where the picture would have
            been — a blank box under a heading reads as a broken page.
          -->
          <div
            v-else-if="shownVersion?.status === 'failed'"
            class="flex aspect-[16/10] w-full items-center justify-center rounded-md bg-bg-muted px-8 text-center text-sm text-muted"
          >
            {{ shownVersion.failure_reason ?? 'Bu sürüm tamamlanamadı.' }}
          </div>

          <!--
            And one still running. The room draws itself as the engine reads it, so the
            most exciting minute in the product is not an empty grey panel.
          -->
          <RcDesignInProgress
            v-else
            class="aspect-[16/10] w-full overflow-hidden rounded-md"
            :stage="shownVersionId ? progress[shownVersionId]?.stage : null"
            :progress-bps="shownVersionId ? (progress[shownVersionId]?.progress_bps ?? 0) : 0"
          />

          <!--
            The one thing the render cannot be held to.

            The model is told to place only what is on the list and it mostly does — but it
            still styles the scene with a plant, a vase, a stack of books. Small things, and
            not furniture, but they are on screen and not in the basket.
          -->
          <p v-if="shownVersion?.image_url" class="mt-3 text-xs leading-relaxed text-muted">
            Aşağıdaki listedeki ürünler odanıza yerleştirildi. Görseldeki küçük dekoratif
            objeler temsilîdir, satışta değildir.
          </p>
        </div>
      </section>

      <!--
        The shopping list.

        Above the version tree, because it is what the customer came for: the picture is
        the idea and this is the part they can act on.
      -->
      <!--
        The shopping list, as a list.

        It was a three-column grid of cards, and almost every placement has one suggestion —
        so nearly every row was one card and two-thirds empty air, and the page ran to three
        and a half thousand pixels of mostly nothing. A shopping list is a list: the product
        on the left, what it is and what it costs across the middle, the decision on the
        right. Alternatives sit under the row that owns them rather than competing with it
        for the same shelf.
      -->
      <section v-if="shoppingList && shoppingList.placements.length > 0" class="rc-card overflow-hidden">
        <div class="flex flex-wrap items-start justify-between gap-4 p-6 pb-5 sm:px-8 sm:pt-8">
          <div>
            <h2 class="text-xl font-medium">Alışveriş listesi</h2>
            <p class="mt-1.5 max-w-[58ch] text-sm leading-relaxed text-ink-secondary">
              Plandaki her parça için satın alınabilir, ölçüsü uyan ve stokta olan ürünler.
              Beğenmediklerinizi işaretlerseniz bir dahakine önerilmez.
            </p>
          </div>

          <button
            type="button"
            class="shrink-0 text-sm text-ink-secondary underline-offset-4 transition-colors hover:text-ink hover:underline disabled:opacity-40"
            :disabled="listBusy"
            @click="rebuildList"
          >
            Önerileri yenile
          </button>
        </div>

        <RcAlert v-if="listMessage" tone="info" class="mx-6 mb-5 sm:mx-8">{{ listMessage }}</RcAlert>

        <ul class="divide-y divide-line border-y border-line">
          <li
            v-for="group in shoppingList.placements"
            :key="group.index"
            class="px-6 py-5 transition-colors sm:px-8"
            :class="group.matches.some(m => m.status === 'accepted') ? 'bg-bg-muted/60' : ''"
          >
            <div class="flex items-start gap-5">
              <!--
                The picture at a size somebody can judge a sofa by. Linked when there is a
                product page to go to, and a plain frame when the placement is empty.
              -->
              <component
                :is="group.matches[0]?.product.slug ? 'a' : 'div'"
                v-bind="group.matches[0]?.product.slug
                  ? { href: `/catalog/${group.matches[0].product.slug}`, target: '_blank', rel: 'noopener' }
                  : {}"
                class="group/img size-24 shrink-0 overflow-hidden rounded-md bg-bg-muted sm:size-28"
              >
                <img
                  v-if="group.matches[0]?.product.image_url"
                  :src="group.matches[0].product.image_url"
                  :alt="group.matches[0].product.name ?? ''"
                  class="size-full object-cover transition-transform duration-300 group-hover/img:scale-105"
                  loading="lazy"
                >
              </component>

              <div class="min-w-0 flex-1">
                <p class="flex flex-wrap items-baseline gap-x-2 text-xs uppercase tracking-wide text-muted">
                  <span>{{ group.category_label ?? group.category }}</span>
                  <span v-if="group.quantity > 1" class="text-ink-secondary">× {{ group.quantity }}</span>
                  <span v-if="group.max_width_mm">· en fazla {{ Math.round(group.max_width_mm / 10) }} cm</span>
                </p>

                <template v-if="group.matches[0]">
                  <a
                    v-if="group.matches[0].product.slug"
                    :href="`/catalog/${group.matches[0].product.slug}`"
                    target="_blank"
                    rel="noopener"
                    class="mt-1 block text-[15px] font-medium hover:underline"
                  >
                    {{ group.matches[0].product.name }}
                  </a>
                  <p v-else class="mt-1 text-[15px] font-medium">{{ group.matches[0].product.name }}</p>

                  <p v-if="group.matches[0].sku.seller" class="mt-0.5 text-sm text-muted">
                    {{ group.matches[0].sku.seller }}
                  </p>

                  <p
                    v-if="group.matches[0].price_has_moved && group.matches[0].current_price_minor"
                    class="mt-1 text-xs text-warning"
                  >
                    Güncel fiyat: {{ money(group.matches[0].current_price_minor, group.matches[0].price.currency) }}
                  </p>
                </template>

                <p v-else class="mt-1 text-sm text-muted">
                  {{ group.unavailable_reason ?? 'Bu parça için uygun ürün bulunamadı.' }}
                </p>
              </div>

              <div v-if="group.matches[0]" class="flex shrink-0 flex-col items-end gap-2">
                <p class="text-[15px] font-medium tabular-nums">
                  {{ money(group.matches[0].price.amount_minor, group.matches[0].price.currency) }}
                </p>

                <button
                  v-if="group.matches[0].status !== 'accepted'"
                  type="button"
                  class="rounded-pill border border-line px-3.5 py-1.5 text-sm transition-colors hover:border-charcoal disabled:opacity-40"
                  :disabled="listBusy"
                  @click="chooseMatch(group.matches[0])"
                >
                  Seç
                </button>
                <span
                  v-else
                  class="rounded-pill bg-charcoal px-3.5 py-1.5 text-sm text-inverse"
                >
                  Seçildi
                </span>
              </div>
            </div>

            <!--
              The alternatives, under the row they belong to rather than beside it.

              Small, because they are a second thought: somebody who likes the first
              suggestion should not have to read four more before moving on, and somebody
              who does not can see all of them without leaving the line.
            -->
            <div v-if="group.matches.length > 1" class="mt-4 flex flex-wrap items-center gap-2 pl-[7.25rem]">
              <span class="text-xs text-muted">Alternatifler:</span>

              <button
                v-for="match in group.matches.slice(1)"
                :key="match.id"
                type="button"
                class="flex items-center gap-2 rounded-pill border py-1 pr-3 pl-1 text-xs transition-colors disabled:opacity-40"
                :class="match.status === 'rejected'
                  ? 'border-line/60 text-muted line-through'
                  : 'border-line hover:border-charcoal'"
                :disabled="listBusy"
                @click="chooseMatch(match)"
              >
                <span class="size-7 overflow-hidden rounded-full bg-bg-muted">
                  <img
                    v-if="match.product.image_url"
                    :src="match.product.image_url"
                    :alt="match.product.name ?? ''"
                    class="size-full object-cover"
                    loading="lazy"
                  >
                </span>
                <span class="tabular-nums">{{ money(match.price.amount_minor, match.price.currency) }}</span>
              </button>

              <select
                class="rounded-pill border border-line bg-surface px-3 py-1.5 text-xs text-ink-secondary"
                :disabled="listBusy"
                @change="sendFeedback(group.matches[0]!, ($event.target as HTMLSelectElement).value)"
              >
                <option value="">Beğenmedim…</option>
                <option v-for="verdict in shoppingList.verdicts" :key="verdict.value" :value="verdict.value">
                  {{ verdict.label }}
                </option>
              </select>
            </div>
          </li>
        </ul>

        <!--
          The total, and the one action the page exists for.

          At the foot of the list rather than floating above it: a customer decides
          item by item and then commits, and a call to action that appears before there is
          anything to commit to is noise.
        -->
        <div class="flex flex-wrap items-center justify-between gap-4 p-6 sm:px-8">
          <div>
            <p class="text-sm text-ink-secondary">
              {{ chosen.length > 0
                ? `${chosen.length} parça seçtiniz`
                : 'Beğendiğiniz ürünleri seçin, tamamını tek seferde sepete ekleyin.' }}
            </p>
            <p v-if="chosen.length > 0" class="mt-0.5 text-xl font-medium tabular-nums">
              {{ money(chosenTotal, shoppingList.currency) }}
            </p>
          </div>

          <RcButton
            :loading="addingToCart"
            :disabled="chosen.length === 0 || addingToCart"
            data-testid="add-design-to-cart"
            @click="addChosenToCart"
          >
            Seçtiklerimi sepete ekle
          </RcButton>
        </div>

        <RcAlert
          v-if="cartMessage"
          :tone="cartMessage.tone"
          class="mx-6 mb-6 sm:mx-8"
        >
          {{ cartMessage.text }}
        </RcAlert>
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
