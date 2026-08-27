<script setup lang="ts">
/**
 * The AI control room.
 *
 * One screen, because the questions an operator arrives with are one question in three
 * forms: is it working, what is it costing, and can I turn it off. Splitting those
 * across three pages would mean noticing a spike on one and hunting for the switch on
 * another.
 *
 * The task list is built from the API's enumeration of every task, not from the routes
 * that exist — an unrouted task is a feature that will fail the first time somebody
 * touches it, and it is exactly the row a list of routes would omit.
 */
definePageMeta({ middleware: 'auth' })
useHead({ title: 'AI yönetimi' })

interface ModelSummary {
  id: string
  code: string
  name: string
  provider: string | null
  is_usable: boolean
}

interface RouteRow {
  id: string
  task: string
  timeout_seconds: number
  max_attempts: number
  credit_cost: number
  max_cost_micros: number
  max_concurrency: number
  is_active: boolean
  is_paused: boolean
  pause_reason: string | null
  primary_model: ModelSummary | null
  fallback_model: ModelSummary | null
  prompt_version: { id: string, version: number, template: string | null, status: string } | null
}

interface TaskRow {
  task: string
  label: string
  modality: string
  requires_structured_output: boolean
  is_interactive: boolean
  route: RouteRow | null
}

interface ProviderRow {
  id: string
  code: string
  name: string
  driver: string
  is_active: boolean
  credential: { id: string, label: string, hint: string, has_expired: boolean } | null
  models: Array<{
    id: string
    code: string
    name: string
    modality_label: string
    is_usable: boolean
    rate: { currency: string, input_micros_per_million_tokens: number } | null
  }>
}

interface UsageRow {
  task: string
  label: string
  attempts: number
  cost_micros: number
  avg_latency_ms: number
  jobs_succeeded: number
  jobs_failed: number
  success_bps: number | null
}

interface FailureRow {
  kind: string
  label: string
  total: number
  is_retryable: boolean
}

const api = useApi()

const tasks = ref<TaskRow[]>([])
const providers = ref<ProviderRow[]>([])
const usage = ref<UsageRow[]>([])
const failures = ref<FailureRow[]>([])
const totalCostMicros = ref(0)
const windowDays = ref(7)

const loading = ref(true)
const banner = ref<{ tone: 'success' | 'danger' | 'info', text: string } | null>(null)
const acting = ref<string | null>(null)

async function load() {
  loading.value = true

  try {
    const [overview, spend] = await Promise.all([
      api.get<{ data: { tasks: TaskRow[], providers: ProviderRow[] } }>('/api/v1/admin/ai/overview'),
      api.get<{ data: { tasks: UsageRow[], failures: FailureRow[], total_cost_micros: number } }>(
        '/api/v1/admin/ai/usage',
        { days: windowDays.value },
      ),
    ])

    tasks.value = overview.data.tasks
    providers.value = overview.data.providers
    usage.value = spend.data.tasks
    failures.value = spend.data.failures
    totalCostMicros.value = spend.data.total_cost_micros
  } catch (error) {
    banner.value = {
      tone: 'danger',
      text: error instanceof ApiError
        ? (error.status === 403 ? 'Bu alana erişim yetkiniz yok.' : error.message)
        : 'AI yapılandırması yüklenemedi.',
    }
  } finally {
    loading.value = false
  }
}

await load()

watch(windowDays, load)

/**
 * The kill switch.
 *
 * A reason is asked for rather than optional: it is what the next person sees on this
 * screen, and "somebody turned this off in March" with no explanation is how a feature
 * stays off for a month after the incident it was disabled for.
 */
async function pause(row: TaskRow) {
  if (!row.route) return

  const reason = window.prompt(
    `"${row.label}" görevi neden durduruluyor? Bu not bu ekranda görünecek.`,
  )

  if (!reason || reason.trim().length < 8) {
    if (reason !== null) {
      banner.value = { tone: 'info', text: 'Gerekçe en az 8 karakter olmalı.' }
    }

    return
  }

  await act(row.route.id, `/api/v1/admin/ai/routes/${row.route.id}/pause`, { reason }, `${row.label} durduruldu.`)
}

async function resume(row: TaskRow) {
  if (!row.route) return

  await act(row.route.id, `/api/v1/admin/ai/routes/${row.route.id}/resume`, {}, `${row.label} yeniden açıldı.`)
}

async function act(id: string, path: string, body: unknown, success: string) {
  acting.value = id
  banner.value = null

  try {
    await api.post(path, body)
    banner.value = { tone: 'success', text: success }
    await load()
  } catch (error) {
    banner.value = {
      tone: 'danger',
      text: error instanceof ApiError ? error.message : 'İşlem tamamlanamadı.',
    }
  } finally {
    acting.value = null
  }
}

/**
 * Micros are millionths of a lira, which is the only sane way to store a cost of a fraction
 * of a kuruş. Nobody reads them, so they are turned into money here — for display only, and
 * never back into arithmetic.
 *
 * Lira, not dollars. The provider quotes in USD and the gateway converts before it stores,
 * so what arrives here is already the platform's own currency: putting a dollar sign on it
 * would have been wrong by the whole exchange rate, and wrong silently.
 */
