<script setup lang="ts">
import type { Product, ProductSkuItem } from '@refconcept/ui/types'

/**
 * Sales options — the commercial half of a listing.
 *
 * Prices are typed as ordinary Turkish amounts and converted to integer minor units
 * at exactly one point, on submit. Nothing in this component ever holds a price as a
 * float: the input is a string until `inputToMinor` turns it into the integer the API
 * takes, and the figures shown back come from the server's own formatting.
 *
 * Width and depth are asked for prominently because they are what decide whether the
 * AI can place the piece against a wall. Height is optional — a rug has none worth
 * stating — which is exactly what the completeness rules say.
 */
const props = defineProps<{
  productId: string
  skus: ProductSkuItem[]
  disabled?: boolean
}>()

const emit = defineEmits<{ updated: [Product] }>()

const api = useApi()

interface SkuForm {
  sku: string
  variant_label: string
  list_price: string
  sale_price: string
  tax_rate_bps: number
  stock_policy: 'track' | 'always_available' | 'made_to_order'
  stock_quantity: number | string
  lead_time_days: number | string
  width_mm: number | string
  height_mm: number | string
  depth_mm: number | string
  weight_g: number | string
  assembly_required: boolean
}

const editingId = ref<string | null>(null)
const showForm = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const formError = ref<string | null>(null)

const stockPolicies = [
  { value: 'track', label: 'Stok takip et' },
  { value: 'always_available', label: 'Her zaman satışta' },
  { value: 'made_to_order', label: 'Siparişe özel üretim' },
] as const

function blankForm(): SkuForm {
  return {
    sku: '',
    variant_label: '',
    list_price: '',
    sale_price: '',
    tax_rate_bps: 2000,
    stock_policy: 'track',
    stock_quantity: 0,
    lead_time_days: 3,
    width_mm: '',
    height_mm: '',
    depth_mm: '',
    weight_g: '',
    assembly_required: false,
  }
}

const form = reactive<SkuForm>(blankForm())

function reset() {
  Object.assign(form, blankForm())
  errors.value = {}
  formError.value = null
}

function startCreate() {
  reset()
  editingId.value = null
  showForm.value = true
}

function startEdit(sku: ProductSkuItem) {
  reset()
  editingId.value = sku.id
  showForm.value = true

  Object.assign(form, {
    sku: sku.sku,
    variant_label: sku.variant_label ?? '',
    list_price: minorToInput(sku.list_price.amount_minor),
    sale_price: sku.sale_price ? minorToInput(sku.sale_price.amount_minor) : '',
    tax_rate_bps: sku.tax_rate_bps,
    stock_policy: sku.stock_policy,
    stock_quantity: sku.stock_quantity ?? 0,
    lead_time_days: sku.lead_time_days,
    width_mm: sku.dimensions?.width_mm ?? '',
    height_mm: sku.dimensions?.height_mm ?? '',
    depth_mm: sku.dimensions?.depth_mm ?? '',
    weight_g: sku.dimensions?.weight_g ?? '',
    assembly_required: sku.dimensions?.assembly_required ?? false,
  })
}

function cancel() {
  showForm.value = false
  editingId.value = null
  reset()
}

/** Optional integers are dropped rather than sent as empty strings. */
function optionalInt(value: number | string): number | undefined {
  if (value === '' || value === null) return undefined

  const parsed = Number(value)

  return Number.isFinite(parsed) ? parsed : undefined
}

async function submit() {
  errors.value = {}
  formError.value = null

  const listMinor = inputToMinor(form.list_price)

  if (listMinor === null) {
    errors.value = { list_price_minor: ['Geçerli bir liste fiyatı girin.'] }

    return
  }

  const saleMinor = form.sale_price.trim() === '' ? null : inputToMinor(form.sale_price)

  if (form.sale_price.trim() !== '' && saleMinor === null) {
    errors.value = { sale_price_minor: ['Geçerli bir indirimli fiyat girin.'] }

    return
  }

  const dimensions: Record<string, unknown> = {
    width_mm: optionalInt(form.width_mm) ?? null,
    height_mm: optionalInt(form.height_mm) ?? null,
    depth_mm: optionalInt(form.depth_mm) ?? null,
    weight_g: optionalInt(form.weight_g) ?? null,
    assembly_required: form.assembly_required,
  }

  const payload: Record<string, unknown> = {
    sku: form.sku,
    variant_label: form.variant_label || null,
    list_price_minor: listMinor,
    sale_price_minor: saleMinor,
    tax_rate_bps: form.tax_rate_bps,
    stock_policy: form.stock_policy,
    stock_quantity: optionalInt(form.stock_quantity) ?? 0,
    lead_time_days: optionalInt(form.lead_time_days) ?? 3,
    dimensions,
  }

  saving.value = true

  try {
    const response = editingId.value
      ? await api.patch<{ data: Product }>(
          `/api/v1/seller/products/${props.productId}/skus/${editingId.value}`,
          payload,
        )
      : await api.post<{ data: Product }>(
          `/api/v1/seller/products/${props.productId}/skus`,
          payload,
        )

    emit('updated', response.data)
    cancel()
  } catch (error) {
    if (error instanceof ApiError && error.isValidation) {
      errors.value = error.errors
    } else {
      formError.value = error instanceof ApiError ? error.message : 'Satış seçeneği kaydedilemedi.'
    }
  } finally {
    saving.value = false
  }
}

