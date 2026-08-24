<script setup lang="ts">
import type { Product } from '@refconcept/ui/types'

/**
 * One product in the catalogue grid.
 *
 * The price shown is `from_price`, computed server-side across every purchasable
 * offer. Reducing the SKU list here instead would let the grid and the product page
 * disagree about what a sofa costs, which is the kind of difference a customer
 * notices and never forgives.
 */
const props = defineProps<{ product: Product }>()

const cover = computed(() =>
  props.product.media?.find(item => item.is_cover) ?? props.product.media?.[0] ?? null,
)

/** The best discount on offer, so a reduced price is legible at a glance. */
const discountBps = computed(() => {
  const offers = props.product.skus ?? []

  return offers.reduce((best, sku) => Math.max(best, sku.discount_bps), 0)
})

const listPrice = computed(() => {
  const cheapest = (props.product.skus ?? [])
    .filter(sku => sku.is_available)
    .sort((a, b) => a.effective_price.amount_minor - b.effective_price.amount_minor)[0]

  return cheapest && cheapest.discount_bps > 0 ? cheapest.list_price.formatted : null
})
</script>

<template>
  <NuxtLink
    :to="`/catalog/${product.slug}`"
    class="group flex flex-col overflow-hidden rounded-md border border-line bg-surface transition-shadow hover:shadow-md"
  >
    <div class="relative aspect-[4/5] overflow-hidden bg-bg-muted">
      <img
        v-if="cover"
        :src="cover.url"
        :alt="cover.alt_text ?? product.name"
        class="size-full object-cover transition-transform duration-500 group-hover:scale-[1.03]"
        loading="lazy"
      >
      <div v-else class="grid size-full place-items-center text-xs text-muted">
        Görsel yok
      </div>

      <span
        v-if="discountBps > 0"
        class="absolute left-3 top-3 rounded-pill bg-charcoal px-2.5 py-1 text-[11px] text-white"
      >
        %{{ bpsToWholePercent(discountBps) }} indirim
      </span>
    </div>

    <div class="flex flex-1 flex-col p-4">
      <p v-if="product.brand" class="text-[11px] tracking-wide text-muted uppercase">
        {{ product.brand.name }}
      </p>

      <h3 class="mt-1 line-clamp-2 text-sm leading-snug font-medium">{{ product.name }}</h3>

      <p v-if="product.category" class="mt-1 text-xs text-muted">{{ product.category.name }}</p>

      <div class="mt-auto pt-4">
        <p class="text-base font-medium tabular-nums">
          {{ product.from_price?.formatted ?? 'Fiyat için görüşün' }}
        </p>
        <p v-if="listPrice" class="text-xs text-muted tabular-nums">
          <s>{{ listPrice }}</s>
        </p>
      </div>
    </div>
  </NuxtLink>
</template>
