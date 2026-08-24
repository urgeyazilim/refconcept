<script setup lang="ts">
import type { ModerationDecision, Product } from '@refconcept/ui/types'

/**
 * Reviewing one listing.
 *
 * The reviewer needs to see what a customer would see — the photographs at a usable
 * size, the price, the dimensions — because that is what they are deciding about.
 * The decision panel sits alongside rather than at the bottom, so approving does not
 * require scrolling past the evidence.
 *
 * A rejection can name the fields at fault. That is the difference between a seller
 * fixing the listing and a seller resubmitting the same problem: the flagged fields
 * are shown back to them on their own editor.
 */
definePageMeta({ middleware: 'auth' })

const route = useRoute()
const api = useApi()
const productId = route.params.id as string

interface ReviewMeta {
  missing_requirements: string[]
  completion_percent: number
  history: ModerationDecision[]
}

const product = ref<Product | null>(null)
const meta = ref<ReviewMeta>({ missing_requirements: [], completion_percent: 0, history: [] })
const loadError = ref<string | null>(null)

const reason = ref('')
const flaggedFields = ref<string[]>([])
const working = ref(false)
const actionError = ref<string | null>(null)
const actionMessage = ref<string | null>(null)
const activeImage = ref(0)

/** The fields a rejection can point at, in the order the seller's editor shows them. */
const flaggableFields = [
  { value: 'name', label: 'Ürün adı' },
  { value: 'description', label: 'Açıklama' },
  { value: 'media', label: 'Görseller' },
  { value: 'primary_category_id', label: 'Kategori' },
  { value: 'attributes', label: 'Kategori özellikleri' },
  { value: 'price', label: 'Fiyat' },
  { value: 'dimensions', label: 'Ölçüler' },
]

async function load() {
  try {
    const response = await api.get<{ data: Product, meta: ReviewMeta }>(
      `/api/v1/admin/products/${productId}`,
    )

    product.value = response.data
    meta.value = response.meta
    activeImage.value = 0
  } catch (error) {
    loadError.value = error instanceof ApiError
      ? ({
          404: 'Bu ürün bulunamadı.',
          403: 'Bu ürünü inceleme yetkiniz yok.',
        }[error.status] ?? error.message)
      : 'Ürün yüklenemedi.'
  }
}

await load()

useHead(() => ({ title: product.value?.name ?? 'Ürün incelemesi' }))

const images = computed(() => product.value?.media ?? [])

const canDecide = computed(() =>
  product.value !== null
  && ['pending_review', 'in_review'].includes(product.value.moderation_status),
)

const canRecall = computed(() => product.value?.moderation_status === 'approved')

async function act(
  path: string,
  body: Record<string, unknown> | undefined,
  onSuccess: string,
) {
  working.value = true
  actionError.value = null
  actionMessage.value = null

  try {
    const response = await api.post<{ message: string, data: Product }>(
      `/api/v1/admin/products/${productId}/${path}`,
      body,
    )

    product.value = response.data
    actionMessage.value = response.message || onSuccess
    reason.value = ''
    flaggedFields.value = []

    // Reload for the decision history and the fresh completeness figures, which the
    // action response does not carry.
    await load()
  } catch (error) {
    if (error instanceof ApiError && error.isValidation) {
      actionError.value = Object.values(error.errors).flat().join(' ')
    } else {
      actionError.value = error instanceof ApiError ? error.message : 'İşlem tamamlanamadı.'
    }
  } finally {
    working.value = false
  }
}

const startReview = () => act('review', undefined, 'Ürün incelemeye alındı.')
const approve = () => act('approve', { reason: reason.value }, 'Ürün onaylandı.')
const reject = () => act('reject', { reason: reason.value, flagged_fields: flaggedFields.value }, 'Ürün reddedildi.')
const recall = () => act('recall', { reason: reason.value }, 'Ürün yayından alındı.')

function toggleField(field: string) {
  const index = flaggedFields.value.indexOf(field)

  if (index === -1) flaggedFields.value.push(field)
  else flaggedFields.value.splice(index, 1)
}

/**
 * Decisions are stored as codes; the history reads them back as Turkish.
 *
 * The tone map is keyed on the code rather than the label for the same reason
 * RcStatusPill is: labels are copy and change, codes are data.
 */
const decisions: Record<string, { tone: string, label: string }> = {
  approved: { tone: 'approved', label: 'Onaylandı' },
  rejected: { tone: 'rejected', label: 'Reddedildi' },
  recalled: { tone: 'in_review', label: 'Yayından alındı' },
  in_review: { tone: 'in_review', label: 'İncelemeye alındı' },
}

function decisionOf(decision: string): { tone: string, label: string } {
  return decisions[decision] ?? { tone: 'draft', label: decision }
}
</script>

