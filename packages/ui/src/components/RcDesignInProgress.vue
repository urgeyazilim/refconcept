<script setup lang="ts">
/**
 * The minute a customer spends waiting for their room.
 *
 * A render takes the better part of a minute, and what filled it was an empty grey panel
 * and the word "hazırlanıyor". That is a minute of a person wondering whether it has
 * broken, which is the worst thing you can do with the most exciting moment in the whole
 * product — they have just handed over a photograph of their own home and are about to see
 * it changed.
 *
 * So the wait shows the work. The room draws itself as the analysis reads it, furniture
 * arrives as the plan places it, the light comes up as the render runs. It is tied to the
 * real stage rather than to a timer: a customer watching the sofa appear the moment the
 * engine chose one is being told something true, and a loop that animates on its own would
 * be a lie that eventually desynchronises visibly.
 *
 * Between them, the craft. Ten things an interior designer actually knows — the sofa off
 * the wall, the rug under the front legs, the picture at eye level — rotating slowly. They
 * are worth reading on their own, and they are the argument for the price: this is what
 * you are paying somebody to know.
 *
 * Motion is honest about being decoration. Everything animated here is `prefers-reduced-
 * motion`-guarded, and with motion off the same information is still on the screen.
 */
const props = withDefaults(
  defineProps<{
    /** The stage the engine last announced: queued, analysis, plan, match, render, save. */
    stage?: string | null
    /** How far along, in basis points, as the engine reckons it. */
    progressBps?: number
  }>(),
  { stage: null, progressBps: 0 },
)

/**
 * What each stage is really doing, said the way a person would say it.
 *
 * "Oda inceleniyor" is a status; "odanızın ışığını, ölçülerini ve mimarisini okuyoruz" is
 * somebody telling you what they are doing. The second is worth a minute of attention and
 * the first is not.
 */
const narration: Record<string, { title: string, detail: string }> = {
  queued: {
    title: 'Başlıyoruz',
    detail: 'Odanız sıraya alındı; birazdan ilk okumaya geçiyoruz.',
  },
  analysis: {
    title: 'Odanızı okuyoruz',
    detail: 'Işığın nereden geldiğini, pencerelerin ve kapıların yerini, duvarların oranını çıkarıyoruz.',
  },
  plan: {
    title: 'Yerleşimi kuruyoruz',
    detail: 'Odanın odak noktasını belirliyor, oturma bölgesini duvarlardan ayırıp dolaşım yollarını açıyoruz.',
  },
  match: {
    title: 'Ürünleri seçiyoruz',
    detail: 'Seçtiğiniz tarza, bütçenize ve odanızın ölçülerine uyan gerçek ürünleri buluyoruz.',
  },
  render: {
    title: 'Odanızı çiziyoruz',
    detail: 'Seçilen ürünleri kendi odanıza yerleştiriyor, ışığı ve gölgeleri kuruyoruz.',
  },
  save: {
    title: 'Son rötuşlar',
    detail: 'Görseliniz kaydediliyor.',
  },
}

const current = computed(() => narration[props.stage ?? 'queued'] ?? narration.queued!)

/**
 * Which parts of the sketch have been reached.
 *
 * Tied to the stage the engine reports rather than to elapsed time. A sofa that appears
 * because the plan chose one is information; a sofa that appears after eleven seconds is
 * a screensaver, and it drifts out of step the first time a provider is slow.
 */
const order = ['queued', 'analysis', 'plan', 'match', 'render', 'save']
const reached = (stage: string) => order.indexOf(props.stage ?? 'queued') >= order.indexOf(stage)

/**
 * What an interior designer knows, and the customer is paying for.
 *
 * Real craft rather than filler. Somebody who reads three of these while waiting has got
 * something out of the minute even if they never buy anything — and they explain, without
 * saying so, why this is not a furniture collage.
 */
