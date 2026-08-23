<script setup lang="ts">
const nav = [
  { label: 'Platform', to: '/' },
  { label: 'Nasıl çalışır', to: '/' },
  { label: 'Ürünler', to: '/' },
  { label: 'Profesyoneller', to: '/' },
  { label: 'Krediler', to: '/' },
]
</script>

<template>
  <div class="min-h-screen bg-bg text-ink flex flex-col">
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

        <div class="flex items-center gap-3">
          <NuxtLink to="/" class="hidden text-sm text-ink-secondary hover:text-ink sm:block">
            Giriş yap
          </NuxtLink>
          <NuxtLink
            to="/"
            class="rounded-pill bg-charcoal px-5 py-2.5 text-sm font-medium text-inverse transition-colors hover:bg-neutral-800"
          >
            Başla
          </NuxtLink>
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
