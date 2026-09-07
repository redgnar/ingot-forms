# 19 — a wizard: one form, several pages

**Built**, and the last entry of [10](10-what-a-vendor-offers.md)'s list to be. What the code
does now is in `CLAUDE.md`, `docs/configuring-forms.md` ("One form on several pages"),
`docs/kits.md` and `docs/architecture.md`; the last section here says what the building
corrected. The design opened from the one line that entry gave it: *"presentation only — stepping and a progress bar over what both kits already
draw"*. That line is the whole design brief, and everything below follows from taking it
literally: **the definition does not move, the document a save sends does not change, and a step
is a way of looking.**

## What a wizard is here

Two container widgets, declared by both engines like every other container:

```json
{"widget": "wizard", "items": [
  {"widget": "step", "label": "t.who",   "items": [ … ]},
  {"widget": "step", "label": "t.what",  "items": [ … ]},
  {"widget": "step", "label": "t.send",  "items": [{"widget": "confirm", "label": "t.send"}]}
]}
```

A `wizard` holds `step`s and shows one at a time; a `step` holds whatever any container holds.
Nothing else in the document changes — an item is presented exactly once, wherever it sits, and
the rules about that are asked in every scope as they always were.

**Why a `wizard` around the steps** rather than `step` containers at the top level: a document
decides *where* things sit, and a wizard has a place on the page — a heading may stand above it
and the form's own triggers below it. It is also the thing that has a progress bar and a
"next", so "one at a time" is a property of the wizard rather than a rule every step repeats.
And it makes the mistakes nameable: a step outside a wizard, a wizard holding something that is
not a step, a wizard inside a wizard.

## What is refused, and where

Beside the rules every presentation already keeps (`Rule/StepsBelongToAWizardValidator`):

| Code | Pointer | What it means |
|---|---|---|
| `presentation.step.outside-a-wizard` | `…/widget` | a `step` that is not directly inside a `wizard` — it would draw as an ordinary group and nothing would step it |
| `presentation.wizard.no-steps` | `…/items` | a `wizard` holding no `step` at all: a stepper with nothing to step |
| `presentation.wizard.holds-more-than-steps` | `…/items/N/widget` | something beside the steps. A heading for the whole wizard goes *before* it, where it is always visible; inside, nothing could say when to draw it |
| `presentation.wizard.nested` | `…/widget` | a wizard inside a wizard: which one "next" belongs to is a question with no answer |
| `presentation.wizard.in-an-entry` | `…/widget` | a wizard inside a list entry, for the reason a trigger cannot sit there — an entry is answered in a form folded under its row, and paging that is not a thing anybody asked for |

**Two wizards side by side are not refused.** Each steps its own steps, every mechanism below is
per-wizard, and a page that wants two of them is describing two independent parts of one form.

## What the page does

- **One step visible, the rest `hidden`.** Not removed: the document a save sends is the whole
  form, whatever page somebody is looking at, because a step is presentation and the contract
  knows nothing about it. This is the invariant to protect, and the one a browser test pins.
- **Next and back are the wizard's own**, drawn by the kit like a list's *add* and *remove* —
  not `PresentationActions` a document places, because a wizard without them is unfinishable in
  the way a presentation without `confirm` is.
