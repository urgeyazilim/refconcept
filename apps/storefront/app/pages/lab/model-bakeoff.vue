<script setup lang="ts">
/**
 * The generators side by side, on our own products.
 *
 * Reads the index `refconcept:model-bakeoff` writes and shows one product at a time with a
 * canvas per generator, all four cameras locked together — turn one and the others turn,
 * which is the only honest way to compare a back nobody photographed. Each mesh is scaled to
 * the SKU's recorded size exactly as the planner would scale it, so what looks convincing
 * here looks convincing there.
 *
 * A bench, not a screen: no layout, no API session, nothing a customer can reach by accident.
 */
import {
  AmbientLight,
  Box3,
  Color,
  DirectionalLight,
  GridHelper,
  Group,
  Mesh,
  MeshStandardMaterial,
  PerspectiveCamera,
  PlaneGeometry,
  PMREMGenerator,
  Scene,
  Vector3,
  WebGLRenderer,
} from 'three'
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js'
import { RoomEnvironment } from 'three/examples/jsm/environments/RoomEnvironment.js'
import { MeshoptDecoder } from 'three/examples/jsm/libs/meshopt_decoder.module.js'
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js'

definePageMeta({ layout: false })
useHead({ title: '3B model karşılaştırması · laboratuvar' })

interface BakeoffModel { code: string, label: string, name: string, price_usd: number }
interface BakeoffProduct {
  id: string
  name: string
  category: string | null
  width_mm: number | null
  height_mm: number | null
  depth_mm: number | null
  image_url: string | null
}
interface Outcome {
  url: string | null
  bytes: number
  triangles: number | null
  textures: number
  seconds: number
  failure: string | null
}
interface BakeoffIndex {
  generated_at: string
  models: BakeoffModel[]
  products: BakeoffProduct[]
  results: Record<string, Record<string, Outcome | null>>
}

const route = useRoute()

const indexUrl = computed(() => {
  const given = route.query.index

  return typeof given === 'string' && given !== ''
    ? given
    : 'http://localhost:59000/refconcept-public/product-models/bakeoff/index.json'
})

const index = ref<BakeoffIndex | null>(null)
const error = ref<string | null>(null)
const current = ref(0)

const product = computed(() => index.value?.products[current.value] ?? null)

/** One vote per product, kept in this browser only. It is a bench. */
const votes = ref<Record<string, string>>({})

function loadVotes(): void {
  try {
    votes.value = JSON.parse(localStorage.getItem('bakeoff-votes') ?? '{}') as Record<string, string>
  }
  catch {
    votes.value = {}
  }
}

function vote(label: string): void {
  if (product.value === null) return

  votes.value = { ...votes.value, [product.value.id]: label }

  try {
    localStorage.setItem('bakeoff-votes', JSON.stringify(votes.value))
  }
  catch {
    // A private window. The vote still shows until the page is closed.
  }
}

const tally = computed(() => {
  const counts: Record<string, number> = {}

  for (const label of Object.values(votes.value)) {
    counts[label] = (counts[label] ?? 0) + 1
  }

  return counts
})

const cost = computed(() => {
  if (index.value === null) return 0

  return index.value.models.reduce((sum, model) => sum + model.price_usd, 0) * index.value.products.length
})

// --- the viewers ---------------------------------------------------------------

interface Viewer {
  label: string
  canvas: HTMLCanvasElement
  renderer: WebGLRenderer
  scene: Scene
  camera: PerspectiveCamera
  controls: OrbitControls
  holder: Group
}

const canvases = ref<HTMLCanvasElement[]>([])
const viewers: Viewer[] = []
let syncing = false
let frame = 0