async function remove(sku: ProductSkuItem) {
  saving.value = true
  formError.value = null

  try {
    await api.delete(`/api/v1/seller/products/${props.productId}/skus/${sku.id}`)

    const refreshed = await api.get<{ data: Product }>(`/api/v1/seller/products/${props.productId}`)

    emit('updated', refreshed.data)
  } catch (error) {
    formError.value = error instanceof ApiError ? error.message : 'Satış seçeneği kaldırılamadı.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="rc-card p-6 sm:p-8">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h2 class="text-lg font-medium">Satış seçenekleri</h2>
        <p class="mt-1.5 max-w-[60ch] text-sm leading-relaxed text-ink-secondary">
          Her renk, ölçü veya kumaş için ayrı bir seçenek ekleyin. Fiyatlar KDV hariç
          girilir; vergi oranı seçenek başına saklanır.
        </p>
      </div>

      <RcButton v-if="!showForm && !disabled" size="sm" variant="secondary" @click="startCreate">
        Seçenek ekle
      </RcButton>
    </header>

    <RcAlert v-if="formError" tone="danger" class="mt-5">{{ formError }}</RcAlert>

    <div v-if="skus.length > 0" class="mt-6 overflow-x-auto">
      <table class="w-full min-w-[640px] text-sm">
        <thead class="border-b border-line text-left">
          <tr>
            <th class="py-2.5 pr-4 font-medium">SKU</th>
            <th class="py-2.5 pr-4 font-medium">Fiyat</th>
            <th class="py-2.5 pr-4 font-medium">Stok</th>
            <th class="py-2.5 pr-4 font-medium">Ölçü</th>
            <th class="py-2.5" />
          </tr>
        </thead>

        <tbody>
          <tr v-for="sku in skus" :key="sku.id" class="border-b border-line last:border-0">
            <td class="py-3.5 pr-4">
              <p class="font-medium">{{ sku.sku }}</p>
              <p v-if="sku.variant_label" class="text-xs text-muted">{{ sku.variant_label }}</p>
            </td>

            <td class="py-3.5 pr-4 tabular-nums">
              <p>{{ sku.effective_price.formatted }}</p>
              <p v-if="sku.discount_bps > 0" class="text-xs text-muted">
                <s>{{ sku.list_price.formatted }}</s>
                · %{{ bpsToPercent(sku.discount_bps) }} indirim
              </p>
              <p class="text-xs text-muted">KDV %{{ bpsToPercent(sku.tax_rate_bps) }}</p>
            </td>

            <td class="py-3.5 pr-4">
              <RcStatusPill :status="sku.status" :label="sku.status_label" size="sm" />
              <p class="mt-1 text-xs text-muted">
                {{ sku.stock_quantity === null ? 'Takip edilmiyor' : `${sku.stock_quantity} adet` }}
              </p>
            </td>

            <td class="py-3.5 pr-4 text-xs text-ink-secondary">
              {{ sku.dimensions?.display ?? 'Girilmedi' }}
            </td>

            <td class="py-3.5 text-right whitespace-nowrap">
              <button
                type="button"
                class="rounded-sm px-2.5 py-1.5 text-xs text-ink-secondary hover:bg-bg-muted disabled:opacity-40"
                :disabled="disabled || saving"
                @click="startEdit(sku)"
              >
                Düzenle
              </button>
              <button
                type="button"
                class="rounded-sm px-2.5 py-1.5 text-xs text-danger hover:bg-danger-subtle disabled:opacity-40"
                :disabled="disabled || saving"
                @click="remove(sku)"
              >
                Kaldır
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p v-else-if="!showForm" class="mt-6 rounded-md bg-bg-muted p-5 text-sm text-ink-secondary">
      Henüz satış seçeneği eklenmedi. İncelemeye göndermek için en az bir seçenek ve
      fiyat gerekir.
    </p>

    <form v-if="showForm" class="mt-6 space-y-5 rounded-md bg-bg-muted p-5" @submit.prevent="submit">
      <div class="grid gap-4 sm:grid-cols-2">
        <RcField
          v-model="form.sku"
          label="SKU kodu"
          name="sku"
          placeholder="ATL-KNP-001"
          hint="Harf, rakam, nokta, tire ve alt çizgi."
          :errors="errors.sku"
          required
        />
        <RcField
          v-model="form.variant_label"
          label="Seçenek adı"
          name="variant_label"
          placeholder="Ekru bouclé · 220 cm"
          :errors="errors.variant_label"
        />
      </div>

      <div class="grid gap-4 sm:grid-cols-3">
        <RcField
          v-model="form.list_price"
          label="Liste fiyatı (₺)"
          name="list_price"
          placeholder="48.900,00"
          :errors="errors.list_price_minor"
          required
        />
        <RcField
          v-model="form.sale_price"
          label="İndirimli fiyat (₺)"
          name="sale_price"
          placeholder="Boş bırakılabilir"
          :errors="errors.sale_price_minor"
        />
        <div>
          <label for="tax_rate_bps" class="mb-1.5 block text-sm font-medium">KDV oranı</label>
          <select
            id="tax_rate_bps"
            v-model.number="form.tax_rate_bps"
            class="w-full rounded-sm border border-line bg-surface px-4 py-2.5 text-sm"
          >
            <option :value="0">%0</option>
            <option :value="100">%1</option>
            <option :value="1000">%10</option>
            <option :value="2000">%20</option>
          </select>
        </div>
      </div>

      <div class="grid gap-4 sm:grid-cols-3">
        <div>
          <label for="stock_policy" class="mb-1.5 block text-sm font-medium">Stok yönetimi</label>
          <select
            id="stock_policy"
            v-model="form.stock_policy"
            class="w-full rounded-sm border border-line bg-surface px-4 py-2.5 text-sm"
          >
            <option v-for="policy in stockPolicies" :key="policy.value" :value="policy.value">
              {{ policy.label }}
            </option>
          </select>
        </div>

        <RcField
          v-if="form.stock_policy === 'track'"
          v-model="form.stock_quantity"
          label="Stok adedi"
          name="stock_quantity"
          type="number"
          :errors="errors.stock_quantity"
          required
        />

        <RcField
          v-model="form.lead_time_days"
          label="Hazırlık süresi (gün)"
          name="lead_time_days"
          type="number"
          :errors="errors.lead_time_days"
        />
      </div>

      <fieldset class="space-y-4">
        <legend class="text-sm font-medium">Ölçüler</legend>
        <p class="max-w-[60ch] text-xs leading-relaxed text-muted">
          Genişlik ve derinlik zorunludur: tasarım motoru ürünün odaya sığıp
          sığmadığına bu iki ölçüyle karar verir. Yükseklik isteğe bağlıdır.
        </p>

        <div class="grid gap-4 sm:grid-cols-4">
          <RcField
            v-model="form.width_mm"
            label="Genişlik (mm)"
            name="width_mm"
            type="number"
            :errors="errors['dimensions.width_mm']"
          />
          <RcField
            v-model="form.depth_mm"
            label="Derinlik (mm)"
            name="depth_mm"
            type="number"
            :errors="errors['dimensions.depth_mm']"
          />
          <RcField
            v-model="form.height_mm"
            label="Yükseklik (mm)"
            name="height_mm"
            type="number"
            :errors="errors['dimensions.height_mm']"
          />
          <RcField
            v-model="form.weight_g"
            label="Ağırlık (g)"
            name="weight_g"
            type="number"
            :errors="errors['dimensions.weight_g']"
          />
        </div>

        <RcField
          v-model="form.assembly_required"
          label="Kurulum gerekiyor"
          name="assembly_required"
          type="checkbox"
        />
      </fieldset>

      <div class="flex items-center gap-3">
        <RcButton type="submit" size="sm" :loading="saving" :disabled="saving">
          {{ editingId ? 'Seçeneği güncelle' : 'Seçeneği ekle' }}
        </RcButton>
        <RcButton size="sm" variant="ghost" @click="cancel">Vazgeç</RcButton>
      </div>
    </form>
  </section>
</template>
