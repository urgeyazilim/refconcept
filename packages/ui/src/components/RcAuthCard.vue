<script setup lang="ts">
/**
 * The shell every authentication screen sits in: a single centred card on a warm
 * neutral ground, generous whitespace, no distractions.
 */
defineProps<{
  title: string
  subtitle?: string
}>()
</script>

<template>
  <div class="flex min-h-[calc(100vh-4.5rem)] items-center justify-center bg-bg-muted px-5 py-16">
    <div class="w-full max-w-[440px]">
      <div class="rc-card p-8 sm:p-10">
        <header class="mb-8">
          <h1 class="text-2xl font-medium tracking-tight">{{ title }}</h1>
          <p v-if="subtitle" class="mt-2 text-sm leading-relaxed text-ink-secondary">
            {{ subtitle }}
          </p>
        </header>

        <slot />
      </div>

      <div v-if="$slots.footer" class="mt-6 text-center text-sm text-ink-secondary">
        <slot name="footer" />
      </div>
    </div>
  </div>
</template>
