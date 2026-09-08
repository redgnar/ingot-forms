# 22 — one form in sections, side by side

**Built 2026-09-08.** What the code does now is in `CLAUDE.md`,
`docs/configuring-forms.md` ("One form in parts"), `docs/kits.md` (four entries) and
`docs/architecture.md` (the markup conventions and the pages chapter). What is below is the design
as it was decided, with what the building corrected at the end.

The last entry of [10](10-what-a-vendor-offers.md)'s comparison table that is a *widget* rather
than a decision: their Layout family has Tabs and ours has `card`, `accordion`
and `row`. The row says "one widget, not a missing mechanism", and after [19](19-wizard.md) that is
exactly right — the mechanism is built, and this is a second way of using it.

## The question that had to be settled first

**Are tabs a restyled wizard?** If they are, this repository's own rule refuses them: a widget must
be a different way of *asking* and never a restyling of one — the rule a floating label was removed
under.

The obvious answer is "no, a wizard is a sequence and tabs are random access", and it is wrong.
Measured rather than assumed: the wizard's marks have been clickable since the day it shipped
(`data-wizard-mark` → `showStep`, nothing gated), so *jump straight to page four* is already there.
Anybody proposing tabs for that reason is describing what we have.

What is actually different is what a reader is **told** and how they **move**, and neither is a
stylesheet:

| | a wizard | tabs |
|---|---|---|
| the roles | a `nav` of buttons, `aria-current="step"` | `tablist` / `tab` / `tabpanel`, `aria-selected` |
| the keyboard | tab to each mark, one at a time | one stop for the whole strip, arrows between tabs |
| what is said | "page 2 of 5", back and next | the tab's own name, and nothing about progress |
| what it implies | finish this, then continue | these are peers; look wherever |

The middle row is the one that cannot be reached by restyling: a screen reader offers arrow-key
movement because the roles say `tablist`, and a strip of five buttons that each take a tab stop is
a different thing to operate from one control holding five choices. So `tabs` is a way of moving
and being told where you are, and the wizard keeps the ordered semantics it is named after.

## What is shared, on purpose

Everything that makes paging safe, because it is one rule and not two:

- **Every panel is in the markup and all but one carry `hidden`.** A save sends the whole form
  whatever is showing; a tab has no contract of its own. That is the invariant, and it is what
  makes this presentation rather than a second way of validating.
- **Nothing is gated.** There is no *next* to gate, and a tab is never disabled for holding an
  unanswered question — a ceiling is held before it is met and a floor never is.
- **A panel whose every question a condition left unasked draws no tab**, and is never landed on.
- **A refusal brings its panel forward** before the caret goes there, for the reason a folded entry
  is unfolded: a message nobody can see is not a message.
- **Two side by side are fine**, each showing its own panels; every mechanism is per-container.

So the two looks share one module function per kit and one Stimulus controller, and the code that
holds them stops being named after the wizard: `data-pager` (with the look as its value —
`steps` or `tabs`), `data-page`, `data-page-mark`. Two names for one mechanism is how the mechanism
drifts, and this block is the moment to pay that rename rather than the moment to double it. What
stays wizard-shaped keeps its name, because it belongs to that look alone:
`data-wizard-nav`, `data-wizard-status`, `data-wizard-back`, `data-wizard-next`.

## What is refused

The wizard's five refusals in a second vocabulary, so one validator judges both pairs
(`PagesBelongToTheirPagerValidator`, renamed from `StepsBelongToAWizardValidator` for the same
reason the attributes were):

| Code | Refuses |
|---|---|
| `presentation.tab.outside-tabs` | a `tab` anywhere but directly inside a `tabs` |
| `presentation.tabs.holds-more-than-tabs` | a `tabs` holding anything that is not a `tab` |
| `presentation.tabs.no-tabs` | a `tabs` with nothing in it |
| `presentation.tabs.nested` | a `tabs` inside a `wizard` or another `tabs` |
| `presentation.tabs.in-an-entry` | a `tabs` inside an entry of a list |

**No pager inside a pager, in either direction**, which generalizes the wizard's own rule and its
message: a panel hidden inside a hidden page needs two mechanisms to agree about what to reveal
when a refusal lands in it, and nothing asked for that. The wizard's own codes keep their names
(`presentation.wizard.nested` now also refuses a wizard inside a `tabs`).

