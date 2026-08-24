<script setup lang="ts">
import type { Option, ProjectDetail } from '@refconcept/ui/types'

/**
 * One project: its rooms, and who can see them.
 *
 * The room list is the working surface. Each card says plainly whether that room can
 * be designed yet and what is missing if not, because "add a room" followed by silence
 * is how somebody concludes the product does not work.
 */
definePageMeta({ middleware: ['auth', 'verified'], layout: 'account' })

const route = useRoute()
const api = useApi()
const projectId = route.params.id as string

const project = ref<ProjectDetail | null>(null)
const roomTypes = ref<Option[]>([])
const loadError = ref<string | null>(null)

const addingRoom = ref(false)
const roomForm = reactive({ name: '', room_type: 'living_room' })
const savingRoom = ref(false)
const roomError = ref<string | null>(null)

const invitingOpen = ref(false)
const inviteForm = reactive({ email: '', role: 'viewer' })
const inviting = ref(false)
const inviteError = ref<string | null>(null)
const inviteLink = ref<string | null>(null)

async function load() {
  try {
    const response = await api.get<{ data: ProjectDetail }>(`/api/v1/projects/${projectId}`)
    project.value = response.data
  } catch (error) {
    loadError.value = error instanceof ApiError
      ? ({ 403: 'Bu projeye erişim yetkiniz yok.', 404: 'Bu proje bulunamadı.' }[error.status] ?? error.message)
      : 'Proje yüklenemedi.'
  }
}

// The room-type vocabulary comes from the list endpoint's meta, which is also where
// the catalogue gets it — one list, so a bedroom means the same thing on both sides.
const listing = await api.get<{ meta: { room_types: Option[] } }>('/api/v1/projects')
roomTypes.value = listing.meta.room_types

await load()

useHead(() => ({ title: project.value?.name ?? 'Proje' }))

async function addRoom() {
  savingRoom.value = true
  roomError.value = null

  try {
    await api.post(`/api/v1/projects/${projectId}/rooms`, {
      name: roomForm.name,
      room_type: roomForm.room_type,
    })

    addingRoom.value = false
    Object.assign(roomForm, { name: '', room_type: 'living_room' })

    await load()
  } catch (error) {
    roomError.value = error instanceof ApiError
      ? (error.fieldError('name') ?? error.message)
      : 'Oda eklenemedi.'
  } finally {
    savingRoom.value = false
  }
}

async function invite() {
  inviting.value = true
  inviteError.value = null
  inviteLink.value = null

  try {
    const response = await api.post<{ data: { id: string, invitation_token: string } }>(
      `/api/v1/projects/${projectId}/members`,
      { email: inviteForm.email, role: inviteForm.role },
    )

    // The token is returned once and never again. Until invitation e-mails ship in
    // Phase 12, the owner copies the link themselves — which is honest about where the
    // feature is rather than pretending a mail went out.
    inviteLink.value = `${window.location.origin}/projects/invitations/accept`
      + `?member=${response.data.id}&token=${response.data.invitation_token}`

    inviteForm.email = ''
    await load()
  } catch (error) {
    inviteError.value = error instanceof ApiError
      ? (error.fieldError('email') ?? error.message)
      : 'Davet gönderilemedi.'
  } finally {
    inviting.value = false
  }
}

async function revoke(memberId: string) {
  try {
    await api.delete(`/api/v1/projects/${projectId}/members/${memberId}`)
    await load()
  } catch (error) {
    inviteError.value = error instanceof ApiError ? error.message : 'Erişim kaldırılamadı.'
  }
}

async function setStatus(status: string) {
  try {
    await api.patch(`/api/v1/projects/${projectId}/status`, { status })
    await load()
  } catch (error) {
    loadError.value = error instanceof ApiError ? error.message : 'Durum değiştirilemedi.'
  }
}
</script>

