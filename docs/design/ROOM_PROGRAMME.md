# Choosing instead of writing

A roadmap for replacing the free-text design brief with a guided room programme, and for
the catalogue vocabulary that has to exist underneath it.

**Status: proposal. Nothing here is built yet.**

---

## The problem

The design page asks the customer one question: *"İstekleriniz"*, a blank textarea. Most
people cannot fill it in. Not because they lack taste — because "describe your living room"
is a professional's question asked of somebody who has never had to answer it. They write
"güzel olsun" or nothing, and the whole engine downstream is guessing.

Meanwhile the model, given nothing to go on, invents a programme: a television unit, floor
length curtains, a framed picture, four cushions. The catalogue stocks none of those, so the
render drew furniture nobody sells until [we stopped it](../../apps/api/database/migrations/0001_01_01_000031_render_only_what_can_be_bought.php).
Both failures have one root: **nobody ever asked the customer what they wanted, and nothing
ever checked what the shop could supply.**

The fix is the same fix for both. Ask in pictures, offer only what exists, and let the
answers — not the model's imagination — be the programme.

---

## The shape of the answer

Today the AI decides *what furniture the room needs*, and matching then hunts for products.
That is backwards. The customer knows whether they want a corner sofa or a three-seater
better than any model does, and the catalogue knows what it stocks better than either.

Inverted:

| | Today | Proposed |
|---|---|---|
| What furniture | model invents it | **customer picks it, from what is in stock** |
| Which style | free text, usually empty | **customer picks a tile** |
| Where it goes | model decides | model decides (unchanged — this it is good at) |
| How big | model guesses | **room measurements + category rules** |
| Which product | vector search | vector search, filtered by the chosen style and budget |

The model keeps the job it is good at — arranging a room and drawing it — and loses the job
it was bad at, which was pretending to know a catalogue it has never seen.

---

## What already exists

Worth being precise, because a good deal of the groundwork is in place.

- **8 styles**, seeded and in the database: `modern`, `minimal`, `scandinavian`,
  `warm-contemporary`, `luxury`, `industrial`, `classic`, `bohemian`. The words the customer
  wants to choose between already have rows.
- **40 categories** in a real tree with `room_type` on the leaves — `kanepe`, `koltuk`,
  `sehpa`, `tv-unitesi`, `hali`, `perde`, `yatak`, `gardirop`, `mutfak-dolabi` and so on.
- **10 room types**: salon, yatak odası, çocuk odası, yemek odası, mutfak, banyo, çalışma
  odası, hol, balkon, diğer.
- **Room measurements** and a project **budget**, both captured today.
- **8 product attributes**: renk, malzeme, ölçü, oturma kapasitesi, montaj, garanti, menşei,
  bakım.

So this is mostly assembly, not invention. Three things are missing, and one of them is
the reason everything else underdelivers.

---

## The gap that matters: the catalogue does not describe itself

`products.style_id` is **nullable and effectively unused**. Every one of the 12 real
products has one only because the seeder set it; the seller product form treats it as
optional and nobody fills it in.

Which means a customer choosing "Lüks" today would get:

```
luxury            → 0 products
classic           → 1 product   (a bed)
modern            → 5 products
scandinavian      → 3 products
```

**A style picker on top of a catalogue that has not been told its own style is a picker that
returns nothing.** The customer-facing work is the visible half; this is the half that makes
it work.

Two consequences for the design, both important:

1. **Style is a ranking signal, not a filter.** With a thin catalogue, filtering hard on
   "luxury" empties the room. Style should push matching products up the ranking and pull
   others down, with neighbouring styles counted as near-misses — `modern ↔ minimal ↔
   scandinavian` are neighbours; `luxury ↔ classic` are neighbours; `industrial` and
   `bohemian` sit apart. A style adjacency map, not a WHERE clause.
2. **A product belongs to more than one style.** A plain oak sideboard is credibly
   scandinavian *and* minimal. `style_id` as a single foreign key is the wrong shape; this
   wants a many-to-many with a strength, so "primarily minimal, also scandinavian" is
   expressible.

---

## The room programme

A **programme** is the set of questions asked for one room type, and what each answer means
in catalogue terms. Data, not code — seeded, versioned, editable by an operator when the
catalogue grows, so adding "yatak başlığı" to the bedroom is a row rather than a deploy.

