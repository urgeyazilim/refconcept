<script setup lang="ts">
/**
 * A labelled form control with validation state.
 *
 * Errors arrive from the API as `{ field: string[] }`, so the component accepts an
 * array directly and shows the first message — the shape Laravel already returns,
 * with no mapping layer for each form to get wrong.
 */
const props = withDefaults(
  defineProps<{
    label: string
    name: string
    type?: string
    modelValue?: string | number | boolean | null
    placeholder?: string
    hint?: string
    errors?: string[]
    required?: boolean
    autocomplete?: string
    disabled?: boolean
  }>(),
  {
    type: 'text',
    modelValue: '',
    placeholder: undefined,
    hint: undefined,
    errors: () => [],
    required: false,
    autocomplete: undefined,
    disabled: false,
  },
)

const emit = defineEmits<{ 'update:modelValue': [string | number | boolean] }>()

const error = computed(() => props.errors?.[0])
const describedBy = computed(() => {
  if (error.value) return `${props.name}-error`
  return props.hint ? `${props.name}-hint` : undefined
})

function onInput(event: Event) {
  const target = event.target as HTMLInputElement

  if (props.type === 'checkbox') {
    emit('update:modelValue', target.checked)

    return
  }

  // A number input must emit a number, or arithmetic downstream silently becomes
  // string concatenation — and an empty field must stay empty rather than become 0.
  if (props.type === 'number') {
    emit('update:modelValue', target.value === '' ? '' : Number(target.value))

    return
  }

  emit('update:modelValue', target.value)
}
</script>

<template>
  <div v-if="type === 'checkbox'" class="flex gap-3">
    <input
      :id="name"
      :name="name"
      type="checkbox"
      :checked="Boolean(modelValue)"
      :disabled="disabled"
      :aria-describedby="describedBy"
      :aria-invalid="Boolean(error)"
      class="mt-0.5 size-4 shrink-0 rounded-sm border-line-strong accent-charcoal"
      @change="onInput"
    >
    <div class="min-w-0">
      <label :for="name" class="block text-sm leading-relaxed text-ink-secondary">
        <slot name="label">{{ label }}</slot>
        <span v-if="required" class="text-danger" aria-hidden="true"> *</span>
      </label>
      <p v-if="error" :id="`${name}-error`" class="mt-1 text-xs text-danger-strong">{{ error }}</p>
    </div>
  </div>

  <div v-else>
    <label :for="name" class="mb-1.5 block text-sm font-medium text-ink">
      {{ label }}
      <span v-if="required" class="text-danger" aria-hidden="true">*</span>
    </label>

    <input
      :id="name"
      :name="name"
      :type="type"
      :value="modelValue"
      :placeholder="placeholder"
      :autocomplete="autocomplete"
      :disabled="disabled"
      :aria-describedby="describedBy"
      :aria-invalid="Boolean(error)"
      class="w-full rounded-sm border bg-surface px-4 py-3 text-sm text-ink transition-colors placeholder:text-muted disabled:cursor-not-allowed disabled:bg-bg-muted"
      :class="error ? 'border-danger' : 'border-line hover:border-line-strong'"
      @input="onInput"
    >

    <p v-if="error" :id="`${name}-error`" class="mt-1.5 text-xs text-danger-strong">{{ error }}</p>
    <p v-else-if="hint" :id="`${name}-hint`" class="mt-1.5 text-xs text-muted">{{ hint }}</p>
  </div>
</template>