function makeViewer(label: string, canvas: HTMLCanvasElement): Viewer {
  const renderer = new WebGLRenderer({ canvas, antialias: true, alpha: true })

  renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2))
  renderer.setSize(canvas.clientWidth, canvas.clientHeight, false)
  renderer.shadowMap.enabled = true

  const scene = new Scene()

  scene.background = new Color(0xf3efe8)

  // Per renderer, not shared: a PMREM texture belongs to the GL context that made it, and
  // handed to a second canvas it lights nothing — the first bench had one bright mesh and
  // three dim ones, which is exactly the false comparison this page exists to avoid.
  scene.environment = new PMREMGenerator(renderer).fromScene(new RoomEnvironment(), 0.04).texture

  const sun = new DirectionalLight(0xffffff, 1.6)

  sun.position.set(2, 4, 3)
  sun.castShadow = true
  sun.shadow.mapSize.set(1024, 1024)
  scene.add(sun, new AmbientLight(0xffffff, 0.25))

  const floor = new Mesh(new PlaneGeometry(6, 6), new MeshStandardMaterial({ color: 0xe6dfd4, roughness: 1 }))

  floor.rotation.x = -Math.PI / 2
  floor.receiveShadow = true
  scene.add(floor, new GridHelper(6, 12, 0xc9bfb0, 0xded6ca))

  const camera = new PerspectiveCamera(40, canvas.clientWidth / canvas.clientHeight, 0.05, 50)

  camera.position.set(2.2, 1.6, 2.6)

  const controls = new OrbitControls(camera, canvas)

  controls.target.set(0, 0.45, 0)
  controls.enableDamping = true

  // Turn one, turn them all.
  controls.addEventListener('change', () => {
    if (syncing) return

    syncing = true

    for (const other of viewers) {
      if (other.controls === controls) continue

      other.camera.position.copy(camera.position)
      other.controls.target.copy(controls.target)
      other.controls.update()
    }

    syncing = false
  })

  const holder = new Group()

  scene.add(holder)

  return { label, canvas, renderer, scene, camera, controls, holder }
}

const loader = new GLTFLoader().setMeshoptDecoder(MeshoptDecoder)

async function show(viewer: Viewer, outcome: Outcome | null, item: BakeoffProduct): Promise<void> {
  viewer.holder.clear()

  if (outcome === null || outcome.url === null) {
    return
  }

  let scene: Group

  try {
    scene = (await loader.loadAsync(outcome.url)).scene
  }
  catch {
    return
  }

  // Scaled to the SKU, the way the planner scales it, and stood on the floor.
  const bounds = new Box3().setFromObject(scene)
  const size = bounds.getSize(new Vector3())
  const width = (item.width_mm ?? 1000) / 1000
  const height = (item.height_mm ?? 800) / 1000
  const depth = (item.depth_mm ?? 800) / 1000
  const scale = Math.min(width / size.x, height / size.y, depth / size.z)

  scene.scale.setScalar(scale)

  const scaled = new Box3().setFromObject(scene)
  const centre = scaled.getCenter(new Vector3())

  scene.position.set(-centre.x, -scaled.min.y, -centre.z)

  scene.traverse((child) => {
    if (child instanceof Mesh) {
      child.castShadow = true
      child.receiveShadow = true
    }
  })

  viewer.holder.add(scene)
}

function showCurrent(): void {
  const item = product.value

  if (item === null || index.value === null) return

  for (const viewer of viewers) {
    void show(viewer, index.value.results[item.id]?.[viewer.label] ?? null, item)
  }
}

function tick(): void {
  frame = requestAnimationFrame(tick)

  for (const viewer of viewers) {
    const { canvas, renderer, camera } = viewer

    if (canvas.width !== canvas.clientWidth || canvas.height !== canvas.clientHeight) {
      renderer.setSize(canvas.clientWidth, canvas.clientHeight, false)
      camera.aspect = canvas.clientWidth / canvas.clientHeight
      camera.updateProjectionMatrix()
    }

    viewer.controls.update()
    renderer.render(viewer.scene, camera)
  }
}

function outcomeFor(label: string): Outcome | null {
  if (product.value === null || index.value === null) return null

  return index.value.results[product.value.id]?.[label] ?? null
}

function megabytes(bytes: number): string {
  return (bytes / 1_048_576).toFixed(1)
}

onMounted(async () => {
  loadVotes()

  try {
    const response = await fetch(indexUrl.value, { cache: 'no-store' })

    if (!response.ok) throw new Error(`${response.status}`)

    index.value = await response.json() as BakeoffIndex
  }
  catch (caught) {
    error.value = `Dizin okunamadı: ${caught instanceof Error ? caught.message : String(caught)}`

    return
  }

  await nextTick()

  index.value.models.forEach((model, at) => {
    const canvas = canvases.value[at]

    if (canvas !== undefined) {
      viewers.push(makeViewer(model.label, canvas))
    }
  })

  showCurrent()
  tick()
})

watch(current, showCurrent)

