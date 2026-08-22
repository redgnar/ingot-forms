# 08 — a skin the document picks, a comfort the reader picks

The owner asked for three things in one sentence: skins for the `bootstrap` kit that a
deployment can switch between (Material or another), easier access in the forms, and high
contrast. They arrive together and they are **not one feature**, so the first job of this plan
is to say which of them belongs to whom.

- A **skin** is how a page looks. It belongs to whoever presents the form — the same person who
  already decides that a group is a `card` and a number is a `range`.
- **Contrast, dark, larger text, less motion** are how a person needs to read. They belong to
  the reader, and to nobody else. A document that could put a reader into low contrast would be
  a document overriding an accessibility need.
- **Being usable with a screen reader or a keyboard** belongs to neither: it is not an option at
  all, it is whether the page is correct. What this plan calls "the reach fixes" are bugs we
  have, listed and priced.

Everything below follows from that split.

## Decisions

1. **A skin may change how a page looks; it may never change what a document may say.** This is
   the rule the kit already lives by, applied one level down. `04-second-engine` removed the
   floating label because it was "the same question asked the same way with the text moved,
   which is a style, not a vocabulary" — a skin is exactly that kind of thing, and this is where
   such things are allowed to live. The consequence is a testable invariant: **the same form
   rendered under two skins produces byte-identical markup**, differing only in which stylesheet
   the page links. A skin that needs a class, an extra element or a different control is not a
   skin — it has become a kit, and it must be one.
2. **A skin is CSS and nothing else.** Bootstrap 5.3 is driven by custom properties, so a skin
   is a stylesheet that sets them. No second framework, no JavaScript, no build step: the same
   bargain the kit was built on. Material Web Components would be a *third kit* — different
   markup, different vocabulary, a different renderer — and this plan deliberately does not go
   there.
3. **What ships is `default`, `material`, `flatly` and `lux`, plus a contrast overlay.** All
   three named ones are Bootswatch themes pinned to the very Bootstrap we already ship (5.3.8,
   each verified to exist, 227–257 KB) and vendored into `assets/vendor/` like everything else.
   Honest naming: `material` is Materia — Bootstrap wearing Material's clothes, not Material
   Design. `flatly` and `lux` are the two the owner asked for on top: the most-used flat theme,
   and the most distinct minimal one. **All three are light themes on purpose.** Bootswatch's
   popular dark ones (Darkly, Slate, Cyborg) would fight the reader's own dark switch, and by
   decision 6 that switch is theirs and not the document's — a skin that decides somebody's
   light level is a skin doing the reader's job.
4. **The engine says which skins exist, exactly as it says which widgets exist.**
   `PresentationEngine::skins(): list<string>` joins `controlsFor()`, `containers()`,
   `decorations()` and `actions()`. `core-html` returns `[]` — it has thirty lines of inline CSS
   and no ambition to be styled, which is the kit's whole point.
5. **The document may name a skin; the deployment sets the default.** Two knobs with two
   different jobs, and the document wins. `presentation.skin` is optional and immutable like the
   rest of the document, so one deployment can serve a branded form beside a plain one; the
   deployment parameter is what rebrands every form that named nothing, without touching a
   single stored document. A skin the engine does not declare is refused at creation
   (`presentation.skin.unknown`, pointer `/skin`); a skin given to an engine that declares none
   is `presentation.skin.unsupported`; an engine nobody here knows is not checked at all — the
   same bargain widgets get today.
6. **An accessibility preference outranks an aesthetic one.** High contrast is not a skin and is
   not in the list: it is an overlay that sits on top of whichever skin the document picked, so
   it composes with all of them and cannot be spent by choosing one. No document can opt a
   reader out of it.
7. **The reader's choices live in the reader's browser.** Theme (auto/light/dark), contrast
   (auto/high) and text size (normal/large) are `localStorage`, applied to `<html>` before first
   paint, defaulting to what the operating system already says (`prefers-color-scheme`,
   `prefers-contrast`, `prefers-reduced-motion`). Nothing reaches the server: this service has no
   identity, and a per-person setting stored here would be the same member nobody can fill that
   `07` refused for "who".
8. **The switcher is chrome, so its words are ours.** Like every sentence this application
   invents, the labels live in `translations/messages.*.yaml` under `page.*` and the templates
   ask the catalogue. No presentation carries a code for a control the adapter added.
