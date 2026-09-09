# 20 — what one session changed, and what using it corrected

Twenty-one commits here and three in the library, 173 files, +19881/−588, in fourteen blocks.
Nine have plans of their own and this is the index to them; **five have none, and that is why
this file exists** — plan [13](13-what-one-session-changed.md) was written for the same reason
after the first long session, and the same reason held again: a block of work with no plan
leaves no record of *why* it happened.

| Block | Commits | Record |
|---|---|---|
| The gateway this service refuses to be | `c050f28` | [09](09-access.md) |
| A save that says which form it is replacing | `8ca322b` | [14](14-conditional-writes.md) |
| Several of one list, as one value | `13bdff1` | [15](15-multiple-choice.md) |
| A confirmed form as something to file | `c3b4a9d` | [16](16-the-record.md) |
| A signature, and a record that shows it | `52715e7` | [17](17-signature.md) |
| Questions asked only sometimes | `bf2956b`, ingot `a725922` | [18](18-conditions.md) |
| One form on several pages | `6457399` | [19](19-wizard.md) |
| A number worked out rather than typed | `b32c95b` | [21](21-calculated-values.md) |
| One form in sections, side by side | `cf58565` | [22](22-tabs.md) |
| An address and a number, as types | the commit that carries this line | [23](23-email-and-phone.md) |
| Refusals that name everything | ingot `72e6767`, `4bb48ca` | this file |
| Alternatives, reported as one | ingot `cfef0b3`, `c6d5563` | this file |
| Documentation held to the code | `6250145`, `b3aa3f3`, `ff3d929`, `9483cc1`, `b136920`, `0e0a6fd` | this file |
| What the owner found by using it | `ffeaf81`, `a6ab1e1`, and half of the above | this file |
| Sweeping up after the last of it | `26d46e9` | this file |

**The row about what the owner found is the shape of the session.** Roadmap [10](10-what-a-vendor-offers.md)'s list is
finished — every one of its seven entries is built — and *most of what is recorded below was not
on any list*. It came from somebody opening a form and clicking.

## Refusals that name everything

The condition work left one thing measured and unfixed: two failing branches of a derived schema
came back one per attempt, so a long conditional form was answered in as many rounds as it had
obligations. The cause was in the library and neither half of it was configurable:

```
ObjectSchema::doValidate()   before → the keywords of the data's type → after,
                             returning after the phase that failed
AllOfKeyword::validate()     returns at the first branch that did not hold
```

so `required` and `properties` come back together (one phase) while `allOf` sits in the next one
and is never reached. The fix is a second *question* rather than a second traversal: a branch of
an `allOf` applies to the same instance as the schema holding it, so each is asked on its own and
the answers merge at the pointers they already carry — **only when the document was refused**, so
an accepted one pays nothing, and never for a branch that names something in the document around
it. That guard is measured rather than cautious: a branch carrying `$ref: "#/$defs/named"`
resolves when the root has already been parsed (opis caches by identity) and throws on a fresh
validator, and what a validator happens to have parsed must never decide whether somebody's
document is refused.

What it buys, on the example the owner was clicking: `POST …/confirm` answered `/kraj` before and
`/kraj`, `/zgloszenie`, `/kontakt` after. What still stages is one scope deep — inside a list
entry, what the entry always owes precedes a conditional obligation beside it — because following
that would mean walking the schema and the data together and re-pointing every finding, which is
the library's own line.

## Alternatives, reported as one

The mirror image, and the reason the two now sit side by side in one class. `anyOf`'s sub-errors
are the roads *not* taken:

```
before   /payment/card      schema.required   "card" is required.
         /payment/transfer  schema.required   "transfer" is required.

after    /payment           schema.anyOf      The value matches none of the 2 alternatives:
                                              (1) /payment/card "card" is required;
                                              (2) /payment/transfer "transfer" is required
```

Two findings that are each a wrong instruction, against one that a caller can act on. A `oneOf`
matching more than one says *that* instead, since then nothing is missing and the document is
ambiguous. Nothing here derives an alternative into a reportable place — a condition's `any`
becomes an `anyOf` inside an `if`, and a failing `if` says nothing at all — so this changed no
test in the service; it is written down for the day something does.

## Documentation held to the code

Six commits, and the pattern in all of them is the same: **the documentation described an older
truth, and only a person reading it could tell.** Nothing in the pipeline reads Markdown, which
is why two of these grew a test instead of a promise.

