# The 3D room editor

A room the customer can measure, furnish and walk around, and — the part that matters
commercially — a structure the renderer has to follow.

## Why it exists

The design pipeline took a photograph and a list of furniture and asked a photorealistic model
to put one into the other. It did, and it also rearranged the room: a doorway narrowed and
moved, a wall segment appeared, the window wall slid across, and a sofa nobody sells was added
to a seating area the plan had deliberately left empty. The customer saw their own flat
containing furniture that does not exist, priced beside furniture that does.

Prompt rules helped and did not fix it. The model is not disobeying. It is resolving a scene
we left underdetermined, and a photorealistic model resolves ambiguity in favour of the
picture. The fix is to stop leaving it ambiguous: agree the room's measurements with the
customer, place every product at its real size, and hand the renderer that as structure.

## The pieces

**Server**

| File | What it decides |
| --- | --- |
| `Projects/Services/LayoutGeometry.php` | Whether a piece is somewhere the room will take it. The copy that decides. |
| `Projects/Services/LayoutComposer.php` | Where furniture goes, in millimetres, from a design's decisions. |
| `Projects/Services/LayoutComposerState.php` | What is already in the room while it is being arranged. |
| `Projects/Services/ComposableProducts.php` | Which products a design settled on, with sizes and wall hints. |
| `Projects/Services/RoomGeometryProposer.php` | Turns the analysis's estimate into measurements to agree to. |
| `Projects/Services/LayoutWriter.php` | Writes a layout down and recomputes its collision states. |
| `Projects/Http/Controllers/RoomLayoutController.php` | The endpoints, all nested under the project. |

**Browser** (`apps/storefront/app/room3d`)

| File | What it does |
| --- | --- |
| `types.ts` | Units and shapes. One scene unit is one metre; the API speaks millimetres. |
| `footprint.ts` | The floor-plan arithmetic — a deliberate copy of `LayoutGeometry`. |
| `CollisionEngine.ts` | The same rules, answered in the frame the pointer moves. |
| `SnapEngine.ts` | Pulls a drag onto walls, edges and centre lines. |
| `MeasurementEngine.ts` | The four gaps around the selected piece. |
| `RoomGeometryBuilder.ts` | Walls, floor and ceiling with real holes cut for openings. |
| `FurnitureBuilder.ts` | Boxes at the SKU's dimensions, wearing the product photograph. |
| `DragController.ts` | Press, move, release. |
| `SceneManager.ts` | Canvas, renderer, dirty-flag render loop, occlusion. |
| `CameraManager.ts` | Üstten / Perspektif / İçeriden. |
| `RoomEditor.ts` | Owns the truth while the page is open; undo, autosave, selection. |

`components/Room3DScene.vue` is the seam — it owns a canvas and holds no layout state.
`components/RoomPlanSvg.vue` is the 2D plan, in SVG so the labels are text.

## Conventions that must not drift

**Millimetres and integers, everywhere.** A coordinate that drifts by a float's last bit
between save and load is a sofa that has moved slightly every time the page opens, and nobody
can say why. Metres appear only inside the Three.js scene, converted at one boundary
(`MM_PER_UNIT`).

**The origin is the corner where the north and west walls meet.** `width` runs along x,
`length` along z, y upwards.

**An opening's `offset_mm` runs along its wall's own axis, from the origin** — north and south
from x = 0, east and west from z = 0, the same direction on all four walls. Not "from the left
seen from inside", which sounds friendlier and reverses on two walls out of four: a window 720
mm along the south wall then lands 720 mm from the opposite corner, which looks like a mirrored
model rather than an arithmetic mistake and survives a screenshot review easily.

**Rotation is clockwise seen from above**, which is how anybody describes turning a sofa.
Three.js turns the other way, so `FurnitureBuilder` negates it. Every model's front is +z, so
0 faces south, 180 north, **90 faces west and 270 faces east** — a piece against the west wall
looking into the room is at 270. Both wall-to-rotation maps (`LayoutComposer::onWall` and
`footprint.ts againstWall`) had this the other way round until 2026-09-15; symmetric boxes hid
it, a coffee table "in front of" an east-wall sofa did not.

