<script setup lang="ts">
import type { CatalogCategory, Product, ProductSummaryRef } from '@refconcept/ui/types'

/**
 * Creating a listing.
 *
 * Deliberately short: a name and a category are all the API demands, and everything
 * else — photographs, prices, dimensions — is asked for on the editor where the
 * seller can see the completeness checklist alongside. A twelve-field form here
 * would be abandoned halfway and leave nothing behind; a two-field one leaves a
 * draft the seller can come back to.
 */
definePageMeta({ middleware: 'auth' })
useHead({ title: 'Yeni ürün' })

const api = useApi()

const form = reactive({
  name: '',
  primary_category_id: '',
  brand_id: '',
  style_id: '',
  description: '',
})

const categories = ref<CatalogCategory[]>([])
const brands = ref<ProductSummaryRef[]>([])
const styles = ref<Array<{ id: string, code: string, name: string }>>([])

const errors = ref<Record<string, string[]>>({})
const formError = ref<string | null>(null)
const saving = ref(false)

const [categoryResponse, brandResponse, vocabularyResponse] = await Promise.all([
  api.get<{ data: CatalogCategory[] }>('/api/v1/catalog/categories'),
  api.get<{ data: ProductSummaryRef[] }>('/api/v1/catalog/brands'),
  api.get<{ data: { styles: Array<{ id: string, code: string, name: string }> } }>(
    '/api/v1/catalog/vocabulary',
  ),
])

categories.value = categoryResponse.data
brands.value = brandResponse.data
styles.value = vocabularyResponse.data.styles

/**
 * Only leaf categories may hold a listing, and the tree is flat on the wire, so a
 * category with children is shown as a disabled group header rather than hidden —
 * the seller needs the branch to understand where the leaf sits.
 */
const categoryOptions = computed(() => {
  const parentIds = new Set(categories.value.map(item => item.parent_id).filter(Boolean))

  return categories.value.map(category => ({
    ...category,
    isLeaf: !parentIds.has(category.id),
    // Non-breaking spaces: a browser collapses ordinary leading whitespace inside
    // an <option>, so plain indentation there silently does nothing.
    indent: '   '.repeat(Math.max(0, category.depth)),
  }))
})

async function submit() {
  saving.value = true
  errors.value = {}
  formError.value = null

  try {
    const payload: Record<string, unknown> = {
      name: form.name,
      primary_category_id: form.primary_category_id,
    }

    if (form.brand_id) payload.brand_id = form.brand_id
    if (form.style_id) payload.style_id = form.style_id
    if (form.description) payload.description = form.description

    const response = await api.post<{ data: Product }>('/api/v1/seller/products', payload)

    await navigateTo(`/products/${response.data.id}`)
  } catch (error) {
    if (error instanceof ApiError && error.isValidation) {
      errors.value = error.errors
    } else if (error instanceof ApiError && error.status === 403) {
      formError.value = 'Ürün ekleyebilmek için satıcı hesabınızın onaylanmış olması gerekiyor.'
    } else {
      formError.value = error instanceof ApiError ? error.message : 'Ürün oluşturulamadı.'
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-2xl space-y-6">
    <header>
      <NuxtLink to="/products" class="text-sm text-ink-secondary hover:text-ink">
        ← Ürünlerim
      </NuxtLink>
      <h1 class="mt-3 text-2xl font-medium">Yeni ürün</h1>
      <p class="mt-1.5 text-sm leading-relaxed text-ink-secondary">
        Şimdilik adı ve kategorisi yeterli. Görselleri, fiyatı ve ölçüleri bir sonraki
        adımda ekleyeceksiniz.
      </p>
    </header>

    <RcAlert v-if="formError" tone="danger">{{ formError }}</RcAlert>

    <form class="rc-card space-y-5 p-6 sm:p-8" @submit.prevent="submit">
      <RcField
        v-model="form.name"
        label="Ürün adı"
        name="name"
        placeholder="Örn. Bouclé Üç Kişilik Kanepe"
        :errors="errors.name"
        required
      />

      <div>
        <label for="primary_category_id" class="mb-1.5 block text-sm font-medium">
          Kategori <span class="text-danger">*</span>
        </label>
        <select
          id="primary_category_id"
          v-model="form.primary_category_id"
          name="primary_category_id"
          required
          class="w-full rounded-sm border border-line bg-surface px-4 py-2.5 text-sm"
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
        <p v-if="errors.primary_category_id" class="mt-1.5 text-xs text-danger">
          {{ errors.primary_category_id[0] }}
        </p>
        <p v-else class="mt-1.5 text-xs text-muted">
          Kategori, ürününüzün hangi filtrelerde ve hangi odalarda görüneceğini belirler.
        </p>
      </div>

      <div>
        <label for="brand_id" class="mb-1.5 block text-sm font-medium">Marka</label>
        <select
          id="brand_id"
          v-model="form.brand_id"
          name="brand_id"
          class="w-full rounded-sm border border-line bg-surface px-4 py-2.5 text-sm"
        >
          <option value="">Marka belirtmeyeceğim</option>
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
          name="style_id"
          class="w-full rounded-sm border border-line bg-surface px-4 py-2.5 text-sm"
        >
          <option value="">Stil belirtmeyeceğim</option>
          <option v-for="style in styles" :key="style.id" :value="style.id">
            {{ style.name }}
          </option>
        </select>
        <p class="mt-1.5 text-xs text-muted">
          Stil, ürününüzün hangi tasarım önerilerinde eşleşeceğini etkiler.
        </p>
      </div>

      <div>
        <label for="description" class="mb-1.5 block text-sm font-medium">Açıklama</label>
        <textarea
          id="description"
          v-model="form.description"
          name="description"
          rows="5"
          placeholder="Malzemesi, üretim detayları, bakım önerileri…"
          class="w-full rounded-sm border border-line bg-surface px-4 py-3 text-sm leading-relaxed"
        />
        <p v-if="errors.description" class="mt-1.5 text-xs text-danger">
          {{ errors.description[0] }}
        </p>
        <p v-else class="mt-1.5 text-xs text-muted">
          İncelemeye göndermeden önce açıklama zorunlu hâle gelir.
        </p>
      </div>

      <div class="flex items-center gap-3 pt-2">
        <RcButton type="submit" :loading="saving" :disabled="saving">Ürünü oluştur</RcButton>
        <RcButton to="/products" variant="ghost">Vazgeç</RcButton>
      </div>
    </form>
  </div>
</template>