### One question, in full

```
Oturma grubu nasıl olsun?              ← soru
  ○ Üçlü kanepe                        → kanepe ×1,  koltuk ×1 (opsiyonel)
  ○ Köşe koltuk                        → oturma-grubu ×1
  ○ İkili kanepe + iki berjer          → kanepe ×1,  koltuk ×2
  ○ Şimdilik istemiyorum               → (hiçbiri)
```

Each option carries what it means: which categories, how many, whether each is essential or
a nice-to-have, and any size rule (a corner sofa needs a wall of at least 2600mm — if the
room does not have one, the option is shown greyed with the reason).

### Salon, fully specified

The room most people start with, and the one the customer has been testing.

| # | Question | Options | Categories |
|---|---|---|---|
| 1 | Oturma grubu nasıl olsun? | Üçlü kanepe · Köşe koltuk · İkili + berjer · İstemiyorum | `kanepe` `koltuk` `oturma-grubu` |
| 2 | Sehpa? | Orta sehpa · Orta + yan sehpa · Sadece yan sehpa · İstemiyorum | `sehpa` |
| 3 | Televizyon ünitesi? | Evet · Hayır | `tv-unitesi` |
| 4 | Halı? | Evet, büyük · Evet, orta · Hayır | `hali` |
| 5 | Aydınlatma | Tavan sarkıtı · Lambader · İkisi de · Mevcut kalsın | `tavan-aydinlatma` `lambader` |
| 6 | Depolama? | Kitaplık · Konsol · İkisi de · İstemiyorum | `kitaplik` `konsol` |
| 7 | Perde? | Evet · Hayır, mevcut kalsın | `perde` |
| 8 | Dekorasyon | Tablo · Bitki · Vazo · Kırlent *(çoklu seçim)* | `tablo` `bitki` `vazo` `kirlent` |

Eight questions, all tappable, none of them requiring a sentence. Plus style and palette,
which are asked once for the whole project rather than per room.

### The other nine

Same shape, different questions. Sketched here; specified in full during the work.

| Room | Question count | Anchor questions |
|---|---|---|
| **Yatak odası** | ~7 | Yatak ölçüsü (tek/çift/king) · Gardırop · Komodin (bir/iki) · Şifonyer · Aydınlatma · Halı · Perde |
| **Çocuk odası** | ~7 | Yaş aralığı · Yatak tipi (ranza/tek/karyola) · Çalışma masası · Oyun/depolama · Halı · Aydınlatma |
| **Yemek odası** | ~5 | Masa kaç kişilik · Sandalye tipi · Konsol/vitrin · Aydınlatma · Halı |
| **Mutfak** | ~5 | Dolap düzeni · Tezgah malzemesi · Bar/ada · Bar taburesi · Aydınlatma |
| **Banyo** | ~5 | Dolap tipi · Lavabo · Ayna · Aksesuar seti · Depolama |
| **Çalışma odası** | ~6 | Masa tipi · Sandalye · Kitaplık · Depolama · Aydınlatma · Halı |
| **Hol** | ~4 | Ayakkabılık/konsol · Ayna · Portmanto · Aydınlatma |
| **Balkon** | ~4 | Oturma tipi · Masa · Bitki · Gölgelik |
| **Diğer** | ~3 | Serbest: kategori seçimi + stil + bütçe |

Roughly 55 questions and 200 options across the home. This is the bulk of the work and it is
content, not engineering: each option has to name real categories, plausible quantities and
honest size rules.

### Never offer what cannot be bought

The rule that makes the whole thing safe. Before a question is shown, each option is checked
against the catalogue for the chosen style and budget:

- **stocked** → shown normally
- **stocked, but nothing in the chosen style** → shown, with a quiet note
- **nothing at all** → hidden, or shown disabled as *"yakında"*

A customer is never offered a television unit when there are none. This closes, at the
source, the exact failure that has been reported twice.

---

## Style and palette

Asked once per project, not per room — a home is styled as a whole, and asking eight times
is how a wizard becomes a form.

**Style**: eight tiles, each a photograph of a real room. Nobody knows what
"warm-contemporary" means as a word; everybody knows it when they see it. One choice,
changeable per room for the customer who wants a classic bedroom in a modern flat.

