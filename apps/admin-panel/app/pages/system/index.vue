<script setup lang="ts">
/**
 * The platform's own switches: feature flags, settings, and the two queues that tell you
 * something is wrong before a customer does.
 *
 * Reserved for a super admin, and the one screen an operator cannot open. Turning a
 * feature on for everybody is a release decision rather than an operational one, and its
 * blast radius is the whole platform rather than one order.
 *
 * A secret setting never shows its value — not even to whoever set it. The page shows
 * whether it is set, and nothing else: a settings screen that prints an API token has
 * published it to everybody who can open the page.
 */
import type { FeatureFlagRow, SystemSettingRow, SystemHealth } from '@refconcept/ui/types'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Sistem' })

const api = useApi()

const flags = ref<FeatureFlagRow[]>([])
const settings = ref<SystemSettingRow[]>([])
const health = ref<SystemHealth | null>(null)

const loading = ref(true)
const busy = ref<string | null>(null)
const loadError = ref<string | null>(null)
const banner = ref<{ tone: 'success' | 'danger', text: string } | null>(null)

/** What the operator has typed but not yet saved, keyed by setting id. */
const drafts = ref<Record<string, string>>({})

async function load() {
  loading.value = true
  loadError.value = null

  try {
    const [flagList, settingList, systemHealth] = await Promise.all([
      api.get<{ data: FeatureFlagRow[] }>('/api/v1/admin/system/flags'),
      api.get<{ data: SystemSettingRow[] }>('/api/v1/admin/system/settings'),
      api.get<{ data: SystemHealth }>('/api/v1/admin/system/jobs'),
    ])

    flags.value = flagList.data
    settings.value = settingList.data
    health.value = systemHealth.data

    drafts.value = Object.fromEntries(
      settingList.data.map(row => [row.id, row.is_secret ? '' : (row.value ?? '')]),
    )
  } catch (error) {
    loadError.value = error instanceof ApiError ? error.message : 'Sistem bilgileri yüklenemedi.'
  } finally {
    loading.value = false
  }
}

await load()

async function act(key: string, operation: () => Promise<unknown>, success: string) {
  busy.value = key
  banner.value = null

  try {
    await operation()
    banner.value = { tone: 'success', text: success }
    await load()
  } catch (error) {
    banner.value = {
      tone: 'danger',
      text: error instanceof ApiError ? error.message : 'İşlem tamamlanamadı.',
    }
  } finally {
    busy.value = null
  }
}

const toggleFlag = (flag: FeatureFlagRow) => act(
  'flag-' + flag.id,
  () => api.patch(`/api/v1/admin/system/flags/${flag.id}`, {
    key: flag.key,
    name: flag.name,
    is_enabled: !flag.is_enabled,
  }),
  flag.is_enabled ? `"${flag.name}" kapatıldı.` : `"${flag.name}" açıldı.`,
)

const setRollout = (flag: FeatureFlagRow, percentage: number) => act(
  'flag-' + flag.id,
  () => api.patch(`/api/v1/admin/system/flags/${flag.id}`, {
    key: flag.key,
    name: flag.name,
    rollout_percentage: percentage,
  }),
  `"${flag.name}" yüzde ${percentage} kullanıcıya açık.`,
)

const saveSetting = (setting: SystemSettingRow) => act(
  'setting-' + setting.id,
  () => api.patch(`/api/v1/admin/system/settings/${setting.id}`, {
    value: castDraft(setting),
  }),
  `"${setting.label}" kaydedildi.`,
)

/** The API type-checks the value; sending the right JavaScript type keeps the error rare. */
function castDraft(setting: SystemSettingRow): unknown {
  const raw = drafts.value[setting.id] ?? ''

  if (setting.type === 'integer') {
    return raw === '' ? null : Number(raw)
  }

  if (setting.type === 'boolean') {
    return raw === 'true'
  }

  if (setting.type === 'json') {
    try {
      return JSON.parse(raw === '' ? '{}' : raw)
    } catch {
      return raw
    }
  }

  return raw === '' ? null : raw
}

const replay = (id: string, gateway: string) => act(
  'webhook-' + id,
  () => api.post(`/api/v1/admin/system/webhooks/${id}/replay`),
  `${gateway} bildirimi yeniden işlendi.`,
)

function when(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString('tr-TR')
}

