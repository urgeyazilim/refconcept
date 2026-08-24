<script setup lang="ts">
import type { ApiCredentialSummary, ApiUsageEntry } from '@refconcept/ui/types'

/**
 * Machine credentials for a seller's own systems.
 *
 * The screen is built around one hard fact: the secret is shown once and cannot be
 * recovered. That is said before the credential is created, shown prominently when it
 * is, and repeated where the hint appears — because a seller who closes the dialogue
 * too fast has to create a new credential, and one who does not believe it will not
 * copy the secret at all.
 */
definePageMeta({ middleware: 'auth' })
useHead({ title: 'Entegrasyonlar' })

const api = useApi()
const config = useRuntimeConfig()

const credentials = ref<ApiCredentialSummary[]>([])
const availableScopes = ref<string[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)

const creating = ref(false)
const form = reactive({ name: '', scopes: [] as string[], expires_in_days: '' })
const saving = ref(false)
const formError = ref<string | null>(null)

/** Held only until the seller dismisses it; never fetched again. */
const issued = ref<ApiCredentialSummary | null>(null)

const usageFor = ref<ApiCredentialSummary | null>(null)
const usage = ref<ApiUsageEntry[]>([])

const scopeLabels: Record<string, string> = {
  'catalog:read': 'Katalog okuma',
  'products:read': 'Ürün okuma',
  'products:write': 'Ürün yazma',
  'prices:read': 'Fiyat okuma',
  'prices:write': 'Fiyat yazma',
  'stock:read': 'Stok okuma',
  'stock:write': 'Stok yazma',
  'orders:read': 'Sipariş okuma',
}

async function load() {
  loading.value = true
  loadError.value = null

  try {
    const response = await api.get<{ data: ApiCredentialSummary[], meta: { available_scopes: string[] } }>(
      '/api/v1/seller/api-credentials',
    )

    credentials.value = response.data
    availableScopes.value = response.meta.available_scopes
  } catch (error) {
    loadError.value = error instanceof ApiError
      ? (error.status === 403 ? 'Entegrasyonlar için satıcı hesabınızın onaylanması gerekiyor.' : error.message)
      : 'Kimlik bilgileri yüklenemedi.'
  } finally {
    loading.value = false
  }
}

await load()

async function create() {
  saving.value = true
  formError.value = null

  try {
    const response = await api.post<{ data: ApiCredentialSummary }>('/api/v1/seller/api-credentials', {
      name: form.name,
      scopes: form.scopes,
      expires_in_days: form.expires_in_days === '' ? null : Number(form.expires_in_days),
    })

    issued.value = response.data
    creating.value = false

    Object.assign(form, { name: '', scopes: [], expires_in_days: '' })

    await load()
  } catch (error) {
    formError.value = error instanceof ApiError
      ? (error.fieldError('scopes') ?? error.fieldError('name') ?? error.message)
      : 'Kimlik bilgisi oluşturulamadı.'
  } finally {
    saving.value = false
  }
}

async function revoke(credential: ApiCredentialSummary) {
  const reason = window.prompt('Bu kimlik bilgisini neden iptal ediyorsunuz?')

  if (!reason || reason.trim().length < 5) return

  try {
    await api.request(`/api/v1/seller/api-credentials/${credential.id}`, {
      method: 'DELETE',
      body: { reason },
    })

    await load()
  } catch (error) {
    loadError.value = error instanceof ApiError ? error.message : 'İptal edilemedi.'
  }
}

async function openUsage(credential: ApiCredentialSummary) {
  usageFor.value = credential

  try {
    const response = await api.get<{ data: ApiUsageEntry[] }>(
      `/api/v1/seller/api-credentials/${credential.id}/usage`,
    )

    usage.value = response.data
  } catch {
    usage.value = []
  }
}

const apiBase = config.public.apiBase

