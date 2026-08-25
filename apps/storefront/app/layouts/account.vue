<script setup lang="ts">
const { logout, displayName } = useAuth()

const nav = [
  { label: 'Projelerim', to: '/projects' },
  { label: 'Hesabım', to: '/account' },
  { label: 'Kredilerim', to: '/account/credits' },
  { label: 'Adreslerim', to: '/account/addresses' },
]
</script>

<template>
  <NuxtLayout name="default">
    <div class="rc-container py-10 lg:py-14">
      <div class="grid gap-8 lg:grid-cols-[220px_1fr] lg:gap-12">
        <aside>
          <p class="mb-4 text-xs tracking-wide text-muted uppercase">{{ displayName }}</p>

          <nav class="flex gap-1 overflow-x-auto lg:flex-col lg:overflow-visible">
            <NuxtLink
              v-for="item in nav"
              :key="item.to"
              :to="item.to"
              class="shrink-0 rounded-sm px-3 py-2 text-sm text-ink-secondary transition-colors hover:bg-bg-muted hover:text-ink"
              active-class="bg-bg-muted font-medium text-ink"
            >
              {{ item.label }}
            </NuxtLink>

            <button
              type="button"
              class="shrink-0 rounded-sm px-3 py-2 text-left text-sm text-muted transition-colors hover:bg-bg-muted hover:text-ink lg:mt-4 lg:border-t lg:border-line lg:pt-4"
              @click="logout"
            >
              Çıkış yap
            </button>
          </nav>
        </aside>

        <main class="min-w-0">
          <slot />
        </main>
      </div>
    </div>
  </NuxtLayout>
</template>