**Two copies of the collision rules, and they have to agree.** The browser's answer has to
arrive while somebody is dragging; the server's is the one that decides, because nothing
arriving over HTTP can be trusted — least of all the collision flags the client computed.
`CollisionEngine.spec.ts` is deliberately the same cases as `LayoutGeometryTest.php`, in the
same order, with the same numbers. Change a rule on one side and the other side's test fails.

**An unmeasured variant has no footprint.** Not a guessed one. It is drawn as a placeholder
that reads as "we do not know how big this is", it collides with nothing, and the composer
reports it rather than placing it. A box at an invented size is a promise that it fits, made on
no evidence, to somebody about to pay for a delivery.

## The flow

1. **Analysis** estimates the room's size and openings from the photograph
   (`room_analysis` prompt v3: measurements, openings, and boxes on the photograph) and `RoomGeometryProposer` writes them as an **unconfirmed**
   `room_geometry_versions` row.
2. **The customer is asked**: "Bu ölçüler doğru mu?" — `[Evet, devam et]` or `[Düzelt]`.
   Confirming writes the figures back onto the room and adopts the detected openings, but only
   into a room that has none of its own.
3. **The editor** loads geometry, openings and layout in one request, and autosaves about a
   second after the last change.
4. **`POST layout/compose`** arranges the products the latest design settled on. It spends no
   credits — the design was paid for and this is arithmetic.
5. **`POST layout/snapshot`** keeps a picture of the plan on the private disk.
6. **The next render** is handed that picture as its second image, named in `image_roles`, and
   told the walls, openings and furniture positions come from it.

## Privacy

The snapshot is a drawing rather than a photograph and that changes nothing: the walls are the
customer's walls, the windows are where their windows are. It lives on the private disk with
their photographs, under the same rules, with a random key, and **no response ever carries a
path to it**. There is no model and no table row — one layout has one current snapshot, it is
regenerated whenever the furniture moves, and a file nothing points at is rubbish rather than
a record.

## Against the storyboard

The product storyboard has six panels. Five are built as drawn: the photograph, the analysis
with its boxes on the picture and "Bu ölçüler doğru mu?", the 3D room with Üstten / Perspektif
/ İçeriden, the catalogue in the room, the editing tools (align to wall, centre, duplicate,
height, lock, measurements on or off), and the final image produced from the plan.

Two differences, both deliberate:

- **A gizmo after all.** The first version had none — "a second input system to keep in step
  with snapping and collision" — and the product owner's verdict was that it could not "turn
  or place like professional 3D". `GizmoController.ts` wraps Three.js's TransformControls:
  arrows along the floor, a ring about the vertical, the other axes switched off. It moves the
  group freely and the editor reads the result back in millimetres, snaps it, holds it inside
  the walls and off the other pieces, and writes it to the group again — so the piece stops
  at the wall with the arrow still in the customer's hand. Turns snap to 15°; Shift frees
  them; R switches between move and turn. Dragging the piece itself still works.
- **Products are not hand-modelled.** See below.

## How the room is drawn

`RoomGeometryBuilder.ts` extrudes each wall from a 2D outline with the openings punched out,
then dresses it: skirting along the foot (broken at doorways), a casing round every opening,
a leaf standing 30° open with its swing arc on the floor for a door, and a sill, glass, a
mullion and a wall-sized daylight sheet outside for a window. Every fixture is a child of its
wall, built in the wall's own 2D space (x along the wall, y up, z through it), so it goes
where the wall goes and hides when the wall hides. `inward` and `innerZ` say which way the
room is from each wall's local frame — the four walls are placed with only two orientations
so an offset always runs the same way as its world axis, which is what the server assumes.

`RoomMaterials.ts` paints the oak boards and the plaster on a canvas at load time — nothing
to fetch, nothing to 404 — and `SceneManager` lights the room with Three's room environment
plus one sun.

## How a product is drawn

Four ways, in order of preference, each falling back to the next:

1. **The seller's own glTF binary**, if they have one from their manufacturer. It is the
   shape of the thing.
