# 18 — conditions

**Built.** This file was written as the design for [10](10-what-a-vendor-offers.md)'s second entry —
*conditional logic* — before any of it existed, and the measurements below are the record of why it
took the shape it did. What the code does now is in `CLAUDE.md`, `docs/configuring-forms.md`
("Questions asked only sometimes"), `docs/kits.md` and `docs/architecture.md`; the last section
here says what the building corrected. The design opened from the entry's own sentence, because
the deciding was the work: *"a condition that changes the **rules** belongs to the definition (and must therefore be
derivable into the published schema, which is where JSON Schema's `if`/`then` earns its keep),
while one that only changes the **view** belongs to the presentation."*

Two claims in that sentence were measured before writing the rest of this file, because the whole
design rests on them.

## What was measured first

Validating a hand-written schema through ingot's own validator, and printing where the findings
land:

```
if/then + required          company, no nip    → /nip schema.required        ✔ placeable
                            company + bad nip  → /nip schema.pattern         ✔
else: {not: {required: …}}  no company + nip   → ''   schema.not             ✘ useless to a page
else: {properties: {nip: false}}
                            no company + nip   → ''   schema.properties      ✘ … but see below
```

**The first line is the whole feature working.** A conditional `required` reports under the member
it is about, because ingot already unpacks `required` per member and its walk reaches inside
`allOf` → `if/then`. A page can put the message beside the control with no change to anything.

The third line needed one more look. The raw opis error tree is:

```
allOf @ ''  →  else @ ''  →  properties @ '' args={"property":"nip"} subs=0
```

The member's name **is in the args**, exactly as it is for the two keywords ingot already unpacks
(`required` names its `missing`, `additionalProperties` names its `properties`). So pointing that
finding at `/nip` is the same eight lines in the same method, and it belongs in ingot — which is
also the only place it can go. `not` is hopeless by contrast: no args, no sub-errors, nothing to
point at. **That decides the spelling**: relevance is expressed as `properties: {x: false}` and
never as `not`.

## Five examples, measured

Every schema below was written by hand as the deriver would emit it and run through ingot's own
validator; the verdicts are what it answered, not what this file hopes.

**A. "Do you have a company?" → NIP.** `{"type":"text","name":"nip","required":true,
"pattern":"^[0-9]{10}$","askedWhen":{"item":"hasCompany","is":true}}`

```
strict   firma + NIP        → accepted        bez firmy          → accepted
         firma, bez NIP     → /nip required   bez firmy + NIP    → / properties  ← the ingot gap
         firma + zły NIP    → /nip pattern    nic                → accepted
draft    nic                → accepted        firma, bez NIP     → accepted
         NIP, nic o firmie  → / properties
```

`required: true` on a conditional item means *required whenever it is asked* — which is why the
two members compose instead of colliding.

**B. Country → PESEL or passport.** Two items, two conditions, mutually exclusive by construction.

```
pl + PESEL   → accepted        pl + passport         → /pesel required
de + passport → accepted       pl + both             → / properties
pl, nothing else → /pesel required
```

**C. `requiredWhen`: a low rating owes a reason.** No `else` branch — the item is always asked,
so an answer is always allowed, and only the obligation is conditional.

```
strict   1 + reason → accepted     1, no reason → /why required
         5, no reason → accepted   5 + reason   → accepted
draft    1, no reason → accepted   (nothing is owed while filling in)
```

**D. Inside a list, and this is the scope claim proven.**

```
dent                → accepted
other, no text      → /damages/0/description required
dent + text         → /damages/0 properties
second entry asks separately → /damages/1/description required
```

**E. `all`.** `askedWhen: {"all": [{"item":"country","is":"pl"}, {"item":"wantsInvoice","is":true}]}`

```
pl + invoice + NIP     → accepted        pl, no invoice, + NIP → / properties
pl + invoice, no NIP   → /nip required   de + invoice          → accepted
```