9. **The plain kit gets the media queries and no switcher.** `core-html` honours
   `prefers-contrast`, `prefers-color-scheme` and `prefers-reduced-motion` in its inline
   stylesheet — a person whose system already says "high contrast" must not be handed #555 hints
   — but it grows no button and no storage. It promised no machinery and it keeps that promise.
10. **The reach fixes are not optional and land in both kits.** They are listed below with what
    each one is worth; none of them is behind a preference, because a page is not correct only
    when somebody asks for it to be.

## What is actually broken today (the reach fixes)

Measured by reading the two templates and the two behaviour layers, not guessed:

- **A choice group's caption points at nothing.** For `radio` and `radio-buttons` the `<label>`
  deliberately carries no `for` (correctly — a group is not one control), but nothing replaces
  it: a screen reader reads the options and never the question. Needs `role="group"` +
  `aria-labelledby`, or a real `fieldset`/`legend`.
- **Required is a star.** `node.required` renders as `*` in the label text and nothing else. Add
  `aria-required`.
- **Hints and refusals are next to the control, not attached to it.** Both are `<div>`s with no
  id, so no control can name them: `aria-describedby` needs ids, and the per-entry id scheme
  (`item-lines-1-sku`) already exists to build them from.
- **A refused control does not say it is refused.** Both kits place text into
  `[data-error="…"]`; neither sets `aria-invalid` on the control, nor clears it with the message.
- **Nothing moves after a refusal.** The page-level alert is `role="alert"` and announces, but a
  keyboard user is left where they were: focus should go to the first refused control — which is
  also the control both kits already have to find in order to unfold the entry it sits in.
- **An upload is silent.** The progress text is a plain paragraph; it wants a live region (or a
  real `progressbar` with `aria-valuenow`), or a screen-reader user learns a file attached by
  guessing.
- **Adding an entry is silent too**, and focus does not enter the row that just appeared.

Each of these is assertable in a renderer test (the markup relationships) plus, for focus and
announcements, one browser assertion apiece.

## The shape

```
Domain/Forms/Presentation/
    Engine/PresentationEngine::skins()      what this kit can be dressed in
    Engine/BootstrapEngine                  ['default', 'material']
    Engine/CoreHtmlEngine                   []
    PresentationDocument::$skin             optional, immutable, judged at creation
    presentation.schema.json                one more optional member
    Rule/…                                  skin.unknown / skin.unsupported
UserInterface/Web/Renderer/
    BootstrapRenderer                       resolves document skin ?? deployment default
assets/
    vendor/bootswatch/…/materia/…css        vendored, committed, no CDN
    styles/skins/high-contrast.css          the overlay, ours
    styles/bootstrap-form.css               unchanged: the kit's own few rules
    controllers/comfort_controller.js       the reader's three switches
templates/forms/bootstrap/form.html.twig    <link> for the skin, the comfort bar, aria wiring
templates/forms/core-html/form.html.twig    media queries, aria wiring
```

The skin stylesheet becomes a `<link>` the page writes, *before* `importmap()` emits the kit's
own CSS, so our few rules and tom-select's still win. The base Bootstrap import moves out of
`assets/pages/bootstrap-form.js` for the same reason: exactly one skin is loaded, never two.

## Order & acceptance

Independent steps; 1 is worth doing whatever we decide about the rest.

1. **Reach fixes, both kits.** Every item in the list above, with renderer tests for the markup
   relationships and browser assertions for focus after a refusal and for the two live regions.
   Acceptance: a required select in an entry announces its question, its requirement, its hint
   and its refusal, and a refused confirm lands the caret on the first bad answer.
2. **The reader's comfort.** `data-bs-theme` + `data-contrast` + `data-text` on `<html>`, an
   inline no-flash script, the overlay stylesheet, a Stimulus controller and a small bar; the
   plain kit gets three media queries. Acceptance: choosing high contrast survives a reload and
   a look at an earlier version, and a machine set to dark with no choice made comes up dark.
3. **Skins.** `skins()` on the port, the document member, the deployment parameter, the
   `<link>`, Materia vendored. Acceptance: the same form under `default` and `material` renders
   byte-identical bodies, a document naming `chrome-yellow` is refused at creation with
   `/skin`, and high contrast still wins over Materia.
4. **Docs.** README's kit section, the presentation schema in `openapi.yaml` (`make docs`), and
   this plan's "what building it changed".

