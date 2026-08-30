<script setup lang="ts">
/**
 * The design brief, asked in pictures.
 *
 * What this replaces was one blank textarea labelled "İstekleriniz". Almost nobody filled
 * it in — not for want of taste, but because "describe your living room" is a
 * professional's question asked of somebody who has never had to answer one. People wrote
 * "güzel olsun", or nothing, and the engine downstream guessed. The model, given nothing,
 * invented a programme: a television unit, floor-length curtains, a framed picture and four
 * cushions, against a catalogue that stocks none of them.
 *
 * So: choose, do not write. Style from a row of names, palette from swatches, and one
 * tapped answer per question about the room — each with a drawing, because people choose
 * furniture by looking at it and a wall of words on tiles is the textarea again in smaller
 * boxes.
 *
 * Two things make it honest rather than merely pretty.
 *
 * **It only offers what the shop can sell.** Every option is checked against the catalogue
 * before it is drawn. Something nobody stocks is shown disabled with the reason, rather
 * than offered and then quietly missing from the shopping list an hour later.
 *
 * **It can be pressed through.** Every required question has a default or a way out, so
 * somebody who wants to see *something* can tap "İleri" five times and get a design. The
 * free-text box survives as an optional last step for the customer who does have a specific
 * thing to say — which is a minority, and always was.
 */
import type { ProgrammeOption, ProgrammeQuestion, RoomProgramme } from '@refconcept/ui/types'

const props = defineProps<{
  projectId: string
  roomId: string
  /** The project's budget in minor units, offered as the starting point. */
  budgetMinor: number | null
}>()

const emit = defineEmits<{
  (event: 'cancel'): void
  (event: 'submit', brief: {
    programme_id: string
    style_code: string | null
    palette_code: string | null
    budget_minor: number | null
    answers: Record<string, string[]>
    note: string | null
  }): void
}>()

const api = useApi()

interface Style { id: string, code: string, name: string, description: string | null }
interface Palette { code: string, name: string, description: string | null, swatches: Array<{ code: string, hex: string | null }> }

const styles = ref<Style[]>([])
const palettes = ref<Palette[]>([])
const programme = ref<RoomProgramme | null>(null)

const styleCode = ref<string | null>(null)
const paletteCode = ref<string | null>(null)
const answers = ref<Record<string, string[]>>({})
const note = ref('')
const budget = ref<string>(props.budgetMinor === null ? '' : String(Math.round(props.budgetMinor / 100)))

const loading = ref(true)
const loadError = ref<string | null>(null)

/*
 * Style and palette are asked once for the whole design, before the room questions, because
 * the answers to those questions depend on it: whether the shop can supply a sofa "in this
 * style" is only answerable once a style exists.
 */
type Step = 'style' | 'palette' | 'questions' | 'budget'

const step = ref<Step>('style')
const questionIndex = ref(0)

const currentQuestion = computed<ProgrammeQuestion | null>(
  () => programme.value?.questions[questionIndex.value] ?? null,
)

/** Every step, plus one per question, so the bar moves at a believable rate. */
const totalSteps = computed(() => 3 + (programme.value?.questions.length ?? 0))

const stepNumber = computed(() => {
  if (step.value === 'style') return 1
  if (step.value === 'palette') return 2
  if (step.value === 'questions') return 3 + questionIndex.value

  return totalSteps.value
})

async function load() {
  loading.value = true

  try {
    const [vocabulary, roomProgramme] = await Promise.all([
      api.get<{ data: { styles: Style[], palettes: Palette[] } }>('/api/v1/catalog/vocabulary'),
      api.get<{ data: RoomProgramme }>(
        `/api/v1/projects/${props.projectId}/rooms/${props.roomId}/programme`,
      ),
    ])

    styles.value = vocabulary.data.styles
    palettes.value = vocabulary.data.palettes
    programme.value = roomProgramme.data

    applyDefaults()
  } catch (error) {
    /*
     * A room type nobody has written questions for yet answers 404, and that is not an
     * error worth a red box — the free-text brief still works and the caller falls back
     * to it. Anything else is worth saying out loud.
     */
    loadError.value = error instanceof ApiError && error.status === 404
      ? null
      : 'Sorular yüklenemedi.'

    if (loadError.value === null) {
      emit('cancel')
    }
  } finally {
    loading.value = false
  }
}