**Palette**: four to six swatch sets — *sıcak nötr*, *soğuk gri*, *toprak tonları*,
*koyu ve dramatik*, *açık ve ferah*. Swatches, not colour names.

**Budget**: already on the project. Add a per-room split, because "300.000 ₺ for the flat"
has to become a ceiling per placement before matching can respect it.

---

## The pipeline, after

```
fotoğraf + oda tipi
      ↓
stil + palet + bütçe            ← chosen, once per project
      ↓
oda programı                    ← chosen, per room, filtered by stock
      ↓
ANALYSE   room photograph → fixed elements, measurements, light   (unchanged)
      ↓
PLAN      the programme is the list; the model places it          (constrained)
      ↓
MATCH     search filtered by budget, ranked by style adjacency    (style-aware)
      ↓
RENDER    only matched products, as now                           (unchanged)
```

The plan stage stops being an invention and becomes an arrangement. The model is handed
"one three-seater sofa up to 2200mm, one rug, one pendant — put them in this room" and does
what it is good at.

---

## Work, in order

Each phase leaves the product working. Nothing here needs a big-bang release.

### Phase 1 — Catalogue vocabulary *(seller side, unblocks everything)*
- `product_styles` many-to-many with a strength, replacing the single nullable `style_id`
- Style becomes **required** to submit a product for moderation, and moderation rejects
  a listing with no style
- Colour becomes a controlled list of families (not free text), so a palette can filter
- Dimensions become required for the categories where placement depends on them
- Backfill the existing catalogue; an admin screen showing coverage per category × style

**Without this the rest returns empty results.** It is first for that reason.

### Phase 2 — Programme definitions *(data)*
- `room_programmes`, `programme_questions`, `programme_options`, and the mapping from
  option to categories with quantity, requirement and size rule
- Seeder for all ten rooms
- Admin screens to edit them, because the catalogue will grow and the questions must follow
- Versioned, so a design records which programme version produced it

### Phase 3 — Availability *(the guard)*
- A service answering "can this option be supplied, in this style, under this budget?"
- Cached, because it is asked once per option per page load
- Feeds the wizard and an operator report: *"salon programında 8 sorudan 3'ü karşılanamıyor"*

### Phase 4 — The wizard *(customer side)*
- Replaces the textarea: photo → style → palette → programme → budget → summary
- Picture tiles throughout; free text survives as an optional last step for the customer
  who does have something specific to say
- Answers stored on the design version, so a refinement can start from them and a customer
  can see what they chose

### Phase 5 — Pipeline
- The plan prompt receives the chosen programme as a hard list, not a suggestion
- Matching filtered by budget and colour family, ranked by style adjacency
- The style adjacency map itself, seeded and editable

### Phase 6 — All ten rooms
- Programmes written, seeded and tested per room type
- One end-to-end journey per room, against a catalogue seeded to support it

---

## Three decisions worth making before code

**1. Style per project, or per room?** Per project is simpler and matches how homes are
furnished; per room is more flexible and one more thing to answer. *Proposal: per project,
with a per-room override that most people never touch.*

**2. What happens when the catalogue cannot fill the programme?** Options: hide the option;
show it disabled with a reason; or let it be chosen and tell the customer at the end. *Proposal:
hide when nothing exists at all, show with a note when it exists but not in the chosen style —
so a thin catalogue reads as a thin catalogue rather than as a broken page.*

**3. Do sellers restyle their existing listings, or do we?** Making style required breaks
every current listing until somebody sets one. *Proposal: required for new listings
immediately; a deadline for existing ones, with an admin report of what is missing, and a
"style unknown" bucket that ranks last rather than not matching at all.*

---

## Honest scope

Phases 1, 3 and 5 are engineering and reasonably well understood. Phase 2 and Phase 6 are
mostly **content** — roughly 55 questions and 200 options that have to name real categories
with defensible quantities and size rules, and each needs a catalogue deep enough to answer
it. That content is the long pole, not the code.

The catalogue as it stands holds **12 products**. The wizard will be honest about that, which
means early rooms will look sparse. That is the correct behaviour and it is also the strongest
possible argument for onboarding sellers — every empty option is a page saying what the shop
is missing.
