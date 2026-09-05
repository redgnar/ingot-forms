# 17 — a signature, which is a file

The sixth entry of [10](10-what-a-vendor-offers.md)'s list: *"a handwritten signature. A widget
whose value is an ordinary file; the file mechanism carries it unchanged."*

That sentence is the whole design, and this plan is mostly about checking it holds and about the
one thing it does not mention.

## What it is, and what it is not

**A widget on a `file` item, not an item type.** The bar for a type here is rules of its own,
and a signature has none: its value is `{id, name, size, type}` like any other file, and "these
bytes were drawn here rather than uploaded" is not enforceable — a client can `POST` anything to
the file endpoint and echo the description back. So `signature` joins `file` and `dropzone` as a
third way of *asking* for the same value, and nothing about the definition, the derived schema,
the gates, the record or the webhooks changes at all.

**The richer kit only.** `core-html` is plain controls, and there is no plain control for this —
the same reason `autocomplete` is bootstrap-only. A document written for `core-html` that asks
for `signature` is refused at creation (`presentation.widget.mismatch`), which is the mechanism
already there for exactly this.

**PNG.** `canvas.toBlob('image/png')`, so the definition has to list `image/png` in `accept` —
and the server sniffs the bytes anyway, so nothing is taken on trust. SVG was considered and
refused: it is markup, and markup is the one kind of upload whose bytes want to be executed.

## The part the entry does not mention: it has to be answerable without drawing

A signature pad cannot be operated with a keyboard, and "a page that cannot be looked at still
has to work" is not an option a document asks for here. So the widget is **a pad *and* the
ordinary picker**, both, always: draw it, or attach a photo of it. That is not a fallback bolted
on — it is what makes the question answerable at all for somebody who cannot draw on a screen,
and it costs nothing, because the picker is the widget this one is built on top of.

The pad gets what every control here gets: the caption naming it, `aria-describedby` tying the
hint and the refusal line to it while both are empty, a **clear** button that says what it does,
and the chip announcing what is now attached (which the file widget already draws).

## How it is put together

The signature controller draws, and knows nothing about uploading. When a stroke ends it turns
the canvas into a `File` and dispatches `file:pick`; the existing file controller does the rest —
the upload with its progress, the check that what came back is a type the item accepts, the
refusal messages, the chip, the hidden control that is the value. That is the reuse the roadmap
entry promised, and it is why this stage adds no new address, no new port and no new gate.

`signature_pad` (vendored through `importmap.php`, like `tom-select`) rather than hand-rolled
pointer maths: smoothing is the difference between a signature and a child's drawing, and
`devicePixelRatio`, coalesced pointer events and touch/pen pressure are three things a first
version gets wrong on the first three devices.

## Three things it has to fix on the way

1. **Redrawing must not litter the store.** Every stroke that lands is an upload, and a person
   redraws a signature two or three times before they like it. So before sending, the widget
   throws away the description it is holding — which is `DELETE …/files/{id}`, already there, and
   already refused (409) for a file a stored save names. So a *temporary* file is collected at
   once, a saved one is untouched, and nothing new decides which.
2. **A save must wait for an upload in flight.** This is an existing silent loss rather than
   something the signature introduces: press *save* while a picked file is still going up and the
   hidden control is still empty, so the member is simply absent from the document. With a pad
   uploading a few hundred milliseconds after the last stroke, it stops being a rare race. The
   file controller keeps its pending upload where the form controller can find it, and the save
   awaits it.

3. **A canvas in a row that sizes itself to its content is 300 pixels wide, for ever.** The file
   widget is a one-line flex row (button, then the chip), and a `width: 100%` canvas inside it
   resolves against a parent as wide as the canvas's own attribute — so the pad drew itself small
   and stayed small however wide the form was. It takes a row of its own, like the progress bar
   already does, and so does the sentence under it. **Found by looking at a screenshot**: every
   test passed, because the markup was right and only the layout was wrong.

## Steps

1. `BootstrapEngine`: `FileField::class => ['file', 'dropzone', 'signature']`, and the engine
   battery's expectations with it.
2. `signature_pad` into `importmap.php` and `assets/vendor/` (`make assets`).
3. `assets/controllers/signature_controller.js` — the pad, the clear, `file:pick`.
4. `file_controller.js` — a `picked`-from-elsewhere action, the discard-before-send, and the
   pending upload the save can wait for.
