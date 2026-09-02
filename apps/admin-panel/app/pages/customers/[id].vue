<script setup lang="ts">
/**
 * One customer, with everything support is actually asked about.
 *
 * Orders, credits and projects on one page, because the question almost never concerns one
 * of them alone — "I paid and my design never appeared" spans all three, and answering it
 * from three screens is how somebody ends up reading the wrong account's history.
 *
 * **Opening a photograph is a deliberate act here.** The projects section lists names and
 * counts; a room photograph or a render is fetched one at a time, after typing why, and the
 * request writes the operator's name and that reason to the audit log. The picture is of
 * the inside of somebody's home. That is the whole reason for the friction, and the friction
 * is the point rather than an oversight.
 */
interface CustomerDetail {
  id: string
  email: string
  name: string | null
  phone: string | null
  status: string
  status_label: string
  email_verified_at: string | null
  locale: string | null
  created_at: string | null
  credits: {
    balance: number
    reserved: number
    transactions: Array<{
      id: string
      type: string
      amount: number
      description: string | null
      created_at: string | null
    }>
  }
  orders: Array<{
    id: string
    number: string
    status: string
    total_minor: number
    currency: string
    placed_at: string | null
  }>
  projects: Array<{
    id: string
    name: string
    status: string
    room_count: number
    created_at: string | null
  }>
}

definePageMeta({ middleware: 'auth' })

const route = useRoute()
const api = useApi()

const id = route.params.id as string

const customer = ref<CustomerDetail | null>(null)
const loadError = ref<string | null>(null)

try {
  const response = await api.get<{ data: CustomerDetail }>(`/api/v1/admin/customers/${id}`)
  customer.value = response.data
} catch (error) {
  loadError.value = error instanceof ApiError
    ? ({ 403: 'Bu ekrana erişim yetkiniz yok.', 404: 'Müşteri bulunamadı.' }[error.status] ?? error.message)
    : 'Müşteri yüklenemedi.'
}

useHead(() => ({ title: customer.value?.email ?? 'Müşteri' }))

function money(minor: number, currency = 'TRY'): string {
  return new Intl.NumberFormat('tr-TR', { style: 'currency', currency }).format(minor / 100)
}

function when(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString('tr-TR')
}
</script>

<template>
  <div class="space-y-8">
    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <template v-else-if="customer">
      <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <NuxtLink to="/customers" class="text-sm text-muted hover:underline">← Müşteriler</NuxtLink>
          <h1 class="mt-1 text-xl font-medium">{{ customer.name || customer.email }}</h1>
          <p class="mt-1 text-sm text-muted">
            {{ customer.email }}<template v-if="customer.phone"> · {{ customer.phone }}</template>
          </p>
        </div>

        <div class="text-right">
          <RcStatusPill :status="customer.status" :label="customer.status_label" />
          <p class="mt-2 text-xs text-muted">
            Kayıt: {{ when(customer.created_at) }}
          </p>
          <p class="text-xs" :class="customer.email_verified_at ? 'text-muted' : 'text-warning'">
            {{ customer.email_verified_at ? 'E-posta doğrulanmış' : 'E-posta doğrulanmamış' }}
          </p>
        </div>
      </header>

      <!-- Credits, and where they went. -->
      <section class="rounded-sm border border-line bg-surface">
        <div class="flex items-baseline justify-between border-b border-line px-5 py-4">
          <h2 class="font-medium">Krediler</h2>
          <p class="text-sm tabular-nums">
            <span class="text-lg font-medium">{{ customer.credits.balance }}</span>
            <span class="text-muted"> bakiye</span>
            <span v-if="customer.credits.reserved > 0" class="text-muted">
              · {{ customer.credits.reserved }} bloke
            </span>
          </p>
        </div>

        <p v-if="customer.credits.transactions.length === 0" class="px-5 py-6 text-sm text-muted">
          Kredi hareketi yok.
        </p>

        <table v-else class="w-full text-sm">
          <tbody>
            <tr
              v-for="entry in customer.credits.transactions"
              :key="entry.id"
              class="border-b border-line last:border-0"
            >
              <td class="px-5 py-2.5">{{ entry.description || entry.type }}</td>
              <td
                class="px-5 py-2.5 text-right tabular-nums"
                :class="entry.amount < 0 ? 'text-danger' : 'text-success'"
              >
                {{ entry.amount > 0 ? '+' : '' }}{{ entry.amount }}
              </td>
              <td class="px-5 py-2.5 text-right text-xs text-muted">{{ when(entry.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- Orders. -->
      <section class="rounded-sm border border-line bg-surface">
        <h2 class="border-b border-line px-5 py-4 font-medium">Siparişler</h2>

        <p v-if="customer.orders.length === 0" class="px-5 py-6 text-sm text-muted">
          Sipariş yok.
        </p>

        <table v-else class="w-full text-sm">
          <tbody>
            <tr v-for="order in customer.orders" :key="order.id" class="border-b border-line last:border-0">
              <td class="px-5 py-2.5 font-mono text-xs">{{ order.number }}</td>
              <td class="px-5 py-2.5">{{ order.status }}</td>
              <td class="px-5 py-2.5 text-right tabular-nums">
                {{ money(order.total_minor, order.currency) }}
              </td>
              <td class="px-5 py-2.5 text-right text-xs text-muted">{{ when(order.placed_at) }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- Projects. Names and counts; the pictures are behind AdminCustomerMedia. -->
      <section class="rounded-sm border border-line bg-surface">
        <div class="border-b border-line px-5 py-4">
          <h2 class="font-medium">Projeler</h2>
          <p class="mt-1 text-xs text-muted">
            Oda fotoğrafları ve tasarımlar bu listede gösterilmez. Bir görseli açmak
            gerekçe ister ve denetim kaydına yazılır.
          </p>
        </div>

        <p v-if="customer.projects.length === 0" class="px-5 py-6 text-sm text-muted">
          Proje yok.
        </p>

        <table v-else class="w-full text-sm">
          <tbody>
            <tr v-for="project in customer.projects" :key="project.id" class="border-b border-line last:border-0">
              <td class="px-5 py-2.5">{{ project.name }}</td>
              <td class="px-5 py-2.5 text-muted">{{ project.status }}</td>
              <td class="px-5 py-2.5 text-right tabular-nums">{{ project.room_count }} oda</td>
              <td class="px-5 py-2.5 text-right text-xs text-muted">{{ when(project.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </section>
    </template>
  </div>
</template>
