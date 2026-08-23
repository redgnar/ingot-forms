# The two kits, control by control

A **kit** is one way of drawing a form. It is the authority on what a presentation written for
it may say — which control suits which kind of item, what may hold other items, what may stand
alone — and the thing that turns the resolved form into markup. A document names its kit at the
top (`"engine"`), and a document written for one is *refused* by the other rather than
half-drawn.

Both kits draw **the same resolved tree**: the definition and the presentation, read together
into one structure with the labels resolved, the values in place and the definition's own limits
attached. So everything below differs in markup and behaviour, never in what a form *is*.

| | `core-html` | `bootstrap` |
|---|---|---|
| Looks like | the browser's own controls | [Bootstrap 5.3](https://getbootstrap.com/docs/5.3/) |
| Ships | one hand-written module (`public/js/core-html-form.js`), ~30 lines of inline CSS | AssetMapper + [Stimulus](https://stimulus.hotwired.dev/) controllers, [Tom Select](https://tom-select.js.org/), [Tabler icons](https://tabler.io/icons) |
| Needs | nothing — no vendor file, no import map | the vendored assets in `assets/vendor/` (committed) |
| Skins | none | `default`, `material`, `flatly`, `lux` |
| Best for | anywhere; a fallback that cannot break; forms nobody will style | forms somebody will look at for a while, and lists somebody will fill in |

**A widget is a way of *asking*, never a restyling.** That rule is why the richer kit has
`autocomplete` (searching a long list is a different act from scrolling one) and does not have a
floating label (the same question with the text moved). If you want a different *look*, that is
a [skin](#skins).

## Contents

- [core-html](#core-html) — [controls](#core-html-controls) · [grouping](#core-html-grouping) · [text between things](#core-html-decorations) · [the page's own](#core-html-page) · [triggers](#core-html-triggers)
- [bootstrap](#bootstrap) — [controls](#bootstrap-controls) · [grouping](#bootstrap-grouping) · [text between things](#bootstrap-decorations) · [the page's own](#bootstrap-page) · [triggers](#bootstrap-triggers) · [skins](#skins)
- [What both kits do without being asked](#what-both-kits-do-without-being-asked)
- [Reference links](#reference-links)

Every entry below says the same four things: **draws** (the markup you get), **from the
definition** (what the item's own rules contribute, with no way for a document to override
them), **options** (what the presented item may carry under `options`), and **notes**.

---

# core-html

Plain controls and nothing else. No stylesheet of anybody else's, no package, no build step —
one module and an inline stylesheet, both small enough to read. It is the kit that works
anywhere, including where a corporate proxy strips everything interesting, and the one to reach
for when a form is a form rather than a product.

<a id="core-html-controls"></a>
## Controls

### `text` — the natural control for a `text` item

- **Draws:** `<input type="text">`
- **From the definition:** `maxlength` (`maxLength`), `pattern`
- **Options:** —
- **From the item:** `placeholder` — a translation code for what the control says while it is
  empty

### `textarea`

- **Draws:** `<textarea>`, four rows unless asked otherwise
- **From the definition:** `maxlength`
- **Options:** `rows` — how tall the box is
- **From the item:** `placeholder`

### `hidden`

- **Draws:** `<input type="hidden">` inside a container marked `hidden`
- **Notes:** for a value a *client* fills in rather than a person. It is a decision written
  down: the server still requires every declared item to be shown somewhere, and this is how a
  document says "shown, but not to anybody".

### `select` — the natural control for a `select` item

- **Draws:** `<select>` with an empty first option, then one per declared value
- **From the definition:** the options, in the order the definition declares them
- **Options:** —
- **From the item:** `placeholder` — words the *empty option*, since a select has no
  placeholder attribute to show
- **Notes:** the empty option is what "nothing chosen yet" looks like. What each value *reads*
  like comes from `choices` on the presented item.

### `radio`

- **Draws:** a `role="radiogroup"` wrapper, one `<label><input type="radio"> text</label>` per
  option
- **From the definition:** the options
- **Options:** —
- **Notes:** the group carries the question's name, because a caption pointing at no control is
  a question a screen reader never asks. Inside a list, the radio group's `name` carries the
  entry's own scope, so two entries never unpick each other.

### `number` — the natural control for a `number` item

- **Draws:** `<input type="number">`
- **From the definition:** `min`, `max`, and `step` derived from `decimals` (`0` → `1`, `2` →
  `0.01`)
- **Options:** —
- **From the item:** `placeholder`

### `date` — the natural control for a `date` item

- **Draws:** `<input type="date">`
- **From the definition:** `min`, `max`
- **Options:** —
- **Notes:** the browser draws its own calendar; the range it offers is the definition's.

### `checkbox` / `switch` — for a `checkbox` item

- **Draws:** `<input type="checkbox">`
- **Options:** —
- **Notes:** both names draw the same box here. `switch` exists so a document can move between
  kits without being rewritten; this kit has one box and no opinion about how a box should look.

### `file` — for a `file` item

- **Draws:** three things — a hidden control holding the description (`data-type="json"`), an
  `<input type="file">` to pick bytes with, and a line naming the file that is held, with a
  download link and a "remove" button
- **From the definition:** `accept` (as the picker's `accept` attribute), `maxSize` (checked in
  the browser before anything is sent)
- **Options:** —
- **Notes:** the picker is only how somebody chooses; the value is the description the upload
  answered with. Progress and refusals are announced in a live region. A file the item does not
  accept is taken back at once, because nothing names it yet.

### `table` — for a `collection` item

- **Draws:** a `<fieldset>` with the list's label as its legend, a `<table>` of what has been
  answered so far, each row followed by the form for that entry folded into a `<details>`, an
  "Add" button in the table's footer and a "Remove" button per row
- **From the definition:** `min` and `max`, carried onto the page so it can grey out its own
  buttons — the server is still what decides
- **Options:** `open: true` — entries start unfolded
- **Notes:** which items the table previews is `columns` on the presented item; leave it out and
  every item of an entry is previewed. A list may hold a list, drawn exactly the same way, as
  deep as the definition goes. Nested lists are shaded a step darker so depth is visible without
  counting frames.

<a id="core-html-grouping"></a>
## Grouping

### `fieldset`

- **Draws:** `<fieldset>` with the label as `<legend>`
- **Notes:** the only way this kit groups, and the browser's own: it draws the frame and cuts
  the name into it. Nests as deep as you like.

<a id="core-html-decorations"></a>
## Text between things

| Widget | Draws |
|---|---|
| `heading` | `<h2>` |
| `paragraph` | `<p>` |


<a id="core-html-page"></a>
## The page's own

Two widgets that say nothing about the form and everything about the page it is drawn on. Both
stand alone, hold nothing and take no `name`.

### `comfort` — the reader's own switches

- **Draws:** a `<details>` folded away, holding three checkboxes: dark colours, high contrast, larger text
- **Options:** —
- **Notes:** placing it is how a document decides *where* the switches go. Leaving it out is not
  how a document decides they are gone: a page with no `comfort` widget still draws them at the
  top. What they control — colours, contrast, text size — is the reader's, and a document that
  could delete the control would be deciding somebody else's contrast for them.

### `language` — the same page, in another language

- **Draws:** `<nav class="language">` with one link per language
- **From the document:** one entry per catalogue in `translations`, in the order the document
  carries them, the current one marked and not a link
- **Options:** `choices` — a translation code per locale (`{"pl": "t.polish", "en": "t.english"}`),
  each resolved **in its own catalogue**, so the list reads *Polski · English* whatever language
  the page is in. Without it each entry reads as its locale code.
- **Notes:** a switch with one position is not a switch — a document carrying a single catalogue
  (or none, because its client keeps its own) draws nothing here, so the widget is safe to place
  before you know how many languages the form will end up with. The links are marked as detours,
  so answers nobody has saved yet travel with the reader and are put back on arrival.

<a id="core-html-triggers"></a>
## Triggers

| Widget | Draws | Does |
|---|---|---|
| `save` | `<button>`, or a link with `options.appearance: "link"` | `PUT …/data` with what is on the page, then says it was stored |
| `confirm` | the same | saves first, then `POST …/confirm`, then reloads the page |
| `reset` | the same | goes back to what the form actually holds, by asking the server to draw it again |
| `history` | a `<details>` panel | lists the moments this form was saved at, newest first, each with **View** and **Restore** |

**At least one `confirm` is required** — where a trigger goes is a design decision, and leaving
it out is not one. The other three are opt-in, and that is the whole of the opting: a document
that does not ask for `history` has no panel.

## What this kit deliberately does not have

No autocomplete, no slider, no stepper, no drop area, no cards or accordions, no icons, no
skins. Every one of those is either a way of asking that needs machinery this kit refuses, or a
way of looking that it has no opinion about. A document that wants them names the other kit.

---

# bootstrap

[Bootstrap 5.3](https://getbootstrap.com/docs/5.3/) markup, behaviour in
[Stimulus](https://stimulus.hotwired.dev/) controllers, icons from
[Tabler](https://tabler.io/icons) inlined as SVG, all delivered by Symfony's AssetMapper — no
build step and no package manager. Everything it adds over the plain kit is a way of *asking*
that the plain kit has no markup for.

<a id="bootstrap-controls"></a>
## Controls

### `text`, `textarea`, `hidden`

- **Draws:** `<input class="form-control">`, `<textarea class="form-control">` (four rows unless
  asked otherwise), and a hidden input in a hidden container —
  [Form control](https://getbootstrap.com/docs/5.3/forms/form-control/)
- **From the definition:** `maxlength`, `pattern`
- **Options:** `rows` on the `textarea`
- **From the item:** `placeholder` — a translation code for what the control says while it is
  empty
- **Notes:** every item is labelled the same way, above its control. A
  [floating label](https://getbootstrap.com/docs/5.3/forms/floating-labels/) was tried and
  removed: it can only float over a text box or a select, so any form with a choice group or a
  slider ends up labelled two ways at once.

### `select`

- **Draws:** `<select class="form-select">` with an empty first option —
  [Select](https://getbootstrap.com/docs/5.3/forms/select/)
- **From the definition:** the declared options, in order
- **Options:** —
- **From the item:** `placeholder` — words the empty option, since a select has no placeholder
  attribute to show

### `autocomplete`

- **Draws:** the same `<select>`, turned by [Tom Select](https://tom-select.js.org/) into a
  searchable control
- **From the definition:** the declared options
- **Options:** —
- **Notes:** the widget the plain kit has no answer for at all. Use it when a list is long
  enough that scrolling it is the problem — for five options it is worse than a select.

### `radio`

- **Draws:** `role="radiogroup"` wrapper with `.form-check` rows —
  [Checks and radios](https://getbootstrap.com/docs/5.3/forms/checks-radios/)
- **From the definition:** the declared options
- **Options:** `columns: true` — the options sit side by side (`.form-check-inline`)

### `radio-buttons`

- **Draws:** a group of toggles: `<input class="btn-check">` + `<label class="btn">` per option
  — [Checkbox toggle buttons](https://getbootstrap.com/docs/5.3/forms/checks-radios/#checkbox-toggle-buttons),
  [Button group](https://getbootstrap.com/docs/5.3/components/button-group/)
- **From the definition:** the declared options
- **Options:** —
- **Notes:** for two or three options that deserve to be seen at once. Still a radio group to a
  screen reader, named by the question.

### `number`

- **Draws:** `<input type="number" class="form-control">`
- **From the definition:** `min`, `max`, `step` (from `decimals`)
- **Options:** —
- **From the item:** `placeholder`

### `range`

- **Draws:** `<input type="range" class="form-range">` —
  [Range](https://getbootstrap.com/docs/5.3/forms/range/)
- **From the definition:** `min`, `max`, `step`
- **Options:** —
- **Notes:** nothing refuses a slider on an item with no bounds, and nothing should — but the
  browser then falls back to 0–100, which is not what the form asks for. Give a `range` item a
  `min`, a `max` and a `decimals`, or draw it as a `number`.

### `stepper`

- **Draws:** an [input group](https://getbootstrap.com/docs/5.3/forms/input-group/): a minus
  button, `<input type="number">`, a plus button
- **From the definition:** `min`, `max`, `step`
- **Options:** —
- **Notes:** the buttons move by the definition's own step and cannot walk past its bounds, so
  a number clicked can never be a number the published schema would refuse.

### `date`

- **Draws:** `<input type="date" class="form-control">`
- **From the definition:** `min`, `max`

### `checkbox` / `switch`

- **Draws:** `.form-check`, and with `switch` also `.form-switch` plus `role="switch"` —
  [Switches](https://getbootstrap.com/docs/5.3/forms/checks-radios/#switches)
- **Options:** —
- **Notes:** here the two names really are two controls: one is a box, the other is a switch.

### `file`

- **Draws:** a hidden control holding the description, `<input type="file" class="form-control">`,
  a [progress bar](https://getbootstrap.com/docs/5.3/components/progress/), and a line naming
  the file that is held with a download link and a "remove" button
- **From the definition:** `accept`, `maxSize`
- **Options:** —

### `dropzone`

- **Draws:** the same three things inside a dashed drop area, with "drop a file here, or choose
  one"
- **From the definition:** `accept`, `maxSize`
- **Options:** —
- **Notes:** the same question asked differently — a place to drop bytes rather than a picker to
  open — with the upload's progress drawn while it happens. The picker is still underneath for
  anybody not dragging anything, and the progress is announced as a number, not only drawn as a
  bar.

### `table` — for a `collection` item

- **Draws:** `<table class="table table-sm">` of what has been answered so far, each row
  followed by that entry's form folded into a `<details>` styled as a card, "Add" in the table's
  footer, "Remove" per row — [Tables](https://getbootstrap.com/docs/5.3/content/tables/)
- **From the definition:** `min`, `max` — the buttons grey themselves out
- **Options:** `open: true` — entries start unfolded
- **Notes:** a refused entry's row is marked (`table-danger`) and stays marked once the form is
  folded back up. Adding an entry puts the caret in it.

<a id="bootstrap-grouping"></a>
## Grouping

### `card`

- **Draws:** [Card](https://getbootstrap.com/docs/5.3/components/card/) with the label as its
  header and the items in its body
- **Options:** —
- **Notes:** the plainest way this kit groups, and what a group with no widget gets.

### `accordion`

- **Draws:** `<details>` styled as a card, with the label as its `<summary>`
- **Options:** `open: true` — starts unfolded
- **Notes:** *not* Bootstrap's [accordion component](https://getbootstrap.com/docs/5.3/components/accordion/).
  A browser has known how to fold a `<details>` open and closed for years, without borrowing
  anybody's JavaScript, and it keeps working when the JavaScript does not.

### `row`

- **Draws:** [grid](https://getbootstrap.com/docs/5.3/layout/grid/) row; each child gets a
  column
- **Options (on the row):** `align: "start" | "center" | "end" | "between" | "around"` — how the
  columns are packed when they do not fill the row
  ([justify-content](https://getbootstrap.com/docs/5.3/layout/columns/#horizontal-alignment))
- **Options (on the child, not the row):** `width: 1–12` — how many of the twelve columns that
  item takes; `width: "auto"` — as wide as the item's own content
  ([`col-auto`](https://getbootstrap.com/docs/5.3/layout/grid/#variable-width-content)).
  Children that ask for nothing share what is left.
- **Notes:** columns collapse to full width on a narrow screen, which is Bootstrap's own
  behaviour and not something a document can turn off.

<a id="bootstrap-decorations"></a>
## Text between things

| Widget | Draws | Options |
|---|---|---|
| `heading` | `<h2 class="h4">` | — |
| `paragraph` | `<p class="text-body-secondary">` | — |
| `alert` | [Alert](https://getbootstrap.com/docs/5.3/components/alerts/) | `tone` — any Bootstrap colour name (`info` by default, `warning`, `danger`, `success`, …) |
| `divider` | `<hr>` | — |


<a id="bootstrap-page"></a>
## The page's own

Two widgets that say nothing about the form and everything about the page it is drawn on. Both
stand alone, hold nothing and take no `name`.

### `comfort` — the reader's own switches

- **Draws:** a `<details>` folded away behind one icon, holding three toggle buttons — dark colours, high contrast, larger text ([Buttons](https://getbootstrap.com/docs/5.3/components/buttons/))
- **Options:** —
- **Notes:** placing it is how a document decides *where* the switches go. Leaving it out is not
  how a document decides they are gone: a page with no `comfort` widget still draws them at the
  top. What they control — colours, contrast, text size — is the reader's, and a document that
  could delete the control would be deciding somebody else's contrast for them.

### `language` — the same page, in another language

- **Draws:** a `<nav>` of links
- **From the document:** one entry per catalogue in `translations`, in the order the document
  carries them, the current one marked and not a link
- **Options:** `choices` — a translation code per locale (`{"pl": "t.polish", "en": "t.english"}`),
  each resolved **in its own catalogue**, so the list reads *Polski · English* whatever language
  the page is in. Without it each entry reads as its locale code.
- **Notes:** a switch with one position is not a switch — a document carrying a single catalogue
  (or none, because its client keeps its own) draws nothing here, so the widget is safe to place
  before you know how many languages the form will end up with. The links are marked as detours,
  so answers nobody has saved yet travel with the reader and are put back on arrival.

<a id="bootstrap-triggers"></a>
## Triggers

The same four as the plain kit, doing the same four things over HTTP. What differs is the
drawing: [buttons](https://getbootstrap.com/docs/5.3/components/buttons/) rather than plain
ones, `confirm` in the primary colour with a send icon, and the `history` panel as a folded
card that fetches its list when somebody opens it and refreshes it after every save.

## What `options` can say, and what it cannot

`options` is **read by the kit, never forwarded**. There is no pass-through to Bootstrap: the
five members below are the whole of what these two kits look at, and anything else a document
puts there is carried, stored and ignored. That is deliberate — a member that reaches a
component untouched is a member nobody can validate, document or keep working across a version
of Bootstrap — but it does mean that "Bootstrap can do X" and "this kit can be asked for X" are
two different questions. The second column is the first one's answer.

| Option | Read on | Does | The component it belongs to |
|---|---|---|---|
| `width: 1–12 \| "auto"` | any item that is a direct child of a `row` | how many of the twelve columns it takes, or as wide as its own content | [Grid columns](https://getbootstrap.com/docs/5.3/layout/grid/#grid-options) and [variable-width content](https://getbootstrap.com/docs/5.3/layout/grid/#variable-width-content) — Bootstrap also has offsets, order and per-breakpoint widths; none of those are exposed |
| `align: "start" \| "center" \| "end" \| "between" \| "around"` | `row` | how the columns are packed when they do not fill it | [Horizontal alignment](https://getbootstrap.com/docs/5.3/layout/columns/#horizontal-alignment) |
| `open: true` | `accordion`, `table` | starts unfolded | the browser's own [`<details>`](https://developer.mozilla.org/en-US/docs/Web/HTML/Element/details), not [Bootstrap's accordion](https://getbootstrap.com/docs/5.3/components/accordion/) — so its "only one open at a time" behaviour is not available either |
| `tone: "info" \| "warning" \| "danger" \| "success" \| …` | `alert` | which Bootstrap colour the alert takes | [Alerts](https://getbootstrap.com/docs/5.3/components/alerts/) — the colour is exposed; dismissible alerts, icons and links inside them are not |
| `columns: true` | `radio` | lays the options out side by side | [Inline checks and radios](https://getbootstrap.com/docs/5.3/forms/checks-radios/#inline) — reversed layout and switch-style radios are not exposed |
| `appearance: "link"` | any action (`save`, `confirm`, `reset`, `history`) | draws the trigger as a link rather than a button | [Button variants](https://getbootstrap.com/docs/5.3/components/buttons/#variants) — the colour of a trigger is the kit's decision (`confirm` is primary, the rest are outline-secondary), not the document's |
| `rows: n` | `textarea` | how tall the box is (four otherwise) | plain HTML; Bootstrap has nothing of its own to say about it |

Three more members that are not `options` but are worth listing beside them: `choices` on a
`select`-ish item words its values, `choices` on `language` words the languages, and
`placeholder` words what a control says while it is empty. All three are translation codes,
resolved from the document's own catalogues — which is exactly why `placeholder` is not an
option: an option is a thing nobody has to translate.

**If you need something this list does not have**, it is a change to the kit — a widget or an
option, added deliberately, drawn by both templates if it belongs to both, and tested. That is a
small change to make; it is just not one a document can make on its own.

<a id="skins"></a>
## Skins

A skin changes how a page looks and never what a document may say. Every widget above draws
under every skin — the same form under two skins renders **byte-identical markup**, differing
only in which stylesheet the page loads.

| `skin` | Is | Looks like |
|---|---|---|
| `default` | stock [Bootstrap 5.3](https://getbootstrap.com/docs/5.3/) | the Bootstrap everybody knows |
| `material` | [Bootswatch Materia](https://bootswatch.com/materia/) | Bootstrap wearing Material's clothes — not Material Design |
| `flatly` | [Bootswatch Flatly](https://bootswatch.com/flatly/) | flat, no gradients, teal and navy |
| `lux` | [Bootswatch Lux](https://bootswatch.com/lux/) | minimal, thin, generous spacing, uppercase buttons |

All four are light themes on purpose: **dark belongs to whoever is reading**, not to the
document, and the reader's dark is painted by this application rather than by the theme (see
below). A document names one with a top-level `"skin"`; a document that names nothing gets
whatever the deployment sets (`FORMS_SKIN`). Everything is vendored and committed — no CDN, no
runtime download.

## What this kit deliberately does not have

No floating labels, no styling knobs beyond the ones listed above, no way for a document to
supply CSS, and no widget that is only a restyling of another. Colours, contrast and text size
are not here either — they belong to the reader.

---

# What both kits do without being asked

**Every page is a client of this service's own API.** `save` is `PUT …/data`, `confirm` saves
and then `POST …/confirm`, `history` reads `GET …/history`, "Restore" is an ordinary
`PUT …/data` of a document the page happened to read. There is no privileged path: whatever a
page can do, any client can.

**A refusal lands where it belongs.** The `errors[]` pointer names an item, so the message goes
under that control, the entry it is in unfolds, every row on the way is marked, the control says
it was refused (`aria-invalid`), and the caret moves there. Anything with no pointer to land on
is shown once, at the top.

**Unsaved answers survive a look at an earlier version.** Opening one is a navigation, and a
navigation throws away what nobody saved — so what is on the page goes with you, is put back on
return, and the page says plainly that those answers are still not saved. A save, a restore or a
reset drops the stash, because each settles the question.

**An earlier version is a page, not a dialog.** `/forms/{id}/versions/{seq}` is the same form
drawn from that save's document, read-only, with the two ways out at the top: put this version
back, or return to the current one.

**A page can be used without seeing it.** Required answers say so where it can be heard, hints
and refusals are tied to their control, a choice group is a group named by its question, an
upload's progress is a number as well as a bar, and a new entry takes the caret. None of it is
an option a document turns on.

**The reader decides how the page reads.** Dark colours, high contrast and larger text, offered
by both kits behind one folded summary — the richer one as buttons, the plain one as checkboxes
— applied before the first paint and remembered in that browser only. Where nobody has chosen,
the machine is asked (`prefers-color-scheme`, `prefers-contrast`) and then the document's own
`theme`, in that order. Nothing reaches
the server; there is no identity here to hang a preference on. High contrast is *not* a skin but
an overlay on top of whichever one the document chose, because an accessibility preference
outranks an aesthetic one.

# Reference links

- Bootstrap 5.3: [forms](https://getbootstrap.com/docs/5.3/forms/overview/) ·
  [components](https://getbootstrap.com/docs/5.3/components/accordion/) ·
  [grid](https://getbootstrap.com/docs/5.3/layout/grid/) ·
  [colour modes](https://getbootstrap.com/docs/5.3/customize/color-modes/)
- [Bootswatch](https://bootswatch.com/) — the three named skins
- [Tom Select](https://tom-select.js.org/) — behind `autocomplete`
- [Tabler icons](https://tabler.io/icons) — imported into the repository, never fetched at runtime
- [Stimulus](https://stimulus.hotwired.dev/) and
  [Symfony AssetMapper](https://symfony.com/doc/current/frontend/asset_mapper.html) — how the
  richer kit's behaviour reaches the browser
