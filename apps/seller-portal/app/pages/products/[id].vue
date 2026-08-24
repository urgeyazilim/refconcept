<script setup lang="ts">
import type {
  CatalogAttribute,
  CatalogCategory,
  Product,
  ProductCompletenessMeta,
  ProductSummaryRef,
} from '@refconcept/ui/types'

/**
 * The listing editor.
 *
 * The checklist in the sidebar is the point of this screen. A seller does not want
 * to know that their listing is "incomplete"; they want to know that it is missing a
 * photograph and a depth measurement, and they want that to stop being true the
 * moment they supply one. So `meta` is refreshed from the server after every change
 * rather than recomputed here — the API owns what "ready for review" means, and a
 * second definition in the browser would eventually disagree with it.
 *
 * Once submitted, everything locks. That is not a UI nicety: the API refuses edits
 * to a listing awaiting review, and showing enabled inputs that will 403 is worse
 * than showing disabled ones with a reason.
 */
definePageMeta({ middleware: 'auth' })

const route = useRoute()
const api = useApi()
const config = useRuntimeConfig()
const productId = route.params.id as string

/** Where a published listing can be viewed as a customer would see it. */
const storefrontUrl = config.public.storefrontUrl

const product = ref<Product | null>(null)
const meta = ref<ProductCompletenessMeta>({
  missing_requirements: [],
  completion_percent: 0,
  can_submit: false,
})

const categories = ref<CatalogCategory[]>([])
const brands = ref<ProductSummaryRef[]>([])
const styles = ref<Array<{ id: string, code: string, name: string }>>([])
const categoryAttributes = ref<CatalogAttribute[]>([])

const loadError = ref<string | null>(null)
const saving = ref(false)
const savedAt = ref<string | null>(null)
const errors = ref<Record<string, string[]>>({})
const actionError = ref<string | null>(null)
const actionMessage = ref<string | null>(null)

const form = reactive({
  name: '',
  description: '',
  primary_category_id: '',
  brand_id: '',
  style_id: '',
  seo_title: '',
  seo_description: '',
})

/** Attribute code → value, kept flat because that is what the API takes. */
const attributeValues = reactive<Record<string, string>>({})

async function loadProduct() {
  const response = await api.get<{ data: Product, meta: ProductCompletenessMeta }>(
    `/api/v1/seller/products/${productId}`,
  )

  applyProduct(response.data)
  meta.value = response.meta
}

function applyProduct(next: Product) {
  product.value = next

  form.name = next.name
  form.description = next.description ?? ''
  form.primary_category_id = next.category?.id ?? ''
  form.brand_id = next.brand?.id ?? ''
  form.style_id = next.style?.id ?? ''

  for (const attribute of next.attributes ?? []) {
    if (attribute.code) attributeValues[attribute.code] = String(attribute.value ?? '')
  }
}

try {
  const [, categoryResponse, brandResponse, vocabularyResponse] = await Promise.all([
    loadProduct(),
    api.get<{ data: CatalogCategory[] }>('/api/v1/catalog/categories'),
    api.get<{ data: ProductSummaryRef[] }>('/api/v1/catalog/brands'),
    api.get<{ data: { styles: Array<{ id: string, code: string, name: string }> } }>(
      '/api/v1/catalog/vocabulary',
    ),
  ])

  categories.value = categoryResponse.data
  brands.value = brandResponse.data
  styles.value = vocabularyResponse.data.styles
} catch (error) {
  // A listing that belongs to another seller answers 403. Saying "bulunamadı" would
  // be friendlier but dishonest; saying nothing and showing Laravel's English default
  // is worse than either.
  loadError.value = error instanceof ApiError
    ? ({
        404: 'Bu ürün bulunamadı.',
        403: 'Bu ürüne erişim yetkiniz yok.',
      }[error.status] ?? error.message)
    : 'Ürün yüklenemedi.'
}

useHead(() => ({ title: product.value?.name ?? 'Ürün' }))

const isLocked = computed(() => product.value !== null && !product.value.is_editable)

const isPublished = computed(() => product.value?.moderation_status === 'approved')