## Risks, and what to check before writing much

- **The identity-of-markup invariant is the whole design, and Materia may break it.** If a
  prebuilt theme turns out to need a wrapper class to look right, the answer is to drop that
  theme rather than to soften the rule — otherwise "skin" quietly becomes "kit" and both kits
  start needing per-skin markup. Check this on the fullest demo form before step 3 goes far.
- **Dark mode multiplies what "looks right" means.** Bootswatch's 5.3 themes support
  `data-bs-theme` unevenly. It is the one item here I would cut first if we want a smaller
  stage: contrast and text size carry most of the value.
- **The browser battery must not grow a skin dimension.** Running the suite twice would undo the
  limits we just put on this machine. One renderer test proves markup identity; one browser test
  proves a skinned page loads and still saves. That is the coverage this deserves.
- **An inline no-flash script is inline JavaScript.** Harmless today (no CSP), worth a note the
  day a policy arrives — a nonce, not a rewrite.
- **~230–256 KB of committed CSS per theme.** Three named skins is the budget the owner set
  deliberately; a fourth is a decision to make again, not a default.

## Non-goals

- **A third kit.** Material Web Components, Tailwind, anything with its own markup.
- **CSS in the document.** A presentation supplying styles is an injection surface and an
  unbounded support burden; skins are a closed list for the same reason widgets are.
- **Server-side reader preferences.** No identity here; the browser remembers, or nothing does.
- **Skinning `core-html`.** It is the kit with no machinery, and that is what it is for.
- **An automated audit (axe-core) as a gate.** Worth discussing later; for now the relationships
  we care about are asserted by name, which is cheaper and says why it failed.

## What building step 1 changed

The list above was written by reading the templates, and building it found the same things plus
three worth writing down.

**A caption that points at nothing was the expensive one.** Both kits already knew that a group
of choices is not one control, and both left the caption pointing nowhere as a result — so the
options were read out and the question never was. `role="radiogroup"` plus `aria-labelledby` is
the whole fix, and it is the same shape in both kits.

**The star had to be marked as decoration.** Once a group is named by its caption, the caption is
read out — and `Country *` is a question with punctuation in the middle of it. The star is now
`aria-hidden`, which is what it always was in fact: `aria-required` is what says the answer is
owed, and the star is what says it to somebody looking.

**Cloning an entry had to learn two more attributes.** A page replaces the blank entry's token in
`id`, `for` and `name`; `aria-labelledby` and `aria-describedby` carry the same token, and
without rewriting them a cloned entry would point at the blank one's caption and at a message
nobody can reach. The browser battery now follows every reference on the page after adding an
entry and fails on any that lands nowhere — the one assertion that could only be made in a
browser, because `<template>` content is inert there and visible to a server-side crawler.

**What is not done here, and was in the list**: nothing announces how many entries a list holds
after one is added or removed. Focus lands in the new entry instead, which is the stronger half;
a live region for the count can come later if it turns out to be missed.

## What building steps 2 and 3 changed

**The reader's half turned out to need two facts, not one.** What somebody chose (`auto`,
`light`, `dark`) and what that comes to right now are different things: Bootstrap does not follow
`prefers-color-scheme` on its own, so "system" has to be worked out — and worked out again when
the machine changes its mind, which is a media-query listener rather than a value read at load.
So the root element carries both: `data-theme` is the choice, `data-bs-theme` is the result. Only
the choice is remembered. Contrast needed the same distinction for a different reason: a machine
that asks for more contrast turns it on for a reader who never chose, and a reader who then turns
it *off* means it — so "off" is written down too, rather than left absent and re-derived on the
next page.

**The overlay is not in `styles/skins/`,** where this plan first put it. It is
`assets/styles/comfort.css`, beside the kit's own rules, because the plan's own decision 6 says
contrast is not a skin — filing it with them would have been the first step towards its becoming
one.

**A skin is one entrypoint, not one `<link>`.** The first sketch had the page link a stylesheet
by path; what it actually needs is an AssetMapper entrypoint per skin (`bootstrap-form-material`
and friends), each importing its own Bootstrap and then the shared `kit.js`. That way exactly one
Bootstrap is ever loaded, the import order is the one AssetMapper guarantees rather than one the
template hopes for, and nothing has to know where a vendored file ended up after digesting. The
page's only decision is which name to pass to `importmap()`.