5. `form_controller.js` — await pending uploads before collecting.
6. `templates/forms/bootstrap/form.html.twig` — the `signature` branch: the file widget's
   wrapper, plus the canvas, the clear button and the picker beneath it. And two lines of CSS in
   `assets/styles/bootstrap-form.css` for the row of its own.
7. `translations/messages.*.yaml` — `page.file.sign`, `page.file.clear`, and the hint that says
   both ways are open.
8. Tests: the engine battery, the renderer (markup, `aria-*`, the picker still there), and a
   browser case that draws with synthesised pointer events and asserts the values document ends
   up holding a file description.
9. Docs: `kits.md` (the control, its options), `configuring-forms.md` (the widget table and the
   note that a signature is a `file` item), and `architecture.md` only if anything structural
   moved — it should not.

## What building it actually cost, beyond the plan

Three things went wrong, and none of them was the design:

- **`pad.isEmpty()` was the wrong question.** `#fit()` resizes the canvas, which clears it, and
  puts the strokes back — and the library's own empty-flag does not survive that. So a pad that
  had been resized once (a scrollbar appearing is a resize) refused to hand anything over, with
  the signature plainly visible on it. `#hand()` runs on `endStroke`, so there is ink by
  definition; the guard was not just wrong, it was unnecessary.
- **WebDriver's pointer offsets are from the element's centre.** An offset that reads like a
  corner puts the press outside a short canvas, where it draws nothing and looks exactly like a
  widget that does not work. Two of the three debugging rounds went on this.
- **The suite's upload ceiling is 4 kB on purpose** (`files.max_upload` in
  `config/services_test.yaml`, so that the refusal is reachable), and a PNG of a full-width
  squiggle is more. The fixture draws a small signature on a short pad, which is the honest fix:
  what is under test is that a drawing becomes a file, not how many kilobytes a canvas encodes
  to.

And the layout fault was caught by **looking at a screenshot** while every test passed, which is
the third time in this repository that a page's CSS was wrong in a way only a picture shows.

## And one thing the owner asked for on seeing it

*"At the signature field I want to see the current signature — the link to the file is the least
important thing here."* Right, and it exposed something worse than a missing preview: a form
**opened again after a save showed an empty pad** with a filename beside it, which is the wrong
answer to "what did I sign".

So a form that holds a signature shows it, fetched from the file's own address — a browser renders
an image subresource whatever the download's `Content-Disposition` says, which was measured before
anything was built on it. The pad goes behind a *sign again* button, the filename stays small
underneath (it is how the bytes are fetched and nothing more), and the read-only page needs no
JavaScript at all: the server renders the image because it knows what the form holds.

The swap is **not** done when the pad itself produced the file, and that is the one subtlety worth
keeping: a signature is often more than one stroke — an initial and a surname, a dot over an i —
and each stroke lands as an upload of its own, so swapping on the first would take the pad away in
the middle of signing.

## And two faults the owner found by using it

Both were in the *editing* state, which the tests had covered and the eye had not:

- **The pad went back to 300 pixels.** Moving the signature controller onto the file widget's own
  element (so that `file:held` could reach it) left the CSS rule that gives the pad a row of its
  own pointing at a child that no longer exists — and `[data-controller="file"]` stopped matching
  an element whose attribute now reads `file signature`. Every selector in that stylesheet is
  `~=` now, and the browser battery **measures** the pad against the widget rather than reading
  the markup, because the markup was right both times this broke.
- **An image of nothing sat under the pad**, so a form being signed showed two signature boxes —
  which is what "there is no point drawing it twice" meant. `hidden` was set on it the whole
  time: Bootstrap ships `[hidden] { display: none !important }` *and*
  `.d-block { display: block !important }`, and its utilities come later in its own file. Ours is
  the last stylesheet on the page, so it states `[hidden]` again — and the test that asked
  `element.hidden` agreed with the bug, so it asks the layout now.

While it was open: the order reads *what was signed → the way to sign again → the pad → the other
way in*, and the picker is hidden while a signature is held, since what a form holding one offers
is the signature and a way to replace it.

## Not planned, on purpose

- **No `signature` in `core-html`.** See above; a document names the kit it was written for.
- **No embedding it in the PDF record.** That needs `gd` in the image and a decision about size;
  the record names the file, and the bytes are one address away.
- **No "typed name as a signature".** It is a different question (a text item), and a document
  that wants one asks for one.
- **No claim that a drawn signature is more trustworthy than an uploaded one.** It is not, and
  the service could not tell them apart if it were.