/**
 * What editing this listing costs right now.
 *
 * Three states worth telling apart, because the seller's next move differs in each:
 * locked while a reviewer holds it, free while it is a draft, and free-but-it-goes-
 * back-to-the-queue once it is live. The last one has to be said before they edit,
 * not discovered when their listing disappears from the catalogue.
 */
const editingNotice = computed(() => {
  if (isLocked.value) {
    return {
      tone: 'info' as const,
      text: 'Bu ürün incelemede olduğu için düzenlenemez. İnceleme sonuçlandığında yeniden düzenleyebilirsiniz.',
    }
  }

  if (isPublished.value) {
    return {
      tone: 'warning' as const,
      text: 'Bu ürün yayında. Yaptığınız her değişiklik ürünü yeniden incelemeye gönderir ve inceleme bitene kadar katalogdan kaldırır.',
    }
  }

  return null
})

const categoryOptions = computed(() => {
  const parentIds = new Set(categories.value.map(item => item.parent_id).filter(Boolean))

  return categories.value.map(category => ({
    ...category,
    isLeaf: !parentIds.has(category.id),
    // Non-breaking spaces: a browser collapses ordinary leading whitespace inside
    // an <option>, so plain indentation silently does nothing.
    indent: '   '.repeat(Math.max(0, category.depth)),
  }))
})

/** The attribute set follows the chosen category, so it reloads when that changes. */
async function loadCategoryAttributes(categoryId: string) {
  const category = categories.value.find(item => item.id === categoryId)

  if (!category) {
    categoryAttributes.value = []

    return
  }

  try {
    const response = await api.get<{ data: CatalogAttribute[] }>(
      `/api/v1/catalog/categories/${category.slug}/attributes`,
    )

    categoryAttributes.value = response.data
  } catch {
    categoryAttributes.value = []
  }
}

watch(() => form.primary_category_id, id => {
  if (id) loadCategoryAttributes(id)
}, { immediate: true })

function attributePayload() {
  return categoryAttributes.value
    .filter(attribute => (attributeValues[attribute.code] ?? '') !== '')
    .map(attribute => ({ code: attribute.code, value: attributeValues[attribute.code] }))
}

async function save() {
  saving.value = true
  errors.value = {}
  actionError.value = null
  actionMessage.value = null

  try {
    const response = await api.patch<{ data: Product, meta: ProductCompletenessMeta }>(
      `/api/v1/seller/products/${productId}`,
      {
        name: form.name,
        description: form.description || null,
        primary_category_id: form.primary_category_id,
        brand_id: form.brand_id || null,
        style_id: form.style_id || null,
        seo_title: form.seo_title || null,
        seo_description: form.seo_description || null,
        attributes: attributePayload(),
      },
    )

    applyProduct(response.data)
    meta.value = response.meta
    savedAt.value = new Date().toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' })
  } catch (error) {
    if (error instanceof ApiError && error.isValidation) {
      errors.value = error.errors
      actionError.value = 'Bazı alanlar düzeltilmeli.'
    } else {
      actionError.value = error instanceof ApiError ? error.message : 'Ürün kaydedilemedi.'
    }
  } finally {
    saving.value = false
  }
}

/** Media and SKU children hand back a fresh product; the checklist follows it. */
async function onChildUpdate(next: Product) {
  applyProduct(next)
  await refreshMeta()
}

async function refreshMeta() {
  try {
    const response = await api.get<{ data: Product, meta: ProductCompletenessMeta }>(
      `/api/v1/seller/products/${productId}`,
    )

    applyProduct(response.data)
    meta.value = response.meta
  } catch {
    // Leaving the previous checklist on screen is better than blanking it: it was
    // accurate a moment ago, and the next successful action will correct it.
  }
}

async function submitForReview() {
  saving.value = true
  actionError.value = null
  actionMessage.value = null

  try {
    const response = await api.post<{
      message: string
      data: Product
      meta: ProductCompletenessMeta
    }>(`/api/v1/seller/products/${productId}/submit`)

    applyProduct(response.data)
    meta.value = response.meta
    actionMessage.value = response.message
  } catch (error) {
    if (error instanceof ApiError && error.isValidation) {
      actionError.value = Object.values(error.errors).flat().join(' ')
    } else {
      actionError.value = error instanceof ApiError ? error.message : 'Ürün gönderilemedi.'
    }

    await refreshMeta()
  } finally {
    saving.value = false
  }
}

