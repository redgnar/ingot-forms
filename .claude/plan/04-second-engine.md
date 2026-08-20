# 04 — a second presentation engine

The first kit (`core-html`) proved a form can be drawn from its presentation. It could not
prove that the *split* was worth anything: with one engine, "the vocabulary belongs to the
kit" is a sentence in a docblock. This stage is the second kit, and the answer to what the
first one cost.

The owner asked for something more advanced than plain HTML — well styled, and supported by
Symfony in its **mechanics**, not only in its looks. That last word settled most of the
design.

## What was chosen, and what was turned down

**Bootstrap 5, drawn by our own templates.** Symfony ships a Bootstrap 5 *form theme* in
`twig-bridge`, and it was the obvious candidate. It was turned down: that theme picks its
blocks from `symfony/form` types, so using it means building a `FormView` in the web layer
and pushing every widget through Symfony's type system. The mapping "item type → form type"
already exists in `src/Infrastructure/Validation/FormValuesType.php` — for validation — and
deptrac rightly forbids the user interface from reaching it. We would have written it a
second time, and the choice of control would have moved from the presentation document to
Symfony's types. The document is the authority on how a form looks; that stays.

**Stimulus for behaviour, over AssetMapper.** Stimulus is the heart of Symfony UX and fits
what the page already is: behaviour declared in markup (`data-controller`, `data-action`,
targets), delivered as ES modules with no build step and no package manager. `importmap.php`
names what the browser may import; `assets/vendor/` holds it, committed, so a clone, a CI run
and a browser test all draw the page from the same bytes with no network of their own.

**Live Components were turned down, for now.** They are the honest answer to "mechanics on
the server" — and the design the owner has in mind for later: the server answering a save
with changes to the view and to the rules. But a live component has an endpoint of its own
that re-renders and runs actions, and this application's page is a client of its API with no
write path of its own. Letting that in as a side effect of adding a kit would settle a much
bigger question quietly. It deserves its own plan, written when there is something to
generalize from.

**Turbo was turned down** for a simpler reason: the page's writes are `fetch` calls to the
API, so there is no form submission or navigation for Turbo to take over.

## The kit

`BootstrapEngine` (`bootstrap`) declares what it draws, and nothing in it is a synonym for
something `core-html` already had:

| | `core-html` | `bootstrap` |
|---|---|---|
| group | `fieldset` | `card`, `accordion`, `row` |
| say something | `heading`, `paragraph` | `heading`, `paragraph`, `alert`, `divider` |
| text | `text`, `textarea`, `hidden` | + `floating` |
| choice | `select`, `radio` | + `radio-buttons`, `autocomplete` |
| number | `number` | + `range`, `stepper` |
| date | `date` | `date` |
| checkbox | `checkbox`, `switch` | `checkbox`, `switch` |
| what a form does | `save`, `confirm` | `save`, `confirm` |

The plain controls deliberately keep the same names in both kits — a text field is a text
field — and everything added is a way of *asking* the plain kit has no markup for. The two
ways of grouping share nothing, which is why a document written for one kit is refused by the
other rather than half-drawn: `presentation.widget.mismatch`, naming the engine, the kind of
item and the control asked for.

Options a document may pass to this kit: `width` (1–12, how much of a `row` an item takes),
`columns` (choices side by side), `tone` (which alert), `open` (an accordion that starts
unfolded), `appearance` (`button` or `link`, as before). They carry no text, so the
completeness of the translation catalogue is unaffected.

## What it cost

- `PresentedNodes` — the half of rendering that is the same whatever the kit (resolve a code
  in the asked-for language, find the value, carry the definition's limits to the control,
  tell a container from a decoration from an action), lifted out of `CoreHtmlRenderer`
  unchanged. Each kit passes in the widget a document gets for naming none.
- `BootstrapRenderer` + one template + three Stimulus controllers (`form`, `autocomplete`,
  `stepper`) + one entrypoint that imports two stylesheets.
- `templates/forms/_attributes.html.twig` — the attribute writer, now shared by both kits.
  While moving it, the escaping changed from `html_attr` to `html`: every value it writes is
  quoted, so quotes, `&` and the angle brackets are the whole danger, and escaping a space
  into `&#x20;` only made class lists unreadable.

No change to the domain beyond the new engine class, none to the API, none to storage. That
is the answer about the split: the second kit cost a class, a template and a stylesheet — not
a second understanding of what a form is.

## Two things worth knowing

**A slider always holds a value.** A `range` control has a position even before anybody
touches it, so a form drawn with one sends a number from the first save. That is a property
of the widget, not a bug to work around — a document that wants "no answer yet" to be
possible should ask for `number` instead.

**The page's JavaScript is not shared between kits.** `core-html` keeps its one hand-written
module in `public/js/`; `bootstrap` has Stimulus controllers in `assets/`. The *convention*
is shared and documented — `data-name` and `data-type` say which item a control holds and
what it is on the wire, `data-error` marks where a refusal goes — and each kit implements it
in the machinery it chose. Deliberate: the point of `core-html` is that it needs nothing.

## Order & acceptance — as built

1. **The machinery**: `symfony/asset` + `symfony/asset-mapper` + `symfony/stimulus-bundle`
   (all pinned `^7.4`, since the install otherwise mixed 7.4 and 8.1), wired by hand because
   this repository has no Flex: the bundle, `framework.asset_mapper.paths`, `importmap.php`,
   `assets/controllers.json`. `make assets` refreshes the vendor files.
2. **The shared half**: `PresentedNodes` extracted, `core-html` unchanged in behaviour (its
   own tests are what says so).
3. **The kit**: `BootstrapEngine`, pinned name by name, plus rules tests that a document is
   only as good as the kit it names.
4. **The drawing**: `BootstrapRenderer`, the template, the controllers, crawled control by
   control.
5. **The proof**: `tests/Browser/BootstrapFormPageTest.php` — TomSelect really replaces the
   select, the stepper really walks between the definition's bounds, the accordion really
   folds without a library, a refusal really marks its control, confirming really locks it.
6. **Documents**: this file, `README.md`, `CLAUDE.md`.

## Non-goals

No wizard (`step` containers, progress, next/back) — the vocabulary above is already enough
to show the split pays, and a multi-step page is a design question about *when a draft is
saved*, not about markup. No second engine's worth of shared JavaScript. No server-driven
view or rule changes: that is the next plan, and it starts from Live Components rather than
from a kit.
