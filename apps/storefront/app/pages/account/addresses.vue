<script setup lang="ts">
import type { Address } from '@refconcept/ui/types'

definePageMeta({ layout: 'account', middleware: ['auth', 'verified'] })
useHead({ title: 'Adreslerim' })

const api = useApi()

const addresses = ref<Address[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)

const showForm = ref(false)
const editingId = ref<string | null>(null)
const errors = ref<Record<string, string[]>>({})
const formError = ref<string | null>(null)
const submitting = ref(false)
const deletingId = ref<string | null>(null)

const blank = () => ({
  label: '',
  recipient_name: '',
  phone: '',
  city: '',
  district: '',
  address_line1: '',
  address_line2: '',
  postal_code: '',
  is_default_shipping: false,
  is_default_billing: false,
})

const form = reactive(blank())

async function load() {
  loading.value = true
  loadError.value = null

  try {
    const response = await api.get<{ data: Address[] }>('/api/v1/addresses')
    addresses.value = response.data
  } catch (error) {
    loadError.value = error instanceof ApiError ? error.message : 'Adresler yüklenemedi.'
  } finally {
    loading.value = false
  }
}

await load()

function openCreate() {
  Object.assign(form, blank())
  editingId.value = null
  errors.value = {}
  formError.value = null
  showForm.value = true
}

function openEdit(address: Address) {
  Object.assign(form, {
    label: address.label ?? '',
    recipient_name: address.recipient_name,
    phone: address.phone ?? '',
    city: address.city,
    district: address.district ?? '',
    address_line1: address.address_line1,
    address_line2: address.address_line2 ?? '',
    postal_code: address.postal_code ?? '',
    is_default_shipping: address.is_default_shipping,
    is_default_billing: address.is_default_billing,
  })
  editingId.value = address.id
  errors.value = {}
  formError.value = null
  showForm.value = true
}

async function onSubmit() {
  errors.value = {}
  formError.value = null
  submitting.value = true

  const payload = {
    ...form,
    label: form.label || null,
    phone: form.phone || null,
    district: form.district || null,
    address_line2: form.address_line2 || null,
    postal_code: form.postal_code || null,
  }

  try {
    if (editingId.value) {
      await api.patch(`/api/v1/addresses/${editingId.value}`, payload)
    } else {
      await api.post('/api/v1/addresses', payload)
    }

    showForm.value = false
    await load()
  } catch (error) {
    if (error instanceof ApiError && error.isValidation) {
      errors.value = error.errors
    } else if (error instanceof ApiError) {
      formError.value = error.message
    } else {
      formError.value = 'Beklenmeyen bir hata oluştu.'
    }
  } finally {
    submitting.value = false
  }
}

async function remove(address: Address) {
  deletingId.value = address.id

  try {
    await api.delete(`/api/v1/addresses/${address.id}`)
    await load()
  } catch (error) {
    loadError.value = error instanceof ApiError ? error.message : 'Adres silinemedi.'
  } finally {
    deletingId.value = null
  }
}
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-xl font-medium">Adreslerim</h2>
        <p class="mt-1 text-sm text-muted">Teslimat ve fatura adreslerinizi yönetin.</p>
      </div>
      <RcButton v-if="!showForm" @click="openCreate">Yeni adres ekle</RcButton>
    </div>

    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <section v-if="showForm" class="rc-card p-6 sm:p-8">
      <h3 class="mb-6 text-lg font-medium">
        {{ editingId ? 'Adresi düzenle' : 'Yeni adres' }}
      </h3>

      <form class="space-y-5" novalidate @submit.prevent="onSubmit">
        <RcAlert v-if="formError" tone="danger">{{ formError }}</RcAlert>

        <div class="grid gap-5 sm:grid-cols-2">
          <RcField v-model="form.label" label="Adres adı" name="label" placeholder="Ev, İş…" :errors="errors.label" />
          <RcField
            v-model="form.recipient_name"
            label="Alıcı adı"
            name="recipient_name"
            required
            :errors="errors.recipient_name"
          />
        </div>

        <RcField v-model="form.phone" label="Telefon" name="phone" :errors="errors.phone" />

        <div class="grid gap-5 sm:grid-cols-2">
          <RcField v-model="form.city" label="İl" name="city" required :errors="errors.city" />
          <RcField v-model="form.district" label="İlçe" name="district" :errors="errors.district" />
        </div>

        <RcField
          v-model="form.address_line1"
          label="Adres"
          name="address_line1"
          required
          :errors="errors.address_line1"
        />
        <RcField
          v-model="form.address_line2"
          label="Adres devamı"
          name="address_line2"
          :errors="errors.address_line2"
        />
        <RcField v-model="form.postal_code" label="Posta kodu" name="postal_code" :errors="errors.postal_code" />

        <div class="space-y-3 border-t border-line pt-5">
          <RcField
            v-model="form.is_default_shipping"
            type="checkbox"
            label="Varsayılan teslimat adresim olsun"
            name="is_default_shipping"
          />
          <RcField
            v-model="form.is_default_billing"
            type="checkbox"
            label="Varsayılan fatura adresim olsun"
            name="is_default_billing"
          />
        </div>

        <div class="flex justify-end gap-3">
          <RcButton variant="ghost" @click="showForm = false">Vazgeç</RcButton>
          <RcButton type="submit" :loading="submitting">
            {{ editingId ? 'Güncelle' : 'Adresi kaydet' }}
          </RcButton>
        </div>
      </form>
    </section>

    <p v-if="loading" class="text-sm text-muted">Yükleniyor…</p>

    <div v-else-if="addresses.length === 0 && !showForm" class="rc-card p-10 text-center">
      <p class="text-sm text-ink-secondary">Henüz kayıtlı adresiniz yok.</p>
      <RcButton class="mt-5" @click="openCreate">İlk adresinizi ekleyin</RcButton>
    </div>

    <ul v-else class="grid gap-4 sm:grid-cols-2">
      <li v-for="address in addresses" :key="address.id" class="rc-card flex flex-col p-6">
        <div class="mb-3 flex items-start justify-between gap-3">
          <p class="font-medium">{{ address.label || address.recipient_name }}</p>
          <div class="flex shrink-0 gap-1.5">
            <span
              v-if="address.is_default_shipping"
              class="rounded-pill bg-accent-100 px-2.5 py-0.5 text-[11px] text-accent-800"
            >
              Teslimat
            </span>
            <span
              v-if="address.is_default_billing"
              class="rounded-pill bg-bg-muted px-2.5 py-0.5 text-[11px] text-ink-secondary"
            >
              Fatura
            </span>
          </div>
        </div>

        <address class="flex-1 text-sm leading-relaxed text-ink-secondary not-italic">
          {{ address.recipient_name }}<br>
          {{ address.address_line1 }}<br>
          <template v-if="address.address_line2">{{ address.address_line2 }}<br></template>
          <template v-if="address.district">{{ address.district }} / </template>{{ address.city }}
          <template v-if="address.postal_code"> {{ address.postal_code }}</template>
          <template v-if="address.phone"><br>{{ address.phone }}</template>
        </address>

        <div class="mt-5 flex gap-2 border-t border-line pt-4">
          <RcButton size="sm" variant="ghost" @click="openEdit(address)">Düzenle</RcButton>
          <RcButton
            size="sm"
            variant="ghost"
            :loading="deletingId === address.id"
            @click="remove(address)"
          >
            Sil
          </RcButton>
        </div>
      </li>
    </ul>
  </div>
</template>
