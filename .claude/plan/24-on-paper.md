# 24 — the same form, on paper

**Built 2026-09-09.** What the code does now is in `docs/kits.md` (both kits), `docs/architecture.md`
(the markup conventions and the pages chapter) and `docs/configuring-forms.md` (what the reader
controls). This file is the design and, at the end, what the building corrected — which here is
most of the interesting part, because three of the four rules turned out to be wrong the first
time and only a browser could say so.

The last row of [10](10-what-a-vendor-offers.md)'s comparison table marked **partial** and never
touched: *"print / export one form — the page prints from a browser and that is the whole
mechanism: no print stylesheet, no archival rendering."* The archival half is built
([16](16-the-record.md)); this is the other one.

## What was actually wrong

Measured before writing a rule: **no print styles of our own existed anywhere** — the only
`@media print` block on a page came from vendored Bootstrap, and it defines display utilities
nobody uses. So `Ctrl+P` printed the page exactly as it stood, which meant:

- **A form paged into parts printed one part.** Every page of a wizard and every panel of a strip
  is in the markup with `hidden` on all but one — the invariant that makes paging *presentation* —
  and on paper it made the printout a lie about what the form holds.
- **A folded group printed as a heading.** `details` closed is content the browser does not
  render at all.
- **The chrome printed and the answers did not read as answers.** The reader's own switches, the
  triggers, the language links, the panel of earlier versions, the strip of tabs, the *add* and
  *remove* buttons — all of it on the paper, with the answers inside boxes.
- **Dark colours printed dark.** A preference about a screen, spent on somebody's cartridge.

## What it is not

The **record** (`GET /api/manage/forms/{id}/pdf`) is an archival copy of a *confirmed* form, laid
out by a library, on the management prefix, naming the author and the confirmer, needing no
presentation. This is the other thing: the form in front of a person, printed either blank to fill
in by hand or filled to keep. Neither replaces the other, and the two are allowed to look
different — one is a document about a form, the other is the form.

## One convention rather than two lists

Everything that has to disappear is **marked in the markup** — `data-chrome` — rather than
enumerated as selectors per kit. Both kits carry it on: the comfort panel, the language nav, every
trigger the document placed, the history panel and its buttons, a wizard's track, status and
back/next, a strip of tabs, *add* / *remove* on a list, *remove* on a file, and an upload's
progress. The print sheet is then one rule, twice — and the next widget that draws a button gets
the marker rather than a second entry in a list that has already fallen behind.

What the sheet does beyond hiding:

| Rule | Why |
|---|---|
| `[data-page][hidden] { display: block }` | every part of the form, not the one that happened to be open |
| `[data-unasked] { display: none }` | a question nobody was asked stays off the paper — printed with a line beside it, it reads as an answer somebody withheld, which is why the record leaves it out too |
| the palette forced to ink on white | a preference about a screen is not a preference about a page |
| a control flattened to a line | the box is how a screen says "type here"; a line is what a printed blank form needs |
| `break-inside: avoid` on entries, groups, pages | a row of a list belongs on one sheet |

And the button: **"Drukuj" in the reader's own panel**, beside the three switches, not a switch
and nothing remembered — a printed page is asked for once. It is there because printing is the
reader's affair like contrast and text size, and because `Ctrl+P` is a shortcut a page cannot
advertise. The document neither places it nor removes it, exactly like the switches it sits with.

## What the building corrected

- **A print stylesheet can be tested, and the caveat I raised was wrong.** I said out loud that
  this was the one thing with no test story, because WebDriver cannot emulate a medium. It can:
  Chrome's own protocol has `Emulation.setEmulatedMedia`, chromedriver exposes it at
  `/session/:id/goog/cdp/execute`, php-webdriver can post to it, and `matchMedia('print')` then
  answers true. So every rule above is asserted by a real browser laying the page out for paper —
  eight tests across the two kits — rather than by reading CSS text.
- **No stylesheet can open a fold.** Four rules were tried in the browser and all four left a
  closed `details` closed: `display: revert` on the children, `content-visibility: visible`, and
  `::details-content` with either. The content of a closed `details` is out of CSS's reach, so
  printing a folded group needs one line of script on `beforeprint` — the browser's own
  invitation — and `afterprint` puts the fold back, because printing is not rearranging.
- **A skin's literal colours again, this time as an `!important` utility.** `body` carries
  `.bg-body-tertiary`, and every Bootstrap background utility is `!important` on a *class*, so an
  element selector loses however late it comes. The rule is written as `body.bg-body-tertiary`
  beside `body` — the same lesson the wizard's marks and the comfort bar already carry, in a third
  form.
- **An emulated medium is state, and it outlived the test that set it.** Panther keeps one Chrome
  for the whole run, so the first case that asked for paper left every later case laid out for
  paper — and a case that never asked reported all three hidden pages as shown. The medium is
  handed back in `tearDown()` now (which also has to call the planting trait's cleanup by hand,
  because a class's method beats a trait's silently — the file suite's old lesson, met again).
- **PHPStan had two things to say about the new battery, and both were right.**
  `executeCustomCommand()` lives on a driver spoken to over the wire, not on the interface above
  it, and a computed style comes back as `mixed` — so the battery says which driver it needs and
  asserts what it got, rather than casting and hoping.
- **And one plain slip worth recording**: the change to the richer kit's template was computed and
  never written, so the Stimulus action was missing while every file around it looked right. Ten
  minutes went into asking the browser what it had — which is also how it was found.

## Deliberately absent

A page-break option a document could ask for, headers and footers of our own (a browser's are the
browser's), a "print without answers" switch (the sheet is one behaviour: what is on the form is
on the paper, and a blank form printed from an empty form is already blank), a QR code or an id in
a corner, and any print rule that a *skin* could change — paper is not a place two skins should
differ.
