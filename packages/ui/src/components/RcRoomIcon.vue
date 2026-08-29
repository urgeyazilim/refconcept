<script setup lang="ts">
/**
 * The picture on a room-programme option.
 *
 * The whole premise of the guided brief is that people choose furniture by looking at it.
 * The design page used to ask "İstekleriniz" into a blank textarea and almost nobody could
 * answer — not for want of taste, but because describing a living room is a professional's
 * skill. Replace that with a wall of words on tiles and nothing has changed; the tile has
 * to show the thing.
 *
 * Drawn rather than photographed. A photograph of a three-seater sofa is a photograph of
 * one particular sofa, and a customer choosing "üçlü kanepe" is choosing a shape, not that
 * sofa — showing a real one would set an expectation the shopping list then breaks. Line
 * drawings say "this kind of thing" and nothing more.
 *
 * Every drawing sits on a 24×24 grid with a 1.5 stroke, so a row of them reads as one set
 * rather than as clip-art assembled from three places. Where two options differ only in
 * count — one nightstand or two, two bar stools or four — the drawings differ only in
 * count, because that is the entire question being asked.
 */
withDefaults(
  defineProps<{
    /** The `icon` a programme option carries — `sofa-three`, `rug-large`, `skip`. */
    name: string
    size?: 'sm' | 'md' | 'lg'
  }>(),
  { size: 'md' },
)

const sizes = {
  sm: 'size-6',
  md: 'size-10',
  lg: 'size-14',
} as const

/**
 * Path data for every option the ten room programmes use.
 *
 * Kept here rather than as files: fifty-one small drawings as fifty-one network requests
 * is a page that assembles itself in front of the customer, and they are each three lines
 * of geometry. The key is the string the seeder writes, so an option with no drawing is a
 * missing key rather than a broken image — and falls back to a plain square, which reads
 * as "something", not as a failure.
 */
