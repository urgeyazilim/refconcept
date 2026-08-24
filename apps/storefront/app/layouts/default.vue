<script setup lang="ts">
const { isAuthenticated, displayName, logout } = useAuth()

const nav = [
  { label: 'Platform', to: '/' },
  { label: 'Nasıl çalışır', to: '/' },
  { label: 'Ürünler', to: '/' },
  { label: 'Profesyoneller', to: '/' },
  { label: 'Krediler', to: '/' },
]

const menuOpen = ref(false)

// Close the account menu on navigation; a menu left hanging over the next page
// reads as a stuck overlay.
const route = useRoute()
watch(() => route.fullPath, () => {
  menuOpen.value = false
})
</script>

<template>
  <div class="flex min-h-screen flex-col bg-bg text-ink">
    <header class="sticky top-0 z-50 border-b border-line/70 bg-bg/85 backdrop-blur">
      <div class="rc-container flex h-18 items-center justify-between gap-8 py-4">
        <NuxtLink to="/" class="flex items-center gap-2.5">
          <span class="grid size-9 place-items-center rounded-md bg-charcoal text-inverse">
            <svg class="rc-icon size-5" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1z" />
            </svg>
          </span>
          <span class="text-lg font-medium tracking-tight">RefConcept</span>
        </NuxtLink>

        <nav class="hidden items-center gap-7 lg:flex">
          <NuxtLink
            v-for="item in nav"
            :key="item.label"
            :to="item.to"
            class="text-sm text-ink-secondary transition-colors hover:text-ink"
          >
            {{ item.label }}
          </NuxtLink>
        </nav>

        <div v-if="isAuthenticated" class="relative flex items-center gap-3">
          <button
            type="button"
            class="flex items-center gap-2.5 rounded-pill border border-line px-3 py-1.5 text-sm transition-colors hover:bg-bg-muted"
            :aria-expanded="menuOpen"
            aria-haspopup="menu"
            @click="menuOpen = !menuOpen"
          >
            <span class="grid size-6 place-items-center rounded-pill bg-accent-100 text-[11px] font-medium text-accent-800">
              {{ (displayName || '?').charAt(0).toUpperCase() }}
            </span>
            <span class="hidden max-w-[140px] truncate sm:block">{{ displayName }}</span>
          </button>

          <div
            v-if="menuOpen"
            class="rc-card absolute top-full right-0 mt-2 w-52 overflow-hidden p-1.5 shadow-md"
            role="menu"
          >
            <NuxtLink
              to="/account"
              class="block rounded-sm px-3 py-2 text-sm text-ink-secondary hover:bg-bg-muted hover:text-ink"
              role="menuitem"
            >
              Hesabım
            </NuxtLink>
            <NuxtLink
              to="/account/addresses"
              class="block rounded-sm px-3 py-2 text-sm text-ink-secondary hover:bg-bg-muted hover:text-ink"
              role="menuitem"
            >
              Adreslerim
            </NuxtLink>
            <button
              type="button"
              class="mt-1 block w-full rounded-sm border-t border-line px-3 pt-2.5 pb-2 text-left text-sm text-muted hover:bg-bg-muted hover:text-ink"
              role="menuitem"
              @click="logout"
            >
              Çıkış yap
            </button>
          </div>
        </div>

        <div v-else class="flex items-center gap-3">
          <NuxtLink
            to="/auth/login"
            class="hidden text-sm text-ink-secondary hover:text-ink sm:block"
          >
            Giriş yap
          </NuxtLink>
          <RcButton to="/auth/register" size="md">Başla</RcButton>
        </div>
      </div>
    </header>

    <main class="flex-1">
      <slot />
    </main>

    <footer class="border-t border-line bg-bg-muted">
      <div class="rc-container flex flex-col gap-4 py-10 text-sm text-muted sm:flex-row sm:items-center sm:justify-between">
        <p>© {{ new Date().getFullYear() }} RefConcept</p>
        <p class="text-xs">Yapay zekâ destekli iç mekân tasarımı ve pazar yeri</p>
      </div>
    </footer>
  </div>
</template>