async function copy(text: string) {
  try {
    await navigator.clipboard.writeText(text)
  } catch {
    // Clipboard access can be refused; the value is on screen and selectable, so
    // there is nothing useful to say about it.
  }
}
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 class="text-2xl font-medium">Entegrasyonlar</h1>
        <p class="mt-1.5 max-w-[70ch] text-sm leading-relaxed text-ink-secondary">
          Kendi sisteminiz — ERP, depo yazılımı, e-ticaret altyapınız — stok ve fiyat
          bilgisini RefConcept'e doğrudan gönderebilir. Her kimlik bilgisi yalnızca
          kendisine verdiğiniz yetkilere sahiptir.
        </p>
      </div>

      <RcButton v-if="!creating" @click="creating = true">Yeni kimlik bilgisi</RcButton>
    </header>

    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <!-- The one and only time the secret exists -->
    <section v-if="issued" class="rc-card border-2 border-gold p-6 sm:p-8">
      <h2 class="text-lg font-medium">{{ issued.name }} oluşturuldu</h2>
      <RcAlert tone="warning" class="mt-4">{{ issued.secret_notice }}</RcAlert>

      <dl class="mt-5 space-y-4">
        <div>
          <dt class="text-xs text-muted">Anahtar (key)</dt>
          <dd class="mt-1 flex items-center gap-2">
            <code class="flex-1 truncate rounded-sm bg-bg-muted px-3 py-2 text-sm">{{ issued.key_id }}</code>
            <RcButton size="sm" variant="secondary" @click="copy(issued!.key_id)">Kopyala</RcButton>
          </dd>
        </div>

        <div>
          <dt class="text-xs text-muted">Gizli anahtar (secret)</dt>
          <dd class="mt-1 flex items-center gap-2">
            <code class="flex-1 truncate rounded-sm bg-bg-muted px-3 py-2 text-sm">{{ issued.secret }}</code>
            <RcButton size="sm" variant="secondary" @click="copy(issued!.secret ?? '')">Kopyala</RcButton>
          </dd>
        </div>
      </dl>

      <div class="mt-6 rounded-md bg-bg-muted p-4">
        <p class="text-xs font-medium">Örnek istek</p>
        <pre class="mt-2 overflow-x-auto text-xs leading-relaxed"><code>curl {{ apiBase }}/api/v1/partner/stock \
  -H "X-RefConcept-Key: {{ issued.key_id }}" \
  -H "X-RefConcept-Secret: {{ issued.secret }}"</code></pre>
      </div>

      <RcButton class="mt-6" variant="secondary" @click="issued = null">
        Kaydettim, kapat
      </RcButton>
    </section>

    <!-- Create -->
    <section v-if="creating" class="rc-card p-6 sm:p-8">
      <h2 class="text-lg font-medium">Yeni kimlik bilgisi</h2>

      <RcAlert v-if="formError" tone="danger" class="mt-4">{{ formError }}</RcAlert>

      <form class="mt-5 space-y-5" @submit.prevent="create">
        <RcField
          v-model="form.name"
          label="Ad"
          name="name"
          placeholder="Örn. Depo yazılımı, Logo ERP"
          hint="Yalnızca sizin için; hangi sistemin kullandığını hatırlamanızı sağlar."
          required
        />

        <fieldset>
          <legend class="mb-2.5 text-sm font-medium">Yetkiler</legend>
          <p class="mb-3 max-w-[60ch] text-xs leading-relaxed text-muted">
            Yalnızca gerekli olanları verin. Stok gönderen bir sistemin fiyat
            değiştirebilmesi için hiçbir sebep yoktur.
          </p>

          <div class="flex flex-wrap gap-1.5">
            <button
              v-for="scope in availableScopes"
              :key="scope"
              type="button"
              class="rounded-pill border px-3.5 py-1.5 text-xs transition-colors"
              :class="form.scopes.includes(scope)
                ? 'border-charcoal bg-charcoal text-white'
                : 'border-line text-ink-secondary hover:bg-bg-muted'"
              @click="form.scopes.includes(scope)
                ? form.scopes.splice(form.scopes.indexOf(scope), 1)
                : form.scopes.push(scope)"
            >
              {{ scopeLabels[scope] ?? scope }}
            </button>
          </div>
        </fieldset>

        <RcField
          v-model="form.expires_in_days"
          label="Geçerlilik (gün)"
          name="expires_in_days"
          type="number"
          hint="Boş bırakırsanız süresiz olur. Geçici entegrasyonlar için süre vermek güvenlidir."
        />

        <div class="flex items-center gap-3">
          <RcButton type="submit" :loading="saving" :disabled="saving || form.scopes.length === 0">
            Oluştur
          </RcButton>
          <RcButton variant="ghost" @click="creating = false">Vazgeç</RcButton>
        </div>
      </form>
    </section>

    <p v-if="loading" class="text-sm text-muted">Yükleniyor…</p>

    <div v-else-if="credentials.length === 0 && !creating" class="rc-card p-12 text-center">
      <RcFeatureIcon
        class="mx-auto"
        size="lg"
        icon="M15 7a4 4 0 1 1-3.9 5H7v3H4v-3.1A4 4 0 0 1 11.1 8H15Zm.5 3.5h.01"
      />
      <h2 class="mt-5 text-lg font-medium">Henüz kimlik bilgisi yok</h2>
      <p class="mx-auto mt-3 max-w-[52ch] leading-relaxed text-ink-secondary">
        Kendi sisteminizden stok ve fiyat göndermek istiyorsanız bir kimlik bilgisi
        oluşturun. Ürünlerinizi elle yönetmeye devam edebilirsiniz — bu isteğe bağlıdır.
      </p>
      <RcButton class="mt-7" @click="creating = true">Kimlik bilgisi oluştur</RcButton>
    </div>

    <div v-else-if="credentials.length > 0" class="rc-card overflow-x-auto">
      <table class="w-full min-w-[720px] text-sm">
        <thead class="border-b border-line bg-bg-muted text-left">
          <tr>
            <th class="px-5 py-3 font-medium">Ad</th>
            <th class="px-5 py-3 font-medium">Anahtar</th>
            <th class="px-5 py-3 font-medium">Yetkiler</th>
            <th class="px-5 py-3 font-medium">Son kullanım</th>
            <th class="px-5 py-3" />
          </tr>
        </thead>

        <tbody>
          <tr
            v-for="credential in credentials"
            :key="credential.id"
            class="border-b border-line last:border-0"
            :class="credential.is_usable ? '' : 'opacity-60'"
          >
            <td class="px-5 py-4">
              <p class="font-medium">{{ credential.name }}</p>
              <p v-if="credential.revoked_reason" class="text-xs text-danger-strong">
                İptal: {{ credential.revoked_reason }}
              </p>
              <p v-else-if="credential.expires_at" class="text-xs text-muted">
                {{ new Date(credential.expires_at).toLocaleDateString('tr-TR') }} tarihine kadar
              </p>
            </td>

            <td class="px-5 py-4">
              <code class="text-xs">{{ credential.key_id }}</code>
              <p class="text-xs text-muted">{{ credential.secret_hint }}</p>
            </td>

            <td class="px-5 py-4">
              <div class="flex flex-wrap gap-1">
                <span
                  v-for="scope in credential.scopes"
                  :key="scope"
                  class="rounded-pill bg-bg-muted px-2 py-0.5 text-[11px] text-ink-secondary"
                >
                  {{ scopeLabels[scope] ?? scope }}
                </span>
              </div>
            </td>

            <td class="px-5 py-4 text-xs text-muted">
              {{ credential.last_used_at ? new Date(credential.last_used_at).toLocaleString('tr-TR') : 'Hiç' }}
            </td>

            <td class="px-5 py-4 text-right whitespace-nowrap">
              <button
                type="button"
                class="rounded-sm px-2.5 py-1.5 text-xs text-ink-secondary hover:bg-bg-muted"
                @click="openUsage(credential)"
              >
                İstekler
              </button>
              <button
                v-if="credential.is_usable"
                type="button"
                class="rounded-sm px-2.5 py-1.5 text-xs text-danger hover:bg-danger-subtle"
                @click="revoke(credential)"
              >
                İptal et
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Usage -->
    <div
      v-if="usageFor"
      class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-charcoal/40 p-4 sm:p-8"
      @click.self="usageFor = null"
    >
      <div class="rc-card w-full max-w-2xl p-6 sm:p-8">
        <header class="flex items-start justify-between gap-4">
          <div>
            <h2 class="text-lg font-medium">{{ usageFor.name }}</h2>
            <p class="mt-1 text-xs text-muted">Son 100 istek</p>
          </div>
          <button
            type="button"
            aria-label="Kapat"
            class="rounded-sm px-2 py-1 text-sm text-muted hover:bg-bg-muted"
            @click="usageFor = null"
          >
            ✕
          </button>
        </header>

        <p v-if="usage.length === 0" class="mt-5 text-sm text-ink-secondary">
          Bu kimlik bilgisiyle henüz istek yapılmadı.
        </p>

        <table v-else class="mt-5 w-full text-sm">
          <tbody>
            <tr v-for="(entry, index) in usage" :key="index" class="border-b border-line last:border-0">
              <td class="py-2.5 pr-4">
                <span
                  class="rounded-sm px-1.5 py-0.5 text-[11px]"
                  :class="entry.ok ? 'bg-success-subtle text-success-strong' : 'bg-danger-subtle text-danger-strong'"
                >
                  {{ entry.status }}
                </span>
              </td>
              <td class="py-2.5 pr-4 font-mono text-xs">{{ entry.method }} /{{ entry.path }}</td>
              <td class="py-2.5 pr-4 text-right text-xs tabular-nums text-muted">{{ entry.duration_ms }} ms</td>
              <td class="py-2.5 text-right text-xs text-muted">
                {{ new Date(entry.created_at).toLocaleString('tr-TR') }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