const paths: Record<string, string> = {
  // --- seating ---------------------------------------------------------------
  'sofa-three': 'M3 11v6M21 11v6M3 13h18M6 13v-2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v2M9 13V9M15 13V9M5 17v2M19 17v2',
  'sofa-corner': 'M3 6v11M3 17h13M3 8h5a2 2 0 0 1 2 2v7M12 12h7a2 2 0 0 1 2 2v3M12 12v5M5 19v-2M19 19v-2',
  'sofa-two-chairs': 'M3 12v5M11 12v5M3 14h8M5 14v-2h4v2M14 13v4M20 13v4M14 15h6M15 15v-2h4v2',
  armchair: 'M7 11v6M17 11v6M7 13h10M9 13v-2a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M8 17v2M16 17v2',
  pouffe: 'M6 12h12v4a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2zM6 14h12',
  chair: 'M9 4v9M15 4v9M9 8h6M8 13h8v2H8zM9 15v5M15 15v5',
  'chair-2': 'M5 5v7M9 5v7M5 8h4M4 12h6v2H4zM5 14v5M9 14v5M15 5v7M19 5v7M15 8h4M14 12h6v2h-6zM15 14v5M19 14v5',
  'stool-2': 'M5 10h5v2H5zM6 12v7M9 12v7M14 10h5v2h-5zM15 12v7M18 12v7',
  'stool-4': 'M3 9h4v2H3zM4 11v4M6 11v4M9 9h4v2H9zM10 11v4M12 11v4M15 9h4v2h-4zM16 11v4M18 11v4M21 13v6M3 17h16',

  // --- tables ----------------------------------------------------------------
  'table-coffee': 'M4 11h16v2H4zM6 13v5M18 13v5M4 12h16',
  'table-coffee-side': 'M3 12h11v2H3zM5 14v4M12 14v4M17 9h5v2h-5zM18 11v7M21 11v7',
  'table-side': 'M8 9h8v2H8zM10 11v8M14 11v8M9 15h6',
  'table-4': 'M4 10h16v2H4zM6 12v6M18 12v6M8 6v3M16 6v3M8 19v3M16 19v3',
  'table-6': 'M3 10h18v2H3zM5 12v6M19 12v6M7 6v3M12 6v3M17 6v3M7 19v3M12 19v3M17 19v3',
  'table-8': 'M2 10h20v2H2zM4 12v6M20 12v6M6 6v3M10 6v3M14 6v3M18 6v3M6 19v3M10 19v3M14 19v3M18 19v3',
  desk: 'M3 10h18v2H3zM5 12v8M19 12v8M11 12v4h8v-4',
  'desk-double': 'M2 10h20v2H2zM4 12v8M20 12v8M9 12v4h6v-4M12 4v6',
  island: 'M4 9h16v3H4zM5 12v8M19 12v8M4 15h16M10 6v3M14 6v3',
  worktop: 'M3 10h18v3H3zM3 13v7h18v-7M8 13v7M16 13v7M11 16h2',

  // --- storage ---------------------------------------------------------------
  'tv': 'M3 6h18v10H3zM8 20h8M12 16v4M6 9h6',
  console: 'M3 9h18v7H3zM3 12h18M9 9v7M15 9v7M5 16v3M19 16v3',
  bookcase: 'M5 3h14v18H5zM5 8h14M5 13h14M5 18h14M8 4v3M11 4v3',
  cabinet: 'M5 4h14v16H5zM12 4v16M9 11h1M14 11h1M7 20v2M17 20v2',
  wardrobe: 'M4 3h16v18H4zM12 3v18M10 11h1M14 11h1M4 7h16',
  nightstand: 'M7 9h10v10H7zM7 13h10M11 11h2M11 16h2',
  'nightstand-pair': 'M2 10h7v8H2zM2 13h7M5 11h1M15 10h7v8h-7zM15 13h7M18 11h1',
  'storage-both': 'M3 4h8v16H3zM3 9h8M3 14h8M14 10h7v9h-7zM14 13h7M17 11h1',

  // --- beds ------------------------------------------------------------------
  'bed-single': 'M3 10v8M21 14v4M3 14h18M5 14v-3a1 1 0 0 1 1-1h9M7 12h3',
  'bed-double': 'M2 9v10M22 13v6M2 13h20M4 13v-3a1 1 0 0 1 1-1h14v4M6 11h4M14 11h4',
  'bed-king': 'M1 8v12M23 12v8M1 12h22M3 12v-3a1 1 0 0 1 1-1h16v4M5 10h5M14 10h5',
  'bed-bunk': 'M3 3v18M21 3v18M3 9h18M3 18h18M5 9V6h14v3M5 18v-3h14v3M3 12h18',
  bedding: 'M3 8h18v12H3zM3 12h18M8 8V6a4 4 0 0 1 8 0v2',

  // --- soft furnishings ------------------------------------------------------
  'rug-large': 'M2 7h20v11H2zM4 9h16v7H4zM2 7l2 2M22 7l-2 2M2 18l2-2M22 18l-2-2',
  'rug-medium': 'M5 8h14v9H5zM7 10h10v5H7zM5 8l2 2M19 8l-2 2',
  curtain: 'M3 3h18M5 3v18c2-1 2-3 2-6s0-6-2-8M19 3v18c-2-1-2-3-2-6s0-6 2-8M12 3v18',
  cushion: 'M6 7h12a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1zM7 9l2 2M17 9l-2 2',

  // --- lighting --------------------------------------------------------------
  'light-pendant': 'M12 2v6M7 14a5 5 0 0 1 10 0zM7 14h10M9 17h6',
  'light-floor': 'M12 21v-13M8 8h8l-2-4h-4zM9 21h6',
  'light-table': 'M12 20v-8M8 12h8l-1.5-5h-5zM9 20h6',
  'light-wall': 'M4 4v16M4 11h4M8 8h6l2 6h-8zM12 14v4',
  'light-both': 'M6 2v4M3 11a3 3 0 0 1 6 0zM3 11h6M18 21v-9M15 12h6l-1.5-4h-3z',

  // --- decoration ------------------------------------------------------------
  art: 'M4 4h16v14H4zM7 14l3-4 2.5 3L16 9l3 5M9 8a1 1 0 1 0 0 .01',
  mirror: 'M12 3a5 7 0 1 0 0 14 5 7 0 1 0 0-14zM9 7a4 5 0 0 1 3-2M9 20h6M12 17v3',
  vase: 'M9 3h6M10 3c0 3-3 4-3 8a5 5 0 0 0 10 0c0-4-3-5-3-8M9 13h6',
  plant: 'M12 21v-9M12 12c-4 0-6-2-6-6 4 0 6 2 6 6zM12 12c4 0 6-2 6-6-4 0-6 2-6 6zM9 21h6',
  accessories: 'M4 6h5v12H4zM12 9h3v9h-3M18 4v14M16 18h4',

  // --- bathroom --------------------------------------------------------------
  basin: 'M4 12h16a8 8 0 0 1-8 6 8 8 0 0 1-8-6zM12 12V6a2 2 0 0 1 4 0M12 18v3',
  vanity: 'M4 11h16v8H4zM4 14h16M12 11v8M6 19v2M18 19v2M9 6a3 3 0 0 1 6 0v5',

  // --- ages ------------------------------------------------------------------
  'age-toddler': 'M12 6a3 3 0 1 0 0 .01M8 21c0-4 1-7 4-7s4 3 4 7M10 12h4',
  'age-child': 'M12 4a3 3 0 1 0 0 .01M12 8v8M8 11h8M9 21l3-5 3 5',
  'age-teen': 'M12 3a3 3 0 1 0 0 .01M12 7v9M7 10h10M8 21l4-5 4 5',

  // --- the way out -----------------------------------------------------------
  // Deliberately not a cross. "Şimdilik istemiyorum" is a choice, not a cancellation,
  // and an X beside three pieces of furniture reads as "close this question". A dash in
  // a circle reads as "none of these", which is what the customer is actually saying.
  skip: 'M12 3a9 9 0 1 0 0 18 9 9 0 1 0 0-18zM8 12h8',
}

const fallback = 'M5 5h14v14H5z'
</script>

<template>
  <svg
    :class="sizes[size]"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.5"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
  >
    <path :d="paths[name] ?? fallback" />
  </svg>
</template>