/** Settings grouped, because a flat list of forty keys is unreadable. */
const groups = computed(() => {
  const map = new Map<string, SystemSettingRow[]>()

  for (const setting of settings.value) {
    const bucket = map.get(setting.group) ?? []

    bucket.push(setting)
    map.set(setting.group, bucket)
  }

  return [...map.entries()].map(([name, rows]) => ({ name, rows }))
})
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-xl font-medium">Sistem</h1>
      <p class="mt-1 text-sm text-muted">
        Özellik bayrakları, platform ayarları ve arka plan işlerinin sağlığı.
      </p>
    </header>

    <RcAlert v-if="banner" :tone="banner.tone">{{ banner.text }}</RcAlert>
    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <p v-if="loading" class="text-sm text-muted">Yükleniyor…</p>

    <template v-else>
      <section aria-label="Özellik bayrakları">
        <h2 class="text-sm font-medium">Özellik bayrakları</h2>
        <p class="mt-1 text-sm text-muted">
          Kademeli açılışta bir kullanıcı hep aynı tarafta kalır; yüzdeyi büyütmek kimseyi
          geri almaz.
        </p>

        <p
          v-if="flags.length === 0"
          class="mt-3 rounded-sm border border-line bg-surface p-8 text-center text-sm text-muted"
        >
          Tanımlı bayrak yok.
        </p>

        <div v-else class="mt-3 space-y-3">
          <div
            v-for="flag in flags"
            :key="flag.id"
            class="flex flex-wrap items-center justify-between gap-4 rounded-sm border border-line bg-surface p-4"
            data-testid="flag-row"
          >
            <div class="min-w-0">
              <p class="text-sm font-medium">{{ flag.name }}</p>
              <p class="font-mono text-[11px] text-muted">{{ flag.key }}</p>
              <p v-if="flag.description" class="mt-1 text-sm text-ink-secondary">{{ flag.description }}</p>
            </div>

            <div class="flex items-center gap-3">
              <div class="flex gap-1">
                <button
                  v-for="percentage in [0, 25, 50, 100]"
                  :key="percentage"
                  type="button"
                  class="rounded-pill border px-2.5 py-1 text-xs tabular-nums"
                  :class="flag.rollout_percentage === percentage ? 'border-ink bg-ink text-surface' : 'border-line hover:bg-bg-muted'"
                  :disabled="busy === 'flag-' + flag.id"
                  @click="setRollout(flag, percentage)"
                >
                  %{{ percentage }}
                </button>
              </div>

              <RcButton
                :variant="flag.is_enabled ? 'secondary' : 'primary'"
                :loading="busy === 'flag-' + flag.id"
                :data-testid="'flag-toggle-' + flag.key"
                @click="toggleFlag(flag)"
              >
                {{ flag.is_enabled ? 'Kapat' : 'Aç' }}
              </RcButton>
            </div>
          </div>
        </div>
      </section>

      <section aria-label="Ayarlar">
        <h2 class="text-sm font-medium">Ayarlar</h2>

        <div v-for="group in groups" :key="group.name" class="mt-3">
          <p class="text-[11px] tracking-wide text-muted uppercase">{{ group.name }}</p>

          <div class="mt-2 space-y-3">
            <div
              v-for="setting in group.rows"
              :key="setting.id"
              class="rounded-sm border border-line bg-surface p-4"
              data-testid="setting-row"
            >
              <div class="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                  <p class="text-sm font-medium">{{ setting.label }}</p>
                  <p class="font-mono text-[11px] text-muted">{{ setting.key }} · {{ setting.type }}</p>
                </div>

                <!--
                  A secret's value never comes back from the API, so the page can only
                  report whether one is stored. That is the whole point.
                -->
                <span v-if="setting.is_secret" class="text-[11px] text-muted">
                  {{ setting.is_set ? 'Gizli · tanımlı' : 'Gizli · tanımsız' }}
                </span>
              </div>

              <p v-if="setting.description" class="mt-1 text-sm text-ink-secondary">
                {{ setting.description }}
              </p>

              <div class="mt-3 flex flex-wrap items-end gap-3">
                <select
                  v-if="setting.type === 'boolean'"
                  v-model="drafts[setting.id]"
                  class="w-40 rounded-sm border border-line bg-surface px-3 py-2 text-sm"
                  :aria-label="setting.label"
                >
                  <option value="true">Açık</option>
                  <option value="false">Kapalı</option>
                </select>

                <textarea
                  v-else-if="setting.type === 'json'"
                  v-model="drafts[setting.id]"
                  rows="3"
                  class="min-w-[280px] flex-1 rounded-sm border border-line bg-surface px-3 py-2 font-mono text-xs"
                  :aria-label="setting.label"
                />

                <input
                  v-else
                  v-model="drafts[setting.id]"
                  :type="setting.is_secret ? 'password' : (setting.type === 'integer' ? 'number' : 'text')"
                  :placeholder="setting.is_secret ? 'Değiştirmek için yeni değeri yazın' : ''"
                  class="min-w-[240px] flex-1 rounded-sm border border-line bg-surface px-3 py-2 text-sm"
                  :aria-label="setting.label"
                >

                <RcButton
                  variant="secondary"
                  :loading="busy === 'setting-' + setting.id"
                  @click="saveSetting(setting)"
                >
                  Kaydet
                </RcButton>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section v-if="health" aria-label="Arka plan sağlığı">
        <h2 class="text-sm font-medium">Başarısız işler ({{ health.failed_job_count }})</h2>

        <p
          v-if="health.failed_jobs.length === 0"
          class="mt-3 rounded-sm border border-line bg-surface p-8 text-center text-sm text-muted"
          data-testid="jobs-empty"
        >
          Kuyrukta başarısız iş yok.
        </p>

        <div v-else class="mt-3 overflow-x-auto rounded-sm border border-line bg-surface">
          <table class="w-full text-sm">
            <thead class="border-b border-line text-left text-xs text-muted uppercase">
              <tr>
                <th class="px-4 py-3">İş</th>
                <th class="px-4 py-3">Kuyruk</th>
                <th class="px-4 py-3">Hata</th>
                <th class="px-4 py-3">Zaman</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="job in health.failed_jobs" :key="job.uuid" class="border-b border-line last:border-0">
                <td class="px-4 py-3 font-mono text-xs">{{ job.job }}</td>
                <td class="px-4 py-3 text-xs">{{ job.queue }}</td>
                <td class="max-w-[420px] truncate px-4 py-3 text-xs text-danger-strong" :title="job.error">
                  {{ job.error }}
                </td>
                <td class="px-4 py-3 text-xs text-muted">{{ when(job.failed_at) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <h2 class="mt-6 text-sm font-medium">Ödeme bildirimleri</h2>

        <p
          v-if="health.webhooks.length === 0"
          class="mt-3 rounded-sm border border-line bg-surface p-8 text-center text-sm text-muted"
        >
          İşlenmemiş bildirim yok.
        </p>

        <div v-else class="mt-3 overflow-x-auto rounded-sm border border-line bg-surface">
          <table class="w-full text-sm">
            <thead class="border-b border-line text-left text-xs text-muted uppercase">
              <tr>
                <th class="px-4 py-3">Sağlayıcı</th>
                <th class="px-4 py-3">Olay</th>
                <th class="px-4 py-3">Durum</th>
                <th class="px-4 py-3">İmza</th>
                <th class="px-4 py-3 text-right">Deneme</th>
                <th class="px-4 py-3" />
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="event in health.webhooks"
                :key="event.id"
                class="border-b border-line last:border-0"
                data-testid="webhook-row"
              >
                <td class="px-4 py-3">{{ event.gateway }}</td>
                <td class="px-4 py-3 font-mono text-xs">{{ event.event_type ?? '—' }}</td>
                <td class="px-4 py-3">
                  <RcStatusPill :status="event.status" size="sm" />
                </td>
                <td class="px-4 py-3 text-xs">
                  <!--
                    An unverified notification is never replayed: anybody can post one, and
                    replaying it would let them fabricate a payment.
                  -->
                  <span :class="event.signature_verified ? 'text-ink-secondary' : 'text-danger-strong'">
                    {{ event.signature_verified ? 'Doğrulandı' : 'Doğrulanmadı' }}
                  </span>
                </td>
                <td class="px-4 py-3 text-right tabular-nums">{{ event.attempts }}</td>
                <td class="px-4 py-3 text-right">
                  <RcButton
                    v-if="event.signature_verified"
                    variant="secondary"
                    :loading="busy === 'webhook-' + event.id"
                    @click="replay(event.id, event.gateway)"
                  >
                    Yeniden işle
                  </RcButton>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>