/**
 * Pre-selects whatever the programme marks as the sensible answer.
 *
 * So that pressing through gives a real design rather than an empty one. A wizard that
 * demands eight decisions before showing anything is a wizard people leave.
 */
function applyDefaults() {
  for (const question of programme.value?.questions ?? []) {
    /*
     * The marked default first, and any available option after it.
     *
     * A default whose category is out of stock is no default at all, and on a thin
     * catalogue that is the common case rather than the odd one — the question would open
     * with nothing selected and an "İleri" that refuses. Falling through to whatever can
     * actually be supplied keeps pressing-through working, which is how most people will
     * use this.
     */
    const fallback = question.options.find(option => option.is_default && option.available)
      ?? question.options.find(option => option.available)

    if (fallback) {
      answers.value[question.code] = [fallback.code]
    }
  }
}

/**
 * Re-asks the catalogue once a style is chosen.
 *
 * "Do we stock a sofa" and "do we stock a sofa in the style you just picked" are different
 * questions, and the second is the one worth showing. Answers survive the reload because
 * they are keyed by code, not by position.
 */
async function refreshForStyle() {
  if (!styleCode.value) return

  try {
    const response = await api.get<{ data: RoomProgramme }>(
      `/api/v1/projects/${props.projectId}/rooms/${props.roomId}/programme?style=${styleCode.value}`,
    )

    programme.value = response.data
  } catch {
    // The questions on screen are still perfectly usable; they simply have not been
    // re-marked for the new style. Not worth interrupting somebody mid-brief.
  }
}

function choose(question: ProgrammeQuestion, option: ProgrammeOption) {
  if (!option.available) return

  if (question.kind === 'multi') {
    const chosen = answers.value[question.code] ?? []

    answers.value[question.code] = chosen.includes(option.code)
      ? chosen.filter(code => code !== option.code)
      : [...chosen, option.code]

    return
  }

  answers.value[question.code] = [option.code]
}

function isChosen(question: ProgrammeQuestion, option: ProgrammeOption): boolean {
  return (answers.value[question.code] ?? []).includes(option.code)
}

const canAdvance = computed(() => {
  if (step.value === 'questions' && currentQuestion.value?.is_required) {
    return (answers.value[currentQuestion.value.code] ?? []).length > 0
  }

  return true
})

async function next() {
  if (step.value === 'style') {
    await refreshForStyle()
    step.value = 'palette'

    return
  }

  if (step.value === 'palette') {
    step.value = programme.value && programme.value.questions.length > 0 ? 'questions' : 'budget'

    return
  }

  if (step.value === 'questions') {
    if (questionIndex.value < (programme.value?.questions.length ?? 0) - 1) {
      questionIndex.value += 1
    } else {
      step.value = 'budget'
    }

    return
  }

  submit()
}

function back() {
  if (step.value === 'budget') {
    step.value = programme.value && programme.value.questions.length > 0 ? 'questions' : 'palette'
    questionIndex.value = Math.max(0, (programme.value?.questions.length ?? 1) - 1)

    return
  }

  if (step.value === 'questions') {
    if (questionIndex.value > 0) {
      questionIndex.value -= 1
    } else {
      step.value = 'palette'
    }

    return
  }

  if (step.value === 'palette') {
    step.value = 'style'

    return
  }

  emit('cancel')
}

function submit() {
  if (!programme.value) return

  emit('submit', {
    programme_id: programme.value.id,
    style_code: styleCode.value,
    palette_code: paletteCode.value,
    // Entered in lira and stored in kuruş, like every other amount in the system.
    budget_minor: budget.value.trim() === '' ? null : Math.round(Number(budget.value) * 100),
    answers: answers.value,
    note: note.value.trim() === '' ? null : note.value.trim(),
  })
}

/** What the customer has chosen, in their own words, for the last screen. */
const summary = computed(() => {
  const rows: Array<{ question: string, answer: string }> = []

  for (const question of programme.value?.questions ?? []) {
    const chosen = (answers.value[question.code] ?? [])
      .map(code => question.options.find(option => option.code === code)?.label)
      .filter((label): label is string => Boolean(label))

    if (chosen.length > 0) {
      rows.push({ question: question.prompt, answer: chosen.join(', ') })
    }
  }

  return rows
})