**One honest wrinkle**: `allOf` reports the first failing condition and not all of them, so a
hand-written client that breaks two at once is told about one, fixes it, and is told about the
next. A page cannot reach that state — it hides what is not asked and sends nothing for it — so
this only ever shows up in a client somebody wrote by hand, which is the audience that reads
codes anyway.

## The split

| A condition that… | belongs to | because |
|---|---|---|
| changes what a document must satisfy | the **definition** | the derived schema is the published contract, and this service may never be stricter than what it published |
| changes only what a person sees | the **presentation** | it is a way of looking, like a card or an accordion |

And the two are **not independent**: a hidden question that is nonetheless required is a form
nobody can finish. So rather than letting both documents state conditions and checking they agree,
only one states them:

**The definition owns conditions. The page's hiding is derived from them.** A presentation gains no
new member at all — what it already has (`card`, `accordion`, `options.open`) covers "fold this
away", which is the only *cosmetic* disclosure there is. One place per rule, and a page that cannot
contradict the contract because it is reading it.

## What a definition may say

Two members, on any item:

```json
{ "type": "text", "name": "nip", "pattern": "^[0-9]{10}$",
  "askedWhen": { "item": "hasCompany", "is": true } }

{ "type": "text", "name": "why", "maxLength": 500,
  "requiredWhen": { "item": "rating", "in": [1, 2] } }
```

- **`askedWhen`** — relevance. When it holds, the item is asked and its own rules apply as they do
  today. When it does not, the item **must be absent**, in *both* contracts, because an irrelevant
  answer is a rule about the value rather than an obligation to finish. That is what makes the
  page's hiding safe: a hidden control contributes nothing, and the server confirms it.
- **`requiredWhen`** — obligation, and only that: the item is always asked, and sometimes owed.
  Strict contract only, exactly like `required`.
- `required: true` beside `requiredWhen` is **refused** (`form.field.required-and-conditional`):
  two ways to say almost the same thing are two things that drift, which is the same reasoning that
  refuses `required` on a collection.

### The condition vocabulary

Closed, declarative, and derivable — never an expression. Plan 10 refuses code in a document for
three reasons that all still hold: a protected evaluator, rules implemented twice, and a contract
that cannot be published.

There are two levels here and they are deliberately not the same words. **A document is not a
schema**; it *derives* one. So an author writes plain words, the way the rest of the definition
vocabulary is plain (`required`, `min`, `options`), and the schema keywords stay inside the
derivation where nobody has to read them.

**One item, tested.** Six forms, three of them the negation of the other three:

| Written | Means | Derives to (inside `if`) |
|---|---|---|
| `{"item": "x", "is": <literal>}` | x was answered, and the answer is exactly that | `{"properties": {"x": {"const": …}}, "required": ["x"]}` |
| `{"item": "x", "isNot": <literal>}` | x was answered, and the answer is something else | `{"properties": {"x": {"not": {"const": …}}}, "required": ["x"]}` |
| `{"item": "x", "in": [<literal>, …]}` | x was answered, with one of those | `{"properties": {"x": {"enum": […]}}, "required": ["x"]}` |
| `{"item": "x", "notIn": [<literal>, …]}` | x was answered, with none of those | `{"properties": {"x": {"not": {"enum": […]}}}, "required": ["x"]}` |
| `{"item": "x", "answered": true}` | x has an answer, whatever it is | `{"required": ["x"]}` |
| `{"item": "x", "answered": false}` | x has no answer | `{"not": {"required": ["x"]}}` |

**Every test except `answered: false` requires the item to be answered**, and that is a decision
rather than a side effect: without it, "asked when the country is not Poland" would fire on an
empty form and the passport question would be there before anybody had said where they live.
Measured: with `isNot`, an empty document is accepted and asks for nothing.

**Several conditions, combined.** Each takes two or more:

