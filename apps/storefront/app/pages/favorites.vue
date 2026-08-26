<script setup lang="ts">
import type { Paginated, Product } from '@refconcept/ui/types'

/**
 * The shortlist.
 *
 * Products the customer wanted to remember. Anything since withdrawn is simply not here —
 * the favourite survives so re-listing brings it back, but a shortlist somebody is
 * shopping from is a worse shortlist for containing things nobody can buy.
 */
definePageMeta({ middleware: 'auth' })
useSeo({ title: 'Favorilerim', noindex: true })

const api = useApi()

const products = ref<Product[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)
const busy = ref<string | null>(null)

async function load() {
  loading.value = true

  try {
    const response = await api.get<Paginated<Product>>('/api/v1/favorites')
    products.value = response.data
  } catch (error) {
    loadError.value = error instanceof ApiError ? error.message : 'Favoriler yüklenemedi.'
  } finally {
    loading.value = false
  }
}

await load()

async function remove(product: Product) {
  busy.value = product.id

  try {
    await api.delete(`/api/v1/favorites/${product.id}`)
    products.value = products.value.filter(item => item.id !== product.id)
  } finally {
    busy.value = null
  }
}

function coverOf(product: Product): string | null {
  return product.media?.find(item => item.is_cover)?.url ?? product.media?.[0]?.url ?? null
}
</script>

<template>
  <div class="rc-container py-10 lg:py-14">
    <h1 class="text-2xl font-medium">Favorilerim</h1>

    <RcAlert v-if="loadError" tone="danger" class="mt-6">{{ loadError }}</RcAlert>

    <p v-else-if="loading" class="mt-6 text-sm text-muted">Yükleniyor…</p>

    <p v-else-if="products.length === 0" class="mt-6 text-sm text-muted">
      Henüz favoriniz yok. <NuxtLink to="/catalog" class="underline">Ürünlere göz atın</NuxtLink>.
    </p>

    <ul v-else class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
      <li v-for="product in products" :key="product.id" class="rc-card overflow-hidden">
        <NuxtLink :to="`/catalog/${product.slug}`" class="block">
          <div class="aspect-[4/3] bg-bg-muted">
            <img
              v-if="coverOf(product)"
              :src="coverOf(product)!"
              :alt="product.name"
              class="size-full object-cover"
              loading="lazy"
            >
          </div>
        </NuxtLink>

        <div class="p-4">
          <NuxtLink :to="`/catalog/${product.slug}`" class="line-clamp-2 text-sm hover:underline">
            {{ product.name }}
          </NuxtLink>

          <p v-if="product.from_price" class="mt-1.5 text-sm font-medium tabular-nums">
            {{ product.from_price.formatted }}
          </p>

          <button
            type="button"
            class="mt-3 text-xs text-muted underline hover:text-ink disabled:opacity-40"
            :disabled="busy === product.id"
            @click="remove(product)"
          >
            Favorilerden çıkar
          </button>
        </div>
      </li>
    </ul>
  </div>
</template>