- **`6250145`** — the widget tables had fallen behind the engines (a whole item type, `datetime`,
  had been missing for as long as it existed). `DocumentedWidgetsTest` now holds both documents to
  what the kits answer, for the reason `RouteGroupsTest` exists.
- **`b3aa3f3`** — the layer map in `CLAUDE.md`, stale across five sessions and the first thing
  anybody reads: a whole layer directory, six ports, four use cases and half the exceptions were
  missing. Also nine `make` targets absent from the table that claims to index them, and an
  endpoint list missing `GET …/data` beside the `PUT` that was there.
- **`ff3d929`** — the lifecycle bullet said a draft enforces "types, enums, ranges, lengths", and
  `pattern` is in none of those words. The owner typed three digits of a tax number, pressed *save
  for later*, and was told no. The contract was right and stayed; the sentence became one rule —
  *an obligation waits, a rule about the value does not* — with both sides spelled out and the
  consequence said out loud.
- **`9483cc1`** — `CLAUDE.md` said `app:files:purge-temporary` collects "directories whose row is
  already gone", as if unconditionally. The age gate is asked first and covers that too, which is
  in the class's own docblock and was not in the document anybody reads.
- **`b136920`** — the wizard had a reference entry in every document and was missing from the
  *prose* a reader meets first: the list of what both kits do unasked, and the plain kit's list of
  what it does not have (from which paging looked absent).
- **`0e0a6fd`** — "how does conditional logic behave in a collection, and do we have an example?"
  The section had the shapes and none of the behaviour, and no `.http` file mentioned `askedWhen`
  at all. `10-conditions.http` is thirteen requests, replayed against a running service *before*
  their assertions were written.

## What the owner found by using it

Four things, and none of them was on a list.

- **`ffeaf81` — `anonymous` has to discard the author too.** It discarded the confirmer and every
  save's actor and kept the author, on the reasoning that creating happens where a caller is
  always known. That made "this form records nobody" a sentence in a document rather than a
  property of the form, which is the whole point of the mode: it is the *creator's own
  configuration*, so asking for a form that records nobody is asking that about oneself as well.
  A migration repaired the rows already written.
- **`a6ab1e1` — a page was read in two languages.** The consent box refused in English under a
  form whose every question was Polish. Nothing was missing from the catalogue: the questions fall
  back to the document's `defaultLocale`, and the page's own words followed the *request*. Now the
  document is asked which language it can actually answer in (`Words::spokenIn()`, beside the
  chain rather than a second copy of it) and the answer goes to the translator, the renderer and
  `<html lang>` alike.
- **The wizard's marks were the wrong shape, twice.** A row of pills looked exactly like the
  choice buttons underneath — a control that changes the page reading as an answer — and wrapped
  at five pages. Then, once they were a track, the connector line painted *over* the numbers,
  because a positioned `::after` is the last child of its place. Both are recorded in
  [19](19-wizard.md); both were found by looking at a screenshot, which is the only ground truth
  for a page.
- **A signature could be lost by saving too quickly.** Three timeouts in one afternoon, two rounds
  of raising a wait, and the wait was never the cause: `endStroke` arrives a frame after the pen
  is lifted, so a save pressed inside that frame found the file widget idle, collected an empty
  control and sent a document with no signature in it. The pad is busy from the moment the pen
  touches it now. The case it fixes is a person signing and pressing *save* in one movement — not
  a test.

## What is deliberately still open

- **There is still no gateway.** A form's UUID opens everything. The routing split, the identity
  header, the document and a runnable example are all here; the deployment is not code.
- **Inside one list entry, refusals still stage.** What the entry always owes precedes a
  conditional obligation beside it. Following it means schema-and-data traversal in the library,
  which is the one thing that library delegates to opis.
- **`schema.*` codes are named after keywords**, so the published contract can refuse under a name
  this service never wrote down. The five `form.value.*` codes are ours and are spelled out; the
  rest grow with the schema.
- **A survey matrix, an embeddable renderer, offline drafts** — the remaining entries of
  [10](10-what-a-vendor-offers.md)'s comparison table, none of them on its ordered list, and each
  still a decision rather than a task. Calculated values, tabs, and `email` and `phone` were on
  this line until the session's last blocks took them off it.

## Sweeping up after the last of it