**The markup-identity test had to be written carefully to mean anything.** Rendering two forms
and comparing them compares two different `FormId`s, and the first version of the test failed on
exactly that plus a fixture difference. The honest shape is one form, one document, two
renderers — then the only variable left is the skin, which is the thing under test.

**A changed constructor is a changed mapper.** Adding `skin` to `PresentationDocument` left the
test suite mapping documents without it until `make cache-clear`: `cache.ingot_mapper` keys on
class names, and a class whose constructor grew a member has the same name it had yesterday. The
guide already says this; this stage is one more instance of it.

**Not done, and deliberately**: nothing announces a list's count after an entry is added (step 1
left focus doing that job), and no automated accessibility audit runs in CI — the relationships
that matter are asserted by name, which is cheaper and says why it failed.

## What the owner found by looking at it

Three reports after the stage shipped, and the first two were real:

**Buttons unreadable in `material`.** Materia sets `--bs-secondary: #fff` and then repaints
`.btn-outline-secondary` with a literal `color: #dee2e6` a few hundred rules later, so the
comfort bar, "add entry", "remove" and the stepper were white on white — a contrast ratio of
1.24. The fix is the kit's own stylesheet asserting that the buttons it leans on read like the
page (`--bs-body-color`, `--bs-border-color`, `--bs-link-color`), stating the property as well
as the variable, and written twice so it also outranks a theme's `[data-bs-theme=dark]` rules.
**A skin may repaint anything; it may not make what we drew invisible** — that is the second
half of the rule this stage is built on, and it was missing.

**A collapsed autocomplete in `lux`.** tom-select measures its own box with
`calc(1.5em + .75rem + var(--bs-border-width) * 2)`, and Lux defines `--bs-border-width: 0` — a
*unitless* zero, which makes that sum "length + number", which is invalid arithmetic, which
throws the whole declaration away. The control collapsed to a 12px line. Stated without asking
a variable what a border is, it is 38px in every skin.

**Dark had to become ours.** Chasing each theme's dark-mode literals rule by rule is a rabbit
hole with no end, so the reader's dark palette is painted by `comfort.css` — the skin keeps its
shapes, radii and fonts, and the page a reader needs belongs to the reader. Same principle as
contrast, applied one step further than this plan first went.

**And the third report was a question**: where are the switches in `core-html`? Decision 9 said
that kit gets media queries and no switcher because it "promised no machinery" — and that
reasoning was thinner than it looked, because the kit has shipped a hand-written module since
it was born. "No machinery" always meant no framework. So it has the same three switches now,
as radios and checkboxes in a `fieldset`: the plainest controls there are, twenty lines in a
module that already existed, sharing the storage keys with the other kit so a reader's choice
holds whichever kit drew the page.

## What the owner asked for once it was on the page

**Where the page's own things go is the document's business.** The switches and the language
links became widgets — `comfort` and `language`, declared by both engines as things that stand
alone — so a document places them like everything else. The panel of earlier versions needed
nothing: `history` was already a widget, which is why there is no "top / bottom / none" option
anywhere. The one thing placement cannot do is *remove* the switches: a document that places no
`comfort` gets them at the top, because what they control is the reader's and a document that
could delete the control would be deciding somebody else's contrast for them.

**"System" was a button that did what "Light" did.** On a machine that says light, the two were
indistinguishable, and the owner said so. Three radios became one on/off: dark or not, starting
from what the machine says. The state that was lost — "follow the machine from now on" — was
worth less than the button it cost, and what the machine says is still where every page starts.

**A document may prefer a starting theme** (`"theme": "light" | "dark"`, beside `skin`), and it
is answered *after* the reader and after their machine. A preference, not a setting.

**Three switches above every form are three switches in the way of it.** They fold away now,
behind one icon in the richer kit and one line in the plain one — `<details>` again, which is
this codebase's answer to folding anything.

**And three things the owner saw that the tests could not.** Materia draws a field as an inset
shadow rather than a border, so tom-select's own control — which has neither — was an invisible
strip until something was picked; it gets an inset underline now, which is the line where a skin
draws one and sits on the border where a skin draws a box. The stepper's buttons were a skin's
raised buttons at a skin's standalone size, dwarfing the number between them: no elevation, less
padding, and a focus *outline* rather than a shadow, because the rule that removed the shadow
would otherwise have removed the focus ring with it. That last one was only visible on hover —
the state nobody screenshots.
