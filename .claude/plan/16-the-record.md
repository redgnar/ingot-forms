# 16 — a form somebody can file

The third entry of [10](10-what-a-vendor-offers.md)'s list: *"a PDF of a confirmed form. Not
their template designer — one road: a confirmed document to an archival PDF. The page already
knows how to draw every value, list and attachment."*

That last sentence is the one thing in the entry that turned out to be wrong, and finding out
why is most of what this stage decided.

## Four decisions, and the reasoning that settled each

**Not the page.** Rendering the existing HTML with headless Chrome is the obvious reuse, and it
was refused twice over. The first reason is operational: `docker/Dockerfile` says
"Development / test image" — there is no production image here, a deployment builds its own, and
a deployment of this service needs PHP, a database and somewhere to put files. Wanting a
*browser* as well, or a second container to talk to, is a demand on everybody who installs this,
for the sake of one endpoint. The second reason is the document itself: a page carries triggers,
the reader's own switches and whichever skin the presentation named, so an archival copy would
look different because a form was dressed differently — and a skin is *how a page looks* and may
never change what a document says. Every one of those would have had to be suppressed, so the
"reuse" was never free either.

So: `dompdf`, one plain template of its own, and it is told it may fetch nothing at all
(`setIsRemoteEnabled(false)`) — a renderer that cannot reach out cannot be made to reach
somewhere it should not, or to hang on a network. It needs `ext-dom` and `ext-mbstring`, both
already in the image; `gd` is not needed because nothing raster is embedded. Dompdf understands
tables and little else of modern CSS, which for a record is a fit rather than a limitation.

**It needs no presentation**, and this is the decision that mattered most. `PresentedNodes`
refuses a form that has none, and rightly — a page has nothing to draw. But a presentation is
*optional* in this service, and the deployments most likely to want an archive are the ones that
never draw a page at all. Requiring one would have left exactly them without a record. A record
is of what was asked and what came back, and the definition says both: without a presentation
the labels are the item names in declaration order; with one, it decides the order, the labels
and how each option reads.

**On the management prefix**, which follows from the identity rule rather than from taste. A
record names the author and the confirmer, and an actor is "recorded, never consulted… served on
the manage side only, never displayed on any page". A document naming who closed a form
therefore belongs to the audience that already knows their name. The consequence was accepted
knowingly: a page cannot offer "download your copy" today. That would be the same document minus
the actors, which is a second variant of one thing and a decision for whoever asks for it.

**Nothing is stored.** A confirmed form cannot change, so the document is the same every time it
is asked for. A copy kept in the file store would be a second representation of the same values,
with a lifecycle of its own to clean up, a question about what to do when a render fails *during
a confirmation* (a write must not fail because a rendering did), and a rendering that quietly
stops matching the code that produced it. Whoever needs a frozen artifact keeps the bytes they
downloaded — which is what an archive is.

## One thing this stage was allowed to add, and one it had to extract

`FormRecords` is **the one place in PHP that turns a stored value into text**. Nothing like it
existed: the kits format in Twig and in JavaScript, each for its own controls, so this duplicates
nothing — but it is now the only one, and a second copy is the thing to refuse. A tick stays
`true`/`false` rather than becoming "yes", because those two are words and words are the
catalogue's; everything else needs the definition to be read at all (an option's wording, the
offset of a moment, what a file was called), and the definition is here.

Resolving a translation code, on the other hand, already existed — inside `PresentedNodes`, as a
private static with the fallback chain in it. Two readers of one presentation would have been two
chains, and the day they disagreed a form would read one way on screen and another on paper. So
it came out as `Words` in the domain, and the page delegates to it in one line.

## What it cost

| | |
|---|---|
| Domain | `Words` (extracted, not new) |
| Application | `RecordSheet`, three row types behind `RecordedRow`, `FormRecords`, `ReadFormRecord`, the `RecordDocuments` port, `FormNotConfirmed` |
| Infrastructure | `DompdfRecordDocuments` and one print template |
| UserInterface | one action, one query DTO, one line in the problem table |
| Dependencies | `dompdf/dompdf` — and the lock moved nothing else |