const insights = [
  'Kanepeyi duvardan 30-40 cm ayırın. Duvara dayalı oturma grubu bekleme salonu hissi verir; ayrılan bir grup oda kurar.',
  'Halı, oturma parçalarının en azından ön ayaklarını almalı. Ortada yüzen küçük halı odayı toplamaz, böler.',
  'İyi aydınlatılmış oda üç katmandır: genel, işlevsel ve vurgu. Tek bir tavan armatürü hiçbir odada yetmez.',
  'Tablonun merkezi yerden 145-155 cm — göz hizası. Çoğu tablo olması gerekenden yükseğe asılır.',
  'Perdeyi pencere üstünden değil, tavana yakın asın ve iki yana taşırın. Pencere birden büyür.',
  'Orta sehpa koltuktan 40-45 cm uzakta olmalı: uzanınca yetişeceğiniz, geçerken çarpmayacağınız mesafe.',
  'Dekoratif objeleri tek sayıyla ve farklı yüksekliklerde gruplayın. Üçlü gruplar ikililerden daha doğal durur.',
  'Her duvarı doldurmayın. Bilinçli bırakılmış bir boşluk da tasarım kararıdır.',
  'Her odanın bir odak noktası olmalı: pencere manzarası, şömine ya da televizyon duvarı. Gerisi ona yönelir.',
  'Kapıdan girince ilk göreceğiniz şeyi siz seçin. Genellikle kimse seçmez ve bir dolabın arkası olur.',
]

const insight = ref(0)
const fading = ref(false)

let rotation: ReturnType<typeof setInterval> | undefined

onMounted(() => {
  // Eight seconds: long enough to read a sentence twice, short enough that a render
  // shows three or four of them.
  rotation = setInterval(() => {
    fading.value = true

    setTimeout(() => {
      insight.value = (insight.value + 1) % insights.length
      fading.value = false
    }, 400)
  }, 8_000)
})

onBeforeUnmount(() => {
  if (rotation) clearInterval(rotation)
})

const percent = computed(() => Math.min(100, Math.max(0, Math.round(props.progressBps / 100))))
</script>

<template>
  <div class="rc-progress flex h-full flex-col justify-between bg-bg-muted">
    <!--
      The room, drawing itself.

      One-point perspective, the way an architect sketches: the shell first, then the
      furniture, then the light. Line art rather than a render, because a photorealistic
      preview would set an expectation the real image then has to meet.
    -->
    <div class="flex min-h-0 flex-1 items-center justify-center p-5">
      <svg
        class="w-full max-w-md"
        viewBox="0 0 320 220"
        fill="none"
        stroke="currentColor"
        stroke-width="1.25"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
      >
        <!-- The shell, drawn while the room is read. -->
        <g class="rc-shell text-line-strong">
          <path d="M40 40h240v120H40z" />
          <path d="M40 40 8 16M280 40l32-24M40 160 8 196M280 160l32 24" />
          <path d="M8 16h304M8 196h304" />
          <path d="M196 66h56v58h-56z" />
          <path d="M224 66v58M196 95h56" />
        </g>

        <!-- The layout, appearing as the plan is decided. -->
        <g v-if="reached('plan')" class="rc-plan text-line">
          <ellipse cx="150" cy="150" rx="86" ry="26" stroke-dasharray="3 5" />
        </g>

        <!-- The furniture, once real products have been chosen. -->
        <g v-if="reached('match')" class="text-ink-secondary">
          <g class="rc-piece" style="--rc-delay: 0ms">
            <path d="M74 120h74v22H74zM74 128h74M80 142v8M142 142v8M74 120v-8h74v8" />
          </g>
          <g class="rc-piece" style="--rc-delay: 180ms">
            <path d="M128 152h44v10h-44zM136 162v8M164 162v8" />
          </g>
          <g class="rc-piece" style="--rc-delay: 360ms">
            <path d="M196 132h56v14h-56zM202 146v6M246 146v6" />
          </g>
          <g class="rc-piece" style="--rc-delay: 540ms">
            <path d="M268 148v-22M258 126h20l-4-12h-12z" />
          </g>
        </g>

        <!-- And the light, when the render runs. -->
        <g v-if="reached('render')" class="rc-light">
          <defs>
            <radialGradient id="rc-lamp" cx="50%" cy="0%" r="90%">
              <stop offset="0%" stop-color="currentColor" stop-opacity="0.22" />
              <stop offset="60%" stop-color="currentColor" stop-opacity="0.06" />
              <stop offset="100%" stop-color="currentColor" stop-opacity="0" />
            </radialGradient>
          </defs>

          <path d="M150 40v22" class="text-line-strong" />
          <path d="M132 78a18 18 0 0 1 36 0z" class="text-ink-secondary" />
          <path d="M132 78h36" class="text-ink-secondary" />
          <ellipse
            cx="150"
            cy="120"
            rx="46"
            ry="42"
            class="rc-glow text-accent-500"
            fill="url(#rc-lamp)"
            stroke="none"
          />
        </g>
      </svg>
    </div>

    <div class="shrink-0 border-t border-line/60 bg-surface/60 px-5 py-4 backdrop-blur-sm">
      <div class="flex items-baseline justify-between gap-4">
        <p class="text-sm font-medium">{{ current.title }}</p>
        <p class="text-xs tabular-nums text-muted">%{{ percent }}</p>
      </div>

      <p class="mt-1 line-clamp-2 text-xs leading-relaxed text-ink-secondary">{{ current.detail }}</p>

      <div class="mt-2.5 h-0.5 w-full overflow-hidden rounded-pill bg-line/60">
        <div
          class="h-full rounded-pill bg-charcoal transition-[width] duration-700 ease-out"
          :style="{ width: `${percent}%` }"
        />
      </div>

      <!--
        The craft, between the stages. Worth reading on its own, and the quiet argument
        for what the customer is paying for.
      -->
      <div class="mt-3.5 border-t border-line/60 pt-3">
        <p class="text-[11px] font-medium uppercase tracking-wide text-muted">
          İç mimarlıktan
        </p>
        <p
          class="mt-1 line-clamp-3 text-xs leading-relaxed text-ink-secondary transition-opacity duration-300"
          :class="fading ? 'opacity-0' : 'opacity-100'"
        >
          {{ insights[insight] }}
        </p>
      </div>
    </div>
  </div>
