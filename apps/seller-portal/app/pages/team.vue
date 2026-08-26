<script setup lang="ts">
/**
 * The people who work for this seller.
 *
 * A seller is a company: somebody dispatches parcels, somebody else answers returns, and
 * the person whose name is on the bank account does neither. Without this screen a company
 * shares one login, and then every audit entry says "the seller" and means nobody.
 *
 * Staff see the list and change nothing — a returns queue showing "kim onayladı" next to
 * an unfamiliar name is worse than no name. The buttons are absent rather than disabled,
 * and the page says why, because a disabled button somebody cannot explain reads as a bug.
 *
 * The last owner cannot demote or remove themselves. The API refuses it too; the screen
 * refuses it first so that the refusal arrives as an explanation rather than as an error.
 */
import type { SellerTeamMember, SellerTeamMeta } from '@refconcept/ui/types'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Ekibim' })

const api = useApi()

const members = ref<SellerTeamMember[]>([])
const meta = ref<SellerTeamMeta | null>(null)

const loading = ref(true)
const busy = ref<string | null>(null)
const loadError = ref<string | null>(null)
const banner = ref<{ tone: 'success' | 'danger', text: string } | null>(null)

const inviteEmail = ref('')
const inviteRole = ref('seller-staff')
const inviteErrors = ref<Record<string, string[]>>({})