No migration, no column, no cleanup, no new env var, and no new demand on a deployment.

## What looking at one changed

The first record of a real form was read on paper before anything was called finished, and it
answered a question the design had got half right. A `card` labelled "When and where" was
**stepped through** — its three questions printed beside everything else, its label gone. The
reasoning behind that was sound as far as it went (a record has no cards, and a skin may never
change what a document says) and it dropped something that was not a shape: the label is a
sentence somebody wrote *about those three questions*, and losing it loses part of what was
asked.

So a container keeps its words and loses its shape. One with a label becomes a `Section`; one
with no label is still stepped through, because a heading with no words is a line where a
sentence should be. That made a third kind of row, which made the rows three types behind an
interface rather than one class with the members of all three — the same reasoning `PresentedNode`
was split under, arrived at from the other end.

## Found on the way, and settled the next day

The first record printed of a demo form showed **`Utworzył local-dev` on a form declared
`identity: anonymous`** — which reads like a privacy bug against the sentence "a form may
instead be declared anonymous and record nobody". It was not a bug but a decision: a test
asserted it on purpose (`testTheAuthorIsRecordedWhateverTheFormDoesWithItsFillers`), reasoning
that an author is the *system* that created the form while `anonymous` is a promise to whoever
fills it in. The fix was written, then reverted, because flipping the meaning of a documented
immutable property is not a decision to take while building something else — and it was put to
the owner instead.

**The owner reversed it**, with the argument the old reasoning was missing: `anonymous` is
**configuration**, and the party configuring it is the very party the old rule kept recording.
Asking for a form that records nobody is asking for that about oneself as well, and it costs
nothing — a system that created a form has not forgotten that it did. Keeping the author made
"this form records nobody" a sentence in a document rather than a property of the form, which is
what the mode exists to prevent, and behind a proxy asserting on every request it named the
creator of every anonymous form there had ever been.

So the discard moved into the constructor, where every way of building a form goes through it
— including `Form::fromState()`, which means a row written under the old rule reads back with no
author anywhere. That is not a read judging what it reads: nothing is refused, the mode simply
cannot hold that value, and an invariant a read can walk around is not one. The data at rest is
repaired too (`Version20260904090000`), because a column holding somebody on a form that promises
to hold nobody is a promise kept only by whichever code happens to read it.

The discard is still never a *demand*: creating a `recorded` form with nobody asserted stays
allowed, since a deployment may put its proxy in front of the pages and not in front of whoever
creates the forms. `recorded` fails loudly at the first save, which is the whole reason it is the
default.

## Not built, on purpose

- **No copy for the person filling the form in.** See the prefix decision above.
- **No PDF/A.** It changes the library (`mpdf`) and drags in `ext-gd`, so it is a decision for a
  deployment that actually has to satisfy an archiving standard — and it changes the image.
- **No letterhead, logo or footer of a deployment's own.** That is a new category: the moment a
  deployment decides how the document looks, there is a third document to describe.
- ~~**No attachments inside the file.**~~ **Reversed, once there was a signature to record.** The
  reasoning held for *attachments* — a record of the answers, with the bytes one address away —
  and did not survive the first answer that **is** an image: a signature recorded as
  `signature.png — 8.3 kB` describes the answer instead of showing it. So an image is drawn into
  the record (PNG, JPEG, GIF, up to 4 MB), and everything else is still named. It cost a fourth
  row type (`Filed`), the bytes read in the *adapter* rather than in the reading — whether an
  image can become a picture is a question about the renderer — and `ext-gd`, which dompdf needs
  for the alpha channel every canvas PNG has. That extension is **optional** and the record says
  so by naming the file when it is missing, which is what keeps this from being a new demand on
  every deployment. What is still not embedded is an attachment that is not an image: a contract
  is named, and a PDF inside a PDF is a different feature.
- **No record of a draft, and no record of a revision.** A draft is not a record of anything
  yet, and `GET …/history/{seq}` plus a client is what asks about an earlier version.
