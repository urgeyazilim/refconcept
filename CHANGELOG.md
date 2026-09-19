# Changelog

All notable changes to RefConcept are recorded here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Fixed — The first reading of a room was the last one

The product owner pressed "Tasarıma göre yeniden diz", got the same room back, and said the
detection was broken. It was, one step further back than the button they were pressing.

Openings were written into a room only when it had none. The reason given was right — "a
customer who has already written down their window is not helped by a second one appearing
beside it at slightly different coordinates" — and what it checked was wrong. The first
reading puts a door and a window on the walls, and from that moment the room *has* openings,
so every later reading of the same photographs was discarded whatever it found. There was no
route at all from a wrong window to a right one except dragging it by hand.

- **The rule is ownership, not existence.** Every opening now records who put it there. A
  reading may replace what a reading put there; nothing replaces what the customer wrote,
  dragged or retyped. A room holding even one of the customer's own is left alone entirely,
  which is the case the old rule was written for.
- **Correcting an opening makes it yours.** The moment somebody drags the window the
  photograph found, it stops being the photograph's answer.
- **Wall by wall, not all or nothing.** The product owner had corrected the door on their
  north wall and left the window on the west as the reading found it. A rule that refused
  whenever anything was theirs would have locked the wall they still wanted read, so the north
  wall is theirs and the west is still the reading's to answer again — and a newly read
  opening never lands on a wall somebody has already answered for.
- **"Fotoğraflardan yeniden oku", on the plan, with its price beside it.** One credit. It says
  what it replaces and names the walls it will not touch.
- Openings written before this column existed count as the customer's unless they carry the
  proposer's own note, because the safe mistake is to protect a row somebody typed rather than
  to overwrite one.


### Changed — The reading is asked where along the wall, not how many millimetres

The product owner said the system cannot work out the doors and windows from their
photographs. It had found both — a door on the north wall, a window on the west — and every
number attached to them was a guess: 4000 by 5500 by 2600, window at 1500 offset and 2500
wide, door at 2800 offset and 900 wide, sill at 850, at a stated confidence of 0.7. Nine
round numbers. They are not measurements, and the reason is the question: `offset_mm` asks a
pair of eyes for a tape measure, and a model asked for millimetres does not refuse — it
answers with something plausible.

- **Prompt v11 asks for proportions.** `starts_at` and `ends_at`, 0 to 1 along the wall;
  `sill_ratio` and `head_ratio`, 0 to 1 up the wall. "This window starts about a third of the
  way along and ends about four fifths" is a judgement about a picture, which is what a vision
  model is for. The millimetres are then worked out from a wall whose length is already known.
- **The millimetre fields are no longer required.** A model told a field is required produces
  one whatever else it is offered, and the number it produces looks exactly like a measurement
  to everything downstream.
- **An opening can no longer overrun its own wall.** A proportion of a wall is on that wall by
  construction, and a wrong room size no longer puts the window in the wrong place as well:
  it stays a third of the way along whatever the wall turns out to be.
- **A reading that answers in millimetres is still honoured**, so rooms read before today keep
  working and a model that leaves the proportions out is not thrown away. Half an answer is
  not salvaged into a whole one: a start with no end is dropped rather than completed.


### Changed — The seating is a group in the middle of the room, not a row against the walls

The product owner put their design next to the room it had been rebuilt in and asked whether
the layout matched. It did not, and the plan said so better than the room did. The design
plan for that room reads, in as many words: "oturma grubu, duvarlara yapıştırılmak yerine
odanın merkezinde bir 'ada' olarak tasarlanmıştır", and of the armchair: "oturma grubunun
kuzey kanadında, kanepeye dik, diğer koltuğa bakacak şekilde." The composer read the word
"north" beside the armchair and put it against the north wall of the room, two and a half
metres from the sofa it was meant to be talking to.

- **A wall name on a secondary seat is a wing of the group, not a wall of the room.** A seat
  narrower than the main one now joins it: level with where the coffee table goes, just
  outside it, turned a quarter so the two wings look across the group at each other. A seat as
  wide as the main one is a second group rather than a wing of this one and still takes a
  wall, and so does a wing the room has no space for — then the wall name means what it says
  again.
- **The plan's quantity is honoured.** "İki berjer" is one placement with a quantity of two
  and it arrived at the room as one armchair: the number was read when the plan was written
  and never again. It is capped at four, because the number comes out of a language model and
  each one becomes furniture standing in somebody's room.
- **Pieces in the middle of the room are checked against each other.** The wall rules kept
  pieces apart along a wall, which was enough while everything stood against one. Rugs and
  anything hanging are not in the way — a chair stands on a rug.
- The owner's own room now comes back as the design drew it: the sofa under the window facing
  the television, two armchairs facing each other across the coffee table, the rug under all
  four.


### Added — The design is on the screen that rearranges it

The plan screen asks somebody to move a design's furniture about and showed them an empty
grey room to do it in. The product owner arrived from a design they liked and said it in one
line: "burada bana vermiş olduğum tasarımı göremiyorum." A plan is a copy of a picture, and
the picture has to be on the screen.

- **The design stands beside the room**, at the top of the column, with its version number
  and a way back to it. It is the reference rather than the subject, so it is small and quiet
  — the room is the thing being worked on — and one click gives it the whole window.
- **It is the design they came from.** `?compose=` already told the screen which version to
  arrange; it now tells it which version to show, so somebody who pressed "yerleşimi değiştir"
  on v1 does not get a picture of v2 beside the room they are rebuilding. A version that is
  not this room's falls back to the newest rather than erroring — a query string is a hint.
- The link is signed and expires in thirty minutes, made the same way the design screen's own
  picture is: it is the inside of somebody's home and no response carries a path to it.


### Fixed — "Tasarıma Göre Diz" put the room round the armchair and left the sofa out

The product owner arranged a real design and said the layout was not what they wanted. It was
not: the composer was handed six pieces and placed five, and the five it placed were grouped
round the wrong one.

- **A window was blocking its wall the way a doorway does.** The room is 4 x 5.5 m with a
  2.5 m window in the middle of the west wall. That left two 1.2 m ends, so the 2.2 m sofa the
  design had chosen was refused with "no room" — in a twenty-two square metre living room. A
  window is only a block for what would cover it: the free runs along a wall are now worked
  out per piece, and anything shorter than the sill passes under the glass. The sofa is 78 cm
  and the sill is 85, so it stands under the window, which is where sofas go. Nothing changed
  for doorways, and a piece nobody measured still counts as tall, because an unmeasured piece
  might be a wardrobe.
- **The coffee table and the rug were following the armchair.** Seating is placed widest
  first, so "the last seat placed" — which is what the table and the rug arranged themselves
  around — was always the smallest one in the room. The result was a 90 cm table and a 2.4 m
  rug huddled against the far wall with a single chair, and bare floor between the sofa and
  the television. They belong to the widest seat standing, and now they go to it.
- The owner's own room recomposes to six of six placed: the sofa under the window facing the
  television across the room, the table in front of it, the rug under them both.


### Changed — Four phases and a loop, instead of ten numbered boxes

- **The wizard is gone.** The strip was ten numbered circles — "1 Fotoğraf — 2 Eşyalar — 3
  Oda" across to "10 Satın al" — and the product owner's verdict was that a guided numbered
  wizard is amateur and the whole thing is boring. Anthropic's own design guidance says the
  same thing independently: numbered markers read as a generated page unless the content
  really is a sequence. It is a rule in four parts now, with the name of the phase you are in
  and the name of the one after it, quietly. Progress is felt along the rule rather than
  counted in circles.
- **Four phases, in the owner's own words:** Fotoğraf, Eşyalar, İstekler, Tasarım.
- **3D is not a fifth step.** It is the door you open when you do not like your design: the
  guide's own words are now "Beğendiysen ürünlere geçelim. Beğenmediysen yerleşimi kendin
  değiştir, yeni tasarımı ona göre vereyim." The render, the tour and the basket stopped being
  steps too — they are things you do with a design you already have.
- **The measurements moved to that door.** A design is drawn on a photograph and does not need
  metres; they start mattering when somebody puts a real sofa in a real room. So the size and
  the openings are the threshold of the 3D door rather than a step on the way, and somebody
  who likes their design never sees the question.
- **The phase change is a phase change.** It was 160 ms with an eight-pixel hop, which is the
  default web transition and reads as a page swapping a div. The outgoing panel now leaves
  quickly and without moving, and the incoming one settles over 420 ms on the standard curve
  with no overshoot — the timing the motion reference calls premium — and it waits for the
  guide's new sentence first, which is the order somebody reads them in.

### Added — "Odayı ölç": the room measured from all of its photographs

- **A task, a route and a job.** `room_scan` on fal's VGGT: every photograph of a room goes up
  at once and a coloured point cloud comes back, with where each camera stood. The reading
  estimates a room by reasoning about one picture; this triangulates from six, which is the
  only way to tell a long room from a square one.
- **Asked for, never automatic.** It costs money per room and the first real attempt came back
  as a cloud with no walls in it, so nothing spends a customer's money on it unprompted. The
  button appears once there are two photographs, six is where it starts being worth it, and
  the job runs on the mesh worker so it cannot hold up anybody's reading.
- **Under the photographs' own rules.** Bytes to the provider, never a link; private disk,
  random key, signed short-lived link, no URL in any response, out of the photo grid. A point
  cloud of somebody's living room is their home as surely as a picture of it is.
- **It stores a shape, not a size.** The reconstruction is faithful about proportion and silent
  about scale, so nothing derives measurements from it: a number taken off that cloud would
  look precise and would not be. Proved end to end on the product owner's own room — 231
  seconds, 3.8 MB, stored and shown.

### Added — The room as its photographs measured it

- **A reconstruction, behind one button.** Six photographs of the product owner's room, handed
  to fal's VGGT, come back as a quarter of a million points in three dimensions. Fitting a
  rectangle to the floor gives 1 : 1.43, which at a normal ceiling is 3.9 × 5.6 m. The reading
  had guessed 3.8 × 4.5 m one time and 4.5 × 5.0 m the next, and neither caught the shape: this
  is a long room and the guesses were nearly square.
- **"Tarama" on the room step** shows it instead of the room we drew, so the two can be
  compared by eye. It is not a tidy room — the far side of the sofa is missing and daylight is
  smeared through the window — but it is measured, and the drawn room beside it is a guess.
- **Kept under the photographs' own rules.** Private disk, signed short-lived link, no URL in
  any response, out of the photo grid. A point cloud of somebody's living room is their home as
  surely as a picture of it is.
- **Scale still rests on an assumption.** The reconstruction is faithful about shape and silent
  about size, so the metres come from taking the ceiling as 2.70 m. The shape is measured; the
  scale is not, and the studio still asks.

### Fixed — A test run reached a customer's room

- **The suite repoints the whole platform at the simulator for the length of a run.** That is
  a switch with no fence around it: anybody using the site while the suite runs gets a fake
  answer. The product owner did. Six photographs of their living room came back in zero
  seconds as the simulator's canned living room, and they asked, reasonably, why it was taking
  so long. The real reading that followed took sixty-six seconds end to end — twenty of them
  the deliberate wait for the uploads to settle, forty the model — which is what the guide
  promises.
- **The platform can now answer one account with the simulator without moving anything
  global.** A job belonging to an address on a configured e-mail domain is routed to the
  simulator; the route on disk is untouched, so the next person through gets the real model.
  Off unless the domain is set, which it is not in production. This is a second layer, not yet
  a replacement: the pipeline's own jobs carry no user, so the global switch still does the
  real work until the owner is stamped on them.
- **Embeddings were never simulated.** `text_embedding` was missing from the list of tasks a
  run points at the simulator, so every suite made real vector calls to Google — a few
  thousandths of a dollar each, dozens a run, invisible because there is no rate on file for
  them and the console showed the cost as zero. A walk-through now makes no paid call at all.

### Fixed — We asked for a compass bearing and never said where north was

- **The wall names had no meaning.** The reading was told "the wall names, seen from inside the
  room: north, south, east, west" and nothing more. A model looking at a photograph of a living
  room has no compass, so it picked one. It was also never told that the planner measures
  `width_mm` along the north and south walls and `length_mm` along the east and west ones, so
  the two numbers and the four names were free to disagree — and they did. The product owner's
  room is a long wall with two sconces facing the camera and a window on the short wall to the
  right; it came back four metres wide by six long with the window on a six-metre wall, and the
  room step drew the proportions the wrong way round. The model answered the question it was
  asked.
- **North is now the wall you are facing in the first photograph**, east is on your right, west
  on your left, south behind the camera. The measurements are tied to those names, and the
  prompt carries the arithmetic to check the answer against itself before giving it.
- **An opening has to fit the wall it claims.** A window wider than its wall is a reading that
  went wrong somewhere and is dropped; one that merely hangs off the end is slid back on,
  because where it is was a guess and that it exists was not.

### Changed — The system picks the photograph, not the customer

- **"Bunu kullan" was the wrong question.** Three photographs, three buttons saying "use this
  one", shown to somebody who had just been told to photograph four corners of their room —
  and the product owner asked, fairly, what they had taken four for. Every photograph is read
  and merged into one description of the room, and the others go to the renderer as
  references; what was being chosen was only the viewpoint of the finished picture, because a
  render is one view of a room.
- **So the system chooses it.** Widest shape first, then the corner the reading saw most of
  the room in, then the most pixels; an emptied room beats the furnished one it came from. It
  costs nothing — the shape of a photograph is recorded on upload and the reading already says
  which corner it saw each fixture in. The button stays as an override and says "Bundan çiz",
  and a customer who uses it is not overruled by the next upload.
- **Landscape, and the screen says so.** A render is a wide picture of a room, so a photograph
  taken with the phone upright hands the model a strip of one: ceiling and floor, with the
  walls the furniture goes against cut off at both sides. The guide asks for the phone to be
  turned sideways and an upright photograph says so on its own card. The product owner worked
  this out before we did.

### Fixed — Two buttons a metre apart called "Ekle"

- The one that opens the fixtures form and the one that submits it had the same name, which is
  confusing to read and was wrong to click: the opener removes itself the moment it is pressed.
  The opener is "Sabit ekle" now.
- **The studio step is kept in the address.** The design screen and the plan already did this;
  the room did not, so anything that reloaded the page threw the customer back to the
  beginning mid-sentence.

### Changed — The room is drawn the way it was read

- **The kind of window comes from the reading, not from the width.** The model reported a
  hole with a width and the kind — single, double, triple, French balcony — was deduced here
  from that width alone, so a 1.2 m opening became a double casement whether the photograph
  showed two sashes or one tall pane. It is asked for now, trusted only when it is a kind
  that opening can be, and the width stays as the answer for a reading that does not say.
- **The room is painted the colour it was photographed.** The reading always described the
  surfaces and only the renderer ever saw the answer, so the planner drew every customer's
  room as the same cream box: a grey room with a white cornice came back white on white and
  the owner could not recognise it. Each surface now carries a colour, the walls and ceiling
  are painted with it, and anything that is not a usable colour is dropped rather than
  coerced.