| Written | Means | Derives to |
|---|---|---|
| `{"all": [<condition>, …]}` | every one of them holds | `{"allOf": […]}` |
| `{"any": [<condition>, …]}` | at least one holds | `{"anyOf": […]}` |
| `{"none": [<condition>, …]}` | not one of them holds | `{"not": {"anyOf": […]}}` |

An object is **either** an item test **or** one combinator, never both
(`form.condition.ambiguous`): one shape per object, so there is never a question about what a
document meant.

Negation costs nothing, and that was measured rather than assumed: a condition sits in `if`, `if`
is not an assertion, and **no finding ever comes from it** — every code a client sees comes from
`then` or `else`. So `isNot`, `notIn`, `answered: false` and `none` are as cheap as their positive
twins.

**`oneOf` is deliberately not in this list.** "Exactly one of these conditions holds" is almost
never what somebody describing a form means — they mean `any` — and it is JSON Schema's classic
footgun for exactly that reason. Every real "exactly one" is either mutually exclusive by
construction (`is: "pl"` beside `isNot: "pl"`) or writable as `any` over tests that cannot both
hold. A genuine exclusive-or is `{"any": [{"all": [A, {"none": [B]}]}, {"all": [B, {"none":
[A]}]}]}`, which is ugly on purpose: the need is rare and the shape deserves to look rare.

Combinators nest, and both the nesting and the number of conditions are capped, for the reason the
collection depth cap exists: so nothing absurd gets stored.

