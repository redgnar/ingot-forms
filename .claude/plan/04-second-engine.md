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
| text | `text`, `textarea`, `hidden` | `text`, `textarea`, `hidden` |
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

## Three things worth knowing

**A slider always holds a value.** A `range` control has a position even before anybody
touches it, so a form drawn with one sends a number from the first save. That is a property
of the widget, not a bug to work around — a document that wants "no answer yet" to be
possible should ask for `number` instead.

**A floating label was tried and taken back out.** It was in the first cut of the kit and it
was a mistake twice over. Bootstrap can only float a label over a text box or a select, so any
form with a choice group, a switch or a slider labels some items inside their control and the
rest above — a mixture no page can be talked out of, and the owner saw it immediately. And it
was the same question asked the same way with the text moved somewhere else, which is a style,
not a way of asking: exactly what this kit's names are not for. The alignment rule written to
prop it up (reserving the space a label would have taken, so a mixed row stayed level) went out
with it. A widget that needs the layout patched around it is worth re-reading before it is
worth patching.

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

## After it was built

- **The floating label came out** (see above), and with it the alignment rule.
- **Two more Symfony pieces earned their place**, once the question "what here is actually
  Symfony's?" was asked out loud: **UX Icons**, imported into `assets/icons/` so nothing is
  fetched at runtime, and **the translator** for what a page says in its own name. That second
  one closed a real gap: the form's own text was localized from the presentation's catalogue
  while the page's sentences — a stored draft, a closed form, the error pages — were hardcoded
  English. They now live in `translations/messages.*.yaml` under `page.*`, in both kits, and the
  browser is handed the one sentence it needs rather than holding it in a controller.
- **Icons need a size from the page.** UX Icons renders an SVG with a viewBox and no width or
  height, deliberately — and an SVG with no size has no intrinsic one, so a browser drew them at
  0x0 in a flex row. `assets/styles/bootstrap-form.css` is now the kit's own handful of rules,
  sizing an icon in `em` so it matches the text beside it, and a browser test measures one.
- **What stayed hand-written**: the autocomplete controller. `symfony/ux-autocomplete` wraps the
  same TomSelect; without a `FormView` we could only use its JavaScript, and its real value —
  fetching options from a server endpoint — is not something a form whose choices come from the
  definition needs.

## Non-goals

No wizard (`step` containers, progress, next/back) — the vocabulary above is already enough
to show the split pays, and a multi-step page is a design question about *when a draft is
saved*, not about markup. No second engine's worth of shared JavaScript. No server-driven
view or rule changes: that is the next plan, and it starts from Live Components rather than
from a kit.