<template>
  <div class="space-y-6">
    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <template v-else-if="product">
      <header>
        <NuxtLink to="/products" class="text-sm text-ink-secondary hover:text-ink">
          ← Ürün moderasyonu
        </NuxtLink>
        <div class="mt-3 flex flex-wrap items-center gap-3">
          <h1 class="text-2xl font-medium">{{ product.name }}</h1>
          <RcStatusPill
            :status="product.moderation_status"
            :label="product.moderation_status_label"
          />
        </div>
        <p class="mt-1.5 text-sm text-ink-secondary">
          {{ product.skus?.[0]?.seller?.display_name ?? 'Satıcı bilinmiyor' }}
          · {{ product.category?.path ?? 'Kategorisiz' }}
        </p>
      </header>

      <RcAlert v-if="actionMessage" tone="success">{{ actionMessage }}</RcAlert>
      <RcAlert v-if="actionError" tone="danger">{{ actionError }}</RcAlert>

      <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
        <div class="space-y-6">
          <!-- What the customer would see -->
          <section class="rc-card overflow-hidden">
            <div v-if="images.length > 0">
              <div class="aspect-[4/3] bg-bg-muted">
                <img
                  :src="images[activeImage]?.url"
                  :alt="images[activeImage]?.alt_text ?? product.name"
                  class="size-full object-cover"
                >
              </div>

              <div v-if="images.length > 1" class="flex gap-2 overflow-x-auto p-3">
                <button
                  v-for="(image, index) in images"
                  :key="image.id"
                  type="button"
                  class="size-16 shrink-0 overflow-hidden rounded-sm border-2 transition-colors"
                  :class="index === activeImage ? 'border-charcoal' : 'border-transparent'"
                  @click="activeImage = index"
                >
                  <img :src="image.url" :alt="image.alt_text ?? ''" class="size-full object-cover">
                </button>
              </div>
            </div>

            <div v-else class="grid aspect-[4/3] place-items-center bg-danger-subtle text-sm text-danger-strong">
              Bu üründe hiç görsel yok.
            </div>
          </section>

          <section class="rc-card p-6 sm:p-8">
            <h2 class="text-lg font-medium">Açıklama</h2>
            <p v-if="product.description" class="mt-4 leading-relaxed whitespace-pre-line text-ink-secondary">
              {{ product.description }}
            </p>
            <p v-else class="mt-4 text-sm text-danger-strong">Açıklama girilmemiş.</p>

            <dl v-if="product.attributes?.length" class="mt-7 grid gap-x-8 gap-y-3 sm:grid-cols-2">
              <div
                v-for="attribute in product.attributes"
                :key="attribute.code ?? attribute.name ?? ''"
                class="flex justify-between gap-4 border-b border-line pb-2.5 text-sm"
              >
                <dt class="text-muted">{{ attribute.name }}</dt>
                <dd class="text-right">
                  {{ attribute.display }}<span v-if="attribute.unit"> {{ attribute.unit }}</span>
                </dd>
              </div>
            </dl>
          </section>

          <section class="rc-card p-6 sm:p-8">
            <h2 class="text-lg font-medium">Satış seçenekleri</h2>

            <div v-if="product.skus?.length" class="mt-5 overflow-x-auto">
              <table class="w-full min-w-[560px] text-sm">
                <thead class="border-b border-line text-left">
                  <tr>
                    <th class="py-2.5 pr-4 font-medium">SKU</th>
                    <th class="py-2.5 pr-4 font-medium">Fiyat</th>
                    <th class="py-2.5 pr-4 font-medium">KDV</th>
                    <th class="py-2.5 pr-4 font-medium">Stok</th>
                    <th class="py-2.5 font-medium">Ölçü</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="sku in product.skus" :key="sku.id" class="border-b border-line last:border-0">
                    <td class="py-3.5 pr-4">
                      <p class="font-medium">{{ sku.sku }}</p>
                      <p v-if="sku.variant_label" class="text-xs text-muted">{{ sku.variant_label }}</p>
                    </td>
                    <td class="py-3.5 pr-4 tabular-nums">
                      {{ sku.effective_price.formatted }}
                      <span v-if="sku.discount_bps > 0" class="text-xs text-muted">
                        (%{{ bpsToPercent(sku.discount_bps) }} indirim)
                      </span>
                    </td>
                    <td class="py-3.5 pr-4 tabular-nums">%{{ bpsToPercent(sku.tax_rate_bps) }}</td>
                    <td class="py-3.5 pr-4">
                      {{ sku.stock_quantity === null ? 'Takipsiz' : `${sku.stock_quantity} adet` }}
                    </td>
                    <td class="py-3.5 text-xs">{{ sku.dimensions?.display ?? 'Girilmedi' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <p v-else class="mt-4 text-sm text-danger-strong">Satış seçeneği eklenmemiş.</p>
          </section>

          <section v-if="meta.history.length > 0" class="rc-card p-6 sm:p-8">
            <h2 class="text-lg font-medium">Karar geçmişi</h2>
            <ol class="mt-5 space-y-4">
              <li
                v-for="(decision, index) in meta.history"
                :key="index"
                class="border-l-2 border-line pl-4"
              >
                <div class="flex flex-wrap items-center gap-2.5">
                  <RcStatusPill
                    :status="decisionOf(decision.decision).tone"
                    :label="decisionOf(decision.decision).label"
                    size="sm"
                  />
                  <span class="text-xs text-muted">
                    {{ decision.decided_by ?? 'Sistem' }} ·
                    {{ new Date(decision.decided_at).toLocaleString('tr-TR') }}
                  </span>
                </div>
                <p v-if="decision.reason" class="mt-2 text-sm leading-relaxed text-ink-secondary">
                  {{ decision.reason }}
                </p>
                <p v-if="decision.flagged_fields?.length" class="mt-1.5 text-xs text-muted">
                  İşaretlenen alanlar: {{ decision.flagged_fields.join(', ') }}
                </p>
              </li>
            </ol>
          </section>
        </div>

        <!-- Decision panel -->
        <aside class="space-y-6 lg:sticky lg:top-6 lg:self-start">
          <section class="rc-card p-6">
            <h2 class="text-sm font-medium">Tamlık kontrolü</h2>
            <p class="mt-2 text-2xl font-medium tabular-nums">%{{ meta.completion_percent }}</p>

            <ul v-if="meta.missing_requirements.length > 0" class="mt-4 space-y-2">
              <li
                v-for="item in meta.missing_requirements"
                :key="item"
                class="flex items-start gap-2.5 text-sm text-warning-strong"
              >
                <svg class="rc-icon mt-0.5 size-4 shrink-0" viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" />
                </svg>
                {{ item }}
              </li>
            </ul>

            <p v-else class="mt-4 text-sm text-success-strong">
              Zorunlu alanların tamamı dolu.
            </p>
          </section>

          <section v-if="product.moderation_status === 'pending_review'" class="rc-card p-6">
            <h2 class="text-sm font-medium">İncelemeyi başlat</h2>
            <p class="mt-2 text-sm leading-relaxed text-ink-secondary">
              Ürünü üzerinize aldığınızda diğer moderatörler bunu görebilir.
            </p>
            <RcButton class="mt-5" :loading="working" :disabled="working" @click="startReview">
              İncelemeye al
            </RcButton>
          </section>

          <section v-if="canDecide || canRecall" class="rc-card p-6">
            <h2 class="text-sm font-medium">
              {{ canRecall ? 'Yayından al' : 'Karar' }}
            </h2>

            <label for="reason" class="mt-4 mb-1.5 block text-xs font-medium text-ink-secondary">
              Gerekçe <span class="text-danger">*</span>
            </label>
            <textarea
              id="reason"
              v-model="reason"
              rows="4"
              placeholder="En az 10 karakter. Satıcı bu metni görür."
              class="w-full rounded-sm border border-line bg-surface px-3 py-2.5 text-sm leading-relaxed"
            />
            <p class="mt-1.5 text-xs text-muted">
              Gerekçe hem satıcıya gösterilir hem de denetim kaydına yazılır.
            </p>

            <template v-if="canDecide">
              <p class="mt-5 text-xs font-medium text-ink-secondary">
                Sorunlu alanlar (reddederken)
              </p>
              <div class="mt-2.5 flex flex-wrap gap-1.5">
                <button
                  v-for="field in flaggableFields"
                  :key="field.value"
                  type="button"
                  class="rounded-pill border px-3 py-1.5 text-[11px] transition-colors"
                  :class="flaggedFields.includes(field.value)
                    ? 'border-danger bg-danger-subtle text-danger-strong'
                    : 'border-line text-ink-secondary hover:bg-bg-muted'"
                  @click="toggleField(field.value)"
                >
                  {{ field.label }}
                </button>
              </div>

              <div class="mt-6 flex flex-col gap-2.5">
                <RcButton
                  block
                  :loading="working"
                  :disabled="working || reason.trim().length < 10"
                  @click="approve"
                >
                  Onayla ve yayınla
                </RcButton>
                <RcButton
                  block
                  variant="danger"
                  :disabled="working || reason.trim().length < 10"
                  @click="reject"
                >
                  Reddet
                </RcButton>
              </div>
            </template>

            <RcButton
              v-else
              class="mt-6"
              block
              variant="danger"
              :disabled="working || reason.trim().length < 10"
              @click="recall"
            >
              Yayından al ve yeniden incele
            </RcButton>
          </section>
        </aside>
      </div>
    </template>
  </div>
</template>