- **Nothing is validated on the way.** A page never stops somebody for being under a floor
  while filling in (`docs/kits.md`, "a ceiling is held before it is met; floors are never
  enforced"), and a step is not a smaller form: it has no contract of its own. So *next* always
  moves, and the refusals arrive from the server at save or confirm.
- **A refusal moves the wizard to the step it is about.** The existing rule — a message nobody
  can see is not a message — already unfolds every entry on the way to a refusal and marks the
  rows; a wizard adds one more thing that hides a message, so it opens the step holding the
  first refused control before the caret moves there.
- **A step with nothing to answer is stepped over.** Conditions can empty a whole page: if every
  question on it is unasked, *next* passes it by and the progress bar leaves it out. A step
  holding a trigger or anything else visible is never skipped — "review and send" is a page with
  no controls and every reason to exist.
- **The progress is the step labels**, the current one marked (`aria-current="step"`), each a
  button that jumps there — free navigation costs nothing, since nothing is gated — plus a line
  a screen reader hears when the step changes.
- **Which step somebody is on is not stored.** Not on the server (there is no identity to hang
  it on) and not in the browser: a wizard opens on the first step with something to answer, and
  the only thing that moves it by itself is a refusal.

## Markup convention

Shared between the kits, like `data-name`/`data-collection` before it:

| Attribute | Means |
|---|---|
| `data-wizard` | the stepper |
| `data-step` | one page of it |
| `data-wizard-nav`, `data-wizard-mark` | where the progress goes, and one label in it |
| `data-wizard-back`, `data-wizard-next` | the two buttons |
| `data-wizard-status` | the line that says where somebody is, for a reader who cannot see the marks |

The words are ours (`page.wizard.*` in `translations/`), the step's own label is the document's.

## Steps, in the order they were taken

1. Domain: `wizard`/`step` in both engines' `containers()`, and
   `Rule/StepsBelongToAWizardValidator` with its battery (six refusals and two accepted shapes
   in `PresentationProcessorTest`).
2. Renderer: nothing new in the tree — a step and a wizard are `BranchNode`s, which is what made
   this presentation-only rather than a fourth node type. One macro per kit, and
   `WizardInTheMarkupTest` asked of both kits for the invariant: every page rendered, one shown.
3. Kits: `wizard_controller.js` in the richer one, a few dozen lines in the plain one's module,
   plus the refusal-opens-the-page behaviour in each `#reveal`.
4. Browser battery per kit (`tests/Browser/Wizard`, five cases each): stepping there and back,
   a page a condition fills in, everything collected whatever page is showing, a refusal about
   another page, and the marks and the ends.
5. Docs: `configuring-forms.md` (its own section, the codes, the index table), `kits.md` (two
   widgets per kit — `DocumentedWidgetsTest` insisted), `architecture.md`, `CLAUDE.md`,
   `README.md`, and this file.

## What the building corrected

- **A stated invariant had to change.** `BootstrapEngineTest` asserted that the two kits' ways
  of grouping *share nothing* — "a fieldset is not a card" — and `wizard`/`step` are the first
  containers both declare. The assertion is now the sharper claim: the ways of **looking** share
  nothing, while the one way of grouping that is not a look is in both kits, because paging a
  form is a structure.
- **One mistake, one complaint.** A wizard inside a wizard is three mistakes on paper — nested,
  not a step, and the outer one left with no steps — and reported all three the first time. The
  nested complaint is made where the wizard sits and the outer one now says nothing, which is
  the rule the definition's own validators already keep.
- **The pair of things that follow an answer got a name.** `ask()` is recursive (once per entry
  scope), so refreshing the steppers inside it would have run once per entry; the plain kit grew
  `evaluate()` — ask, then refresh — called from the three places an answer changes.
- **The first shape of the marks was wrong twice, and the owner said so.** A row of Bootstrap
  pills looked exactly like the choice buttons underneath it — so a control that changes the
  page read as an answer — and it wrapped onto a second row at five pages. They are a **track**
  now: a numbered place per page joined by a line, on one line that scrolls, with the current
  place scrolled into view and filled with the body colour rather than the accent. The numbers
  are a CSS counter, which buys two things worth having: no markup renders them, so a page a
  condition hid takes none, and a screen reader does not read them out — what it reads is the
  line underneath. Hiding the *mark* alone left an empty place and a gap in the numbering, so
  the place goes with it (`:has(> .wizard-mark[hidden])`).
- **A bug in the signature pad came out of it**, which is the third time this afternoon the
  same symptom was mistaken for a slow machine. The battery kept timing out inside `make ci`,
  and raising the wait (six → ten → twenty seconds) never fixed it because the wait was never
  the problem: `endStroke` is a frame later than the release, so a save pressed inside that
  frame found the file widget idle, collected an empty control and sent a document with no
  signature in it. The pad is now busy from the moment the pen touches it (`beginStroke`), which
  is the state a save waits on — and the case it fixes is a person signing and pressing *save*
  in one movement, not a test.
- **A test helper cannot be called `status()`**: `PHPUnit\Framework\TestCase::status()` is
  final, and the override is a fatal error rather than a failure.

## What this deliberately will not be

- **No validation per step**, for the reason above.
- **No server-side stepping.** A page is a client of the API; a step is not a request.
- **No branching between steps.** "Which page comes next" as a rule would be a second condition
  language over the one the definition already has; a step with nothing asked is skipped, and
  that is the whole of it.
- **No step in the record.** A PDF of a confirmed form keeps a labelled container's *words* and
  loses its shape, and a step is a shape — so a labelled step reads as a section, like a card.