- **The cornice is drawn when there is one.** The skirting board was already there; the
  moulding at the ceiling was not, though the reading had been listing it all along.
- **The room step draws the right floor.** It drew boards for every room; the plan screen had
  been reading the floor material from the same analysis for months.

### Changed — The radiators and sconces reach the room

- **Everything fixed to the wall is written down, not just the openings.** The reading listed
  two wall sconces on the north wall of the product owner's room and nothing was done with
  them beyond telling the renderer not to paint over them — so they were invisible to the
  customer and invisible to the arrangement, and a bookcase could be planned across a
  radiator with nothing to object. Radiators, columns, beams, fireplaces, stairs, built-ins,
  sockets and wall lights now land in the room as constraints, marked as the photograph's.
- **Called what they are.** The constraint type is a category, so three entries all read
  "Diğer"; the reading's own word decides the name.
- **Trim is not on that list.** A skirting board and a cornice are drawn as part of the room.
  Putting them among the things furniture must avoid would fill the list with two entries
  that apply to every wall and mean nothing.

### Changed — One frame, one strip, ten steps that are ten screens

- **Every page starts in the same place.** A walk through the site measured the first row of
  content at three different heights depending on which family of page you were on, so moving
  between them jumped the content under the reader's eye. There is one page shell now, with
  the top and bottom rhythm as design tokens, and the first row sits at the same height on all
  twenty-two screens.
- **The studio scrolls by nothing.** It set its height to the window minus the header's
  height and forgot the header's hairline, which is one pixel — and one pixel is all it takes
  to put a scrollbar on a screen that promised not to scroll.
- **The step strip has its own row.** Sharing a line with the chrome made how much of it you
  could read depend on how long the project was called: the room step showed all ten names,
  the questions step showed none, and the design screen wrapped "Satın al" outside its card.
- **Ticks are monotonic.** Each screen worked out for itself what it could see and they saw
  different things; the same room answered "Eşyalar" with a number on one screen and a tick on
  another. You cannot be at step five without having passed step two, so the strip says so.
- **Render, 360 and Satın al are three screens.** All three opened the design exactly as it
  was, because nothing read the anchor the strip linked to. The anchor now opens the step and
  the step writes the anchor back, so a reload lands where the customer was.
- **The catalogue's heading came inside the frame**, and the four steps of a room with no
  design yet link back to the room instead of building an address with two anchors in it.

### Changed — Each step shows the thing it is asking about

- **The furniture step shows the photograph.** It asked whether to take the furniture out of a
  room it was not showing, and left six hundred pixels of empty screen under the question.
- **The room step no longer prints a size nobody gave.** The scene falls back to a
  room-shaped box before anything is measured, and it was showing that box's dimensions in the
  corner as a fact, under a guide asking for the measurements.
- **The room step says its own sentence.** The balloon told the customer to add a product from
  a column that is not on that screen.
- **The photograph step fills the screen** instead of ending a third of the way down it.

### Changed — Nothing is cut off at the bottom

- The radiators-and-columns form moved into the room's own side column, which scrolls by
  itself; at 1440 by 900 its sentence and button were cut in half by the bottom edge.
- The two lines under a render have room to be read.
- The account pages lost their side column, which repeated the header's own links, and got one
  quiet row of tabs.
- An empty basket is a card with a sentence and a button, like every other empty state.

### Changed — The reading puts the doors and windows on the walls itself

- **It had them all along.** The reading found a three-metre window on the north wall and a
  door on the east wall, with positions and sizes, and held them on the unconfirmed
  proposal. The room step drew an empty box and the panel beside it said "Fotoğraftan kapı
  ya da pencere çıkaramadım" — about a reading that had found both. They go onto the walls
  as soon as the reading lands. The photograph is taken so that nobody has to do this by
  hand.
- **Nothing is silent about it.** That step's whole job is to ask "Doğru mu?", each opening
  is marked as the photograph's rather than the customer's, and any of them can be dragged,
  retyped or removed in a tap. A room that already has openings of its own is left alone.
- **The guide says what it did.** It claimed to have put the doors and windows aside whether
  or not it had; it now says how many it found, or that it found none.

### Fixed — The screen wandered off the step the customer was on

- **A step opened from the strip is stayed on.** The chosen step was forgotten whenever it
  matched the step the guide would have picked anyway, and the guide's step is "the first one
  not finished" — so a reading landing a second later could make an earlier step unfinished
  again and take the screen with it. Somebody typing measurements on step three was dropped
  onto step two mid-word, because the reading had just found a sofa to ask about.

### Fixed — The room had no way to a basket

- **Buying what is standing in the room came back.** Taking the duplicated "Render al" off
  the plan took the basket button with it, and the design screen's is not the same thing:
  that one buys what the designer chose, this one buys what is standing in the room after
  the customer moved it, swapped it and took things out. The endpoint was still there with
  nothing to reach it. It sits under the room's own list and its total.

### Fixed — Two tests that were wrong about the product

- **The listing test uploaded into whichever input came first.** A seller was given
  somewhere to put their manufacturer's mesh, which made `input[type=file]` ambiguous on
  that page; the photograph now goes into the one that takes photographs.
- **The settlement hold test failed on somebody else's leftovers.** `buildAll` walks every
  active seller and hands back any settlement already open, so a draft left by an earlier
  run of the same test answered to the name it matched on. The rule it was testing —
  fourteen days from delivery before a seller is paid — was working the whole time. The
  test names its seller once and matches it exactly.

### Fixed — A size typed while the room is being read

- **The measurements vanished as they were typed.** While the photographs are being read the
  room screen reloads itself every four seconds, and each reload wrote the room's saved size
  back over the three boxes. Somebody typing their own measurements watched the numbers
  disappear mid-word and got "Genişlik ve uzunluk gerekli" when they pressed save. A box is
  only refilled while it still holds what was put there; the moment it holds something of the
  customer's, it is theirs.
- **The form no longer closes under the customer's hands.** It used to disappear the instant
  the reading proposed a size, taking a half-typed measurement with it. Somebody who has
  started typing is answering the question, and the reading waits its turn.

### Fixed — Tests that clicked a page before it was listening

- **Hydration was declared too early.** `waitForHydration` waited for `__vue_app__`, which Vue
  sets when it mounts — before a single listener is attached. On a route the dev server was
  compiling for the first time that window was tens of seconds wide: the photograph went into
  an input nothing was listening to and was swallowed without a word, and the test failed two
  minutes later saying the step never ticked. It waits for Nuxt to put `isHydrating` down,
  which is the same hook that finishes the takeover.
- **The studio walk-through covers going back.** The guide takes the customer forward and the
  strip is the only way back; the test now presses a tick, and asserts the studio really is on
  that step again with the room's doors and windows in front of it.

### Changed — The room step shows the room

- **In three dimensions, not as a rectangle.** Step three drew the customer's room as a flat
  black outline in SVG beside a heading, a paragraph of instructions and a row of pills — the
  product owner's verdict was "cin ali gibi", a child's primer. The 3D room already existed
  one step further on. It is the room step now: walls, floor, the doors and windows on them,
  the palette of kinds down the left, and a door dragged onto a wall with the pointer.
- **The question is asked once.** The size was written out under the guide band with the same
  two buttons the band already carried. The band asks; the panel is the room. The measuring
  form appears only when somebody is typing a size, and it is one row.
- **Nothing that belongs to another step.** The furniture inspector and the product list are
  hidden here (openingsOnly), and the "Boş oda" card with only a heading in it is gone.

### Fixed — The studio stopped on step one

- **The room reading stood down whenever the primary photograph changed.** The job compares
  the photographs it was queued for with the ones the room has now; that list is ordered
  primary-first, so pressing "Bunu kullan" on another corner reordered it and every queued
  reading cancelled itself. Nothing re-queued one, so the guide said "odanı okuyorum" for
  ever. The comparison is a set now: the reading looks at all of them and the order is not
  what it is about.
- **A mesh could hold up a customer.** GenerateProductModel shared the AI queue with room
  readings, plates and designs, and one worker runs one job at a time — three meshes at three
  to four minutes each put a customer twelve minutes behind a catalogue job nobody was
  waiting for. Meshes have their own queue and their own worker now: queue-models.
- **The waiting screen gives up out loud.** After ninety seconds with no reading the guide
  says "Okuma uzun sürdü" and offers both ways on: try again, or go ahead and type the size.

### Changed — One frame for the whole journey

- **Including the header.** The bar at the top used the 1200-pixel container while the studio
  pages used the 1440 one, so the logo sat 120 pixels right of the page under it. Header,
  footer, catalogue, basket, checkout, favourites, the account pages and the legal pages all
  use the wide container now: one left edge on every screen in the product. The legal prose
  keeps its comfortable measure but starts at that edge rather than centred in the middle.

- **One left edge, one top gap, on every screen.** The home page, the house list, a house,
  a room, the plan and the design each had their own margins: three different left edges and
  three different distances to the first thing on the page, because the list and the house
  still carried the account sidebar while the studio did not. They all use the wide container
  now, the sidebar is gone from the journey (the account pages keep it, and the header menu
  is how you reach them), and each screen opens with the same single line of chrome.
- **No page in the journey has a heading block any more.** "Evlerim" with a paragraph and a
  button, and the house with a breadcrumb, a title, a subtitle and its buttons, both said what
  the guide band underneath was about to say. One line each now: where you came from, what
  this is, and the numbers that matter.
- The room screen no longer draws a card for a step that lives elsewhere; the guide band says
  where it is and takes you there.

### Changed — The guide is a band, not a column

- **The left column is gone from every studio screen.** The guide stood in a 380-pixel card
  beside the room, the plan and the design: a portrait avatar, a badge, four lines of copy
  and two buttons, taking a third of the width even while the engine was working and there
  was nothing to decide. The product owner's verdict was that no other site does this. It is
  now one band across the top — who is speaking, what it says, what to press — and the screen
  below it belongs to the room. The photographing tips and the furniture choices take their
  own line inside the band rather than stacking down a column.
- **The waiting screen is one centred stage.** No columns, no cards, no bordered footer: the
  room draws itself in the middle, the stage is named under it in one sentence, and how far
  along it is shows as a hairline across the top.
- **No heading over the picture.** The design's "Odan" heading, its paragraph and its version
  pill said what the guide had just said; the picture now starts at the top of its card and
  is never taller than the window.
- **The step strip never scrolls sideways.** Below a threshold the labels give way to the
  numbered dots (container queries), so ten steps fit any width.

### Fixed — The owner's own room, on the ten steps

- **Step two did not end.** The owner emptied their first corner while the second was the
  primary photograph; the studio kept asking "Eşyaları kaldırayım mı?" about a photograph
  that was never going to be emptied. An emptied photograph now becomes the primary one
  (`RoomClearer::adoptAsPrimary`, unless the primary has a plate of its own), and step two
  counts any emptied photograph as answered.
- **The emptied room pushed the step below the window.** The before/after picture is never
  taller than the stage now; a portrait photograph at full width was a screen and a half.
- **The four photographing tips overlapped their own words** in the guide's column; they
  stand one under the other. Five photographs fit on one screen (four or five across).

### Fixed — Walking the ten steps as a customer

Found by walking the steps end to end with a fresh account, through the guide's own
buttons, screenshot at every step. None was caught by a test.

- **After a photograph the guide said "okuyorum… bitince devam ederiz" and never moved.**
  The reading was only followed after "Yeniden oku"; an upload queued one and nobody
  watched it, so nothing happened until the customer reloaded. The room page now follows
  a reading after every upload and on opening a room whose photographs are being read.
- **"Hayır, hepsi kalsın" skipped step three.** Saying no made step two done, the
  automatic step moved to three, and "advance" then advanced from three — to four. The same
  fault skipped step four after "Evet, doğru". `advance()` now moves from the step the
  customer was on.
- **"Kaldırıyorum…" for ever.** A plate that never comes (the provider's answer thrown away,
  a job that fails quietly) left the spinner spinning. After 150 s the guide says "Boş odayı
  hazırlayamadım" and offers another go or the room as it is.
- **"Render al" opened the old picture.** The plan sent the design's id as the version to
  show, so the design screen opened on v1 saying "Tasarımın hazır" while v2 was made out of
  sight. It sends the version's id now, and the design screen honours `?version=`.
- Two "Render al" buttons on the plan (the guide's and the panel's) — the panel's is gone,
  with the basket button beside it; step ten owns the purchase.
- The stage was three pixels taller than the window; it is not now.

### Changed — Ten steps, one screen each

- **The studio walks the product owner's ten steps in their order:** Fotoğraf, Eşyalar,
  Oda, İstekler, Tasarım, 3B, Kayıt, Render, 360, Satın al. The strip shows all ten on every
  studio screen; none is hidden and none is skipped. The room screen carries the first
  four, the plan the sixth and seventh, the design screen the fifth and the last three —
  one at a time, with the guide asking the next question.
- **The measurement confirmation is step three's one question** ("Odanı 4,2 × 3,5 m
  okudum. Doğru mu?"), the owner's choice over automatic acceptance; the doors and windows
  are beside it. The old "Tanıma" panel of chips and warnings is gone.
- **The design screen is staged.** The picture with "Yerlerini değiştirmek ister misin?",
  then the render with "360 tur oluşturayım mı? (20 kredi)", then the tour with "Ürünleri
  sepete koyayım mı?", then the shopping list. The version tree stays under the picture.
- **The plan has the guide too**: "Ürünlerin odada; kaydettim. Bitince render alayım." with
  "Render al" as its button; "Tasarıma göre yerleştir" became its quieter second.
- **Every stage is as tall as the window.** The guide column never moves; the step's panel
  scrolls inside its own column when it has to; the page does not scroll.

### Changed — The room, the plan and the design are one workspace each

- **No footer, no sidebar, one line of chrome.** The room, plan and design pages leave
  the account layout and say `chrome: 'studio'`; the default layout keeps its footer off
  them. Where you came from, which room, its size and the step strip share one line; the
  title, subtitle and header block that took a screen's worth above the work are gone.
- **The guide stands beside the work.** On the room and design pages the guide is a sticky
  column on the left and the step's panel is on the right, so a laptop shows both without
  a scroll. The product owner's verdict on the stacked version: "üst bölgeler gereksiz
  bilgilerle dolu, sürekli mouse ile aşağıya iniyorum".
- The photograph panel lost its second voice: the "Boş oda" card with its paragraph and
  button only appears once an emptied photograph exists, because the guide already asks
  "Eşyaları kaldırayım mı?" on its own step. The plan on the confirmation step is
  height-bound so a long room does not push the page past the window.

### Changed — The 3D room is furnished the moment the design is ready

