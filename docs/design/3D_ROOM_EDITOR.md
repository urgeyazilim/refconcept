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
Three.js turns the other way, so `FurnitureBuilder` negates it.

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

- **No translate gizmo.** Furniture is moved by dragging it and nudged with the arrow keys —
  a centimetre a press, ten with shift. A three-arrow gizmo is a second input system to keep
  in step with snapping and collision, for a gesture the pointer already does.
- **Products are not hand-modelled.** See below.

## How a product is drawn

Four ways, in order of preference, each falling back to the next:

1. **The seller's own glTF binary**, if they have one from their manufacturer. It is the
   shape of the thing.
2. **A mesh generated from the product photograph** — fal.ai / Tripo 2.5, thirty cents, once
   per product, queued when a listing is approved. A likeness: its far side was never
   photographed. Used only in the planner and never in a render, and an operator can throw it
   away without touching what the seller uploaded.
3. **The photograph, cut out of its background**, standing on its footprint slab and turning
   to face the camera as it narrows to the silhouette the real piece would present.
4. **A plain box**, when the photograph cannot be read at all, or was taken in a room rather
   than on a studio sweep — keying that would eat holes in the furniture.

**Every one of them is scaled to the SKU's recorded dimensions**, never to what the file or
the generator thought. A beautiful model at the wrong size is worse than a box: it looks
convincing and it does not fit.

## What is not built yet
- The snapshot conditions the render as a reference image, which is weaker than depth-conditioned
  generation (ControlNet on SD/Flux). That would need a third provider and is the next real
  step for wall fidelity.
- Kitchen-specific rules (worktop runs, appliance clearances) are not in the composer.