<template>
  <div class="space-y-8">
    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <template v-else-if="project">
      <header>
        <NuxtLink to="/projects" class="text-sm text-ink-secondary hover:text-ink">
          ← Projelerim
        </NuxtLink>

        <div class="mt-3 flex flex-wrap items-center justify-between gap-4">
          <div>
            <div class="flex flex-wrap items-center gap-3">
              <h1 class="text-2xl font-medium">{{ project.name }}</h1>
              <RcStatusPill
                v-if="project.status !== 'active'"
                :status="project.status"
                :label="project.status_label"
              />
            </div>
            <p class="mt-1.5 text-sm text-ink-secondary">
              {{ project.project_type_label }}
              <span v-if="project.budget"> · Bütçe {{ project.budget.formatted }}</span>
            </p>
          </div>

          <div v-if="project.is_owner" class="flex flex-wrap gap-2">
            <RcButton
              v-if="project.status === 'archived'"
              size="sm"
              variant="secondary"
              @click="setStatus('active')"
            >
              Arşivden çıkar
            </RcButton>
            <RcButton
              v-else
              size="sm"
              variant="ghost"
              @click="setStatus('archived')"
            >
              Arşivle
            </RcButton>
          </div>
        </div>
      </header>

      <RcAlert v-if="project.status === 'archived'" tone="info">
        Bu proje arşivlendi. Düzenlemek için önce arşivden çıkarın.
      </RcAlert>

      <!-- Rooms -->
      <section>
        <header class="flex flex-wrap items-center justify-between gap-4">
          <h2 class="text-lg font-medium">Odalar</h2>
          <RcButton
            v-if="project.can_edit && !addingRoom"
            size="sm"
            variant="secondary"
            @click="addingRoom = true"
          >
            Oda ekle
          </RcButton>
        </header>

        <form v-if="addingRoom" class="rc-card mt-5 space-y-5 p-6" @submit.prevent="addRoom">
          <RcAlert v-if="roomError" tone="danger">{{ roomError }}</RcAlert>

          <div class="grid gap-4 sm:grid-cols-2">
            <RcField
              v-model="roomForm.name"
              label="Oda adı"
              name="name"
              placeholder="Örn. Salon"
              required
            />

            <div>
              <label for="room_type" class="mb-1.5 block text-sm font-medium">Oda türü</label>
              <select
                id="room_type"
                v-model="roomForm.room_type"
                class="w-full rounded-sm border border-line bg-surface px-4 py-2.5 text-sm"
              >
                <option v-for="type in roomTypes" :key="type.value" :value="type.value">
                  {{ type.label }}
                </option>
              </select>
            </div>
          </div>

          <div class="flex items-center gap-3">
            <RcButton type="submit" size="sm" :loading="savingRoom" :disabled="savingRoom">
              Ekle
            </RcButton>
            <RcButton size="sm" variant="ghost" @click="addingRoom = false">Vazgeç</RcButton>
          </div>
        </form>

        <p v-if="project.rooms.length === 0 && !addingRoom" class="mt-5 rounded-md bg-bg-muted p-6 text-sm leading-relaxed text-ink-secondary">
          Henüz oda eklemediniz. Bir oda ekleyip fotoğrafını yüklediğinizde tasarım
          üretebilirsiniz.
        </p>

        <div v-else-if="project.rooms.length > 0" class="mt-5 grid gap-4 sm:grid-cols-2">
          <NuxtLink
            v-for="room in project.rooms"
            :key="room.id"
            :to="`/projects/${project.id}/rooms/${room.id}`"
            class="rc-card flex flex-col p-5 transition-shadow hover:shadow-md"
          >
            <div class="flex items-start justify-between gap-3">
              <div>
                <h3 class="font-medium">{{ room.name }}</h3>
                <p class="mt-0.5 text-xs text-muted">{{ room.room_type_label }}</p>
              </div>

              <RcStatusPill
                :status="room.is_ready_for_design ? 'approved' : 'draft'"
                :label="room.is_ready_for_design ? 'Tasarıma hazır' : 'Fotoğraf bekliyor'"
                size="sm"
              />
            </div>

            <dl class="mt-4 flex flex-wrap gap-x-5 gap-y-1.5 text-xs text-ink-secondary">
              <div v-if="room.floor_area_m2">
                <dt class="inline text-muted">Alan:</dt>
                <dd class="inline tabular-nums"> {{ room.floor_area_m2 }} m²</dd>
              </div>
              <div>
                <dt class="inline text-muted">Ölçü:</dt>
                <dd class="inline"> {{ room.measurement_quality_label }}</dd>
              </div>
              <div v-if="room.constraint_count > 0">
                <dt class="inline text-muted">Kısıt:</dt>
                <dd class="inline tabular-nums"> {{ room.constraint_count }}</dd>
              </div>
            </dl>
          </NuxtLink>
        </div>
      </section>

      <!-- Sharing -->
      <section v-if="project.is_owner">
        <header class="flex flex-wrap items-center justify-between gap-4">
          <div>
            <h2 class="text-lg font-medium">Paylaşım</h2>
            <p class="mt-1 max-w-[58ch] text-sm leading-relaxed text-ink-secondary">
              Eşinize ya da iç mimarınıza projeyi açabilirsiniz. Davet ettiğiniz kişi
              odalarınızın fotoğraflarını görür; bunu yalnızca güvendiğiniz kişilerle yapın.
            </p>
          </div>

          <RcButton
            v-if="!invitingOpen"
            size="sm"
            variant="secondary"
            @click="invitingOpen = true"
          >
            Kişi davet et
          </RcButton>
        </header>

        <form v-if="invitingOpen" class="rc-card mt-5 space-y-5 p-6" @submit.prevent="invite">
          <RcAlert v-if="inviteError" tone="danger">{{ inviteError }}</RcAlert>

          <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_200px]">
            <RcField
              v-model="inviteForm.email"
              label="E-posta"
              name="email"
              type="email"
              required
            />

            <div>
              <label for="role" class="mb-1.5 block text-sm font-medium">Yetki</label>
              <select
                id="role"
                v-model="inviteForm.role"
                class="w-full rounded-sm border border-line bg-surface px-4 py-2.5 text-sm"
              >
                <option value="viewer">Görüntüleyebilir</option>
                <option value="editor">Düzenleyebilir</option>
              </select>
            </div>
          </div>

          <div class="flex items-center gap-3">
            <RcButton type="submit" size="sm" :loading="inviting" :disabled="inviting">
              Davet oluştur
            </RcButton>
            <RcButton size="sm" variant="ghost" @click="invitingOpen = false">Vazgeç</RcButton>
          </div>
        </form>

        <RcAlert v-if="inviteLink" tone="warning" class="mt-5">
          <p class="font-medium">Davet bağlantısı oluşturuldu</p>
          <p class="mt-1.5 leading-relaxed">
            Bu bağlantı yalnızca bir kez gösterilir ve davet ettiğiniz e-posta adresiyle
            giriş yapan kişi tarafından kullanılabilir. Kendiniz iletmeniz gerekiyor —
            davet e-postaları henüz gönderilmiyor.
          </p>
          <code class="mt-3 block overflow-x-auto rounded-sm bg-surface p-3 text-xs">{{ inviteLink }}</code>
        </RcAlert>

        <ul v-if="project.members.length > 0" class="mt-5 space-y-2">
          <li
            v-for="member in project.members"
            :key="member.id"
            class="rc-card flex flex-wrap items-center justify-between gap-3 p-4 text-sm"
          >
            <div>
              <p class="font-medium">{{ member.name ?? member.email }}</p>
              <p class="text-xs text-muted">
                {{ member.role_label }}
                · {{ member.status === 'active' ? 'kabul etti' : 'davet bekliyor' }}
              </p>
            </div>

            <button
              type="button"
              class="rounded-sm px-2.5 py-1.5 text-xs text-danger hover:bg-danger-subtle"
              @click="revoke(member.id)"
            >
              Erişimi kaldır
            </button>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>
