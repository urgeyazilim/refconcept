# 3D Planner v2 — closing the gap to the storyboard

Status: proposal, 2026-09-15. Written after the product owner compared the live editor
with the six-panel storyboard and rejected the result: "tree js çok sağlıklı çalışmıyor;
profesyonel 3D gibi çeviremiyor, yerleştiremiyor; odanın dışına çıkabiliyor, duvarların
içine girebiliyor; ben bu şekilde istemedim."

## 1. What is actually wrong (an honest diagnosis)

Three.js is not the problem. Roomle, Coohom's web viewer, Homestyler and most browser
planners are WebGL and several are Three.js. The problem is the level at which the
current editor was built. Measured against the storyboard, panel by panel:

| Storyboard promise | What exists today | Gap |
|---|---|---|
| **3** A room with real windows and doors in the walls, wood floor, baseboards, lighting | Walls with holes cut, flat colour materials, one sun + shadows | No door/window frames or glass, no PBR floor/wall materials, no environment lighting. Reads as a diagram, not a room. |
| **4** Every product a real 3D object; a green/red/blue move gizmo; a category browser | One product in three has a mesh; the rest are **photograph cut-outs** on a slab (the red table in the owner's screenshot). No gizmo — drag only | The cut-outs are the single biggest reason it does not look professional. They cannot be fixed; they must go. |
| **5** "Ürünler duvarların içine giremez, çarpışma kontrolü yapılır"; free rotate ring; distances to walls | Collision is **flagged** (red state) but **not prevented** — a drag can leave the room or cross a wall. Rotation is 90° buttons only | Constraint must be hard: the piece stops at the wall and slides along it. Free rotation with a ring and 15° snapping. |
| **2** AI reads walls, windows, doors, and draws them on the photo; user confirms or edits | Exists (estimate + confirm), rectangles only | Non-rectangular rooms and dragging openings on the plan are missing. |
| **6** Final render conditioned on the plan | Exists | Fine; quality improves automatically as the plan improves. |

Two of the gaps are engineering effort; one is money (assets). None needs a new engine.

## 2. How the professionals do it

- **Assets.** Every product is a real glTF with PBR textures, typically 5–30k triangles,
  Draco/meshopt compressed, KTX2 textures, normalised to the catalogue's dimensions, with
  a known "front". HomeByMe carries manufacturer models (IKEA and others); Roomle has
  manufacturers upload configurable models. Nobody ships photograph billboards.
- **Placement.** Objects move on the floor plane with a gizmo; the room is a polygon and
  the object's rotated footprint is clamped inside it; overlaps are resolved by push-out
  or refusal; walls are magnetic; some categories are wall-mounted (pictures, shelves,
  TV) and some stack (lamp on table, rug under sofa).
- **Room capture.** IKEA Kreativ (Geomagical Labs) reconstructs the room from a series of
  photographs with stereo vision and trained networks, then lets the customer erase
  existing furniture and place new items in the reconstructed scene. That is a research
  team's product; our single-photo estimate + confirmation is the pragmatic version and
  is the right one to keep, with a better editor for the plan itself.
- **Rendering.** Environment-mapped PBR, contact shadows, ACES tone mapping, a
  first-person view, and a 2D plan kept in sync with the 3D scene.

Sources: [Meshy/Tripo/Rodin/Hunyuan comparison](https://ideate.xyz/blogs/posts/ai-3d-model-comparison-trellis-tripo-meshy-rodin-hunyuan),
[3D generation APIs 2026](https://www.3daistudio.com/blog/best-3d-model-generation-apis-2026),
[archviz ranking](https://visiomake.com/en/blog/best-ai-image-to-3d-tools-2026-comparison-archviz),
[Coohom pricing](https://www.coohom.com/pricing), [HomeByMe](https://sourceforge.net/software/product/Homebyme/),
[Roomle](https://www.roomle.com/en/floorplanner), [IKEA Kreativ / Geomagical](https://www.ikea.com/us/en/newsroom/corporate-news/ikea-launches-new-ai-powered-digital-experience-empowering-customers-to-create-lifelike-room-designs-pub58c94890/),
[TechCrunch on Kreativ](https://techcrunch.com/2022/06/22/ikea-rolls-out-an-ai-powered-interactive-design-experience-for-shoppers/).

## 3. Three ways forward

| Path | What it buys | What it costs | Verdict |
|---|---|---|---|
| **A. Build on what exists (Three.js)** with the libraries professionals use: `TransformControls`, `three-mesh-bvh` (fast ray/overlap), `three-bvh-csg` (frames cut into walls), `postprocessing` (SSAO, bloom), `RoomEnvironment`, `gltf-transform` server-side | Full control, no lock-in, our catalogue, our data model already in place | ~4–6 weeks of focused work, in the phases below | **Recommended.** The 3,700 lines already written are the data model and the fallbacks; the scene and interaction layers get rewritten. |
| **B. Switch to Babylon.js** | Gizmo manager, CSG2, Havok physics and PBR ship in the box; faster to a "professional feel" | Rewrite of all 3,700 lines, two engines' worth of knowledge in one repo, same asset problem | Only if A stalls. The engine is not what the owner is unhappy about. |
| **C. Embed a commercial planner SDK** (Roomle Room Designer, Coohom/Homestyler API) | Professional editor on day one | Per-seat/per-view licensing (undisclosed; enterprise quotes), our products must be converted into their format anyway, no control over the checkout/credit/AI flows that are RefConcept's actual product | Not recommended for a marketplace whose differentiator is its own catalogue and AI pipeline. |

**Whichever path: the asset problem is the same and must be solved first.** A professional
editor full of photograph cut-outs is still cut-outs.

## 4. The plan (Path A)

### Phase 0 — Stop the bleeding (2 days, no cost) — DONE 2026-09-15
- Hard constraints in `DragController`/`CollisionEngine`: rotated footprint (OBB) clamped
  inside the room polygon inset by wall thickness; overlap with another OBB is refused and
  the piece slides to the nearest free position along the wall (separating-axis push-out).
  The red "collision" state stays only for server-side disagreement.
- Cut-outs are demoted: a product with no mesh is drawn as a **parametric placeholder**
  per category (sofa = seat + back + arms box set; table = top + legs; wardrobe = box with
  door seams) with the photograph as a front-face texture only. Ugly but solid; never a
  billboard again.
- Unit tests for both, mirrored server-side in `LayoutGeometry`.

### Phase 1 — Every product a real mesh (1 week + asset budget) — IN PROGRESS

Done 2026-09-15: seller GLB upload screen; `mesh-tools` sidecar (gltf-transform + meshopt:
101k → 20k faces, 3.3 MB → 262 KB on the first armchair); the adapter speaks Tripo 2.5/H3.1,
Rodin 2.5 and Hunyuan3D v3; `refconcept:model-bakeoff` and `/lab/model-bakeoff`. Bake-off
running (10 products × 4 generators ≈ $15.25). Open: the owner's verdict, repointing the
route, the seller-portal completeness hint.
- **Seller upload first**: the GLB upload API exists; add the seller-portal screen and
  make it a completeness item ("3B model yükleyin veya biz üretelim").
- **Generation for the rest**, on approval, through the existing gateway. Tripo 2.5 works
  (first model landed 2026-09-15). Evaluate Meshy 6 and Rodin Gen-2.5 on ten products of
  ours: the 2026 comparisons put Meshy first for texture quality on furniture and Rodin
  first for geometry, both at similar per-model cost. Pick one per category if they
  differ. Multiview (front/left/back/right) stays — it is what stops invented backs.
- **Post-processing pipeline** (`gltf-transform`, server-side, in `ProductModelStorage`):
  decimate to ≤20k triangles (Tripo ignored `face_limit`: 101k delivered), Draco or
  meshopt, KTX2/Basis textures, re-centre to the ground plane, scale to SKU dimensions,
  detect the front (the face that best matches the front photograph) and bake the
  rotation. Target ≤1.5 MB per product; the current 3.3 MB armchair is too heavy for a
  ten-product room.
- Cost: ~$0.30–0.50 per product, once. The catalogue owner decides how many, and when.

### Phase 2 — Interaction that feels like a planner (1.5 weeks) — IN PROGRESS

Done 2026-09-15: move/turn gizmo (TransformControls, floor axes only, 15° steps, Shift free,
R toggles) held by the same snap + constraint pipeline as a drag; outline (SAT) collision on
both sides so a turned piece is judged by its outline, not its box; a browser test drives the
ring and the arrow and proves the wall stops the piece. Open: magnets for turned pieces,
"eşit mesafe", category rules (wall-mounted, stacking), touch gestures for the piece.
- `TransformControls` in translate-XZ and rotate-Y modes with the storyboard's look
  (arrows on select, ring on "Döndür"); 15° rotation snapping, Shift for free.
- Magnet-to-wall and to neighbours (exists in `SnapEngine`; extend to rotated pieces),
  "Duvara hizala", "Eşit mesafe" between three pieces.
- Live dimension callouts to the two nearest walls and the nearest piece (exists; make it
  follow rotation), plus a "Geçiş: 110 cm" passage measure between pieces.
- Category rules: wall-mounted (picture, shelf, TV unit) snap to a wall at a set height;
  rugs go under; lamps and décor stack on tables and sideboards (surface detection from
  the mesh's top face).
- Keyboard: arrows nudge (exists), R rotates, Delete, Ctrl+D duplicate, Ctrl+Z/Y (exists).
- Touch: single-finger drag, two-finger rotate for the piece, pinch for the camera.

### Phase 3 — A room that looks like a room (1 week) — IN PROGRESS

Done 2026-09-15: canvas-painted oak floor and plaster walls (`RoomMaterials.ts`), skirting
broken at doorways, door casing + open leaf + swing arc, window casing + sill + glass +
mullion + daylight sheet, environment lighting. Open: first-person walk, a floor material
chosen from the room analysis, a quality toggle, SSAO.
- Openings become objects: door leaf + frame + swing arc, window frame + glass +
  sill, cut into the wall with `three-bvh-csg`; both draggable along their wall in the
  plan view.
- Materials: PBR floor (oak, tile, carpet — from the room analysis's detected floor),
  painted walls with baseboards, ceiling; `RoomEnvironment` HDR, contact shadows,
  SSAO; the sun through the window as the key light.
- Camera: Plan / Üstten / Perspektif / İçeriden exist; add a smooth transition and a
  first-person walk (WASD, or drag on mobile) with collision against walls.
- A quality toggle (low/high) — the high path is heavy on integrated GPUs.

### Phase 4 — The plan itself (1 week) — IN PROGRESS

Done 2026-09-15: openings dragged along their wall on the plan (`RoomPlanSvg`
`editableOpenings`), added and removed from the plan page, persisted as room constraints;
`room-openings.spec.ts`. Open: non-rectangular rooms (wall polyline), corner dragging,
polygon containment on the server.
- Non-rectangular rooms: the geometry becomes a wall polyline (L-shapes, bay windows);
  the AI proposal stays rectangular, the plan editor lets the customer drag corners.
- Openings placed by dragging on the plan; the 3D scene updates live.
- 2D ⇄ 3D sync with the same selection and undo stack.
- Server-side `LayoutGeometry` gains polygon containment; the mirrored tests stay mirrored.

### Phase 5 — Verify against the storyboard (3 days)
- Playwright journey extended: rotate freely, push a sofa into a wall (it stops), stack a
  lamp on a table, walk through the room, render.
- Performance budget: ten products, 60 fps on a 2020 laptop with an integrated GPU;
  first paint under 3 s on 4G.
- A side-by-side page: storyboard panel next to a live screenshot for each of the six.

## 5. What this costs

- Engineering: roughly 5–6 weeks for one focused engineer along Phases 0–5. Phases 0 and 1
  are worth shipping on their own.
- Assets: per product, once, ~$0.30–0.50; the owner approves the batch size each time.
- Nothing else recurring. No SDK licence.

## 6. Decisions needed from the product owner

1. Path A (build on Three.js) — confirm, or ask for B/C.
2. Asset budget: generate the whole current catalogue now, or only on approval going
   forward (sellers can upload their own for free either way).
3. Which provider to standardise on after the ten-product bake-off (Tripo / Meshy / Rodin).
4. Phase order: 0 → 1 → 2 → 3 → 4, or interaction (2) before assets (1).
