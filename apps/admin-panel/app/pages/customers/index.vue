<script setup lang="ts">
/**
 * Every customer, for the person answering the phone.
 *
 * Searched by e-mail, name or telephone, because those are the three things a caller
 * actually has — nobody reads a UUID aloud. Read-only: suspending an account or moving
 * credits are real actions with their own rules, and a button here would be a way around
 * those rules rather than an implementation of them.
 *
 * No pictures anywhere on this screen, deliberately. These accounts contain photographs of
 * the inside of people's homes; a thumbnail beside each row would make looking the default
 * rather than a decision somebody has to make and justify. Opening one is on the detail
 * page, it asks for a reason, and it is written to the audit log.
 */
interface CustomerRow {
  id: string
  email: string
  name: string | null
  phone: string | null
  status: string
  status_label: string
  email_verified: boolean
  project_count: number
  order_count: number
  credit_balance: number
  created_at: string | null
}

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Müşteriler' })

const api = useApi()

const customers = ref<CustomerRow[]>([])
const search = ref('')
const status = ref('')
const loading = ref(true)
const loadError = ref<string | null>(null)

const statuses = [
  { value: '', label: 'Tümü' },
  { value: 'active', label: 'Aktif' },
  { value: 'pending_verification', label: 'Doğrulama bekliyor' },
  { value: 'suspended', label: 'Askıya alınmış' },
]

async function load() {
  loading.value = true
  loadError.value = null

  try {
    const params: Record<string, string> = {}

    if (search.value.trim() !== '') params.search = search.value.trim()
    if (status.value !== '') params.status = status.value

    const query = new URLSearchParams(params).toString()

    const response = await api.get<{ data: CustomerRow[] }>(
      `/api/v1/admin/customers${query === '' ? '' : `?${query}`}`,
    )

    customers.value = response.data
  } catch (error) {
    loadError.value = error instanceof ApiError
      ? (error.status === 403 ? 'Bu ekrana erişim yetkiniz yok.' : error.message)
      : 'Müşteriler yüklenemedi.'
  } finally {
    loading.value = false
  }
}

await load()

watch(status, load)

function when(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString('tr-TR')
}
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-xl font-medium">Müşteriler</h1>
      <p class="mt-1 text-sm text-muted">
        E-posta, ad ya da telefon ile arayın. Bu ekran salt okunurdur.
      </p>
    </header>

    <form class="flex flex-wrap items-end gap-3" @submit.prevent="load">
      <div class="min-w-[260px] flex-1">
        <label for="customer-search" class="mb-1.5 block text-sm font-medium text-ink">Ara</label>
        <input
          id="customer-search"
          v-model="search"
          name="customer-search"
          type="search"
          placeholder="ornek@eposta.com, Ayşe Yılmaz veya 05551112233"
          class="w-full rounded-sm border border-line bg-surface px-3 py-2 text-sm"
          data-testid="customer-search"
        >
      </div>

      <div class="w-56">
        <label for="customer-status" class="mb-1.5 block text-sm font-medium text-ink">Durum</label>
        <select
          id="customer-status"
          v-model="status"
          name="customer-status"
          class="w-full rounded-sm border border-line bg-surface px-3 py-2 text-sm"
        >
          <option v-for="option in statuses" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </div>

      <RcButton type="submit" :loading="loading">Ara</RcButton>
    </form>

    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <p v-if="loading" class="text-sm text-muted">Yükleniyor…</p>

    <p
      v-else-if="customers.length === 0"
      class="rounded-sm border border-line bg-surface p-8 text-center text-sm text-muted"
      data-testid="customers-empty"
    >
      Bu ölçütlere uyan müşteri yok.
    </p>

    <div v-else class="overflow-x-auto rounded-sm border border-line bg-surface">
      <table class="w-full text-sm">
        <thead class="border-b border-line text-left text-xs text-muted uppercase">
          <tr>
            <th class="px-4 py-3">Müşteri</th>
            <th class="px-4 py-3">Durum</th>
            <th class="px-4 py-3 text-right">Proje</th>
            <th class="px-4 py-3 text-right">Sipariş</th>
            <th class="px-4 py-3 text-right">Kredi</th>
            <th class="px-4 py-3">Kayıt</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="customer in customers"
            :key="customer.id"
            class="border-b border-line transition-colors last:border-0 hover:bg-bg-muted"
            data-testid="customer-row"
          >
            <td class="px-4 py-3">
              <NuxtLink :to="`/customers/${customer.id}`" class="block hover:underline">
                <span class="font-medium">{{ customer.name || '—' }}</span>
                <span class="block text-xs text-muted">{{ customer.email }}</span>
              </NuxtLink>
            </td>
            <td class="px-4 py-3">
              <RcStatusPill :status="customer.status" :label="customer.status_label" size="sm" />
              <!--
                Said separately from the status. An account can be active and unverified,
                and "the link never arrived" is the single most common support call there is.
              -->
              <span v-if="!customer.email_verified" class="mt-1 block text-xs text-warning">
                E-posta doğrulanmamış
              </span>
            </td>
            <td class="px-4 py-3 text-right tabular-nums">{{ customer.project_count }}</td>
            <td class="px-4 py-3 text-right tabular-nums">{{ customer.order_count }}</td>
            <td class="px-4 py-3 text-right tabular-nums">{{ customer.credit_balance }}</td>
            <td class="px-4 py-3 text-xs text-muted">{{ when(customer.created_at) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
