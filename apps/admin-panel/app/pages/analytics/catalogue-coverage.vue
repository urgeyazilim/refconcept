<script setup lang="ts">
/**
 * Which of the design questions the shop can answer, room by room.
 *
 * A commercial screen, not a technical one. The customer-facing wizard is honest about a
 * thin catalogue — an option nobody stocks is shown disabled with the reason — but honesty
 * on their screen is only half of it. Somebody has to know *which* sellers to go and find,
 * and "the living room feels empty" is not that knowledge. "Salon programındaki 8 sorudan
 * 3'ü karşılanamıyor: tv-unitesi, perde, tablo" is.
 *
 * Every missing category here is a sentence the product is currently having to say to a
 * customer, so the list doubles as a ranked list of sellers worth signing.
 */
definePageMeta({ middleware: 'auth' })

const api = useApi()

interface RoomCoverage {
  room_type: string
  name: string
  questions: number
  answerable: number
  missing_categories: string[]
}

const rooms = ref<RoomCoverage[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)

async function load() {
  try {
    const response = await api.get<{ data: RoomCoverage[] }>('/api/v1/admin/analytics/catalogue-coverage')
    rooms.value = response.data
  } catch (error) {
    loadError.value = error instanceof ApiError ? error.message : 'Kapsam raporu yüklenemedi.'
  } finally {
    loading.value = false
  }
}

await load()

useHead(() => ({ title: 'Katalog kapsamı' }))

/** Every category nobody sells, across all ten rooms, most-wanted first. */
const wanted = computed(() => {
  const counts = new Map<string, number>()

  for (const room of rooms.value) {
    for (const slug of room.missing_categories) {
      counts.set(slug, (counts.get(slug) ?? 0) + 1)
    }
  }

  return [...counts.entries()]
    .sort((a, b) => b[1] - a[1])
    .map(([slug, rooms]) => ({ slug, rooms }))
})

function tone(room: RoomCoverage): string {
  const share = room.questions === 0 ? 1 : room.answerable / room.questions

  // Thresholds rather than a gradient: an operator is scanning for the bad rows, and a
  // colour that shades smoothly from green to red makes every row look the same.
  if (share >= 0.8) return 'text-success'
  if (share >= 0.5) return 'text-warning'

  return 'text-danger'
}
</script>

<template>
  <div class="space-y-8">
    <header>
      <h1 class="text-2xl font-medium">Katalog kapsamı</h1>
      <p class="mt-1.5 max-w-[70ch] text-sm leading-relaxed text-ink-secondary">
        Tasarım sihirbazının sorduğu soruların kaçına cevap verebiliyoruz. Karşılanamayan
        her kategori, müşteriye "bu ürün grubunda henüz satıcımız yok" dediğimiz bir ekran —
        ve aranacak bir satıcı.
      </p>
    </header>

    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>
    <p v-else-if="loading" class="text-sm text-muted">Yükleniyor…</p>

    <template v-else>
      <section class="rc-card overflow-hidden">
        <table class="w-full text-sm">
          <thead class="border-b border-line text-left text-xs uppercase tracking-wide text-muted">
            <tr>
              <th class="px-5 py-3 font-medium">Oda</th>
              <th class="px-5 py-3 font-medium">Cevaplanabilir</th>
              <th class="px-5 py-3 font-medium">Eksik kategoriler</th>
            </tr>
          </thead>

          <tbody>
            <tr v-for="room in rooms" :key="room.room_type" class="border-b border-line/60 last:border-0">
              <td class="px-5 py-3">{{ room.name }}</td>
              <td class="px-5 py-3 tabular-nums" :class="tone(room)">
                {{ room.answerable }} / {{ room.questions }}
              </td>
              <td class="px-5 py-3 text-ink-secondary">
                <span v-if="room.missing_categories.length === 0">—</span>
                <span v-else>{{ room.missing_categories.join(', ') }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <!--
        The same information the other way round: not "which room is thin" but "which seller
        would fix the most rooms". That is the question somebody doing the signing actually
        has, and it is not answerable by reading the table above.
      -->
      <section v-if="wanted.length > 0" class="rc-card p-6 sm:p-8">
        <h2 class="text-lg font-medium">En çok aranan kategoriler</h2>
        <p class="mt-1.5 text-sm text-ink-secondary">
          Kaç odanın sorusunu açıkta bıraktığına göre sıralı.
        </p>

        <ul class="mt-5 flex flex-wrap gap-2">
          <li
            v-for="entry in wanted"
            :key="entry.slug"
            class="rounded-pill border border-line px-3 py-1.5 text-sm"
          >
            {{ entry.slug }}
            <span class="ml-1 text-xs tabular-nums text-muted">{{ entry.rooms }} oda</span>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>