2. **A mesh generated from the product photographs** — fal.ai / Tripo 2.5, thirty cents, once
   per product, queued when a listing is approved. A likeness: whatever side was not
   photographed is a guess. Used only in the planner and never in a render, and an operator can
   throw it away without touching what the seller uploaded.

   **One photograph or four, at the same price.** `product_media.view` holds `front`, `left`,
   `back` or `right`; given more than one the adapter posts to Tripo's multi-view endpoint
   instead of the single-image one, and the generator stops inventing a back. A partial unique
   index keeps one photograph per side, and naming a side that another photograph holds moves
   it there in the same transaction, because that is what a seller correcting themselves means.

   Sellers label their own photographs in the portal. For the ones nobody labelled,
   `ProductViewTagger` asks a vision model and is allowed to answer "I don't know": under 0.7
   confidence, or anything that is not one of the four sides, is left blank. A photograph
   mislabelled as the back is worse than a missing one — the generator fuses two fronts and
   produces something that is not furniture. **A seller's own label is never overruled**, and
   the simulator deliberately labels nothing: a fabricated label would be written once and
   would then block the real answer forever, because a product with any label is never asked
   about again.
3. **A shape of the product's kind, in the product's own colour** — a seat with a back and
   arms, a top on legs, a carcass on a plinth — at the SKU's exact size, casting a shadow,
   facing the way the piece faces. `PlaceholderShapes.ts` maps the category slug to one of
   eleven shapes; the colour is the photograph's dominant one, pulled towards a mid tone.
   Plain on purpose: it is a stand-in and looks like one.

   Two earlier answers are gone and must not come back. A box with the photograph on its
   front was "a warehouse of cartons". The photograph cut out and stood up on its footprint,
   turning to face the camera, was a flat picture — and in the product owner's own screenshot
   a round table on a red rug became a red plate. A picture is not a thing in a room.

**Furniture cannot leave the room or enter another piece.** `ConstraintEngine.ts` runs on every
drag frame, arrow-key nudge and rotation: the rotated footprint is clamped inside the walls,
an overlap is resolved by the shortest push out, and a position with no answer leaves the
piece where it was. The collision engine still runs behind it and the server still decides —
with the constraint in front, "blocked" now means a bug rather than a state.

**Every one of them is scaled to the SKU's recorded dimensions**, never to what the file or
the generator thought. A beautiful model at the wrong size is worse than a box: it looks
convincing and it does not fit.

## What is not built yet
- The snapshot conditions the render as a reference image, which is weaker than depth-conditioned
  generation (ControlNet on SD/Flux). That would need a third provider and is the next real
  step for wall fidelity.
- Kitchen-specific rules (worktop runs, appliance clearances) are not in the composer.

## Professional pass (2026-09-16)

What the editor gained in one day, in the order the owner would notice it:

- **Doors and windows are things you pick up.** A palette (Kapı, Pencere, Balkon kapısı)
  puts one in the room; it is taken by its casing or glass in 3D and follows the pointer onto
  whichever wall it is over (`OpeningDragController`, registered ahead of the furniture drag;
  hidden walls are skipped by the ray). On the plan the same drag crosses walls and a handle
  at each end resizes. No numeric form remains.
- **The camera flies** (`CameraManager.flyTo/focusOn/reframe`): between perspective views,
  in to a double-clicked piece, back out on "Odayı sığdır". Cuts only to and from the plan.
- **Hover, footprint, contact shadow** (`FurnitureBuilder.paint(…, hovered)`, `shadow` and
  `footprint` children of every floor-standing piece).
- **Wall names** as HTML overlay labels (`RoomEditor.wallLabels`), hidden with the wall.
- **Toolbar on the room** for the selected piece; Delete / Ctrl+D / Esc / R / arrows; keys in
  fields beside the scene are left alone.
- **Running total** (K20) with each piece's photograph, size and price; "Render al" and the
  basket beside it. The layout endpoint sends `price`.
- Test hooks on the editor for the browser tests: `openingsNow()`, `openingScreenPoint(id)`,
  `wallScreenPoint(wall, along, height)`.

Still open: SSAO/quality toggle, non-rectangular rooms, resizing openings in 3D (only on the
plan today), a right-hand catalogue column beside the scene.