/** Pausing and resuming a published listing needs no second review. */
async function setStatus(status: 'active' | 'archived') {
  saving.value = true
  actionError.value = null

  try {
    const response = await api.patch<{ data: Product }>(
      `/api/v1/seller/products/${productId}/status`,
      { status },
    )

    applyProduct(response.data)
    actionMessage.value = status === 'active' ? 'Ürün yeniden yayında.' : 'Ürün satıştan kaldırıldı.'
  } catch (error) {
    actionError.value = error instanceof ApiError ? error.message : 'Durum değiştirilemedi.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="space-y-6">
    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <template v-else-if="product">
      <header class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
          <NuxtLink to="/products" class="text-sm text-ink-secondary hover:text-ink">
            ← Ürünlerim
          </NuxtLink>
          <h1 class="mt-3 truncate text-2xl font-medium">{{ product.name }}</h1>

          <div class="mt-2.5 flex flex-wrap items-center gap-2">
            <RcStatusPill
              :status="product.moderation_status"
              :label="product.moderation_status_label"
            />
            <RcStatusPill
              v-if="product.moderation_status === 'approved'"
              :status="product.status"
              :label="product.status_label"
            />
            <span v-if="savedAt" class="text-xs text-muted">{{ savedAt }} itibarıyla kaydedildi</span>
          </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <RcButton
            v-if="product.moderation_status === 'approved' && product.status === 'active'"
            variant="secondary"
            size="sm"
            :disabled="saving"
            @click="setStatus('archived')"
          >
            Satıştan kaldır
          </RcButton>
          <RcButton
            v-else-if="product.moderation_status === 'approved'"
            variant="secondary"
            size="sm"
            :disabled="saving"
            @click="setStatus('active')"
          >
            Yeniden yayınla
          </RcButton>

          <RcButton
            v-if="product.is_editable && !isPublished"
            :disabled="!meta.can_submit || saving"
            :loading="saving"
            @click="submitForReview"
          >
            İncelemeye gönder
          </RcButton>
        </div>
      </header>

      <RcAlert v-if="actionMessage" tone="success">{{ actionMessage }}</RcAlert>
      <RcAlert v-if="actionError" tone="danger">{{ actionError }}</RcAlert>

      <RcAlert v-if="editingNotice" :tone="editingNotice.tone">
        {{ editingNotice.text }}
      </RcAlert>

      <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div class="space-y-6">
          <!-- Details -->
          <section class="rc-card p-6 sm:p-8">
            <h2 class="text-lg font-medium">Ürün bilgileri</h2>

            <form class="mt-6 space-y-5" @submit.prevent="save">
              <RcField
                v-model="form.name"
                label="Ürün adı"
                name="name"
                :errors="errors.name"
                :disabled="isLocked"
                required
              />

              <div>
                <label for="description" class="mb-1.5 block text-sm font-medium">
                  Açıklama <span class="text-danger">*</span>
                </label>
                <textarea
                  id="description"
                  v-model="form.description"
                  rows="6"
                  :disabled="isLocked"
                  placeholder="Malzemesi, üretim detayları, bakım önerileri…"
                  class="w-full rounded-sm border border-line bg-surface px-4 py-3 text-sm leading-relaxed disabled:opacity-60"
                />
                <p v-if="errors.description" class="mt-1.5 text-xs text-danger">
                  {{ errors.description[0] }}
                </p>
              </div>

              <div class="grid gap-4 sm:grid-cols-2">
                <div>
                  <label for="primary_category_id" class="mb-1.5 block text-sm font-medium">
                    Kategori <span class="text-danger">*</span>
                  </label>
                  <select
                    id="primary_category_id"
                    v-model="form.primary_category_id"
                    :disabled="isLocked"
                    class="w-full rounded-sm border border-line bg-surface px-4 py-2.5 text-sm disabled:opacity-60"
                  >
                    <option value="" disabled>Kategori seçin</option>
                    <option
                      v-for="category in categoryOptions"
                      :key="category.id"
                      :value="category.id"
                      :disabled="!category.isLeaf"
                    >
                      {{ category.indent }}{{ category.name }}{{ category.isLeaf ? '' : ' —' }}
                    </option>
                  </select>
                </div>

                <div>
                  <label for="brand_id" class="mb-1.5 block text-sm font-medium">Marka</label>
                  <select
                    id="brand_id"
                    v-model="form.brand_id"
                    :disabled="isLocked"
                    class="w-full rounded-sm border border-line bg-surface px-4 py-2.5 text-sm disabled:opacity-60"
                  >
                    <option value="">Belirtilmedi</option>
                    <option v-for="brand in brands" :key="brand.id" :value="brand.id">
                      {{ brand.name }}
                    </option>
                  </select>
                </div>

                <div>
                  <label for="style_id" class="mb-1.5 block text-sm font-medium">Tasarım stili</label>
                  <select
                    id="style_id"
                    v-model="form.style_id"
                    :disabled="isLocked"
                    class="w-full rounded-sm border border-line bg-surface px-4 py-2.5 text-sm disabled:opacity-60"
                  >
                    <option value="">Belirtilmedi</option>
                    <option v-for="style in styles" :key="style.id" :value="style.id">
                      {{ style.name }}
                    </option>
                  </select>
                </div>
              </div>

              <!-- Category-driven attributes -->
              <fieldset v-if="categoryAttributes.length > 0" class="space-y-4 pt-2">
                <legend class="text-sm font-medium">Kategori özellikleri</legend>
                <p class="max-w-[60ch] text-xs leading-relaxed text-muted">
                  Bu alanlar seçtiğiniz kategoriye göre değişir ve müşterinin filtrelerde
                  ürününüzü bulmasını sağlar.
                </p>

                <div class="grid gap-4 sm:grid-cols-2">
                  <div v-for="attribute in categoryAttributes" :key="attribute.code">
                    <label :for="`attr-${attribute.code}`" class="mb-1.5 block text-sm font-medium">
                      {{ attribute.name }}
                      <span v-if="attribute.is_required" class="text-danger">*</span>
                      <span v-if="attribute.unit" class="text-muted">({{ attribute.unit }})</span>
                    </label>

                    <select
                      v-if="attribute.values.length > 0"
                      :id="`attr-${attribute.code}`"
                      v-model="attributeValues[attribute.code]"
                      :disabled="isLocked"
                      class="w-full rounded-sm border border-line bg-surface px-4 py-2.5 text-sm disabled:opacity-60"
                    >
                      <option value="">Seçilmedi</option>
                      <option v-for="option in attribute.values" :key="option.value" :value="option.value">
                        {{ option.label }}
                      </option>
                    </select>

                    <input
                      v-else
                      :id="`attr-${attribute.code}`"
                      v-model="attributeValues[attribute.code]"
                      :type="attribute.data_type === 'integer' || attribute.data_type === 'decimal' ? 'number' : 'text'"
                      :disabled="isLocked"
                      class="w-full rounded-sm border border-line bg-surface px-4 py-2.5 text-sm disabled:opacity-60"
                    >
                  </div>
                </div>
              </fieldset>

              <details class="rounded-md border border-line p-4">
                <summary class="cursor-pointer text-sm font-medium">Arama motoru bilgileri</summary>
                <div class="mt-4 space-y-4">
                  <RcField
                    v-model="form.seo_title"
                    label="SEO başlığı"
                    name="seo_title"
                    :errors="errors.seo_title"
                    :disabled="isLocked"
                  />
                  <div>
                    <label for="seo_description" class="mb-1.5 block text-sm font-medium">
                      SEO açıklaması
                    </label>
                    <textarea
                      id="seo_description"
                      v-model="form.seo_description"
                      rows="3"
                      :disabled="isLocked"
                      class="w-full rounded-sm border border-line bg-surface px-4 py-3 text-sm disabled:opacity-60"
                    />
                  </div>
                </div>
              </details>

              <div class="pt-2">
                <RcButton type="submit" :loading="saving" :disabled="saving || isLocked">
                  Değişiklikleri kaydet
                </RcButton>
              </div>
            </form>
          </section>

          <ProductMediaManager
            :product-id="productId"
            :media="product.media ?? []"
            :disabled="isLocked"
            @updated="onChildUpdate"
          />

          <ProductSkuEditor
            :product-id="productId"
            :skus="product.skus ?? []"
            :disabled="isLocked"
            @updated="onChildUpdate"
          />
        </div>

        <!-- Completeness checklist -->
        <aside class="space-y-6 lg:sticky lg:top-6 lg:self-start">
          <section v-if="isPublished" class="rc-card p-6">
            <h2 class="text-sm font-medium">Yayında</h2>
            <p class="mt-2.5 text-sm leading-relaxed text-ink-secondary">
              Bu ürün onaylandı ve katalogda görünüyor. Satıştan kaldırmak için üstteki
              düğmeyi kullanabilirsiniz; bu, yeniden inceleme gerektirmez.
            </p>
            <NuxtLink
              v-if="product.slug"
              :to="`${storefrontUrl}/catalog/${product.slug}`"
              target="_blank"
              class="mt-4 inline-flex items-center gap-1.5 text-sm text-ink-secondary underline underline-offset-4 hover:text-ink"
            >
              Katalogdaki sayfasını aç
              <svg class="rc-icon size-3.5" viewBox="0 0 24 24" aria-hidden="true">
                <path d="M7 17 17 7m0 0H8m9 0v9" />
              </svg>
            </NuxtLink>
          </section>

          <section v-else class="rc-card p-6">
            <div class="flex items-center justify-between">
              <h2 class="text-sm font-medium">Yayına hazırlık</h2>
              <span class="text-xl font-medium tabular-nums">%{{ meta.completion_percent }}</span>
            </div>

            <div class="mt-3 h-2 w-full overflow-hidden rounded-pill bg-neutral-150">
              <div
                class="h-full rounded-pill transition-[width] duration-300"
                :class="meta.can_submit ? 'bg-success' : 'bg-gold'"
                :style="{ width: `${meta.completion_percent}%` }"
              />
            </div>

            <div v-if="meta.missing_requirements.length > 0" class="mt-5">
              <p class="text-xs font-medium text-ink-secondary">Eksik olanlar</p>
              <ul class="mt-2.5 space-y-2">
                <li
                  v-for="item in meta.missing_requirements"
                  :key="item"
                  class="flex items-start gap-2.5 text-sm text-ink-secondary"
                >
                  <svg class="rc-icon mt-0.5 size-4 shrink-0 text-warning-strong" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" />
                  </svg>
                  {{ item }}
                </li>
              </ul>
            </div>

            <p v-else class="mt-5 flex items-start gap-2.5 text-sm text-success-strong">
              <svg class="rc-icon mt-0.5 size-4 shrink-0" viewBox="0 0 24 24" aria-hidden="true">
                <path d="m5 13 4 4L19 7" />
              </svg>
              Bu ürün incelemeye gönderilmeye hazır.
            </p>
          </section>

          <section v-if="!isPublished" class="rc-card p-6">
            <h2 class="text-sm font-medium">Sonra ne olur?</h2>
            <ol class="mt-4 space-y-4 text-sm leading-relaxed text-ink-secondary">
              <li class="flex gap-3">
                <span class="grid size-6 shrink-0 place-items-center rounded-pill bg-bg-muted text-xs">1</span>
                Ürününüzü incelemeye gönderirsiniz; bu sırada düzenleme kapanır.
              </li>
              <li class="flex gap-3">
                <span class="grid size-6 shrink-0 place-items-center rounded-pill bg-bg-muted text-xs">2</span>
                Ekibimiz görselleri, ölçüleri ve açıklamayı kontrol eder.
              </li>
              <li class="flex gap-3">
                <span class="grid size-6 shrink-0 place-items-center rounded-pill bg-bg-muted text-xs">3</span>
                Onaylanan ürün katalogda yayınlanır ve tasarım önerilerinde yer alır.
              </li>
            </ol>
          </section>
        </aside>
      </div>
    </template>
  </div>
</template>