</template>

<style scoped>
/*
 * The shell draws itself once, the way a pen would. `stroke-dasharray` set well above the
 * real path length so one value covers every segment — measuring each in script would be
 * more exact and would also mean the drawing cannot start until layout has settled.
 */
.rc-shell path {
  stroke-dasharray: 900;
  stroke-dashoffset: 900;
  animation: rc-draw 1.6s ease-out forwards;
}

.rc-shell path:nth-child(2) { animation-delay: 0.3s; }
.rc-shell path:nth-child(3) { animation-delay: 0.6s; }
.rc-shell path:nth-child(4) { animation-delay: 0.9s; }
.rc-shell path:nth-child(5) { animation-delay: 1.1s; }

.rc-plan ellipse {
  stroke-dasharray: 3 5;
  animation: rc-fade 0.8s ease-out forwards;
  opacity: 0;
}

/* Each piece arrives on its own beat, so the room fills rather than snapping into place. */
.rc-piece {
  animation: rc-settle 0.7s cubic-bezier(0.22, 1, 0.36, 1) forwards;
  animation-delay: var(--rc-delay, 0ms);
  opacity: 0;
}

.rc-light path { animation: rc-fade 1s ease-out forwards; opacity: 0; }

/* The lamp is the last thing to arrive and the only thing that keeps moving — a slow
   breath rather than a pulse, which reads as warmth rather than as a loading indicator. */
.rc-glow {
  animation: rc-breathe 4s ease-in-out infinite;
  opacity: 0.6;
}

@keyframes rc-draw {
  to { stroke-dashoffset: 0; }
}

@keyframes rc-fade {
  to { opacity: 1; }
}

@keyframes rc-settle {
  from { opacity: 0; transform: translateY(6px); }
  to { opacity: 1; transform: translateY(0); }
}

@keyframes rc-breathe {
  0%, 100% { opacity: 0.55; }
  50% { opacity: 1; }
}

/*
 * With motion off, everything is simply present.
 *
 * Not "less animated" — none. Somebody who has asked their system not to animate things
 * has usually asked for a reason, and a gentler version of the thing that makes them ill
 * is still the thing that makes them ill.
 */
@media (prefers-reduced-motion: reduce) {
  .rc-shell path,
  .rc-plan ellipse,
  .rc-piece,
  .rc-light path,
  .rc-glow {
    animation: none;
    opacity: 1;
    stroke-dashoffset: 0;
    transform: none;
  }

  .rc-glow { opacity: 0.75; }
}
</style>