function money(micros: number): string {
  return new Intl.NumberFormat('tr-TR', {
    style: 'currency',
    currency: 'TRY',
    // Four places: a single AI call costs a fraction of a kuruş, and rounding to two
    // would show every one of them as ₺0,00.
    minimumFractionDigits: 4,
    maximumFractionDigits: 4,
  }).format(micros / 1_000_000)
}

function successRate(bps: number | null): string {
  return bps === null ? '—' : `%${(bps / 100).toFixed(1)}`
}

const unroutedCount = computed(() => tasks.value.filter(task => task.route === null).length)
const pausedCount = computed(() => tasks.value.filter(task => task.route?.is_paused).length)
const usageByTask = computed(() => new Map(usage.value.map(row => [row.task, row])))
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-medium">AI yönetimi</h1>
      <p class="mt-1.5 max-w-[70ch] text-sm leading-relaxed text-ink-secondary">
        Her görevin hangi modele gittiği, ne kadara mal olduğu ve açık olup olmadığı
        buradan yönetilir. Bir görevi durdurmak, o özelliği tüm müşteriler için kapatır.
      </p>
    </header>

    <div
      v-if="banner"
      class="rounded-sm border px-4 py-3 text-sm"
      :class="{
        'border-line bg-surface text-ink': banner.tone === 'info',
        'border-success/30 bg-success/5 text-ink': banner.tone === 'success',
        'border-danger/30 bg-danger/5 text-ink': banner.tone === 'danger',
      }"
    >
      {{ banner.text }}
    </div>

    <div v-if="loading" class="rounded-sm border border-line bg-surface p-6 text-sm text-muted">
      Yükleniyor…
    </div>

    <template v-else>
      <!-- The three numbers somebody actually arrives wanting. -->
      <section class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-sm border border-line bg-surface p-4">
          <p class="text-[11px] uppercase tracking-wide text-muted">Son {{ windowDays }} günün maliyeti</p>
          <p class="mt-1 text-xl font-medium tabular-nums">{{ money(totalCostMicros) }}</p>
        </div>
        <div class="rounded-sm border border-line bg-surface p-4">
          <p class="text-[11px] uppercase tracking-wide text-muted">Durdurulmuş görev</p>
          <p class="mt-1 text-xl font-medium tabular-nums" :class="pausedCount > 0 ? 'text-danger' : ''">
            {{ pausedCount }}
          </p>
        </div>
        <div class="rounded-sm border border-line bg-surface p-4">
          <p class="text-[11px] uppercase tracking-wide text-muted">Yönlendirilmemiş görev</p>
          <!--
            Highlighted when non-zero: an unrouted task is a feature that fails the first
            time a customer touches it, and it is silent until then.
          -->
          <p class="mt-1 text-xl font-medium tabular-nums" :class="unroutedCount > 0 ? 'text-danger' : ''">
            {{ unroutedCount }}
          </p>
        </div>
      </section>

      <section class="rounded-sm border border-line bg-surface">
        <header class="flex items-center justify-between gap-4 border-b border-line px-5 py-3.5">
          <h2 class="text-sm font-medium">Görev yönlendirmeleri</h2>
          <label class="flex items-center gap-2 text-xs text-muted">
            Dönem
            <select
              v-model.number="windowDays"
              class="rounded-sm border border-line bg-bg px-2 py-1 text-xs text-ink"
            >
              <option :value="1">24 saat</option>
              <option :value="7">7 gün</option>
              <option :value="30">30 gün</option>
            </select>
          </label>
        </header>

        <div class="overflow-x-auto">
          <table class="w-full min-w-[900px] text-sm">
            <thead>
              <tr class="border-b border-line text-left text-[11px] uppercase tracking-wide text-muted">
                <th class="px-5 py-2.5 font-medium">Görev</th>
                <th class="px-5 py-2.5 font-medium">Model</th>
                <th class="px-5 py-2.5 font-medium">İstem</th>
                <th class="px-5 py-2.5 text-right font-medium">Kredi</th>
                <th class="px-5 py-2.5 text-right font-medium">Başarı</th>
                <th class="px-5 py-2.5 text-right font-medium">Ort. süre</th>
                <th class="px-5 py-2.5 text-right font-medium">Maliyet</th>
                <th class="px-5 py-2.5 font-medium">Durum</th>
                <th class="px-5 py-2.5" />
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in tasks"
                :key="row.task"
                class="border-b border-line/60 last:border-b-0"
              >
                <td class="px-5 py-3">
                  <p class="font-medium">{{ row.label }}</p>
                  <p class="text-[11px] text-muted">
                    {{ row.modality }}<span v-if="row.requires_structured_output"> · yapısal çıktı</span>
                  </p>
                </td>

                <td class="px-5 py-3">
                  <template v-if="row.route?.primary_model">
                    <p>{{ row.route.primary_model.code }}</p>
                    <p v-if="row.route.fallback_model" class="text-[11px] text-muted">
                      yedek: {{ row.route.fallback_model.code }}
                    </p>
                  </template>
                  <span v-else class="text-danger">—</span>
                </td>

                <td class="px-5 py-3 text-[11px] text-muted">
                  <span v-if="row.route?.prompt_version">v{{ row.route.prompt_version.version }}</span>
                  <span v-else>—</span>
                </td>

                <td class="px-5 py-3 text-right tabular-nums">{{ row.route?.credit_cost ?? '—' }}</td>

                <td class="px-5 py-3 text-right tabular-nums">
                  {{ successRate(usageByTask.get(row.task)?.success_bps ?? null) }}
                </td>

                <td class="px-5 py-3 text-right tabular-nums text-muted">
                  <span v-if="usageByTask.get(row.task)">{{ usageByTask.get(row.task)!.avg_latency_ms }} ms</span>
                  <span v-else>—</span>
                </td>

                <td class="px-5 py-3 text-right tabular-nums text-muted">
                  {{ money(usageByTask.get(row.task)?.cost_micros ?? 0) }}
                </td>

                <td class="px-5 py-3">
                  <span
                    v-if="!row.route"
                    class="rounded-sm bg-danger/10 px-2 py-0.5 text-[11px] text-danger"
                  >
                    yönlendirilmemiş
                  </span>
                  <span
                    v-else-if="row.route.is_paused"
                    class="rounded-sm bg-danger/10 px-2 py-0.5 text-[11px] text-danger"
                    :title="row.route.pause_reason ?? undefined"
                  >
                    durduruldu
                  </span>
                  <span v-else class="rounded-sm bg-success/10 px-2 py-0.5 text-[11px] text-success">
                    açık
                  </span>
                </td>

                <td class="px-5 py-3 text-right">
                  <button
                    v-if="row.route && !row.route.is_paused"
                    type="button"
                    class="rounded-sm border border-line px-2.5 py-1 text-xs text-ink-secondary transition-colors hover:bg-bg-muted hover:text-ink disabled:opacity-50"
                    :disabled="acting === row.route.id"
                    @click="pause(row)"
                  >
                    Durdur
                  </button>
                  <button
                    v-else-if="row.route"
                    type="button"
                    class="rounded-sm border border-line px-2.5 py-1 text-xs text-ink-secondary transition-colors hover:bg-bg-muted hover:text-ink disabled:opacity-50"
                    :disabled="acting === row.route.id"
                    @click="resume(row)"
                  >
                    Aç
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <p
          v-for="row in tasks.filter(task => task.route?.is_paused && task.route.pause_reason)"
          :key="`reason-${row.task}`"
          class="border-t border-line px-5 py-2 text-[11px] text-muted"
        >
          <span class="text-ink">{{ row.label }}:</span> {{ row.route!.pause_reason }}
        </p>
      </section>

      <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-sm border border-line bg-surface">
          <h2 class="border-b border-line px-5 py-3.5 text-sm font-medium">Sağlayıcılar</h2>

          <div v-for="provider in providers" :key="provider.id" class="border-b border-line/60 px-5 py-3.5 last:border-b-0">
            <div class="flex items-center justify-between gap-3">
              <div>
                <p class="text-sm font-medium">{{ provider.name }}</p>
                <p class="text-[11px] text-muted">{{ provider.driver }}</p>
              </div>

              <!--
                The hint, never the key. Four characters answer "is this the key I think
                it is" and are useless to anybody who obtains them.
              -->
              <span v-if="provider.credential" class="text-[11px] text-muted">
                anahtar ••••{{ provider.credential.hint }}
                <span v-if="provider.credential.has_expired" class="text-danger">(süresi doldu)</span>
              </span>
              <span v-else class="text-[11px] text-danger">anahtar yok</span>
            </div>

            <ul class="mt-2 space-y-1">
              <li
                v-for="model in provider.models"
                :key="model.id"
                class="flex items-center justify-between gap-3 text-[11px] text-muted"
              >
                <span :class="model.is_usable ? '' : 'line-through'">{{ model.code }}</span>
                <span>{{ model.modality_label }}</span>
              </li>
            </ul>
          </div>
        </section>

        <section class="rounded-sm border border-line bg-surface">
          <h2 class="border-b border-line px-5 py-3.5 text-sm font-medium">
            Hatalar · son {{ windowDays }} gün
          </h2>

          <p v-if="failures.length === 0" class="px-5 py-4 text-sm text-muted">
            Bu dönemde kayıtlı hata yok.
          </p>

          <ul v-else>
            <li
              v-for="failure in failures"
              :key="failure.kind"
              class="flex items-center justify-between gap-3 border-b border-line/60 px-5 py-2.5 text-sm last:border-b-0"
            >
              <span>
                {{ failure.label }}
                <!--
                  Whether a kind was retried is the difference between "we tried three
                  times" and "we stopped immediately", which changes what the number means.
                -->
                <span v-if="!failure.is_retryable" class="text-[11px] text-muted">· tekrar denenmez</span>
              </span>
              <span class="tabular-nums text-muted">{{ failure.total }}</span>
            </li>
          </ul>
        </section>
      </div>
    </template>
  </div>
</template>