onBeforeUnmount(() => {
  cancelAnimationFrame(frame)

  for (const viewer of viewers) {
    viewer.controls.dispose()
    viewer.renderer.dispose()
  }
})
</script>

<template>
  <div class="min-h-screen bg-bg p-6 text-ink">
    <header class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 class="text-xl font-medium">3B model karşılaştırması</h1>
        <p v-if="index" class="mt-1 text-sm text-muted">
          {{ index.products.length }} ürün × {{ index.models.length }} üretici · ~{{ cost.toFixed(2) }} $ ·
          {{ new Date(index.generated_at).toLocaleString('tr-TR') }}
        </p>
      </div>

      <div v-if="index" class="flex flex-wrap gap-2 text-xs">
        <span
          v-for="model in index.models"
          :key="model.label"
          class="rounded-pill border border-line bg-surface px-3 py-1"
        >
          {{ model.label }}: {{ tally[model.label] ?? 0 }} oy
        </span>
      </div>
    </header>

    <p v-if="error" class="mt-6 text-sm text-danger">{{ error }}</p>

    <template v-if="index && product">
      <section class="mt-6 flex flex-wrap items-center gap-4">
        <button type="button" class="rounded-sm border border-line px-3 py-1.5 text-sm" :disabled="current === 0" @click="current--">← Önceki</button>
        <div>
          <p class="font-medium">{{ current + 1 }} / {{ index.products.length }} · {{ product.name }}</p>
          <p class="text-xs text-muted">
            {{ product.category ?? '-' }} · {{ product.width_mm ?? '?' }} × {{ product.depth_mm ?? '?' }} × {{ product.height_mm ?? '?' }} mm
          </p>
        </div>
        <button type="button" class="rounded-sm border border-line px-3 py-1.5 text-sm" :disabled="current >= index.products.length - 1" @click="current++">Sonraki →</button>
      </section>

      <section class="mt-4 grid gap-4" :style="{ gridTemplateColumns: `repeat(${index.models.length + 1}, minmax(0, 1fr))` }">
        <!--
          The photograph the generators were given, the same size as their answers and in the
          first column: the comparison is "which of these is that", and that has to be on the
          same row as these.
        -->
        <figure class="rc-card overflow-hidden">
          <div class="flex aspect-[4/3] w-full items-center justify-center bg-white">
            <img v-if="product.image_url" :src="product.image_url" :alt="product.name" class="max-h-full max-w-full object-contain">
            <span v-else class="text-xs text-muted">fotoğraf yok</span>
          </div>
          <figcaption class="space-y-1 p-3 text-xs">
            <div class="flex items-center justify-between">
              <span class="font-medium">Ürün fotoğrafı</span>
              <span class="text-muted">girdi</span>
            </div>
            <p class="text-muted">Üreticilere verilen görsel; modeller bununla karşılaştırılır.</p>
          </figcaption>
        </figure>

        <figure v-for="model in index.models" :key="model.label" class="rc-card overflow-hidden">
          <canvas ref="canvases" class="block aspect-[4/3] w-full" />
          <figcaption class="space-y-1 p-3 text-xs">
            <div class="flex items-center justify-between">
              <span class="font-medium">{{ model.name }}</span>
              <span class="text-muted">{{ model.price_usd.toFixed(2) }} $</span>
            </div>
            <template v-if="outcomeFor(model.label)?.url">
              <p class="text-muted">
                {{ outcomeFor(model.label)?.seconds }} sn ·
                {{ (outcomeFor(model.label)?.triangles ?? 0).toLocaleString('tr-TR') }} üçgen ·
                {{ outcomeFor(model.label)?.textures }} doku ·
                {{ megabytes(outcomeFor(model.label)?.bytes ?? 0) }} MB
              </p>
            </template>
            <p v-else class="text-danger">{{ outcomeFor(model.label)?.failure ?? 'üretilmedi' }}</p>
            <label class="flex items-center gap-2 pt-1">
              <input type="radio" :name="`vote-${product.id}`" :checked="votes[product.id] === model.label" @change="vote(model.label)">
              <span>Bu ürün için en iyisi</span>
            </label>
          </figcaption>
        </figure>
      </section>

      <p class="mt-4 text-xs text-muted">Bir görünümü döndürün, hepsi birlikte döner. Ölçek planlayıcıdakiyle aynı: SKU ölçülerine oturtulmuş.</p>
    </template>
  </div>
</template>