- **Nobody presses "Tasarıma göre yerleştir" any more.** The product owner opened the plan
  of a room the engine had just designed and found it empty, behind a button: "yapay zekâ
  tasarımı yaptı, o zaman 3B'de sen yerleştir". Arranging is free arithmetic against the
  room, so it now runs inside the design pipeline as soon as the picture is made
  (`LayoutAutoComposer`), and the plan page arranges on opening any room that has a finished
  design and no arrangement yet — designs made before today included. The button stays,
  as "Yeniden yerleştir", and an arrangement the customer has moved is never overwritten
  unasked.
- **Proposed measurements are taken as agreed when nothing else is there.** A room the
  reading measured and nobody confirmed used to be refused ("Önce oda ölçülerinin
  onaylanması gerekiyor"); the composer now confirms the reading's proposal itself, the way
  "Evet, doğru" would, adopting the doors and windows it found. The guide keeps asking until
  somebody measures. Only a room nobody has read or measured is refused.
- The version's event log says how many pieces went into the room and how many did not.

### Fixed — The renderer painted fixtures it had only been told about

- **A radiator and a window across the television wall.** The reading looks at every
  photograph of the room and names the fixtures it saw in each; the renderer edits one
  photograph and was handed the whole list — "preserve: radiator, air_conditioner" — with
  the radiator on a wall it could not see. It painted one. Now the renderer is named only
  the fixtures the reading saw in the photograph being edited (`preservedElements($index)`),
  and the room's other photographs go with it as references, up to three, each labelled
  "REFERANS n: aynı odanın başka bir açıdan fotoğrafı … bu bir ürün değildir, çizilmeyecek".
- **Render prompt v8** (`image_render_draft`, `image_render_premium`): the fourth rule no
  longer says every image after the first is a product — the role list says which are —
  and a sixth rule says what a reference is for and that nothing is drawn for a wall the
  first photograph does not show. `render_inputs.view_count` records how many went.
- The fidelity check is told the same, visible-only list.

### Changed — The plan is a workspace, not a page

- **The room fills the window.** The plan screen stacked the stepper, a title, a banner, the
  scene, the selection panel, the product list, the openings and the catalogue one under the
  other; the product owner's verdict was a screen full of empty space and a mouse wheel that
  never stopped. Now the room is as tall as the window allows and everything that acts on it
  stands in one scrolling column beside it: "Tasarıma göre yerleştir" first, then the
  selection, the products and the total, the doors and windows, the catalogue. The page
  itself does not scroll. It also leaves the account layout's sidebar behind and uses the
  wide container, which is a third more room for the room.
- **The scene watches its own box.** A `ResizeObserver` on the canvas, since a column
  appearing or a page giving a different height never fires a window resize; and when the
  box changes shape the room is refitted once, so a wide box does not cut the ceiling and a
  tall one does not leave the room small in the middle.
- `Room3DScene` has a `workspace` prop and `side-start` / `side` slots for the column.

### Fixed — The browser suite was quietly billing the real providers

- **Readings fired after the routing was restored.** A photograph uploaded by a test queues
  a reading of the room twenty seconds later; the suite's teardown put the real routing back
  the moment the last test ended, and the reading then ran against Gemini and was billed —
  thirteen readings on 2026-09-16 alone, none approved, unnoticed because every test had
  passed. The teardown now deletes the fixture accounts' projects first (a reading whose room
  is gone stands down), waits with `refconcept:await-ai-queue` until no reading, plate or
  design job is left waiting, delayed or reserved, and only then restores the routing.
- **The planner, reranker, renderer and render check were never routed to the simulator.**
  Only the two upload-triggered tasks were; a journey that asked for a design paid for the
  rest — between half a lira and two and a half per run. All seven tasks a test can trigger
  are now pointed at the simulator for the run.
- `refconcept:purge-e2e-fixtures` also deletes the fixture customers' projects, rooms and
  photographs, and says so.

### Added — Which kind of window it is

- **A window is a kind of window now.** Single, double or triple casement, or a French
  balcony; a door with one leaf or two; a balcony door in either, or sliding. The palette
  beside the room offers them by name, grouped as a customer thinks of them, each put in
  at the size such a thing usually is and then dragged where it belongs.
- **The room is drawn with the right one.** Three panes have two mullions; a double door
  hangs a leaf on each jamb and swings both; a sliding door is two glass panels, one slid
  behind the other, on a track; a French balcony is glass to the floor with a guard rail
  outside it. The plan marks where the leaves meet and draws the second sliding panel, so
  the kinds differ on paper too.
- **The reading's openings are given a kind by their width** — a 2.1 m "window" is three
  panes, a 1.6 m "door" two leaves — and the customer corrects it with one tap on a chip
  beside the opening, on the plan page and at the confirmation step alike. A kind has to
  be one its type can be: the API refuses a sliding window.
- `room_constraints.variant` (nullable, checked); `variant` and `variant_label` on the
  constraint and `variant` on the layout's openings.
- **Which way the door goes.** A door hangs on one jamb and opens into the room or out of
  it, and the quarter of floor it sweeps is floor nothing may stand on. Both are on the
  opening now: the plan draws the leaf standing open and its arc from the right jamb in the
  right direction, the 3D room hangs it there, and two chips beside the door — "Menteşe
  solda ⇄", "İçeri açılır ⇄" — turn it round. Left and right are what the customer sees
  from inside; the row keeps which end of the wall, which does not reverse per wall. A
  door opening out sweeps the corridor and its floor inside is free. `room_constraints.swing`
  (`start_in`, `end_in`, `start_out`, `end_out`; null means start, in); a window cannot
  have one.

### Added — Products in three dimensions

- **A 3D model made from the seller's own photograph.** fal.ai / Tripo 2.5, thirty cents
  a model, once per product, queued when a listing is approved. A catalogue cost like the
  photograph itself: no customer is charged, and every customer who plans that product
  into a room afterwards uses the same file.
- **Four sides instead of one, for the same money.** A seller can say which photograph is
  the front, the left, the back and the right; with more than one the generator is sent its
  multi-view endpoint, which costs exactly what the single-image one costs and stops it
  inventing the back of the sofa. Photographs nobody labelled get a vision pass that is
  allowed to answer "I don't know" — a mislabelled back is worse than a missing one, so
  anything under 0.7 confidence is left blank, and a seller's own label is never overruled.
- **The mesh's size is never believed.** It arrives in whatever units the generator felt
  like; the catalogue knows the variant is 2200 mm wide because a seller measured it, and
  the editor scales every model to the recorded dimensions. A beautiful model at the wrong
  size is worse than a box.
- **A seller's own glTF binary always wins**, and a generated likeness is never used in a
  render — its far side was never photographed, which is fine in a planner seen across a
  room and not fine in a picture somebody buys from.
- **Products without a model are cut out of their photograph** and stand on their
  footprint, turning to face the camera and narrowing to the silhouette the real piece
  would present. A customer called the previous boxes "squares", and they were.
- `refconcept:product-models` backfills a catalogue that already exists, printing both the
  expected price and the ceiling before it queues anything, and saying plainly when the
  task is still routed to the simulator.

### Added — doors and windows sized by hand

- On the plan, a handle at each end of a door or window makes it wider or narrower; the far
  end stays, the end in the hand slides, nothing narrower than 40 cm and nothing past the
  corner. Saved as the customer's own. The room says what to do when nothing is selected,
  and what to do when it is empty.

### Changed — the room keeps a running total

- Every piece in the room's list shows its photograph, its size and its price, and the list
  ends in a **total** (K20) — a product added or removed moves the sum, and pieces without
  a price are counted as none and said so. The layout endpoint now sends each item's price.
- **"Render al"** and **"Odadakileri sepete ekle"** sit beside the total, where the decision
  is made, rather than in a card further down the page. Double-clicking a row zooms to it.

### Changed — the 3D room, made to feel like a tool

- **The camera flies** between views and in to a double-clicked piece, and "Odayı sığdır"
  brings it back out; a view that cut from one angle to another lost the customer for a
  moment. **Hover** lights the piece under a resting pointer and the cursor says it can be
  taken. **Contact shadows** sit every piece on the floor, and the selected piece shows its
  exact **footprint** on the floor — what it takes up, which a sofa with arms does not say.
- **The walls are named** at the top of each (Kuzey, Doğu, Güney, Batı) so the plan and the
  room agree on which is which; hidden walls carry no label.
- **A toolbar on the room itself** for the selected piece — turn, align to the wall, copy,
  lock, zoom in, delete — and the keys that go with it: Delete, Ctrl+D, Esc, R, arrows.
  Keys typed into a field beside the scene stay the field's.

### Added — doors and windows you pick up and put on a wall

- **In the 3D room too.** A door or window is picked up where it is (its casing, its glass)
  and follows the pointer onto whichever wall it is over — the same wall or another — and
  sits where the pointer is along it; the room redraws as it moves and the camera stays put.
  `OpeningDragController`, registered ahead of the furniture drag so a press on a door is the
  door's.
- **A palette, not a form.** Three icons at the left of the scene — Kapı, Pencere, Balkon
  kapısı — put one in the room at a sensible size on a wall with space for it; then it is
  dragged into place. Nobody types where a door is; the numeric form on the plan is gone. A
  room may have as many doors as it has.
- On the plan, dragging an opening towards another wall moves it onto that wall.

### Added — doors and windows you can drag

- **`RoomOpeningsEditor`** on the Onay step: the room from above with every door and window
  as a handle on its wall — drag it along, change its wall from the list, type the distance
  from the corner, add one with "+ Kapı" / "+ Pencere" and drag it into place. Saved as it
  happens and marked as the customer's own. The reading has no compass and is often a wall
  out; the answer is to make the correction take seconds, not to trust it more.
- A reading's openings now go into a room whose size was already agreed (when it has none of
  its own), and the reading must give every opening a place and a size or leave it out.

### Changed — one step on the screen, and the guide moves on by itself

- **The room screen shows one step at a time**: the strip, the guide, and that step's panel.
  The sections that used to stack down the page — photographs, reading, size, plan card,
  fixtures — are now the panels of their steps, opened from the strip. Nothing to scroll
  past, nothing to discover at the bottom.
- **No "Odayı tanı" button.** The reading starts when the photographs arrive, the customer
  stays with their pictures while it runs (and may add a corner), and when it lands the guide
  asks its first question: "Eşyaları kaldırayım mı?" — Evet, kaldır / Hayır, hepsi kalsın.
  Then the size ("… okudum, doğru mu?" — Evet, doğru / Düzelt, confirmed on the spot, no
  trip to the plan), then "Şimdi sen: ne istersin?" opens the questions itself.
- **The design screen asks the last question**: "Tasarımın hazır. Yerlerini değiştirmek ister
  misin?" — Evet, düzenleyelim opens the 3D plan with that layout.
- The step order the guide walks is photo → reading → empty room → size → design; the strip
  numbers follow it.

### Added — the guide: the product speaks, in the second person singular

- **`docs/product/REHBER.md`**: how RefConcept talks to a customer. One voice (an interior
  designer standing next to you), "sen", first person, one next step at a time, the reason
  for every ask, no corporate words; a table of what it says at every moment of the studio.
