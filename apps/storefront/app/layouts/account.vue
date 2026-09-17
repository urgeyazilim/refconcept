<script setup lang="ts">
/**
 * The account pages, under one row of tabs rather than beside a column.
 *
 * The column repeated what the header already carried — "Projelerim" in two places, a metre
 * apart — and it was the last of the side columns the product owner asked to be rid of
 * ("projenin tüm sol tarafındaki saçma şeyleri kaldır"). The same links now sit in one quiet
 * row above the page, which is where a reader looks for a section's own navigation.
 */
const { logout } = useAuth()

const nav = [
  { label: 'Hesabım', to: '/account' },
  { label: 'Siparişlerim', to: '/account/orders' },
  { label: 'İadelerim', to: '/account/returns' },
  { label: 'Kredilerim', to: '/account/credits' },
  { label: 'Adreslerim', to: '/account/addresses' },
]
</script>

<template>
  <NuxtLayout name="default">
    <div class="rc-page rc-page--wide">
      <nav class="mb-6 flex flex-wrap items-center gap-1 border-b border-line pb-3" aria-label="Hesap menüsü">
        <NuxtLink
          v-for="item in nav"
          :key="item.to"
          :to="item.to"
          class="rounded-pill px-3 py-1.5 text-sm text-ink-secondary transition-colors hover:bg-bg-muted hover:text-ink"
          active-class="bg-bg-muted font-medium text-ink"
        >
          {{ item.label }}
        </NuxtLink>

        <button
          type="button"
          class="ml-auto rounded-pill px-3 py-1.5 text-sm text-muted transition-colors hover:bg-bg-muted hover:text-ink"
          @click="logout"
        >
          Çıkış yap
        </button>
      </nav>

      <main class="min-w-0">
        <slot />
      </main>
    </div>
  </NuxtLayout>
</template>