The block with no feature in it, and the reason it is recorded: three of the four things it found
were **documents and configuration disagreeing with the code**, which is the same failure this
session met six times over and the one nothing in the pipeline notices.

- **Our own deprecation, logged and never read.** `ValidFormPresentation` handed `[]` to
  `Constraint::__construct()`, which is symfony/validator 7.4's deprecated path — a constraint
  that takes no options must hand it nothing at all. It had been in every `make docs` run for
  weeks, at `INFO`, in JSON. Both constraints are the same minimal shape now (and
  `ValidFormDefinition`'s `getTargets()` went with it: it restated the base class's own answer).
- **Two variables a deployment could not know about.** `FORMS_SKIN` and `FORMS_WEBHOOK_TIMEOUT`
  were documented in `.env.dist` — which is a developer's document — and absent from the
  Operations chapter, which is the one whoever installs this reads. So
  `DocumentedConfigurationTest` now holds both documents to what the code reads, in both
  directions, because a variable nothing reads is a lie of the same size as an undocumented one.
  It is `DocumentedWidgetsTest`'s argument applied to the other hand-written document, and it was
  checked by taking a variable out of the chapter and watching it fail.
- **Two sentences that survived the fix that falsified them.** Both kit documents still said a
  chain settles in one pass, and that a person "never meets" `form.value.miscalculated` — the
  claim the owner had disproved by meeting it. They now say *should*, and say why the page words
  the refusal anyway.
- **And the test byte store, which leaks by construction.** Bytes are in nobody's transaction, so
  a rolled-back row cannot take committed bytes with it: 49 directories and 480 KB had
  accumulated. `make storage-clean` is the sweep, `app:files:purge-temporary` is the same fact in
  production, and `docs/architecture.md` says so now rather than leaving it to be rediscovered.

## One form in sections, side by side

Recorded in [22](22-tabs.md), and the reason it is worth a plan at all is the question it opened:
**tabs looked like a restyled wizard**, which this repository refuses by name. Settling it took a
measurement rather than an argument — the wizard's marks had been clickable since the day it
shipped, so *jump anywhere* was never the difference; the roles and the keyboard are, and a
stylesheet cannot say either. What the block cost beyond the widget was the rename that follows
from the answer: one mechanism under two looks is one name (`data-pager`, `data-page`,
`pager_controller.js`, `PagesBelongToTheirPagerValidator`), paid once here rather than doubled.
Generalizing the rule also closed a hole nobody had noticed — a wizard inside a *page* of a
wizard used to be accepted, because one flag was answering two questions.

## An address and a number, as types

Recorded in [23](23-email-and-phone.md). The interesting half was not the two types but the
measurement they needed: `format: email` is a keyword, and this server reads it with
`FILTER_VALIDATE_EMAIL` while a client reads it with `ajv-formats`, with plain Ajv, or not at all.
That is the shape of mistake `multipleOf` already made here, so the pattern published beside the
format was **chosen against the filter** — 28 strings, the fringes included, no divergences — and
that measurement is the whole reason both rules can be published at once. `phone` went the other
way for the same reason: E.164 as a plain regex, no format, no options, and formatting left to the
client because nothing here reformats what it was sent.

It also found a guard not guarding: `DocumentedWidgetsTest` asks its questions of a **hand-written**
list of item types, so a new type was invisible to the test that exists to notice exactly that. It
now reads the union's own discriminator map and fails when the two lists differ.

## The shape of the session, if it is worth copying

Three habits did the work, and each of them was cheap.

**Measure before writing a sentence.** Every claim in the four blocks above was checked against a
running service or a real browser: the opis error tree was printed rather than reasoned about
(which is what showed *two* short-circuits where one was expected), the request file was replayed
before its assertions were written, the skins were compared by reading the computed colour of the
current step, and the page's language was confirmed with an `Accept-Language` header rather than
by reading the code that reads it.

**Let mutation testing tell you the code is wrong, not the tests.** Nine escaped mutants in the
condition validators were nine missing cases or nine pieces of redundancy — and one of them found
a *rule* that was wrong: the comparability table quietly allowed comparing a `multiselect` to one
of its options, which could never hold. Three formulations in the library were rewritten because
their shorter form had a mutant nothing could kill.

**Take a correction as a design input.** The owner reversed or sharpened six decisions mid-flight,
and every one is recorded next to the thing it changed. Two of them — the author under
`anonymous`, and the page in one language — are better rules than what they replaced, and neither
would have been found by reading the code.
