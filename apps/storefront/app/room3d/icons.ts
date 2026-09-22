/**
 * The line drawings the room's tools are labelled with.
 *
 * One place, because an icon is a word: "this one means turn" has to be true on the toolbar,
 * in the inspector and in the shortcut list, and three copies of a path drift until it is not.
 *
 * All 24 × 24, all stroked rather than filled, all drawn on the same grid — a 2 px margin, a
 * 1.6 px stroke, round caps. Mixing a filled icon into a stroked set reads as a mistake even
 * to somebody who could not say why, and this set sits next to the door and window drawings
 * in {@link ./openings}, which are stroked for the same reason.
 *
 * The names say what the thing does, not what it looks like: `rotateLeft`, not `arrowCircle`.
 * Somebody adding a button should be able to guess the name from the tooltip they are writing.
 */
export const ICONS = {
  /** Turn anticlockwise: an arc with the arrowhead on its leading end. */
  rotateLeft: 'M4 12a8 8 0 1 1 2.6 5.9M4 12V7M4 12h5',

  /** Turn clockwise. */
  rotateRight: 'M20 12a8 8 0 1 0-2.6 5.9M20 12V7M20 12h-5',

  /** Put it against the nearest wall: a block pushed up to a line. */
  alignWall: 'M3 3v18M7 8h11v8H7zM7 12h11',

  /** Put it in the middle of the room: a block with the room's axes through it. */
  centre: 'M12 3v4M12 17v4M3 12h4M17 12h4M9 9h6v6H9z',

  /** One more of the same: two overlapping rectangles. */
  duplicate: 'M9 9h11v11H9zM5 15H4V4h11v1',

  /** Locked: a closed shackle. */
  lock: 'M6 11h12v9H6zM9 11V8a3 3 0 0 1 6 0v3',

  /** Unlocked: the same shackle, open on one side. */
  unlock: 'M6 11h12v9H6zM9 11V8a3 3 0 0 1 6 0',

  /** Look closer at this one: a frame closing in on a point. */
  focus: 'M4 8V4h4M16 4h4v4M20 16v4h-4M8 20H4v-4M10 12h4',

  /** Remove it: a bin with a lid. */
  remove: 'M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v5M14 11v5',

  /** Undo: an arrow curving back on itself. */
  undo: 'M4 9h11a5 5 0 0 1 0 10h-6M4 9l4-4M4 9l4 4',

  /** Redo. */
  redo: 'M20 9H9a5 5 0 0 0 0 10h6M20 9l-4-4M20 9l-4 4',

  /** Zoom in: a lens with a plus. */
  zoomIn: 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14zM16 16l4 4M11 8v6M8 11h6',

  /** Zoom out. */
  zoomOut: 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14zM16 16l4 4M8 11h6',

  /** Fit the whole room in the frame: corners closing on a room. */
  fitRoom: 'M3 8V3h5M16 3h5v5M21 16v5h-5M8 21H3v-5M8 9h8v7H8z',

  /** The plan: a floor seen from directly above, with a door in one wall. */
  plan: 'M3 4h18v16H3zM3 14h5M14 4v5',

  /** From above, in three dimensions: a box lid. */
  top: 'M12 3l9 5-9 5-9-5zM3 8v8l9 5 9-5V8',

  /** At an angle: a box drawn in perspective. */
  perspective: 'M12 3l9 5v8l-9 5-9-5V8zM12 13l9-5M12 13v8M12 13L3 8',

  /** Standing in the room: a figure between two walls. */
  inside: 'M5 3v18M19 3v18M12 8a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM12 8v7M9 21l3-6 3 6M9 12h6',

  /** The measurements: a ruler with its ticks. */
  ruler: 'M3 9h18v6H3zM7 9v3M11 9v3M15 9v3M19 9v3',

  /** The room as the photographs measured it: a cloud of points. */
  scan: 'M6 6h.01M12 5h.01M18 7h.01M5 12h.01M11 11h.01M17 12h.01M7 17h.01M13 18h.01M19 17h.01',

  /** A door or a window, for the palette's own handle. */
  openings: 'M4 3h7v18H4zM9 12h1M14 5h6v10h-6zM14 10h6',

  /** What the keys do: a keyboard. */
  keys: 'M3 6h18v12H3zM7 10h.01M11 10h.01M15 10h.01M8 14h8',

  /** Close this. */
  close: 'M6 6l12 12M18 6L6 18',
} as const

export type IconName = keyof typeof ICONS
