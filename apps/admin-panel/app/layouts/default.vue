<script setup lang="ts">
/**
 * Super Admin shell — operational density, same design family.
 * Full navigation is built out in Phase 18; these are the section anchors.
 */
const { isAuthenticated, logout } = useAuth()

const nav = [
  { label: 'Başvurular', to: '/', icon: 'M8 4h8a1 1 0 0 1 1 1v15l-5-3-5 3V5a1 1 0 0 1 1-1Zm2 5h4m-4 4h4' },
  { label: 'Satıcılar', to: '/sellers', icon: 'M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20M10 11.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z' },
]
</script>

<template>
  <div data-rc-theme="operational" class="flex min-h-screen bg-bg text-ink">
    <aside class="hidden w-64 shrink-0 border-r border-line bg-surface lg:block">
      <div class="flex h-16 items-center gap-2.5 border-b border-line px-6">
        <span class="grid size-8 place-items-center rounded-sm bg-charcoal text-inverse text-xs font-medium">
          RC
        </span>
        <div class="leading-tight">
          <p class="text-sm font-medium">RefConcept</p>
          <p class="text-[11px] text-muted">Süper Admin</p>
        </div>
      </div>

      <nav class="flex flex-col gap-0.5 p-3">
        <NuxtLink
          v-for="item in nav"
          :key="item.label"
          :to="item.to"
          class="flex items-center gap-3 rounded-sm px-3 py-2.5 text-sm text-ink-secondary transition-colors hover:bg-bg-muted hover:text-ink"
        >
          <svg class="rc-icon size-[18px]" viewBox="0 0 24 24" aria-hidden="true">
            <path :d="item.icon" />
          </svg>
          {{ item.label }}
        </NuxtLink>
      </nav>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
      <header class="flex h-16 items-center justify-between gap-4 border-b border-line bg-surface px-6">
        <p class="text-sm text-muted">Süper Admin</p>
        <button
          v-if="isAuthenticated"
          type="button"
          class="rounded-sm px-3 py-1.5 text-sm text-ink-secondary transition-colors hover:bg-bg-muted hover:text-ink"
          @click="logout"
        >
          Çıkış yap
        </button>
        <NuxtLink
          v-else
          to="/auth/login"
          class="rounded-sm px-3 py-1.5 text-sm text-ink-secondary hover:bg-bg-muted hover:text-ink"
        >
          Giriş yap
        </NuxtLink>
      </header>

      <main class="flex-1 p-6">
        <slot />
      </main>
    </div>
  </div>
</template>