**Comparisons (`atLeast`, `atMost`) are deliberately not in the first version** — they derive just
as cleanly (`minimum`, `formatMinimum`) and can be added the day somebody asks, without moving
anything else. `in` covers the small ranges people actually write (`in: ["1", "2"]` is "a rating
below three").

### What the derived schema looks like

```json
{
  "type": "object",
  "properties": { "hasCompany": {"type": "boolean"}, "nip": {"type": "string", "pattern": "^[0-9]{10}$"} },
  "additionalProperties": false,
  "allOf": [{
    "if":   { "properties": {"hasCompany": {"const": true}}, "required": ["hasCompany"] },
    "then": { "required": ["nip"] },
    "else": { "properties": {"nip": false} }
  }]
}
```

Strict as above. In the **draft** contract the `then` goes — nothing is owed while somebody is
still filling a form in — and the `else` **stays**, because "this was not asked" is a rule about
the value. So the same split `required` and `max` already follow, applied to a condition.

## What it refuses, and where

Every one of these is a mistake somebody will make, and each is silent without a check:

| Code | When |
|---|---|
| `form.condition.unknown-item` | it names an item this scope does not declare |
| `form.condition.self-reference` | an item's own condition names itself |
| `form.condition.cycle` | a asked when b, b asked when a — a page whose evaluation never settles |
| `form.condition.not-comparable` | `is: "yes"` on a checkbox, `is: "ES"` on a select that offers no `ES`, `answered` on nothing |
| `form.condition.too-deep` | `all`/`any` nested past the cap |
| `form.field.required-and-conditional` | `required: true` beside `requiredWhen` |

`not-comparable` is the one that earns its place twice over: it is the typo that would otherwise
hide a question for ever, and nothing else would ever say so.

**Scope**: a condition names items **in its own scope**, full stop — the rule every other rule
here follows. Inside a collection that means the entry's own answers, which is also what makes the
page's evaluation local and the schema's `allOf` land inside `items`. Reaching out of an entry to a
top-level answer is a real want and a later question.

## What the page does

Both kits already receive the resolved tree and each item's rules; a condition rides along the same
way. Then, on every input:

1. evaluate each item's `askedWhen` against what is currently on the page;
2. hide what is not asked (and clear any message on it), show what is;
3. **collect nothing from a hidden item** — which is what makes the document match the contract
   rather than being refused by it;
4. draw the star from `requiredWhen` when it holds.

The evaluator is a closed vocabulary and about forty lines per kit. It **looks** like "rules
implemented twice" and is not: the condition is data, the server enforces it, and the page uses the
same data to decide what to draw — precisely what `required` already does today, where the page
draws a star and the server refuses.

## Steps, in the order they were taken

1. **ingot first** (`CLAUDE.md`'s rule): point a `properties` finding at the member its args name.
   Eight lines beside `missingMembers()`, plus three tests — the third being `dependentRequired`,
   which is also a leaf carrying `property` in its args and must be left alone. Pushed and landed
   before anything here.
2. Domain: `Condition` with its own battery; `askedWhen`/`requiredWhen` on the field classes;
   `ConditionShapeValidator` and `ConditionsMakeSenseValidator`; `DataSchemaDeriver` emitting
   `allOf` per mode.
3. Both item batteries for the types that carry a condition, and a values battery for the
   contracts — the one that proves the two gates still agree.
4. Page: the condition in the markup, an evaluator per kit, unasked answers dropped, and a browser
   battery per kit (14 cases, seven per kit) — the half no server test can prove.
5. `RefusalWords`: **nothing to add**, and that is the answer rather than an omission. The only
   new code a *request* can produce is `schema.properties` (an answer to an unasked question), and
   a page cannot produce it — it collects nothing from a question it is not asking. A code only a
   hand-written request can reach keeps the API's own message, which is the right one for whoever
   wrote that request.
6. Docs: `configuring-forms.md` (a section of its own, the vocabulary, the codes, the
   ship-checklist line), `kits.md`, `architecture.md`, `README.md`, `CLAUDE.md`, and this file.

## What the building corrected

- **A third reader appeared.** The design had two — the schema enforcing, the page drawing — and
  the record of a confirmed form is a third: it has no browser, and a question nobody was asked
  printed with a dash beside it reads as an answer somebody withheld. So `Condition::holds()`
  went into the domain, `FormRecords` asks it, and `PresentedNodes` asks it too, which also
  removed a flash of questions nobody is being asked on the way in. Two readings of one rule are
  worth having only while they cannot drift, so `ConditionsAgreeWithTheSchemaTest` puts 140
  (condition, document) pairs to both and insists they agree.
- **`multiselect` had to join the "only `answered`" family.** The comparability table quietly let
  a multiple choice be compared to one of its options, which is a condition that could never hold
  — `{"tags": ["urgent"]}` is not `"urgent"`. A mutant found it, which is exactly the kind of
  thing mutation testing is for: every test passed, and the rule was wrong.
- **`requiredWhen` on a collection is refused**, with `form.collection.required-not-allowed` and
  the same words as `required`: an empty list satisfies "the member is there" while answering
  nothing, and a condition does not change what the word would mean.
- **`data-unasked` is not `hidden`.** An item drawn with the `hidden` widget is one a client fills
  in and its answer travels; a question nobody is being asked is hidden *and* its answer is left
  out. Two facts, two attributes (`data-out-of-sight` for the first), or a page that stopped
  hiding one would reveal the other.
- **The page has to iterate.** A question may be asked on the strength of an answer to a question
  that is not being asked, so one pass over a scope is not enough; the evaluator repeats until
  nothing moves, which terminates because a ring is refused at creation. That refusal stopped
  being a nicety the moment the page was written.

Three sessions, with step 2 the bulk of it.

## What this deliberately will not be

- **No expressions.** See above; it is the one thing plan 10 refuses by name.
- **No conditional `min`/`max`/`pattern`** in the first version. Relevance and obligation cover the
  forms people actually describe; a conditional *value rule* multiplies the surface and can come
  later without moving any of this.
- **No cross-scope conditions**, for now.
- **No conditional widget.** Which control draws a question is a way of looking, and a document
  that wants two shapes for one answer is describing two questions.
- **No `else` branch in a document.** "Show B when not A" is two conditions, and two conditions
  read better than a branch.