- **`StudioGuide`**, one card on every studio screen — the projects list ("Hadi başlayalım."),
  the house ("Şimdi bir oda ekle." / "Salon hazır — hadi tasarlayalım."), the room ("Hadi
  odanın fotoğrafını çekelim." with the four angles as illustrated tips; "4 fotoğraf aldım,
  odanı okuyorum."; "Odanı gördüm: iki kanepe, halı… Hangilerini kaldırayım, hangileri
  kalsın?" with chips; "Odanı 3,8 × 5,0 m okudum, doğru mu?"; "Odan boş. Şimdi sen: ne
  istersin? Renklerin gri, siyah, beyaz…"; "Tasarımın hazır."). It replaces the readiness
  alert and the empty-state cards.
- **The plate can keep things** (§8 decision "eşyalarım kalsın" — taken): the guide's chips
  choose what stays; `POST …/clear` takes `keep: [...]`, the emptying prompt (v2) names what
  stays, and a different choice remakes the plate.
- Copy across the projects, project and room screens rewritten in the guide's voice; the
  room screen's sections now follow the steps in order.

### Fixed — a real room was "read" by the simulator

- **The simulator can no longer answer for a real model that failed.** A route whose
  primary is real and whose fallback is the local simulator is a development convenience;
  when Gemini failed three times on the owner's living room, the simulator answered with its
  stock sofa, window and radiator, and the room screen presented them as the customer's own.
  `AiTaskRoute::candidateModels()` now drops a simulator fallback behind a real primary, so
  the job fails and the room says so (`analysis_failure`) instead.
- **Why Gemini failed: a schema it could not be given.** `fixed_elements: array` and
  `surfaces: object` had no item type or properties, so the Google adapter dropped them from
  the enforced schema — and the model, held to what remained, left them out exactly as told;
  the validator then refused every answer for lacking the fields it was never asked for.
  Prompt v5 (migration 000052) types every array and object, and the adapter now sends no
  schema at all rather than one missing a required field.
- **Every emptied photograph is shown**, not only the primary's: a customer who emptied one
  corner and then made another picture the primary had a plate that existed and appeared
  nowhere. The card also says when the primary photograph itself has no plate yet.

### Added — Oda Stüdyosu: every photograph is read, and the reading is a step of its own

- **All of a room's photographs go to the analysis as one room** (primary first, up to six),
  and the model is told they are corners of the same room so a window is counted once. The
  owner shot four corners and asked why only one was recognised: the reading only ever saw
  the primary photograph, and only when a design was started.
- **"Tanıma" happens on its own**, twenty seconds after the last upload — the job queued for
  an earlier set of photographs stands down when it finds the room has moved on — and on
  request (`POST rooms/{room}/analyse`, 202; `force` to read again). The room screen shows
  what it found (what stands in the room, what is fixed, what it was unsure of), says when
  the reading is stale because a photograph was added, and the step strip ticks "Tanıma"
  from it. Reading is free of credits; a reading that cannot run never fails an upload.
- **"Eşyaları kaldır" on every photograph**, not only the primary; the card under the gallery
  says which plate the render starts from.
- **The E2E suite can no longer bill anybody.** Its global setup points the tasks a test
  triggers in the background — the reading, the plate — at the local simulator for the
  whole run and its teardown puts the routing back; before this, every photograph a test
  uploaded would have queued a paid reading twenty seconds after the test had passed.

### Added — Oda Stüdyosu, sprint 5 (versions side by side)

- **A strip of every version as a picture**, above the render. Clicking one looks at it —
  the picture, the fidelity note and the shopping list follow — without touching the
  design's current version; "Geçerli sürüm yap" is the decision, and the tree says which is
  which. Versions still running or failed keep their place in the strip and say why they
  are blank.
- **Two versions held up together** (K26): side by side by default, or wiped over each
  other to see that the walls did not move — both started from the same plate.

### Added — Oda Stüdyosu, sprint 3 (the interior designer's rules)

- **Each of K13's rules is in `LayoutComposer` by name, with a test by name.** Odak: the
  seating faces the television, else the widest window, and the television goes across from
  the window. Dolaşım: the 900 mm in front of a door stays empty, round the corner too, and a
  sofa floats off its wall only when what faces it leaves room to walk past. Ölçek: standing
  furniture covers at most 40 % of the floor and a sofa at most two thirds of its wall — past
  either, the piece is reported *with the rule that stopped it* rather than squeezed in. Halı:
  the rug goes down after the seating, its back edge under the front legs. Simetri: the bed and
  the television take the middle of their walls, bedside tables go either side, the sofa lines
  up with the screen. Yükseklik: sconces at 1.7 m, curtains on the window. Aydınlatma: the
  floor lamp stands at the sofa's elbow.
- **A piece against the east or west wall now faces the room.** It was turned into the wall
  (90/270 swapped against the scene's convention), which nobody noticed while every piece was
  a symmetric box; a coffee table placed "in front of" it would have gone into the wall.
- `refconcept:product-models` backfills only products that are on sale; the dev catalogue's
  sixty-two archived test products had made the job look four times its size.

### Added — Oda Stüdyosu, sprint 4 (the step strip)

- **One strip, seven steps, every room screen.** Fotoğraf · Tanıma · Onay · Boş oda · Öneri ·
  Düzenle · Render, at the top of the room, the plan and the design. A done step carries a
  tick and stays a link, the current step is lit, the ones ahead are visible and quiet, and
  the strip names what comes next. The room screen's sections are labelled with the step
  they belong to.
- **The plan opens with the size the customer already typed.** The layout endpoint now hands
  the plan the room's own measurements and photo count; the confirmation form was opening
  empty on a room that had been measured a minute earlier.
- **The fidelity verdict is on the version tree** (`fidelity`, `render_base`), so the design
  screen can say "odaya uymadı, yeniden yapıldı" — or that the picture was painted onto the
  emptied room — under the render itself.

### Added — Oda Stüdyosu, sprints 1–2

- **The product contract**, `docs/product/ODA_STUDYOSU_KURALLARI.md`: the seven-step journey
  and thirty rules (K1–K30) the owner described — one photograph, the analysis proposes and
  the customer decides, the furniture is taken out, real products only, one layout the
  picture, the 3D scene and the render all read, and a render that invents nothing.
- **The plate** (K8–K10): the room photograph with its furniture removed, made once per
  photograph in the background by an image-editing task told by name what the analysis saw
  standing there; kept on the private disk beside the photograph, pointing at it; every render
  starts from it when it exists. The gallery shows it as a before/after slider.
- **What a render was made from** (K23): the base, the layout and the product count are
  written on the version before the picture is made.
- **The fidelity check** (K24): a vision call compares the picture with the plan it was made
  from; a picture that invented furniture or moved an opening is removed and the render runs
  once more, and the verdict is shown beside the version.

### Added — Planner v2, phase 4 (the plan itself)

- **Doors and windows corrected by hand.** On the plan view a door or window drags along its
  wall and stops at the corners; a "Kapılar ve pencereler" panel adds the one the photograph
  did not show and removes the "window" that was a mirror. Every change is a room constraint,
  so the 3D room, the collision rules and the final picture all read the same wall. A browser
  test adds a window, drags it with a real pointer and reads the room back.
- **The floor is drawn as the photograph showed it** — boards, tiles or carpet — from the
  analysis's own words.

### Added — Planner v2, phase 3 (a room that looks like a room)

- **Materials painted on a canvas, not loaded from files:** oak boards laid in staggered
  rows with grain and seams, plaster with a little noise in it. They ship with the code, so
  they cannot 404 or arrive a second after the room did.
- **Skirting along every wall, broken at the doorways.** The cheapest thing that makes a box
  read as a room.
- **Doors and windows are things, not holes.** A door has a casing and a leaf standing open
  30° into the room with its swing drawn on the floor; a window has a casing, a sill, glass
  with a mullion, and daylight behind it. They belong to their wall and hide with it.
- **Environment lighting** (Three's room environment) instead of a flat ambient, so a matt
  wall has a gradient and a glossy floor has something to reflect.
- **The inside view is walked, not orbited.** The camera stays at eye height, a drag turns
  the head, W A S D walk — and the walls stop you, because a customer can no more walk
  through one than their sofa can.
- **Pictures, mirrors, wall lights and curtains hang on the nearest wall** and slide along
  it; on both sides of the wire they collide with nothing, because on the wall is not on the
  floor.

### Added — Planner v2, phase 2 (interaction)

- **Handles on the selected piece.** Arrows slide it along the floor, a ring turns it about
  the vertical; the other axes are switched off because nobody means to lift a sofa. Turns
  snap to 15° (Shift frees them), R switches move/turn. Everything the handle does passes
  through the same snap and constraint pipeline as a drag, so a piece pushed at a wall stops
  at the wall with the arrow still in hand.
- **Two turned pieces are judged by their outlines, not their boxes** — on the browser and
  on the server, with the same numbers. A sofa on the diagonal no longer refuses the table
  tucked into the corner its box would cover.
- A browser test (`room-gizmo.spec.ts`) drives the ring and the arrow with a real pointer.

### Added — Planner v2, phase 1 (assets)

- **A mesh post-processing sidecar** (`mesh-tools`, Node, its own container): every stored
  model is welded, decimated towards 20k faces, meshopt-compressed, its textures resized to
  1024 px and re-encoded as WebP. The first real armchair went from 101k triangles and 3.3 MB
  to 20k and 262 KB in under a second, and looks the same across a room. The API stores the
  raw mesh if the sidecar is unreachable — heavier, still a model.
- **Sellers can upload their own GLB** from the product page. A manufacturer's file is the
  shape of the thing; it outranks anything generated and is never re-reviewed.
- **A bake-off across every generator fal hosts** — Tripo 2.5, Tripo H3.1, Rodin Gen-2.5,
  Hunyuan3D v3 — on ten of our own products, with a comparison page (`/lab/model-bakeoff`)
  showing the product photograph beside the four meshes, cameras locked together. The
  product owner voted Hunyuan3D v3 for all ten; the routing table and the seeder now name it.

### Changed — Planner v2, phase 0

- **Furniture can no longer leave the room or enter another piece.** The first editor only
  flagged a bad position; the product owner's screenshot showed a sofa half through a wall,
  red. `ConstraintEngine` now runs on every drag frame, arrow-key nudge and rotation: the
  rotated footprint is clamped inside the walls, an overlap is resolved by the shortest push
  that stays in the room, and a position with no answer leaves the piece where it was. A
  rotation that would put an end through the wall is nudged clear or refused.
- **Photograph cut-outs are gone.** A product without a 3D model is drawn as a shape of its
  kind in its own colour — a seat with a back and arms, a top on legs, a carcass on a plinth,
  eleven shapes mapped from the category — at the SKU's exact size, casting a shadow, facing
  the way the piece faces. Plain on purpose. The round table that became a red plate cannot
  happen again.
- The plan for the rest: `docs/design/3D_PLANNER_V2_PLAN.md`.

### Fixed

- **The first real fal.ai run, and everything it found.** One armchair came back as a
  textured 101k-triangle mesh and stands in the planner; getting there took five fixes:
  - A refused call (403, locked account) was recorded as a bought model — the flat
    per-request fee was written whether or not the provider accepted the request. It is now
    charged only on a 2xx.
  - fal cannot reach a localhost bucket, and the Tripo endpoint cannot open a data URI
    either. Photographs now go onto fal's own storage first (free) and the generator is
    given the CDN link.
  - The job ran on the default queue, whose worker kills anything past sixty seconds; Tripo
    takes about one. Two generations were killed after fal had made and billed them. It now
    runs on the AI worker with a ten-minute timeout.
  - A job whose worker died stayed `running` forever and held its idempotency key, so the
    product could never be retried. After half an hour it is closed as a timeout, its
    credits settled, and the key released — with a reason that says the provider may have
    billed for the call, because nothing here can know.
  - Generated meshes ignore the requested face limit (20k asked, 101k delivered); noted, not
    yet handled.
- **The bank-transfer and checkout sweepers died whenever two things expired together.** Both
  lazy-loaded a relation inside the loop, which Laravel refuses only when a model came out of a
  list of more than one — so every single-record test passed while the real scheduler threw on
  the first busy day, closed nothing, and kept the goods on hold. Found in the scheduler's own
  log. Transfers are now also re-read under a lock, so one confirmed a second after the sweep
  listed it is not expired out from under a paid order.
- **An idempotency key no longer pins a job to a failure forever.** The key exists to stop
  a second charge, not to stop a second attempt: a job that failed *without spending
  anything* — a cost ceiling set too low, a missing key, a locked provider account — now
  releases its key and can be run again once the cause is fixed. A failure that had already
  been billed still never re-runs, which is the case the key was written for.
- **The view-classification ceiling was below its own estimate.** The gateway prices a call
  pessimistically, at the model's entire output budget; for Gemini 2.5 Pro that is 8.2 cents
  against an answer four lines long, and a five-cent ceiling refused every call before one
  was made. Raised to twelve cents, which still catches a misrouted model.

### Added — The 3D room editor

- **A room the customer measures, furnishes and walks around.** Three.js scene at the
  confirmed measurements, with real holes cut for doors and windows, three views in the
  storyboard's own words — Üstten, Perspektif, İçeriden — and a 2D plan drawn in SVG so
  its labels are text at any zoom rather than pixels.
- **Measurements are proposed and then agreed to.** The analysis now estimates a room's
  width, length and height in millimetres and reports its doors and windows as a wall, an
  offset and a width (`room_analysis` prompt v2). Those arrive as an unconfirmed
  `room_geometry_versions` row and the screen asks "Bu ölçüler doğru mu?" with
  `[Evet, devam et]` and `[Düzelt]`. Confirming writes them onto the room and adopts the
  detected openings — but only into a room that has none of its own.
- **Furniture at its real size, from the SKU.** Drag with snapping to walls, edges and
  centre lines; live distances in centimetres to whatever is next to a piece; rotation,
  locking, undo and redo; autosave a second after the last change.
- **Collision rules in two places that have to agree.** The browser answers while somebody
  drags; the server decides, because nothing arriving over HTTP can be trusted — least of
  all the collision flags the client computed. `CollisionEngine.spec.ts` is deliberately
  the same cases as `LayoutGeometryTest.php`, in the same order, with the same numbers.
- **`LayoutComposer` arranges what a design settled on.** Words like "a sofa on the north
  wall" become millimetres here rather than in a language model, which produces coordinates
  that look like coordinates and put a wardrobe through a doorway. Wall pieces fill a wall
  from one end, skipping what a door is owed; seating floats 350 mm off its wall when the
  room can spare it and hugs it when it cannot; rugs go down first, tables in front of
  seating, pictures 1.5 m up. What did not fit comes back by name.
- **The plan goes to the renderer as structure.** The browser sends a picture of the room
  it has already agreed with the customer, and the render carries it as a second image with
  its role stated. This is the answer to the render that narrowed a doorway, moved a wall
  and invented a sofa: the model was not disobeying, it was resolving a scene left
  underdetermined. It lands on the private disk under the same rules as a room photograph,
  and no response ever carries a path to it.

### Added — Room tour video

- **A finished design can be filmed.** Veo 3.1 Lite turns the render into an eight-second
  1080p walk through the room, starting from the render as its first frame so the camera
  can only reveal what the photograph already showed. Measured at ninety to a hundred and
  ten seconds end to end, and about sixty-four cents a film.
- `AiModality::Video` and `AiTask::VideoTour`, routed to a long-running operation the
  Google adapter starts, polls and downloads inside one gateway call. Its own modality
  rather than a flavour of image: the call shape, the result size and the pricing unit are
  all different, and an image model must never be routed to it by accident.
- `design_videos` records the state of one film — held credits, provider job, finished
  asset, failure reason — apart from the file it produces, with a partial unique index
  allowing only one in flight per design so an impatient double click cannot pay twice.
- Twenty credits, held when the button is pressed and released in full if the film fails.
  Over HTTP an unaffordable request answers `402` with the price, so the client shows a
  top-up prompt rather than a form error.
- `RcVideoPlayer`: five-second skips, frame stepping while paused, zoom to four times with
  drag to pan, playback rate, loop, fullscreen and a download, all reachable by keyboard.
  The browser's own controls are built for hour-long video and offer no way to stop on a
  frame and look closely at a sofa somebody is about to buy.

### Fixed — The room tour zoomed instead of travelling

- **The camera now walks into the room and turns.** The first prompt asked for "a slow,
  smooth cinematic move" and "a gentle dolly forward"; pulled apart frame by frame, the room
  at seven seconds was the room at nought seconds and simply larger. Prompt version 2 names
  the shot in cinematography terms and adds a yaw partway through, which is the moment the
  film stops being a picture that grows and becomes a space somebody is standing in.
- Verified against the live API rather than by reading: `negativePrompt` — the documented
  place for "do not zoom" — is rejected outright by `veo-3.1-lite` with a 400, so the
  prohibitions live in the prompt itself. Video extension is likewise refused by this model,
  so a longer tour would have to be chained clip by clip or bought on the full Veo 3.1 at
  five times the price.
- A lateral orbit was tried and rejected. It travelled further and looked better for two
  seconds, then deleted both armchairs and the rug by the fifth. Everything in the film is
  meant to be something the customer can buy.

### Fixed — Render and routing

- **The renderer was drawing its own measuring tape into the room.** The placement rules
  ("coffee table 40–45 cm from the sofa") came back as dimension arrows and `45 cm` lettered
  across the finished photograph, and every frame of the room tour inherited them because
  the render is the video's first frame. Prompt version 6 states the measurements as
  instructions to the model and makes "no writing or annotation in the picture" a numbered
  rule beside the other three.
- **`db:seed` was quietly undoing migrations.** `AiGatewaySeeder` wrote routes with
  `updateOrCreate`, so a routine re-seed moved the render back onto the old model and back
  to prompt version 1 — and would have restarted a route an operator had paused during an
  outage. Routes are now created and never updated; the seeder ships the installation
  configuration and everything after it belongs to migrations and to the operator.
- A staged video was filed as `image/png`, because the staging store recovers the type from
  the extension and video was not in the list. Stored and served with that content type, a
  browser downloads fifteen megabytes instead of playing them, with no error anywhere.
- Content types are now stated when a design asset is written rather than left to the
  storage driver to infer from the path.

### Added — Phase 0 (Repository Bootstrap & Design Foundation)

- Monorepo layout (`apps/`, `packages/`, `infra/`, `docs/`, `scripts/`) with npm workspaces.
- Docker development stack: PostgreSQL 16 + pgvector 0.8.6, Redis 7, MinIO (S3-compatible),
  Mailpit, PHP 8.3-FPM API image, nginx, queue worker and scheduler.
  Host ports shifted (`58000`, `55432`, `56379`, `59000`, `58025`) so an existing XAMPP
  installation is never disturbed.
- PostgreSQL bootstrap creating both `refconcept` and `refconcept_test` with `vector`,
  `pg_trgm` and `citext` installed in each.
- Laravel 13 API with the `Administration` domain as the reference module layout, and a
  `GET /api/health` readiness endpoint probing database, pgvector, cache, queue, storage
  and migrations (503 when a critical dependency is down).
- Pest test suite running against **real PostgreSQL** (never SQLite), PHPStan/Larastan
  level 6, and Laravel Pint with `declare_strict_types` and strict comparisons.
- `@refconcept/ui` design system package: typed tokens, `--rc-*` custom properties,
  Tailwind v4 `@theme` bridge that deletes Tailwind's default palette, base layer and
  cross-app components.
- Three Nuxt 4 applications (storefront, seller portal, super admin) sharing that design
  system, each verifying API connectivity on boot.
- Design token guard (`scripts/check-design-tokens.mjs`) failing CI on any colour outside
  the approved palette.
- GitHub Actions CI: backend tests + static analysis + style, frontend typecheck/lint/build,
  container image build, dependency audit.
- Developer command wrappers (`scripts/rc.ps1`, `Makefile`) and idempotent bootstrap scripts.
- ADR-0002 (local development topology), ADR-0003 (design system delivery).

### Fixed — Phase 0

- **Test suite ran against the development database.** `env_file` injection made the
  container's real environment override PHPUnit's `<env>` values, so `RefreshDatabase`
  would have truncated local development data while reporting a green run. The injection
  was removed and `Tests\TestCase::setUp()` now refuses any connection whose database name
  does not end in `_test`.
- Composer could not install through the Windows bind mount (300s unzip timeout);
  dependencies moved to a named volume.
- Laravel boot cost 22.8s per command over the Windows bind mount. Application source moved
  to a named volume with explicit host→container sync: boot 22.8s → 4.3s, test suite
  104s → 32s.

### Added — Phase 1 (Identity / RBAC / Organizations)

- Identity schema on UUIDv7 primary keys with `citext` e-mail, UTC timestamps, database
  CHECK constraints on every status column and partial unique indexes guaranteeing one
  default shipping and one default billing address per customer.
- Authentication API: registration with KVKK consent capture, login issuing Sanctum
  tokens, session records per device, logout and logout-everywhere, `GET /auth/me`.
- E-mail verification and password reset built on single-use SHA-256 hashed tokens.
  Redeeming a reset revokes every live token and closes every session.
- RBAC: 12 seeded permissions and 5 system roles, platform- and organization-scoped
  grants with expiry, and an `AccessControl` service that answers membership and
  permission separately.
- Organizations as the tenant boundary, with an `OrganizationPolicy` that decides
  seller-to-seller isolation in one place.
- Append-only `audit_logs`, immutability enforced by a PostgreSQL trigger, written by an
  `AuditLogger` that redacts passwords, tokens, card data and IBANs.
- Customer profile and address book with ownership policies and a verified-e-mail gate.
- Rate limiters for login, registration, password reset and verification resend, keyed by
  e-mail **and** IP so one attacker cannot lock out a victim by failing their login.
- 78 backend tests / 235 assertions, including 15 tenant isolation cases.

### Fixed — Phase 1

- **Every authenticated route returned 500.** A stale `bootstrap/cache/packages.php` left
  on the host was pushed into the container on each sync, hiding Sanctum and removing its
  auth guard. The sync scripts now clear the compiled cache on both push and pull.
- `config/auth.php` pointed at the framework's `App\Models\User` and defined no Sanctum
  guard; rewritten for the Identity domain model.
- Model factories could not resolve for domain-namespaced models
  (`Factory::guessFactoryNamesUsing`).
- `email:rfc,dns` performed a live MX lookup on every registration, breaking the test
  suite and blocking `*.local` development accounts; extracted to configuration.

### Added — Phase 2 (Seller Onboarding)

- Seller applications kept separate from approved sellers, so a rejection stays on
  record with its reason and an approval preserves what was reviewed. One open
  application per applicant, enforced by a partial unique index.
- `Iban` value object: constructs only from a value passing the ISO 13616 mod-97
  check, stored encrypted with a masked display value and a keyed fingerprint for
  duplicate detection.
- Onboarding checklist derived from the data rather than stored as flags, driving both
  the portal's progress bar and the server-side submission guard from one
  implementation.
- Required documents follow the taxpayer type, so a sole proprietor is not asked for a
  trade registry gazette.
- Versioned agreements with immutable, checksummed acceptances — enforced by a
  database trigger as well as in PHP.
- `ApplicationWorkflow` as the single place status changes. Approval creates the
  organization, seller, membership and role grant in one transaction.
- Suspension, reactivation and commission changes demand a reason and are recorded in
  both `seller_status_history` and the audit log.
- Onboarding documents on the private disk under random keys, served by short-lived
  signed URL after a policy check.
- Seller portal: sign-in, dashboard and the full onboarding wizard.
- Super admin: review queue, application review with document decisions, and seller
  administration.
- `refconcept:grant-role` console command for bootstrapping the first operator; there
  is deliberately no HTTP endpoint that grants platform roles.
- Shared API and auth composables moved into `@refconcept/ui` so all three apps talk
  to the backend identically.

### Fixed — Phase 2

- **Every primary call to action on the storefront navigated nowhere.** `RcButton`
  rendered `<component is="NuxtLink">` by string name, which only resolves against
  locally registered components — and the component lives in a shared package. Found
  by an end-to-end click; screenshots looked perfect.
- Database status defaults were not reflected on freshly created models.
- `Artisan::starting()` does not exist in Laravel 13 and broke every artisan command;
  commands are now registered through `withCommands()`.

### Added — Phase 3 (Catalog / PIM and the product lifecycle)

- Catalogue taxonomy on UUIDv7 keys: categories with materialised paths and room
  types, brands, styles, colours, materials, and attributes whose "required" flag
  lives on the category pivot — so the seller's form and the submission gate read the
  same source and cannot disagree about what is mandatory.
- `CatalogTaxonomySeeder` — reference data, not demo data, so it runs in production
  too: 40 categories, 8 attributes, 19 colours, 18 materials, 8 styles and 6 brands,
  idempotent by natural key.
- Products separated from offers: a `Product` is what the thing *is*, a `ProductSku`
  is one seller's commercial terms for it. Two sellers can list the same sofa without
  the matching engine seeing two different sofas.
- **Money as integer minor units from the form field to the database column.** Prices
  cross the wire as integers, tax and discounts are basis points, and the single
  conversion between what a seller types and what is stored lives in one place.
- Product dimensions in millimetres, with width and depth required: they are what
  decide whether the piece fits the wall the design engine wants to put it against.
- `ProductCompleteness` — the readiness of a listing is derived from its data, never
  from a stored flag that a partial save could set.
- `ProductModerationWorkflow` — the only place a moderation status changes. Every
  transition is checked against the state machine, recorded in history, and audited,
  and every decision carries a mandatory reason enforced by both the application and a
  database constraint.
- Product imagery on its own anonymously-readable bucket: random object keys, the file
  extension derived from the decoded image type rather than the uploaded filename, and
  a partial unique index guaranteeing exactly one cover image per product.
- Public catalogue: category-branch, room, style, budget and trigram search filters,
  four sort orders, and a scalar subquery for price sorting so pagination does not lie
  about how many products exist. Every query runs through one `publiclyVisible()`
  scope; nothing builds its own visibility condition.
- Seller portal: product list, creation, and a full editor with a live completeness
  checklist, gallery management with cover selection and reordering, and per-offer
  pricing, stock and dimensions.
- Super admin: the moderation queue, and a review screen that shows the reviewer what
  a customer would see, with approve, reject (naming the fields at fault) and recall.
- Storefront: the catalogue with URL-backed filters, and a product page organised
  around choosing between sellers' offers.
- `DemoCatalogSeeder` — twelve published listings with real photography, uploaded to
  the public bucket exactly as a seller's upload would be.
- `RcStatusPill` and `useMoney` in the shared package, so lifecycle colours and money
  formatting cannot drift between the three apps.

### Fixed — Phase 3

- **A seller could not upload a product image at all.** There was no endpoint and no
  screen, so the completeness gate demanded a photograph that could not be supplied
  and no listing could ever be submitted.
- **An approved listing was approved, complete, and invisible.** Approval left the
  product's status and its offers at `draft`, so it satisfied moderation and still
  failed the visibility scope. The unit tests passed because they set the status by
  hand; only the end-to-end run went through the door a seller actually uses.
- **An approved listing could never be edited again** — no typo fix, no better
  photograph, ever. Approved listings are now editable, and any edit sends the listing
  back to the review queue and clears `published_at`, so what a customer sees is
  always something a reviewer looked at.
- The seller's product list returned 500 whenever a listing had an offer: the "from"
  price asks each offer whether its seller may trade, and the relation was not eager
  loaded. Lazy loading is disabled outside production, which turned an N+1 into an
  error — the right trade, and the reason this surfaced at all.
- `attributes` and `dimensions` were passed to `fill()` although neither is a column,
  so any request carrying them raised a mass-assignment error.
- The attribute *label* was serialised where the value belonged, so the seller's form
  matched none of its own options and silently cleared every attribute on save.
- Categories were ordered by position across all depths, which interleaved branches in
  the category select; they are now ordered by the materialised path.
- Demo seller accounts had an organization and a role grant but no trading account, so
  a demo seller reached the product form and was refused at the last step for a reason
  nothing on screen explained.

### Added — Phase 4 (Import, pricing, inventory and the partner API)

- **Bulk product import in three steps.** The file is parsed once into `import_rows`;
  validation reads those rows and writes nothing to the catalogue; commit applies the
  ones that passed, each in its own transaction. A seller sees how many products will
  be created, how many updated, and exactly which lines are wrong and why — before
  anything happens, because there is no undo for a catalogue.
- A streaming CSV and XLSX reader built for the files sellers actually have: the
  semicolon delimiter Turkish Excel writes (detected by column count, not guessed),
  the byte-order mark it prefixes, the Windows-1254 encoding older exports use, and
  comma decimals. Every line is stored verbatim alongside its parsed form, so "why did
  line 251 come out wrong" is answerable months later without the original file.
- Column mapping guessed from Turkish or English headers, accent-insensitively, and
  always shown to the seller for confirmation. Two columns claiming the same field
  leaves both unmapped rather than picking one — a wrong guess nobody notices writes
  wrong data into a live catalogue.
- An import template generated from the field catalogue rather than committed as a
  static file, so it cannot drift from the columns the importer understands.
- **Price lists with time windows**, so a campaign never overwrites the everyday
  price. Ending a campaign restores yesterday's prices because nothing overwrote them.
- **Append-only price history**, enforced by a database trigger, recording what
  changed, by how much in basis points, who changed it and *where it came from* — a
  40% drop caused by a misplaced decimal in a spreadsheet is otherwise indistinguishable
  from a deliberate campaign.
- **A stock ledger.** `stock_movements` is the record; `stock_items` is a snapshot of
  it written inside the same locked transaction. Every write takes a row lock and
  decides from what it reads under that lock, and CHECK constraints refuse a negative
  or over-reserved balance even for a caller that forgets to.
- Reservations with expiry: idempotent per reference so a retried checkout cannot take
  the stock twice, all-or-nothing across a multi-line basket, and released
  automatically — on the next reservation of that row, and by a five-minute sweep for
  everything else.
- **Scoped machine credentials** for a seller's own systems. Deliberately not Sanctum
  tokens: a partner credential belongs to a system rather than a person, carries its
  own scopes, is rate-limited per credential, and is revocable without logging anybody
  out. The secret is hashed and returned exactly once.
- A partner API addressed by the seller's own SKU codes rather than by RefConcept ids,
  reporting per-line results so one discontinued product does not fail a 4,000-row
  nightly sync.
- Seller portal: bulk import with the mapping and preview flow, a bulk price editor
  with per-SKU history, a stock screen separating on-hand from reserved from sellable,
  and integration credentials with a request log.

### Fixed — Phase 4

- A newly imported SKU had no price history at all: the row was created already
  priced, so the price book correctly saw no change and wrote nothing. The origin of a
  product's very first price — the one most worth being able to explain — was the one
  thing nobody could look up.
- The demo catalogue set stock quantities on SKUs with no ledger rows behind them, so
  a demo product page claimed six in stock while the stock screen was empty. Opening
  stock is now booked as a receipt through the ledger.

### Fixed — after Phase 22 (currency and the AI image path)

- **Every money figure is now lira, including AI spend.** The AI console stored and printed
  the provider's own dollars while every other figure in the system was TRY — a spend total
  sitting next to an order total in a different unit with nothing saying so. Google publishes
  its price list in dollars, so the cost is now converted once, when the usage row is
  written, and what is stored is lira. **Relabelling was not an option**: writing ₺ over a
  dollar figure makes the number wrong by the whole exchange rate and wrong *silently* —
  nothing else in the system would ever disagree with it. The rate is configurable and an
  operator can update it from Sistem → Ayarlar (`finance.usd_try_rate`); an unusable rate
  leaves the figure unconverted rather than zeroing it, because a spend report reading zero
  looks like a quiet month and nobody investigates a quiet month.
- **Product prices accept only the supported currency.** The SKU form allowed EUR, USD and
  GBP from a hardcoded list while `money.supported_currencies` said TRY. It now reads the
  config, so the two cannot disagree.
- **A seeded model that Google does not serve.** `gemini-3-pro` was configured for room
  analysis, design planning, tagging and support answers. Every one of them failed with the
  provider's `invalid_request`, which the platform rendered to a customer as *"Oda fotoğrafı
  okunamadı. Daha aydınlık bir fotoğrafla tekrar deneyin."* — a good message for the failure
  it was written for and a lie about this one. The customer retook the photograph in better
  light and failed again. Repointed at `gemini-2.5-pro`, verified against ListModels *and* a
  real call.
- **`refconcept:verify-ai-models`**, so that cannot happen quietly again. It asks each
  provider whether the configured model codes exist and suggests near misses. Deliberately
  not in the test suite: it needs the network and a live key, and a suite that fails when a
  third party is having a bad morning is a suite people learn to ignore.
- **Every room photograph rendered as a broken image.** Object storage is reached by two
  names — this container talks to MinIO on the Docker network, a browser talks to it through
  a published port — and a signed URL carries the host in its signature, so a link signed for
  the first is rejected at the second. The host cannot be swapped afterwards without
  invalidating the signature, so links are now *signed* for the host the browser will use.
  The same bug silently affected onboarding documents and payment receipts. In a deployment
  where the two names are the same this changes nothing; it exists for every one where they
  are not.
- **Room photographs were being handed to the provider as a URL.** Wrong twice over. It does
  not work — Gemini's `file_uri` accepts a URI from Google's own Files API, not an arbitrary
  link, so every design generation failed with *"Cannot fetch content from the provided
  URL"*. And it should not work: room photographs live on the private disk precisely so no
  URL to one ever leaves this system, and handing a third party a fetchable link to somebody's
  home would have quietly broken that while looking like an optimisation. The bytes are now
  read inside our own network and sent inline, bounded at 8MB per image, with the URL kept
  out of the logs.

### Added — Phase 22 (Web release / stabilization)

- **The API contract, generated from the router** and frozen at `apps/api/openapi.json` —
  205 paths, 251 operations, with the required permission stated on every administrative
  one. `refconcept:openapi --check` fails in CI when the committed document stops matching
  the routes, because a specification nobody verifies is wrong the first time somebody adds
  an endpoint and an integrator finds out before the team does. Request and response schemas
  are deliberately not invented: the shapes live in `packages/ui/src/runtime/types.ts`,
  where three clients compile against them.
- **Component tests for the shared design system** — eighteen, on the rules every app
  inherits: a status chip takes its colour from the code and never from the label, an error
  is *associated* with its field rather than merely drawn near it, a loading button cannot be
  pressed a second time, and a button given a destination renders a real link. That last one
  is a regression test: it once rendered an unknown element, looked perfect in every
  screenshot and did nothing when clicked.
- **Four operational documents**, written for the person who did not build this: a payment
  runbook whose first rule is never to guess which system is right, a seller onboarding
  runbook that explains what to write in a rejection reason, a production checklist that
  lists what is **blocked** rather than omitting it, and a deployment document explaining why
  reference data is seeded on every deploy and why workers restart after the migration.
- **A security checklist** that marks every rule as enforced by a test or by a person, and
  says plainly what is not covered.
- **A limited release strategy**: sellers first by invitation, bank transfer before cards,
  credits with a low ceiling, feature flags as the throttle, reconciliation daily from day
  one rather than from the first problem.

### Fixed — Phase 22

- **The OpenAPI freeze would have been decorative.** The document was written to a path
  outside the container's application tree, so `--check` had nothing to compare against.
- **The published API version still said `0.1.0-phase0`.**

### Release status

`WEB_RELEASE_APPROVED` is **not** written. Everything measurable passes and P0/P1 are zero,
but Phases 12 (iyzico) and 13 (QNB) are deferred pending documentation and credentials —
so the platform cannot take a card payment. A marketplace on bank transfer alone is a viable
limited launch and is not a completed web release. See the Independent Test Agent verdict at
the bottom of `12_FINAL_WEB_ACCEPTANCE.md`.

### Added — Phase 21 (Hardening)

- **The security rules are properties now, not prose.** A rule that lives only in a document
  is a rule somebody breaks in a hurry six months from now with nothing to stop them. The
  suite asserts that a card number has nowhere to go, that no HTTP route grants a platform
  role, that the super-admin bypass never reaches a customer's project, that no plaintext
  IBAN leaves the server or sits in a row, that every append-only table really is, and that
  nothing shaped like a provider key is committed anywhere.
- **Security headers set by the application**, not only by nginx — including
  `Permissions-Policy` and HSTS over TLS. A header added by infrastructure disappears the
  day somebody puts a different proxy in front, and nothing fails when it does.
- **A request id on every response**, honouring one the caller already assigned and
  replacing one that could not safely be logged. The audit log's `request_id` column had
  been null since Phase 1.
- **Payment reconciliation** (`refconcept:reconcile-payments`): the provider's transaction
  log against the journal, because each is internally consistent and neither can be checked
  against itself. Non-zero exit on anything critical, so a scheduler can alert; warnings do
  not fail the run, because an alert that fires on normal business gets turned off. Nothing
  is corrected automatically — a mismatch means two systems disagree about money.
- **A backup and restore drill** (`scripts/backup-drill.sh`) that dumps, restores into a
  throwaway database, compares row counts on the tables whose loss would hurt, and cleans up
  after itself. A backup nobody has restored is a hope.
- **A load smoke test** (`scripts/load-smoke.mjs`) that fails on any 5xx or dropped request
  under concurrency and only reports slow percentiles — the question is whether anything
  collapses, not how fast a laptop is.
- **Product media cached immutably.** The keys contain a UUID, so a URL always names the
  same bytes; that is what makes a year's caching correct rather than reckless.

### Fixed — Phase 21

- **Payment webhooks were queued behind ten-minute AI renders**, on a single worker. A
  customer's payment confirmation could sit behind somebody else's sofa. Split into two
  queues and two worker processes, with a test that stops the next job landing on the wrong
  one.
- **Every catalogue search made a live call to the embedding provider** — a network round
  trip on the most-used endpoint on the site, a cost per search, and a search box whose
  latency was somebody else's uptime. Query vectors are cached for an hour under a hashed
  key; search p50 under concurrency went from 2120ms to 602ms.
- **Two duplicate indexes**, one of them five phases old. Invisible from outside — no query
  is slower, the table simply pays for two index writes on every insert and holds two copies
  on disk.
- **Product images were served with no cache headers**, so every catalogue grid
  re-downloaded every thumbnail on every visit.

### Added — Phase 20 (Storefront complete + approved design language)

- **A phone can use the site.** The desktop navigation is hidden below `lg` and nothing
  replaced it, so a phone visitor saw a logo and a sign-up button and no way to reach the
  catalogue. The drawer that replaces it is a real dialog: focus moves into it, Escape
  closes it, the page behind does not scroll, and it closes on navigation so it never reads
  as a stuck overlay. The basket link now stays in the header at every width, because that
  is truer on a phone than on a desktop.
- **A skip link on all three apps**, first in the DOM and visible only when focused.
  Tabbing through a whole header — or a whole admin sidebar — to reach the row you came for
  is not navigation.
- **One SEO composable** rather than a `useHead` block per page: canonical, Open Graph,
  Twitter card, and a description trimmed at a word boundary, because a snippet cut
  mid-word reads as machine output.
- **Everything behind a sign-in refuses to be indexed.** An order page is not secret, it is
  protected — but a URL a crawler can reach is a URL a search result can carry. Those pages
  carry `noindex` and no canonical at all: a canonical asks a crawler to index one URL
  rather than another, which is a contradiction on a page that must not be indexed.
- **`robots.txt` and `sitemap.xml` are generated.** A static disallow list drifts from the
  router the moment somebody adds a page, and a hand-kept sitemap is wrong the day after
  somebody adds a product. The sitemap pages the catalogue at the API's own limit, and
  falls back to the static pages rather than answering 500 where a crawler expected XML.
- **Product structured data** — price, currency and availability — taken from what the page
  itself displays, so a rich result can never contradict the page behind it.
- **A footer that links the legal pages.** They existed and could only be opened by typing
  the URL; a terms page nobody can find is a terms page nobody agreed to.

### Fixed — Phase 20

- **Three navigation links pointed at the homepage.** "Platform", "Nasıl çalışır" and
  "Profesyoneller" all resolved to `/`. A menu that lies about where it goes is worse than
  a shorter menu.
- **A `<Teleport>` in the layout crashed the client app on unmount**, taking the page's
  event handlers with it — the symptom was a product page whose "Sepete ekle" button did
  nothing at all. The drawer is fixed-positioned and the layout root imposes no transform,
  so the teleport bought nothing.
- **The seller portal wiped its own confirmation.** Saving a parcel set a success message
  and then refreshed the screen, which cleared it — the seller saw the parcel appear and no
  word about whether it had worked.
- **A head getter reached forward to a `const` declared later in the same file**, which
  throws on the client and takes the whole page down with it. The product page lost its
  canonical and its event handlers together.

### Added — Phase 19 (Seller portal complete)

- **A seller can have colleagues.** Somebody dispatches parcels, somebody else answers
  returns, and the person whose name is on the bank account does neither. Two roles, and no
  third: an owner changes the team and the payout account, staff work the day-to-day. A
  third rung would need a permission editor, and a permission editor a seller can use is a
  way for a seller to lock themselves out of their own account.
- **The last owner cannot demote or remove themselves.** A company with no owner is a
  company where nobody can add one back, and the only way out is a support ticket and a
  console command. Refused by the API and by the screen, so the refusal arrives as an
  explanation rather than as an error.
- **One person belongs to one seller.** Somebody on two teams would see two companies'
  orders through one session, and every isolation guarantee in this platform is written per
  organization.
- **The person being added must already have an account.** Creating one from a team screen
  would let a seller set a password for an address they do not control, and "somebody added
  me to their company" is not a reason to hand over an account.
- **A removed member is marked, not deleted**, because the orders they confirmed and the
  returns they decided still name them — and they can be added back, since somebody
  returning from leave is not a new company.
- **A real seller dashboard**, leading with the queue rather than with revenue: orders not
  yet confirmed, parcels not yet sent, returns nobody has answered, stock running out,
  listings still in moderation. Each one is a way in rather than a number to write down.
  Low stock and nothing-on-the-shelf are counted separately, because one is a reminder and
  the other is a listing that has stopped selling.
- **A parcel screen.** A shipment is a physical thing with a carrier and a tracking number,
  and one order can have several. The remaining quantity per line comes from the server, so
  no client has to subtract shipment lines from order lines and no seller has to do it in
  their head. Delivery is marked per parcel; the order becomes "kargoya verildi" on its own
  once everything has actually gone.
- **Staff can read the team and change nothing.** Somebody working a returns queue sees
  "kim onayladı" next to a decision, and a name they cannot look up is worse than no name.
  The management controls are absent rather than disabled, and the page says why — a
  greyed-out button nobody can explain reads as a bug.

### Fixed — Phase 19

- **The seller's dashboard queried a column that does not exist.** Listings are owned by an
  organization rather than by a seller row, so every catalogue count answered 500.
- **A team listing was a lazy-load away from failing.** `displayName()` reads the profile,
  lazy loading is disabled on purpose, and a list of twenty members would otherwise have
  been twenty extra queries.
- **A permission added to the enum is not a permission granted.** The E2E run caught the
  deployment consequence: the role map is code and the grants are rows, and
  `RolesAndPermissionsSeeder` is what reconciles them.

### Added — Phase 18 (Super admin complete)

- **A permission matrix that no administrative endpoint can escape.** The guarantee is not
  "these endpoints are protected" — that is a list somebody has to keep up to date, and the
  entry that is missing is invisible. It is that *no admin route can exist without a
  decision about who may call it*: route-name prefixes map to permissions, middleware on the
  whole API group consults the map, and a route with no entry is refused at runtime and
  fails the suite at build time.
- **Failing closed.** An unknown admin route is a 403 — not a 404 and not a pass — because
  "we have not decided who may do this yet" is much closer to "nobody" than to "everybody".
  The middleware recognises its own territory by path rather than being attached route by
  route, because a check that has to be remembered is a check that is invisible when it is
  missing.
- **The longest prefix wins**, so reading a settlement and approving one can be different
  powers even though they live under the same prefix.
- **Nine new platform permissions and three roles that mean something.** An analyst reads
  and cannot press a single verb; an operator works every queue but cannot touch the
  platform's own switches; a super admin can. Turning a feature on for everybody is a
  release decision rather than an operational one, and it is the only power on these
  screens whose blast radius is the whole platform.
- **A critical-action audit gate.** Thirteen cases perform an action for real — a bank
  transfer confirmed, a settlement approved and paid, a manual refund, a credit adjustment,
  a seller suspended, a return decided, an order moved, a feature flag flipped, a setting
  changed, a webhook replayed — and then assert the trail: what happened, who did it, and
  where it costs somebody something, why. The trail is append-only in the database, so the
  record of a decision cannot be edited by whoever made it.
- **Feature flags and platform settings that actually do something.** The settlement hold
  period, the return window and three flags are read by the services that obey them, with
  the environment as the floor and a stored row as the override. A settings screen that
  writes rows nothing reads is worse than no screen: it tells whoever used it that they
  changed the platform, and they will act on that belief.
- **A missing flag is on.** A feature that switched itself off because somebody forgot to
  seed a row would be an outage caused by the safety mechanism. Turning something off is a
  decision, and a decision has a row. A partial rollout buckets on a stable hash of key and
  user id, so somebody who has the feature keeps it rather than losing it mid-journey.
- **Switching a payment method off does not strand the money already taken through it.**
  Only *starting* a payment is gated; refunds and late notifications still reach the adapter
  that understands them.
- **A secret is never echoed back** — not to the settings screen, not to whoever set it, and
  not into the audit log, which is read by more people than a secret store is. An unverified
  webhook is never replayed either: anybody can post one, and replaying it would let them
  fabricate a payment.
- **The admin panel's own screens.** A dashboard that leads with the queue of things still
  waiting for a person rather than with totals, an order search by the two things a caller
  on the phone actually has, an audit viewer with the caller's own permissions beside it —
  so a button they cannot press is explained by a page rather than by a 403 — and a system
  screen for flags, settings, failed jobs and unprocessed webhooks.

### Fixed — Phase 18

- **A feature check could crash the feature it guards.** The flag model was cached, and an
  Eloquent object read back out of a shared cache by another process comes back as whatever
  that process can reconstruct — here an incomplete class and a fatal error, which took out
  seller onboarding, AI jobs and the bank-transfer method together. Two scalars are cached
  now, and a test pins the service and the model to the same rollout arithmetic.
- **The audit list read columns that do not exist.** The viewer mapped `subject_type` and
  `subject_id`; the table stores `auditable_type` and `auditable_id`, so the one screen an
  investigation starts from answered 500.
- **A seller could no longer read their own record.** It was served from
  `/admin/sellers/{id}`, where the new rule asks whether the caller holds a platform
  permission — which a seller never does. Rather than carve an exception into the
  authorisation rule, the seller got `/api/v1/seller/profile` and the administrative path
  stayed administrative. An authorisation rule with an exception in it is a rule nobody can
  state.
- **PHPStan caught two unsound assumptions** in the new code: iterating the route collection
  through an interface that only promises countability, and an untyped array return on the
  settings model.

### Added — Phase 17 (Shipping, returns and refunds)

- **Shipments as parcels, not as a status.** A seller order can ship in several — a sofa
  and its cushions leave on different days — so the quantities live on the shipment lines
  and the order only becomes "shipped" once everything ordered has actually gone. A seller
  who dispatches one of four chairs and sees "kargoya verildi" has been given a status that
  will confuse their customer for a week.
- **Returns per line and per quantity.** A customer who bought four chairs and wants to
  return one is the ordinary case; an order-level model turns it into a support
  conversation. Every line carries both a requested and an approved quantity, because a
  seller opening the box and accepting two of three is normal.
- **`received` and `completed` as separate states**, with separate buttons. A parcel
  arriving is a physical fact; deciding the return is finished is what releases money. One
  button would turn a courier's delivery scan into a refund.
- **Refunds with their own lifecycle**, deliberately not folded into the return. Goods and
  money travel on different timetables: a provider can refuse a refund on a payment that is
  too old, a bank can take days, and a goodwill refund has no return behind it at all. A
  failed refund is a state that can be retried, because the customer is owed the money
  either way — and nothing is posted to the ledger until the money has actually gone.
- **The reversal is split by share.** The seller's payable comes down by their part and
  commission by its part, at the rate that was charged. Posting the whole refund against
  commission would make the platform pay for the seller's return; keeping the commission
  would mean the platform earns on a sale that did not happen.
- **Goods are restocked when they arrive, not when the return is approved** — and only the
  quantity that was accepted. Restocking on approval would put a sofa back on sale while it
  is still in a courier's van.
- **An open return holds the seller's payout** and says so on their earnings page, before
  the release date rather than after it: a date on its own is misleading while a return is
  running, and a seller who planned around it would be owed an explanation twice.
- **The settlement hold can never be shorter than the return window.** A configuration
  where it was would pay a seller while the customer could still send everything back, so
  the larger of the two is taken and the misconfiguration is harmless instead of expensive.
- **A returns section in the storefront** — a list that says where the goods are and,
  separately, where the money is — and a returns queue in the seller portal where accepting
  part of a request is a number per line rather than a yes or no.

### Fixed — Phase 17

- **A refused refund could never be retried.** Refund attempts were deduped on an operation
  key that included failed ones, so a retry short-circuited and reported success — and the
  unique index would have refused the row anyway. A provider outage would have left a
  customer permanently unrefunded with the record saying otherwise.
- **"No exception" was treated as success.** The payment processor records a provider
  refusal rather than throwing, because a decline is an answer rather than an error; the
  refund service now reads that record back instead of assuming.

### Added — Phase 16 (Commission, ledger and settlement)

- **A double-entry journal, append-only.** Every financial event is a set of lines that sum
  to zero, and neither the entries nor the lines can be updated or deleted — a mistake is
  corrected by a reversing entry so both stay visible. That is the difference between a
  ledger and a table of numbers, and it is enforced by database triggers rather than by
  convention.
- **Balance checked twice.** In the service, with a message naming both figures, and again
  by a **deferred constraint trigger** that runs at commit. The deferral is the point: an
  entry is built line by line and can only be judged as a whole.
- **A marketplace's cash treated as what it is.** A sale debits cash and credits each
  seller's payable plus commission revenue. Posting the customer's payment as income and
  the payouts as expenses would balance perfectly and describe a different business — one
  that looks enormously profitable right until it pays its sellers.
- **The commission hierarchy from the finance rules**, six rungs deep: the order item's own
  snapshot, then a campaign, seller+category, seller, category and the platform default. It
  is resolved once, at order time, and copied onto the line — re-resolving later would let a
  rate change rewrite what a seller earned last quarter. The decision carries the rule that
  produced it, because "why is my commission 14%" is the question sellers ask most.
- **Settlement eligibility with reasons.** Payment captured, goods delivered, hold period
  passed, nothing open against it, seller still trading, not already settled. Each order a
  seller is waiting on carries a sentence — "12.09.2026 tarihinde hakedişe girer" — rather
  than a status code, because a date is something a seller can plan around.
- **A three-step payout.** Building is arithmetic and can be re-run and posts nothing;
  approving commits the money into a clearing account so it cannot be counted twice or
  swept into a second run; paying is a person recording that a transfer left, with the
  bank's own reference. Collapsing them would turn a mistake in the arithmetic into a bank
  transfer, and a nightly job that approved its own payouts would pay a suspended seller at
  three on a Sunday morning.
- **The same order can never be in two settlements**, enforced by a unique index rather than
  by care — a bank transfer is not something anybody can recall.
- **Seller balances as a projection**, rebuilt from the journal rather than incremented. If
  it ever disagrees, the journal is right and this is rebuilt; incrementing would mean every
  write has to be perfect forever.
- **An earnings page in the seller portal** showing four figures rather than one — ready,
  pending, in payout, paid — because the money really is in four states, and a single
  "bakiye" is how a seller reads a number they cannot yet have.
- **A finance screen in the admin panel** that says whether the books balance before it says
  anything else, lists the account balances and the journal, and keeps approving and paying
  as two separate buttons.

### Added — Phase 15 (Orders and seller orders)

- **One order for the customer, one per seller inside it.** A marketplace order is two
  things at once: the customer paid once for a basket and will ask about it by one number,
  while each seller received a separate instruction with their own parcel, their own status
  and their own money. Modelling only the first leaves every seller screen filtering a
  shared table by hand; modelling only the second leaves a customer with three orders they
  never placed.
- **Every line is a snapshot.** The product name, the SKU code, the price, the tax rate and
  the commission are copied at the moment of the order. A product renamed next month must
  not change what an invoice from last month says it was, and a seller who renegotiates
  their rate must not retroactively change what they earned. An order is a record of an
  event, not a view over the current catalogue.
- **A status machine per seller order, and a master status derived from them.** Nobody sets
  the customer's status by hand — it is computed after every change, because a summary that
  can be written independently of what it summarises will eventually disagree with it.
  `partially_shipped` exists because telling a customer their order has shipped while two
  parcels are still on shelves is technically true and practically a lie.
- **A seller cannot cancel what has already left.** After the van it is a return, with a
  different set of rights. Cancelling before that puts the stock back on the shelf, because
  the stock left when the payment was captured and a warehouse that disagrees with the
  ledger only reveals it weeks later as a sale nobody can fulfil.
- **One payment makes one order**, guaranteed by a unique index on the checkout session
  rather than by the caller being careful — the same defence that protects the credit load
  beside it.
- **An append-only status history** with who changed it, when, in what role and why. "When
  did this become shipped, and who said so" is the question every dispute starts with, and
  a table that can be edited cannot answer it.
- **Order numbers people can read out.** `RC-2026-001234` for the customer and
  `RC-2026-001234-2` for the second seller in it, so a seller and a customer on the phone
  are obviously talking about the same order. Allocated from a database sequence, because a
  count is a race and a random string is unreadable.
- **Orders screens** in the storefront — a list and a detail grouped by seller — and a
  working queue in the seller portal that opens on what still needs packing, shows what the
  seller will actually be paid next to what the customer paid, and takes its available
  moves from the server rather than from a second copy of the rules in a Vue file.
- **Sellers are told when they have something to pack**, and customers when one of their
  parcels leaves — by name, because "kargoya verildi" without one raises more questions
  than it answers on a three-seller order.

### Added — Phase 14 (Havale / EFT)

- **A payment method with no provider in it.** The customer transfers money to one of the
  platform's own accounts and a person in finance confirms it against a statement. It goes
  through the same gateway contract, the same state machine and the same fulfilment path as
  a card payment, so nothing downstream has to know how the money arrived.
- **A reference built to be typed.** It is the only thing tying a line on a bank statement
  to an order, so it is unique for all time rather than merely among live transfers, and it
  is drawn from an alphabet with no 0/O and no 1/I/L — a character pair that is identical in
  one bank's font is a payment nobody can match.
- **Short and over payments as named states.** People transfer the wrong figure constantly:
  a typo, an intermediary bank's fee taken in transit, two orders paid in one go. A boolean
  "paid?" forces an operator to decide privately whether 4.997,50₺ is close enough and
  leaves no trace of the decision. A shortfall releases nothing and states the figure still
  owed; an overpayment releases the order and records a surplus somebody owes back.
- **Stock held for the transfer window, not the card window.** Two days rather than fifteen
  minutes, because a customer told their goods are reserved and then losing them overnight
  has been lied to. It is a real cost borne against a payment that may never arrive, which
  is why the window is configured and why an unpaid transfer is expired promptly — and why
  expiring one returns its own stock rather than waiting for a second timer to agree.
- **Confirmation happens once**, enforced three ways: a row lock, a state check that refuses
  the second operator with a sentence rather than a blank error, and a partial unique index
  behind both.
- **Reading a payment and settling one are separate grants.** Answering "did it arrive" is a
  support job; deciding that it did releases goods and cannot be undone. An analyst gets the
  first and not the second.
- **Receipts on the private disk**, under random keys, reachable only through a five-minute
  signed link issued after a permission check — the same tier as seller onboarding
  documents, because a bank's PDF carries an account number and a balance.
- **A finance screen that does the arithmetic in front of the operator.** The received
  figure has to be typed rather than defaulted, and the difference from what was expected is
  shown before the confirm button does anything — because a number already in the box is a
  number that gets accepted without being read.
- **A transfer page in the storefront** with the reference above the account details,
  copyable in one tap, and the instruction to include it repeated where somebody skimming
  will still see it.

### Added — Phase 11 (Checkout and payment core)

- **A checkout session that freezes what is being paid for.** Between pressing "pay" and
  the bank answering there is a redirect, a 3DS page and often several minutes — and in
  those minutes a seller can reprice and an address book can be edited. The session copies
  the totals and the address text in and stops asking, so the amount charged is the amount
  agreed and the parcel goes where it was promised.
- **A payment state machine with declared transitions.** Providers deliver news out of
  order: a capture can arrive before the browser has come back from 3DS, a failure can
  follow a success because an older retry was queued behind it. A transition that is not
  listed is not applied — a late "failed" against a captured payment is dropped, because
  the alternative is a record saying we were not paid while the money sits in the account.
- **A webhook inbox: received first, understood later, never twice.** The endpoint writes
  a row and answers 200; a queued job works out what it meant. Doing the domain work inline
  is how a slow database turns into a provider retry, then a second delivery, then a
  customer credited twice. Duplicates are answered 200 for the same reason — a provider
  told a duplicate failed will resend it forever.
- **Two duplicate defences.** The inbox dedupes on the provider's own event id *and* on a
  fingerprint of the raw body, both unique indexes so a simultaneous double delivery is
  settled by PostgreSQL rather than by a check-then-insert both copies pass. And because a
  provider may send two genuinely *different* events carrying the same news, the state
  machine catches what no fingerprint can.
- **`Idempotency-Key` on the one route where a duplicate costs money.** A browser on a bad
  connection retries. A mobile app retries on timeout. Both get the first answer back, byte
  for byte. The same key with a different body is refused rather than answered with
  somebody else's result, and a failed answer is never stored — freezing a transient error
  into a permanent one for that key would be worse than the error.
- **An append-only payment record**, enforced by a PostgreSQL trigger rather than by an
  Eloquent guard a raw query would walk past. Every call to a provider and its outcome,
  including the ones we were told and deliberately ignored. When a customer says they were
  charged twice, this table is the answer, and it is only an answer if nothing can quietly
  edit it.
- **A provider contract with five methods and nothing else**, and a marketplace settlement
  capability kept separate because most providers do not have one. An adapter translates
  vocabulary; it does not retry, does not write to the database, and does not decide what a
  successful payment means. Adding iyzico cannot introduce a second set of rules about when
  a payment counts as paid.
- **A test provider that behaves like a real one** — immediate capture, 3DS, a decline, a
  timeout, a refund, duplicate webhooks — with no network call, chosen by card token rather
  than by amount. It is what lets the payment tests be part of the ordinary suite instead of
  something somebody remembers to run against a sandbox once a release.
- **Card data never enters the codebase.** No PAN, no CVV, no expiry: the customer types it
  on the provider's own page and we receive a token or a redirect. Provider responses are
  redacted before they are stored, belt and braces.
- **A payment page and a return page** in the storefront, a working "Satın al" on the credit
  packages where a placeholder used to promise one, and a checkout that gives the stock back
  the moment somebody changes their mind.

### Fixed — Phase 11

- **A basket emptied by its own stock hold.** A customer taking the last of the stock into
  checkout and then re-reading their basket was told the thing they were buying was sold
  out, and the lines were removed. Revalidation now counts a cart's own reservations as
  available to it, and "withdrawn" is separated from "none left" so the ledger stays the
  authority on quantity.
- **A sold-out listing that stayed on sale.** `product_skus.stock_quantity` — what the
  catalogue's list query reads — was written only by the seller's own stock endpoint, so
  buying the last unit left the listing advertising stock until a seller happened to open
  the stock page. The inventory ledger now keeps the projection in step on every movement.

### Added — Phase 10 (Search, favourites and the basket)

- **A multi-seller basket.** Lines are grouped by who is selling them, because that is what
  a marketplace basket is: several parcels from several shops, arriving on different days.
  The seller is recorded on the line rather than looked up through the offer, so a basket
  keeps saying which shop something came from even after the listing is withdrawn.
- **Stock is not held while a basket sits there.** Holding it would mean a browser tab left
  open for a week keeps a sofa off the market, and a marketplace's job is to sell the sofa.
  The hold is taken at checkout, for fifteen minutes, by the ledger built in Phase 4 — all
  of a basket or none of it, with rows locked in a fixed order so two baskets queue instead
  of deadlocking. Backing out releases immediately rather than leaving a sofa unbuyable
  while somebody else is told "sold out".
- **A price is snapshotted when a line is added, and never silently changed.** Revalidation
  reports what moved: a rise is shown with both figures and has to be accepted, a fall
  blocks nothing, an item that sold out is removed and said so, and one short of stock is
  reduced rather than dropped. Charging a customer more than they were shown is the failure
  this whole mechanism exists to prevent, and finding out at payment is the worst moment.
- **Tax is counted as part of the price, not on top.** Turkish prices are quoted inclusive
  of KDV: 20.000₺ at 20% contains 3.333,33₺ of tax. The other way round overcharges every
  customer by a fifth.
- **Hybrid search**: a trigram match on the name for the misspellings a search box actually
  receives, full-text over the description, and a vector for meaning — fused by rank rather
  than by score, because a similarity, a `ts_rank` and a cosine distance are numbers on
  unrelated scales and adding them is arithmetic without meaning.
- **The vector ranks but does not decide.** Measured against the live embedding model, pure
  nonsense sits about 0.35 from its nearest product and a real keyword match about 0.30 —
  not a margin to build a search box on. So a query with no lexical footing returns nothing
  rather than answering gibberish with a page of sofas.
- **Facets** for category, style and price band, counted before pagination and excluding
  their own filter — the only way a count tells somebody what is behind a filter they have
  not clicked yet. Empty bands are not offered at all.
- **Favourites**, per product rather than per offer: favouriting a sofa means the sofa, and
  a favourite that broke when one seller went out of stock would be a promise the feature
  never made. A withdrawn product leaves the list but keeps its row, so re-listing brings
  it back.
- **A cart page and a favourites page** in the storefront, and a working basket button on
  the product page where a placeholder used to say the feature was coming.

### Added — Phase 9 (Product matching)

- **A design now comes with a shopping list.** Every placement in the plan — "a sofa up to
  2200mm against the south wall" — becomes a shortlist of products that are in stock, fit,
  cost less than the budget allows, and look like the design.
- **Narrow first, then rank.** Category, stock, budget and width are applied in SQL before
  anything is scored, because a model asked to respect "no wider than 2200mm" will sometimes
  and a `WHERE` clause always does. What is left for the vector is the part that is
  genuinely a matter of resemblance.
- **Semantic search over the catalogue**, using pgvector with an HNSW cosine index. A
  customer asking for "warm minimalist oak" finds a sofa a seller described as "İskandinav
  meşe iskeletli" without either phrase containing the other — which full-text search cannot
  do, and which a synonym list large enough to fake would need maintaining forever.
- **A category that matches nothing returns nothing.** Silently dropping the filter would
  let the search fall back to the nearest products in the whole catalogue, which is how a
  plan asking for a chandelier ends up recommending a wardrobe with nothing looking wrong.
- **The rerank is optional in the strongest sense.** A model reorders the shortlist — only
  the shortlist, because a rerank over four hundred candidates is a bill — and if the call
  fails, the list built from similarity is returned unchanged. Its opinion is blended 60/40
  with the similarity rather than replacing it, so a model with a favourite cannot bury a
  genuinely closer match.
- **Prices are snapshots.** A customer who returns next week sees the list they were shown,
  with today's price beside it when the two differ. Hiding the change would be the wrong kind
  of tidy: the difference is the most useful thing the row can tell them.
- **Feedback, because everything else is the system marking its own homework.** Six verdicts,
  each naming the part of the pipeline it blames — wrong size is a filter bug, wrong style a
  modelling problem — so a week of clicks is readable. Every verdict is kept rather than the
  latest overwriting the last, and the one thing it changes automatically is that a rejected
  product is not suggested again for that spot.
- **Catalogue embedding is hashed and scheduled.** `refconcept:embed-catalogue` runs nightly
  and is safe to repeat: a product whose text has not changed costs nothing. The text
  embedded is assembled from what describes the product, in a fixed order, with the seller's
  name and delivery terms deliberately left out — two sofas from the same shop must not be
  similar *because* of the shop.
- **Matching cannot fail a design.** It runs as the last step of the generation pipeline and
  is unable to fail the version: a render the customer paid for is not lost because the
  catalogue happened to have no sofas in their budget.

### Added — Phase 8 (AI room analysis and design generation)

- **The design engine**: a room photograph becomes a finished render in three model calls —
  read the room, decide the layout, draw it — with the arithmetic in between that stops the
  layout asking for furniture the room cannot take.
- **A room is read once.** The analysis is cached against the photograph rather than the
  design, because a room does not change when somebody tries a second style. The second
  render of the same room reuses the first reading, and the quote drops the step so nobody
  is billed for a call that will not happen.
- **The plan is kept, and it is what the shopping list will be built from.** "A sofa up to
  2200mm against the south wall, in oak and cream" is a product search; the picture is not.
  A plan is immutable once written, by a database trigger, because it is the row that
  answers "why is there a sideboard there".
- **Placements are checked against the room.** A model will cheerfully put a 2600mm sofa
  against a 2200mm wall, and the render will look fine because an image is not to scale —
  the customer discovers the problem when a delivery van arrives. What does not fit is
  recorded with its reason rather than silently dropped, because a plan that quietly loses a
  piece of furniture produces an image and a shopping list that disagree.
- **One charge for a design, not one per step.** Credits are held when the version is
  created and settled when it finishes; the three model calls underneath run at zero
  customer cost. Every failure — a provider refusal, a render with no image, a dead worker —
  returns the whole hold.
- **Progress a customer can watch.** Each step writes an append-only event, the page polls,
  and the bar is driven by which stage the engine announced rather than by elapsed time. A
  bar fed by real durations jumps about as providers vary; one fed by stage boundaries moves
  predictably. Polling that keeps failing stops and says so, rather than leaving a spinner
  that has quietly given up.
- **Two render qualities** — a quick preview and the one you show people — chosen by the
  customer, priced from the AI routes, and stored on the version so a route repointed next
  month cannot rewrite what a version already in the tree was.
- **Provider images are staged on the private disk.** They cannot travel in a job row, and
  they must not sit on an anonymously-readable bucket: what passes through is a render of
  the inside of somebody's home. The pipeline copies the bytes to the design's own storage
  and discards the staged copy.
- **Turkish case-folding, in one place.** `mb_strtolower('İ')` produces an i followed by a
  combining dot rather than a plain i — so a spreadsheet column headed "İndirimli fiyat"
  folded to "i ndirimli fiyat", matched no alias, and the discount prices silently never
  arrived. `TurkishText` folds before lowercasing and is now used by every place that
  compares Turkish text.
- **Running out of credits is a 422 and a paused feature is a 503**, rendered once at the
  application boundary rather than caught in each controller — so a new caller cannot forget
  to, and the customer gets the two numbers they need.

### Added — Phase 7 (Credit economy)

- **An immutable credit ledger, and a wallet that is only ever a snapshot of it.** Every
  movement is an append-only row carrying the balance it produced; `credit_wallets` exists
  so a page load is one row rather than a sum over a year of history. Both are written
  inside one locked transaction, so they cannot drift — and when they ever did, the ledger
  wins. Append-only is enforced by a PostgreSQL trigger rather than by an Eloquent guard a
  raw query would walk straight past: this is the table a customer's complaint gets settled
  against, and a mistake is corrected with a compensating entry the way a mistake in any
  ledger is.
- **Credits expire in lots, soonest deadline first.** A balance cannot expire; a grant can.
  Fifty credits bought in March and ten from a promotion in June are one number in a wallet
  and two different deadlines, so consumption draws from batches in deadline order. Spending
  the long-lived credits first would silently destroy the ones with a date on them, and the
  customer would see a balance drop for no reason they could find.
- **A hold is not a charge.** An AI job reserves its cost before it is queued and either
  consumes or releases afterwards, so a render that failed because a provider timed out
  costs the customer nothing — that is our problem, not theirs. Three attempts against a
  flaky provider is still one charge: the retry is our decision and our cost. A customer who
  cannot afford a render is told so while they are still looking at the button rather than
  handed a job id and a failure four seconds later.
- **Every mutating path is idempotent on a caller-supplied reference.** A client retrying a
  request whose response it never saw is the normal case, not the exceptional one, and
  answering it with a second charge is the failure worth engineering against. A hold settles
  exactly once however many times a duplicate queue delivery asks it to.
- **The database refuses what the application should not have asked for.** A negative
  balance, held credits exceeding the balance, a rate window that ends before it begins, and
  — the one worth naming — a movement whose direction contradicts its type. A "consume" that
  adds credits is not a rounding error, it is free money, and it would balance perfectly in
  every report.
- **A hand correction demands a reason, in the schema.** It is the only movement that
  happens because a person decided it should, and "why do I have forty fewer credits than
  yesterday" needs an answer better than "somebody ran a script". Both the member of staff
  and their reason reach the customer's own statement, not only an internal log. A
  correction that would drive a balance below zero is refused rather than clamped.
- **Promotion codes written on the assumption that somebody is attacking them.** The
  promotion row is locked before its redemptions are counted, so two simultaneous claims
  cannot both find room under the limit. An unknown code, an ended campaign and an exhausted
  budget all return one identical refusal, because distinguishing them turns the endpoint
  into an oracle that enumerates live campaigns. Redemption is rate-limited per account and
  requires a verified e-mail — without which a promotion is a free-credit machine for
  anybody willing to type a different address each time. "Already redeemed" *is* said
  plainly, because the person asking has already proved they know the code.
- **An hourly sweep** that expires dated lots and returns holds nobody came back for.
  Expiry alone would be fine once a day; the holds set the cadence, because credits a
  customer cannot spend while their screen says they can becomes a support ticket within the
  hour. An abandoned hold is recorded as `expired` rather than `released`, because a release
  is a system that finished its job and an expiry is a request that vanished.
- **Credit tables restrict deletion rather than cascading.** A financial record outlives the
  account it belonged to, which is what tax retention requires anyway. Erasing an account
  means anonymising the person and keeping the money — an explicit, audited procedure rather
  than a side effect of a foreign key.
- **A customer's credits page**: available and total balance, what is about to expire shown
  *above* the statement rather than buried in it, a promotion code field, the packages on
  sale, and a statement with holds filtered out — a reserve followed by a consume is one
  event to the person who ran a render, and three lines for it is how a statement becomes
  something nobody checks.
- **A credits screen in the admin panel**: packages and campaigns with their redemption
  counts, budgets and windows, and a switch for each. Adjusting a balance lives behind a
  wallet lookup rather than on the list, because a correction should be something somebody
  arrives at after reading an account's history.

### Added — Phase 6 (AI gateway foundation)

- **One gateway between RefConcept and every model.** Which provider, which model, which
  prompt version, what timeout, how many retries, what a call may cost, how many a
  customer may run at once, and whether the feature runs at all are rows in
  `ai_task_routes`. Nothing in the application writes a model name in a string literal,
  so moving a task onto a cheaper model — or off the site entirely — is configuration
  rather than a deploy, and every change is audited.
- **All the policy in one place.** Adapters translate one call into one answer and
  classify what came back; retries, fallback, cost ceilings, recording and structured
  output validation belong to the gateway. An adapter that also retried would be a second
  home for the retry rule, and the second home is always the one that drifts.
- **Failures are values, not exceptions.** A timeout, a rate limit, a malformed answer and
  a safety refusal mean four different things, and the classification is what decides
  whether to try again, try somebody else, or stop. A safety refusal is not retried — the
  same provider will refuse identically — but it *does* warrant a fallback, because
  providers draw the line in different places.
- **Cost is checked before the call, not after.** An estimate that passes a ceiling and
  then overshoots it has protected nothing. Prices live in `ai_cost_rates` in **micros**
  (millionths of a currency unit, the one documented exception to minor units, because a
  thousand tokens can cost a fraction of a cent) with a validity window, so a job run in
  March keeps reporting March's price however often the rate has moved since.
- **Prompts are versioned and, once published, immutable** — enforced by a PostgreSQL
  trigger rather than by convention, because one UPDATE would silently rewrite the history
  of every job that ever ran against that wording. Improving a prompt means the next
  version, which leaves the old one readable beside the jobs that used it. Version numbers
  are assigned under a row lock, and a version can be previewed against sample input
  without calling anything.
- **Every attempt is recorded** — `ai_requests`, `ai_usage`, `ai_failures` — including the
  ones that failed, because a provider that read the input and then refused still charged
  for reading it. Credits are counted once per job, never per attempt: a customer must not
  pay three times because a provider was flaky.
- **A kill switch per task**, with a mandatory written reason that appears on the console
  rather than only in a log. It refuses at the dispatcher as well as at the gateway, so a
  paused feature does not quietly accumulate a queue of jobs that will all fail.
- **A customer's AI job is as private as the room it describes.** Its input holds the link
  to a photograph of their home and whatever they typed about how they live in it, so
  `AiJob` joins projects and rooms in the exclusion from the super-admin bypass. Platform
  staff get the operational view — task, model, timings, cost, failure kind, the rendered
  prompt — and never the payload. An image URL travels as an attachment and never as
  prompt text, because a URL in a prompt is a URL a model can repeat back into an answer
  somebody else reads.
- **Adapters for Google Generative AI and OpenAI**, each translating that provider's own
  vocabulary of failure into ours. Two traps are handled explicitly: Google reports a
  safety refusal as an ordinary `200` with a `finishReason`, and OpenAI reports both a
  genuine bad request and a content-policy refusal as a `400`. The Google key travels in a
  header rather than the query string, because query strings reach access logs.
- **A deterministic fake provider** so continuous integration exercises the whole AI path
  on every commit without spending a lira. Its answers derive from the call's fingerprint,
  and it can be scripted to produce any failure on demand — which is how the retry,
  fallback, cost-cap and kill-switch paths are provoked exactly rather than hoped about.
- **Idempotent dispatch and per-user concurrency limits.** A customer who taps "render"
  twice, or a client retrying a request whose response it never saw, gets the same job
  back rather than a second charge. The limit is per user per task, so one person queueing
  forty renders neither delays nor locks out anybody else.
- **The AI control room** in the admin panel: every task with its model, prompt version,
  credit cost, success rate, average latency and spend over a chosen window; the failure
  breakdown; provider keys shown as a four-character hint and never in full; and the pause
  and resume buttons. Tasks with no route are highlighted, because an unrouted task is a
  feature that fails the first time a customer touches it and is silent until then.
- **A seeder that ships all twelve tasks routed and prompted**, so the gateway works the
  moment the database exists. With no provider key on file it routes everything to the
  local simulator and says so, rather than shipping twelve features that fail on first use.

### Added — Phase 5 (Projects, rooms and design versions)

- **Projects**: a customer's home, or the part of it they are working on. Owner,
  status history, an optional budget in minor units, and an address reused from their
  own address book rather than duplicated.
- **Rooms** carrying their envelope and an honest `measurement_quality` — estimated,
  measured by hand, scanned, verified — because the difference changes what a design
  *means*: a sofa against a guessed wall is a suggestion, one against a measured wall
  is close to a promise. A database constraint refuses a room that claims to be
  measured while leaving the numbers empty.
- **Room constraints**: windows, doors, radiators, columns, placed against a wall at an
  offset. "There is a window" decides nothing; where it is and how wide it is decides
  whether a 220 cm sofa fits under it.
- **The strictest privacy tier in the system.** Room photographs go on the private disk
  under random object keys. No response ever contains a URL or a storage path — a link
  is a separate request that runs the ownership check and expires in five minutes — and
  the models have no `url()` method at all, because there is nowhere to point one. The
  filename is deliberately kept out of the audit log.
- **Platform staff excluded from the super-admin bypass** for customer projects. That
  bypass is right for operational tables and would have been silently wrong here; the
  exclusion is matched on model class rather than ability name, and both directions are
  asserted.
- **Designs as a tree, not a list.** Every version records the version it came from, so
  "make the sofa darker" branches rather than overwrites and the version somebody liked
  is always still there. Numbers are chosen under a row lock and never reused even after
  a failure, only a finished version may be branched from, and a finished version never
  changes.
- **The original is immutable, structurally**: AI renders live in `design_assets` and
  the customer's own photographs in `room_media`, with different writers, so there is no
  code path that could write one over the other.
- **Project sharing** for the ordinary case — a partner, an interior designer. Invited by
  e-mail because the person you want to show your living room to usually has no account
  yet; the invitation token is hashed, returned once, bound to the invited address,
  expires in two weeks and is burned on use. Revoking is recorded rather than deleted.
- Storefront: projects, rooms with a photograph gallery, measurements in centimetres,
  constraints, and the design version tree drawn as a tree.
- `RoomType` is now one vocabulary shared with the product catalogue, which is what
  makes matching possible: a bedroom design offers bedroom furniture because both sides
  agree what a bedroom is.

### Fixed — Phase 5

- **The super-admin authorization bypass would have let platform staff open any
  customer's project** and look at photographs of their home. Correct for operational
  tables, silently wrong for this one.
- *(Carried from Phase 2)* `DocumentStorage` fell back to a route name the router never
  registers, so a deployment without object storage would have returned 500 on every
  "view document". Every environment RefConcept is tested in can sign a URL, so nothing
  exercised it; a test now asserts all three download route names resolve.
- `DesignVersionRefused` declared a readonly `$code`, which PHP refuses to redeclare
  over `Exception::$code` — a fatal error at class load.