**A `tab` with no label is not refused**, and that is deliberate: it falls back to its number, the
way a step does. A numbered tab strip is a poor page and a fixable document, while a refusal is
forever — and the day somebody asks for that rule, it is one line in the same validator.

## Steps

1. **The rename, with the wizard's own batteries as the proof.** `data-pager`/`data-page`/
   `data-page-mark`, `pager_controller.js`, `pager:reveal`, and the validator's new name — no new
   behaviour, and the wizard's five browser tests, three markup tests and six refusals stay green.
2. **The vocabulary.** Both engines declare `tabs` and `tab`; the validator judges the second pair;
   the refusal battery grows the five cases and the accepted shapes (two strips side by side, a
   `tabs` beside a `wizard`).
3. **Both kits draw it.** The plain kit as a strip of buttons over a line, the richer one as
   Bootstrap's own nav-tabs; roles, `aria-selected`, one tab stop with arrows, `Home`/`End`.
4. **The batteries.** A markup test for what the server renders, and a browser test per kit:
   moving by click and by arrow key, a tab a condition emptied, a refusal on a hidden panel, and
   the whole form saved from whichever tab is showing.
5. **The documents.** `configuring-forms.md` (the container table and the five codes), `kits.md`
   (both kits, control by control), `architecture.md` (the markup conventions, now `data-pager`),
   `CLAUDE.md` (the paging paragraph, which is where the shared-mechanism rule belongs).

## Deliberately absent

Per-tab validation, a tab that is disabled until another is finished, remembering which tab
somebody was on (there is nowhere to keep it that is not a second document), a trace of a tab in
the printed record (a labelled tab reads as a section, like a card and a step), and tabs inside
tabs.

## What the building corrected

- **`data-page` was already taken, in the kit that had no controller to hide it.** The plain
  kit's `<body>` carried `data-page` — the address of this page, which its history panel reads —
  so the new attribute for a panel collided with it the moment a crawler asked for `[data-page]`.
  The body's is now `data-page-url`, which is what it always meant. Found by a test that had
  nothing to do with tabs, counting the wizard's own pages: exactly the kind of collision a
  rename is for.
- **The generalization closed a hole the old rule had.** `insideAWizard` was a single flag set
  for a wizard's direct children, so it answered two questions at once and got the second wrong:
  a `wizard` inside a `step` of a wizard was **accepted**, because walking into the step reset the
  flag. Belonging to a pager is about being a direct child; being *inside* one is about any depth,
  and they are now two facts. A wizard inside a page of a wizard is refused, with a case of its
  own.
- **A document that mixes the pairs gets one complaint, not two.** A `tab` directly inside a
  `wizard` is one mistake with two readings, and both fire at the same pointer. The pager's is
  the one kept — "a wizard holds the pages of the form and nothing else" names both halves of
  what does not fit — so a page says nothing when the pager directly above it is the other pair's.
- **The panels name themselves instead of pointing at their tabs.** `aria-controls` and
  `aria-labelledby` need ids; a unique id needs identity the presented tree does not carry
  (a container presents no value, so it has no name), and a generated one would break the rule
  that the same form renders byte-identical markup. So the name is repeated on the panel, which
  every screen reader announces; what is lost is one shortcut in one of them.
- **Mutation testing found two escaped mutants, and both were redundancy.** A `return` after
  handing an item to the pager branch (a pager's widget is never a page's name, so the rest of the
  method could not have fired anyway) became an `else` and a method of its own; and the finding's
  fourth argument — the word that does not belong, which a client reads as `input` — was written
  `$item->widget ?? ''`, a coalesce nothing could reach and nothing asserted. The page's name is
  now read back out of the table the search matched in, which is the same word and needs no
  fallback, and a test pins what the refusal carries. A third mutant appeared in the fix itself
  (a `false` sentinel for "no widget written" that `is_string` would have refused anyway) and went
  the same way: a strict search cannot match `null` against the names in the table, so the guard
  was never a guard.
- **The first arrow-key expectation was wrong, and the mechanism was right.** The test pressed
  `→` and expected the second section; it got the third, because the second holds one question
  nobody was being asked yet — a section a condition emptied is passed over rather than landed
  on, which is the wizard's rule holding in the new look without a line written for it. The test
  now answers that question first and leaves the arrows nothing to skip.
