# 21 — calculated values

**Built.** What the code does now is in `CLAUDE.md`, `docs/configuring-forms.md` ("Numbers worked
out from the answers"), `docs/kits.md` and `docs/architecture.md`, with a runnable
`tests/_requests/11-calculated.http`; the last section here says what the building corrected.
This file was the design for the largest remaining entry of
[10](10-what-a-vendor-offers.md)'s comparison table — *calculated values*, `calculateValue` in
the platform it was measured against — written before any of it, because three decisions are the
whole of the work and each of them can be got wrong quietly.

The standard case is the one to keep in view: **a list of lines and a total**. Everything below
is judged by whether it answers that without becoming a language.

## Decision 1 — the value is stored, not shown

A total could be a thing a page draws (a running sum in a read-only box, recomputed in the
browser, never sent) or a member of the values document like any other answer.

**Stored.** The reason is who else reads a form: an owner receiving `form.confirmed` and reading
`GET …/data` wants the total *in the document*, and so does the printed record. Left to the
readers, every one of them recomputes it — and the day the rule changes, the ones that have not
been redeployed disagree with the ones that have. A number that three parties compute is three
numbers.

That settles what a calculation *is*: not a widget, not presentation, but a **rule about a value**
— which puts it in the definition, beside `min`, `max` and `decimals`.

## Decision 2 — the client computes it, the server checks it

Three ways to get a stored total:

| | What it costs |
|---|---|
| the server computes it on save | breaks the oldest invariant in this codebase: the stored document is **exactly the JSON text that passed validation**, handed back byte for byte. A server that fills members in makes the stored document a thing the client never sent |
| the server computes it on read | breaks the other half: a write never answers with the thing it wrote, and `GET …/data` serves what is stored rather than a thing assembled per request |
| **the client sends it, the server refuses a wrong one** | the client has to be able to add up — and the page is a client, so it computes it after every keystroke exactly as it evaluates a condition |

The third, then. A calculation is a rule about a value, and it is checked where the other two
rules that a schema cannot state are checked: in a gate, **the third one stricter than the
published contract**, for the same reason as the other two — no JSON Schema can say "this member
equals the sum of that member across that list", any more than it can say "this file id exists".
The derived schema *describes* the calculation (a `description`, exactly as `decimals` is
described) so the contract is honest about what it does not assert.

What a client that cannot add up does: sends nothing, and the member is owed at confirmation like
any `required` answer. A draft with no total is storable; a draft with a **wrong** total is not,
because that is a rule about a value and those hold in both contracts.

## Decision 3 — a closed vocabulary, and no arithmetic anybody writes

`calculated` on a `number` item: **three words and one modifier**, flat, in the shape a
condition already has — one object, one thing written in it, and a validator that says so.

```json
{"calculated": {"sum": ["amount"], "over": "lines"}}              a total of a list
{"calculated": {"sum": ["net", "vat"]}}                            a total of two answers beside it
{"calculated": {"product": ["quantity", "price"], "over": "lines"}}   the invoice: × per entry, then added
{"calculated": {"product": ["hours", "rate"]}}                     the same without a list
{"calculated": {"count": "lines"}}                                 how many entries
```

- **`sum`** names one member or several; **`product`** names at least two (a product of one is
  that one). Both take names and nothing else, so nothing nests and there is nothing to parse.
- **`over`** is what makes it an aggregate: with it, the named members are read in *every entry*
  of that list and the results added; without it they are answers beside the calculated one.
- **`count`** is the one aggregate that is not arithmetic, and the one a client cannot get wrong.

The uniformity is deliberate: `sum` is always a list of names, so nothing here is a union type,
and `{"sum": ["a", "b"], "over": "lines"}` reads as what it is — add `a` and `b` in each entry,
then add up the entries.

Deliberately absent: division, percentages, subtraction, rounding modes, a calculation that
depends on a condition, a calculation reading another form. Every one of those is the first step
of an expression language, and the model refuses one by name — it is the reason conditions are
data and the reason this is a vocabulary of four words rather than a parser.

**Precision is required.** A calculated item must declare `decimals`, because a sum of JSON
doubles compared exactly is a coin toss: `0.1 + 0.2` is not `0.3` in any of the three languages
this document will be read by. With `decimals` the comparison is the one the precision gate
already makes — round to that many places and compare exactly — which is a question a person can
check by hand.

## Scope: a sum reaches into a list, and a condition may not

A condition names an item **declared beside it** and nothing else; `form.condition.unknown-item`
refuses an entry that asks about the form around it and a form that asks about an entry. A sum
does the opposite: it names a list declared beside it and a member *inside* that list's entries.

That is not an exception, and the difference is worth stating because somebody will read the two
rules side by side. A condition asks about **an** answer — and "the country" inside a list of
three entries is three answers, so the question has no meaning. A sum asks about **all** of them
at once, which is exactly why it has one: an aggregate is well defined precisely because it does
not pick an entry.

Inside an entry, the same holds one level down: an item of an entry may sum a list *that entry*
declares, and may not reach out of its entry.

## What is refused, and where

At creation, where somebody can still fix it (`form.calculated.*`):

`calculated` on an item that is not a `number` needs no rule of its own: the member belongs to
`NumberField`, so the mapper refuses it at its own pointer with `mapping.unexpected_key` —
measured, not assumed.

| Code | What it means |
|---|---|
| `form.calculated.needs-decimals` | a calculated number with no `decimals`: see above |
| `form.calculated.empty` | nothing to calculate with: no `sum`, no `product`, no `count` |
| `form.calculated.ambiguous` | two of those written where one was meant, or an `over` beside a `count` |
| `form.calculated.unknown-item` | a name that is not declared where the calculation can see it |
| `form.calculated.not-a-list` | `over` or `count` naming something that is not a `collection` |
| `form.calculated.not-a-number` | a name in `sum` or `product` that is not a `number` item |
| `form.calculated.self-reference`, `form.calculated.cycle` | a calculation reading itself, or a ring of them — a page computing that would never settle, which is the argument conditions already make |

And on the way in, from the gate: **`form.value.miscalculated`**, at the member's own pointer,
carrying what was sent. One finding per wrong number, in every scope, like every other rule about
a value.

A calculated item may carry `askedWhen` — a total nobody was asked for is not owed and may not be
sent, which is the existing rule and needs nothing new. `required` beside `calculated` is fine and
ordinary: it says the total has to be there to finish.

## What the page does

A calculated control is **read-only and recomputed**: the calculation rides into the markup as
the data it is (`data-calculated`), and each kit adds up after every keystroke — the same
mechanism as a condition, in the same place, for the same reason (what a total is may be an answer
somebody is typing now). It is collected like any other value, because the document carries it.

Nobody types into it, so nothing is lost by drawing it as an `output`/readonly box. A refusal on
it is still shown where it belongs: `form.value.miscalculated` is what a client sees when it
computed a different number, and a page that computes the same number as the server never shows
it — which is the point.

## Steps, in order

1. Domain: a `Calculation` value object with its own battery; `calculated` on `NumberField`; the
   two validators (shape, and "does it make sense here", including the ring); the meta-schema
   grows the shape, as it did for a condition.
2. `DataSchemaDeriver`: describe the calculation on the item, publish nothing that lies.
3. `Infrastructure/Validation/CalculationsAgree`: the gate, in every scope, with a table battery
   like the other two gates have.
4. Page: the calculation in the markup, an adder per kit, a read-only control, and a browser
   battery per kit — including the case that matters, adding a line and watching the total move.
5. Docs: `configuring-forms.md` (its own section beside the conditions, the codes, the item
   table), `kits.md` (what both kits do with it), `architecture.md` (a third gate, and the order
   the page works in), `CLAUDE.md`, `README.md`, a runnable
   `tests/_requests/11-calculated.http` — replayed against a running service before its
   assertions were written — and this file.

## What the building corrected

- **The vocabulary got smaller before a line was written.** The first shape was four nested ones
  (`{"sum": {"list": …, "of": …}}`, with `of` taking a name, a list of names *or* a product), and
  designing it against the mapper showed the cost: `of` would have been a union type, which this
  codebase has met before (`Condition::$is` is `mixed` for exactly that reason) and which means a
  validator interpreting a member the type system cannot describe. Three flat words and one
  modifier say the same things, `sum` is always a list of names, and nothing is a union.
- **One refusal was not needed.** `calculated` on an item that is not a `number` is refused by the
  mapper at its own pointer (`mapping.unexpected_key`) — measured before writing the rule that
  would have duplicated it. The same measurement showed that `#[Constraints]` answers in
  `mapping.min_items` / `mapping.unique_items` rather than schema codes, and points at the repeat
  itself (`…/sum/1`) rather than at the list.
- **A rule fell out of the page, not out of the plan.** A condition testing a calculated number is
  a feedback loop: hiding an answer changes the total, the total changes the question, the question
  shows the answer again. A page would flap, and no cap on passes makes that honest. So
  `form.condition.on-a-calculated-number` refuses it, and the two mechanisms are ordered instead —
  conditions from what somebody typed, totals afterwards, one pass each. That is the only reason
  the page needs no fixed point.
- **The same early-return trap, caught twice.** `#total()` went inside the richer kit's `#ask()`,
  which returns early on a form with no conditions — so a form with a total and no conditions
  computed nothing. Fixing it turned up a latent bug from the wizard: `form:asked` was dispatched
  from inside `#ask()` too, so a wizard on a form with no conditions was never told to refresh.
  Both now hang off one named pass (`#evaluate()`), which is what the plain kit's module already
  had.
- **A chain inside one scope was a pass behind, and the owner found it.** `total` is worked out
  from `net`, which is worked out in the same scope; the page read the answers *once* and then
  wrote each total, so a total reading a total saw the previous keystroke's number — the page
  showed 44.33 and saved 46.61, and the server refused a number nobody had typed. Measured on the
  page rather than reasoned about: rounding was the first suspect and was checked off on 8991
  legal (quantity, price) pairs, where PHP and JavaScript agreed every time. The fix is a pass per
  level, bounded by the number of totals in the scope and terminating because rings are refused at
  creation — and it holds whichever order a document declares them in, which one pass never could.
- **And the refusal had no words.** `form.value.miscalculated` was not in `RefusalWords`, on the
  reasoning that a page which does the same arithmetic can never produce it — which was true of
  the design and false of the code. A person met the API's English sentence under a Polish form.
  Both catalogues have it now: a refusal a person can reach is a refusal the page has to word,
  and "cannot happen" is not a reason to leave one unworded.
- **Mutation testing found twenty escaped mutants and four of them were redundancy**: a depth cap
  the walked-list already made unreachable, two returns that guarded nothing, and a coalesce that
  read the same either way. The other sixteen were missing cases — including the two messages that
  tell "you misspelled it" from "you named the wrong kind of thing", which no test had ever read.

## What this deliberately will not be

- **No expression language.** Four words, and a fifth needs an argument as good as the ones above.
- **No server-side filling.** The two invariants it would break are older and more valuable than
  the convenience.
- **No conditional calculation.** `askedWhen` decides whether the *question* is asked; what it is
  worth is not a second condition.
- **No cross-form or cross-scope reach**, beyond the one a sum is made of.