async function load() {
  loading.value = true
  loadError.value = null

  try {
    const response = await api.get<{ data: SellerTeamMember[], meta: SellerTeamMeta }>(
      '/api/v1/seller/team',
    )

    members.value = response.data
    meta.value = response.meta
  } catch (error) {
    loadError.value = error instanceof ApiError ? error.message : 'Ekip bilgileri yüklenemedi.'
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

async function invite() {
  inviteErrors.value = {}
  banner.value = null
  busy.value = 'invite'

  try {
    await api.post('/api/v1/seller/team', { email: inviteEmail.value, role: inviteRole.value })

    banner.value = { tone: 'success', text: `${inviteEmail.value} ekibinize eklendi.` }
    inviteEmail.value = ''
    await load()
  } catch (error) {
    if (error instanceof ApiError && error.isValidation) {
      inviteErrors.value = error.errors
    }

    banner.value = {
      tone: 'danger',
      text: error instanceof ApiError ? error.message : 'Ekip üyesi eklenemedi.',
    }
  } finally {
    busy.value = null
  }
}

const changeRole = (member: SellerTeamMember, role: string) => act(
  'role-' + member.id,
  () => api.patch(`/api/v1/seller/team/${member.id}`, { role }),
  `${member.email} rolü güncellendi.`,
)

const remove = (member: SellerTeamMember) => act(
  'remove-' + member.id,
  () => api.delete(`/api/v1/seller/team/${member.id}`),
  `${member.email} ekipten çıkarıldı.`,
)

const owners = computed(() => members.value.filter(member => member.role === 'seller-owner'))

/** The refusal that saves somebody their account, stated before it is needed. */
function isLastOwner(member: SellerTeamMember): boolean {
  return member.role === 'seller-owner' && owners.value.length <= 1
}

function when(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString('tr-TR')
}
</script>

<template>
  <div class="mx-auto max-w-4xl space-y-6">
    <header>
      <h1 class="text-xl font-medium">Ekibim</h1>
      <p class="mt-1 text-sm text-muted">
        Şirketinizde kimin çalıştığı ve ne yapabildiği. Herkesin kendi hesabı olmalı —
        paylaşılan bir giriş, her kaydın "satıcı" deyip kimseyi işaret etmemesi demektir.
      </p>
    </header>

    <RcAlert v-if="banner" :tone="banner.tone">{{ banner.text }}</RcAlert>
    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <p v-if="loading" class="text-sm text-muted">Yükleniyor…</p>

    <template v-else>
      <!--
        Absent rather than disabled, with the reason said out loud: a greyed-out button
        nobody can explain reads as a bug in the page.
      -->
      <RcAlert v-if="meta && !meta.can_manage" tone="info">
        Ekibi yalnızca yetkili hesaplar değiştirebilir. Siz listeyi görebilir,
        değişiklik yapamazsınız.
      </RcAlert>

      <section v-if="meta?.can_manage" aria-label="Ekip üyesi ekle" class="rc-card p-6">
        <h2 class="text-sm font-medium">Ekip üyesi ekle</h2>
        <p class="mt-1 text-sm text-muted">
          Eklemek istediğiniz kişinin önce kendi hesabını açmış olması gerekiyor.
        </p>

        <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="invite">
          <div class="min-w-[240px] flex-1">
            <label for="invite-email" class="mb-1.5 block text-sm font-medium text-ink">
              E-posta adresi
            </label>
            <input
              id="invite-email"
              v-model="inviteEmail"
              name="invite-email"
              type="email"
              required
              placeholder="depo@sirketiniz.com"
              class="w-full rounded-sm border border-line bg-surface px-3 py-2 text-sm"
              data-testid="team-email"
            >
            <p v-if="inviteErrors.email" class="mt-1 text-xs text-danger-strong">
              {{ inviteErrors.email[0] }}
            </p>
          </div>

          <div class="w-56">
            <label for="invite-role" class="mb-1.5 block text-sm font-medium text-ink">Rol</label>
            <select
              id="invite-role"
              v-model="inviteRole"
              name="invite-role"
              class="w-full rounded-sm border border-line bg-surface px-3 py-2 text-sm"
              data-testid="team-role"
            >
              <option v-for="role in meta.roles" :key="role.value" :value="role.value">
                {{ role.label }}
              </option>
            </select>
          </div>

          <RcButton type="submit" :loading="busy === 'invite'">Ekle</RcButton>
        </form>

        <dl class="mt-4 space-y-1 text-xs text-muted">
          <div v-for="role in meta.roles" :key="role.value" class="flex gap-2">
            <dt class="font-medium text-ink-secondary">{{ role.label }}:</dt>
            <dd>{{ role.description }}</dd>
          </div>
        </dl>
      </section>

      <section aria-label="Ekip">
        <div class="overflow-x-auto rounded-sm border border-line bg-surface">
          <table class="w-full text-sm">
            <thead class="border-b border-line text-left text-xs text-muted uppercase">
              <tr>
                <th class="px-4 py-3">Kişi</th>
                <th class="px-4 py-3">Rol</th>
                <th class="px-4 py-3">Katıldı</th>
                <th class="px-4 py-3" />
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="member in members"
                :key="member.id"
                class="border-b border-line last:border-0"
                data-testid="team-row"
              >
                <td class="px-4 py-3">
                  <p>{{ member.name ?? member.email }}</p>
                  <p class="text-xs text-muted">{{ member.email }}</p>
                </td>

                <td class="px-4 py-3">
                  <select
                    v-if="meta?.can_manage && !isLastOwner(member)"
                    :value="member.role ?? ''"
                    class="rounded-sm border border-line bg-surface px-2 py-1 text-sm"
                    :disabled="busy === 'role-' + member.id"
                    :aria-label="`${member.email} rolü`"
                    @change="changeRole(member, ($event.target as HTMLSelectElement).value)"
                  >
                    <option v-for="role in meta.roles" :key="role.value" :value="role.value">
                      {{ role.label }}
                    </option>
                  </select>

                  <span v-else>{{ member.role_label ?? '—' }}</span>
                </td>

                <td class="px-4 py-3 text-xs text-muted">{{ when(member.joined_at) }}</td>

                <td class="px-4 py-3 text-right">
                  <!--
                    The last owner has no button at all. A company with no owner is a
                    company where nobody can add one back, and the only way out is a
                    support ticket.
                  -->
                  <span v-if="isLastOwner(member)" class="text-xs text-muted">
                    Tek yetkili
                  </span>

                  <RcButton
                    v-else-if="meta?.can_manage"
                    variant="ghost"
                    :loading="busy === 'remove-' + member.id"
                    :data-testid="'team-remove-' + member.email"
                    @click="remove(member)"
                  >
                    Çıkar
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
