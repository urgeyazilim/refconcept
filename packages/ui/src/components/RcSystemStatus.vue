<script setup lang="ts">
/**
 * Phase 0 boot verification: proves the storefront can reach the Laravel API
 * through its runtime config base URL. Replaced by real content in Phase 20,
 * but the health contract itself stays.
 */
interface HealthCheck {
  status: 'ok' | 'degraded' | 'down'
  message?: string
}

interface HealthResponse {
  status: 'ok' | 'degraded' | 'down'
  application: string
  environment: string
  version: string
  checks: Record<string, HealthCheck>
}

const config = useRuntimeConfig()

const { data, error, pending, refresh } = await useFetch<HealthResponse>('/api/health', {
  baseURL: config.public.apiBase,
  server: false,
  lazy: true,
  timeout: 5000,
})

const toneClass = computed(() => {
  if (error.value || data.value?.status === 'down') return 'bg-danger-subtle text-danger-strong'
  if (data.value?.status === 'degraded') return 'bg-warning-subtle text-warning-strong'
  if (data.value?.status === 'ok') return 'bg-success-subtle text-success-strong'
  return 'bg-bg-muted text-muted'
})

const label = computed(() => {
  if (pending.value) return 'API kontrol ediliyor…'
  if (error.value) return 'API erişilemiyor'
  if (!data.value) return 'API durumu bilinmiyor'
  return `API ${data.value.status} · ${data.value.environment}`
})
</script>

<template>
  <div class="flex flex-wrap items-center gap-3">
    <span
      class="inline-flex items-center gap-2 rounded-pill px-3.5 py-1.5 text-xs font-medium"
      :class="toneClass"
    >
      <span class="size-1.5 rounded-pill bg-current" aria-hidden="true" />
      {{ label }}
    </span>

    <button
      type="button"
      class="text-xs text-muted underline-offset-4 transition-colors hover:text-ink hover:underline"
      @click="refresh()"
    >
      yenile
    </button>

    <ul v-if="data?.checks" class="flex flex-wrap gap-2">
      <li
        v-for="(check, name) in data.checks"
        :key="name"
        class="rounded-pill border border-line px-2.5 py-1 text-[11px] text-ink-secondary"
        :title="check.message"
      >
        {{ name }}: {{ check.status }}
      </li>
    </ul>
  </div>
</template>