/*
 * Loaded on mount rather than awaited at the top of the script.
 *
 * Top-level await makes this an async component, and an async component rendered inside a
 * `v-if` needs a <Suspense> boundary around it — without one it silently never mounts,
 * which is exactly what happened: the button worked, the request went out, and the page
 * stayed where it was. A loading state is the honest shape for something that fetches.
 */
onMounted(() => { void load() })
</script>

<template>
  <section v-if="!loading && programme" class="rc-card overflow-hidden">
    <!--
      Progress by step rather than by percentage. "3 / 11" is a promise somebody can hold
      you to; a bar at 27% is a shape.
    -->
    <div class="border-b border-line px-6 py-4 sm:px-8">
      <div class="flex items-baseline justify-between gap-4">
        <h2 class="text-lg font-medium">{{ programme.name }} tasarımı</h2>
        <p class="text-sm tabular-nums text-muted">{{ stepNumber }} / {{ totalSteps }}</p>
      </div>

      <div class="mt-3 h-1 w-full overflow-hidden rounded-pill bg-bg-muted">
        <div
          class="h-full rounded-pill bg-charcoal transition-[width] duration-300"
          :style="{ width: `${(stepNumber / totalSteps) * 100}%` }"
        />
      </div>
    </div>

    <div class="p-6 sm:p-8">
      <!-- --- style ---------------------------------------------------------- -->
      <div v-if="step === 'style'">
        <h3 class="text-base font-medium">Hangi tarzı seviyorsunuz?</h3>
        <p class="mt-1.5 text-sm text-ink-secondary">Sonradan değiştirebilirsiniz.</p>

        <ul class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <li v-for="option in styles" :key="option.code">
            <button
              type="button"
              class="w-full rounded-md border p-4 text-left transition-colors"
              :class="styleCode === option.code
                ? 'border-charcoal bg-bg-muted'
                : 'border-line hover:border-charcoal/40'"
              @click="styleCode = option.code"
            >
              <span class="block text-sm font-medium">{{ option.name }}</span>
              <span v-if="option.description" class="mt-1 block text-xs leading-relaxed text-muted">
                {{ option.description }}
              </span>
            </button>
          </li>
        </ul>
      </div>

      <!-- --- palette -------------------------------------------------------- -->
      <div v-else-if="step === 'palette'">
        <h3 class="text-base font-medium">Renkler nasıl olsun?</h3>
        <p class="mt-1.5 text-sm text-ink-secondary">
          Renk adı yerine tonlara bakarak seçin.
        </p>

        <ul class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <li v-for="option in palettes" :key="option.code">
            <button
              type="button"
              class="w-full rounded-md border p-4 text-left transition-colors"
              :class="paletteCode === option.code
                ? 'border-charcoal bg-bg-muted'
                : 'border-line hover:border-charcoal/40'"
              @click="paletteCode = option.code"
            >
              <span class="flex gap-1.5">
                <span
                  v-for="swatch in option.swatches"
                  :key="swatch.code"
                  class="size-6 rounded-sm border border-line/60"
                  :style="{ backgroundColor: swatch.hex ?? 'transparent' }"
                />
              </span>

              <span class="mt-3 block text-sm font-medium">{{ option.name }}</span>
              <span v-if="option.description" class="mt-0.5 block text-xs text-muted">
                {{ option.description }}
              </span>
            </button>
          </li>
        </ul>
      </div>

      <!-- --- one question at a time ------------------------------------------ -->
      <div v-else-if="step === 'questions' && currentQuestion">
        <h3 class="text-base font-medium">{{ currentQuestion.prompt }}</h3>
        <p v-if="currentQuestion.help" class="mt-1.5 text-sm text-ink-secondary">
          {{ currentQuestion.help }}
        </p>

        <ul class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <li v-for="option in currentQuestion.options" :key="option.code">
            <!--
              Disabled rather than hidden when the shop cannot supply it.

              A question whose options quietly disappear looks like a shorter question. One
              that shows what it cannot offer tells the customer something true about the
              catalogue — and tells us which sellers to go and find.
            -->
            <button
              type="button"
              class="flex w-full flex-col items-start gap-3 rounded-md border p-4 text-left transition-colors"
              :class="[
                !option.available
                  ? 'cursor-not-allowed border-line/60 opacity-45'
                  : isChosen(currentQuestion, option)
                    ? 'border-charcoal bg-bg-muted'
                    : 'border-line hover:border-charcoal/40',
              ]"
              :disabled="!option.available"
              :aria-pressed="isChosen(currentQuestion, option)"
              @click="choose(currentQuestion, option)"
            >
              <RcRoomIcon :name="option.icon ?? 'skip'" size="md" class="text-ink-secondary" />

              <span class="block text-sm font-medium">{{ option.label }}</span>

              <span v-if="option.unavailable_reason" class="block text-xs leading-relaxed text-muted">
                {{ option.unavailable_reason }}
              </span>

              <!--
                Stocked, but not in the chosen style. Said rather than withheld: on a thin
                catalogue, hiding these would leave the question looking empty and the shop
                looking broken.
              -->
              <span
                v-else-if="option.available && !option.exact_style && styleCode"
                class="block text-xs leading-relaxed text-warning"
              >
                Seçtiğiniz tarzda değil, benzer ürünler önerilecek.
              </span>
            </button>
          </li>
        </ul>

        <p v-if="currentQuestion.kind === 'multi'" class="mt-4 text-xs text-muted">
          Birden fazla seçebilir veya hiçbirini seçmeden geçebilirsiniz.
        </p>
      </div>

      <!-- --- budget and the optional last word -------------------------------- -->
      <div v-else>
        <h3 class="text-base font-medium">Son olarak</h3>

        <div class="mt-5 max-w-sm">
          <label for="brief-budget" class="mb-1.5 block text-sm font-medium">
            Bu oda için bütçeniz
          </label>
          <div class="flex items-center gap-2">
            <input
              id="brief-budget"
              v-model="budget"
              type="number"
              min="0"
              step="100"
              inputmode="numeric"
              placeholder="Örn. 40000"
              class="w-full rounded-sm border border-line bg-surface px-4 py-2.5 text-sm tabular-nums"
            >
            <span class="text-sm text-muted">₺</span>
          </div>
          <p class="mt-1.5 text-xs text-muted">
            Boş bırakırsanız proje bütçeniz kullanılır.
          </p>
        </div>

        <!--
          The textarea, demoted.

          It is last, optional, and small on purpose. It used to be the only question and
          it is the one most people cannot answer; for the minority who can, it is still
          the fastest way to say something no tile covers.
        -->
        <div class="mt-6 max-w-xl">
          <label for="brief-note" class="mb-1.5 block text-sm font-medium">
            Eklemek istediğiniz bir şey var mı?
          </label>
          <textarea
            id="brief-note"
            v-model="note"
            rows="2"
            placeholder="İsteğe bağlı — örn. kedimiz için tırmalamayan kumaş"
            class="w-full rounded-sm border border-line bg-surface px-4 py-3 text-sm leading-relaxed"
          />
        </div>

        <div v-if="summary.length > 0" class="mt-7 border-t border-line pt-5">
          <h4 class="text-sm font-medium">Seçtikleriniz</h4>
          <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2">
            <div v-for="row in summary" :key="row.question" class="flex justify-between gap-4 text-sm">
              <dt class="text-muted">{{ row.question }}</dt>
              <dd class="text-right">{{ row.answer }}</dd>
            </div>
          </dl>
        </div>
      </div>
    </div>

    <div class="flex items-center justify-between gap-3 border-t border-line px-6 py-4 sm:px-8">
      <RcButton variant="ghost" @click="back">
        {{ step === 'style' ? 'Vazgeç' : 'Geri' }}
      </RcButton>

      <RcButton :disabled="!canAdvance" @click="next">
        {{ step === 'budget' ? 'Tasarımı başlat' : 'İleri' }}
      </RcButton>
    </div>
  </section>

  <RcAlert v-else-if="loadError" tone="danger" class="mt-4">{{ loadError }}</RcAlert>

  <!-- Fetching the questions, and the catalogue's verdict on each of their options. -->
  <section v-else-if="loading" class="rc-card p-6 text-sm text-muted sm:p-8">
    Sorular hazırlanıyor…
  </section>
</template>
