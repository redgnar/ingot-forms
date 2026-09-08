# Configuring a form

Everything somebody needs to describe a form to this service and get it drawn, filled in and
confirmed. It is written for the person who **writes the two documents** — what the form asks,
and how it is shown — rather than for the person maintaining the service; that half lives in
[architecture.md](architecture.md), and the generated endpoint reference in [api.md](api.md).

A form here is **one fillable document**: one definition, one set of values, one expiry date,
and optionally one description of how to draw it. There are no templates, no versions and no
submission collections — one form is one thing somebody fills in once.

Two documents describe it, and both are **immutable for the life of the form**:

| Document | Answers | Given |
|---|---|---|
| **definition** | what is asked, and what an answer must satisfy | required, at creation |
| **presentation** | how it is shown, and in what words | optional, at creation |

Immutable means what it says: there is no endpoint that changes either one. Changing what a form
asks — or how it looks — is **delete and create again**. A form that will never change its
questions has no reason for the description of them to drift, and a form somebody has already
answered is a form whose answers were given to the questions it had.

## Contents

- [The life of a form](#the-life-of-a-form)
- [Creating one](#creating-one)
- [The definition: what is asked](#the-definition-what-is-asked)
- [Questions asked only sometimes](#questions-asked-only-sometimes)
- [Numbers worked out from the answers](#numbers-worked-out-from-the-answers)
- [Files](#files)
- [The presentation: how it is shown](#the-presentation-how-it-is-shown)
- [One form on several pages](#one-form-on-several-pages)
- [Widget reference](#widget-reference)
- [Accessibility: what the reader controls, and what you can default](#accessibility-what-the-reader-controls-and-what-you-can-default)
- [Being told what happened](#being-told-what-happened)
- [The record of a confirmed form](#the-record-of-a-confirmed-form)
- [History](#history)
- [Talking to the API](#talking-to-the-api)
- [Saving without overwriting somebody](#saving-without-overwriting-somebody)
- [When something is refused](#when-something-is-refused)
- [A complete example](#a-complete-example)
- [Before you ship a form](#before-you-ship-a-form)

## The life of a form

```
                 PUT …/data (repeatable, lenient)      POST …/confirm (strict, once)
   created ─────────────────────────────────────► draft ──────────────────────────────► confirmed
   (empty, or a draft already)                      ▲   │                                (locked for good)
                                                    └───┘
                                              every save kept as a revision
```

- **Empty** — created, nothing answered yet. `GET …/data` answers `404`.
- **Draft** — `PUT …/data` stores what is there so far. Repeatable, and judged **leniently** —
  which means one thing exactly: **an obligation waits, a rule about the value does not.**
  `required`, `mustBeChecked`, a collection's `min` and a multiple choice's `min` are
  obligations, and a draft asks for none of them. Everything that says what a value *may be*
  holds while somebody is still filling the form in: the type, `enum`, `maxLength`, **`pattern`**,
  a number's `min`/`max` and `decimals`, a date's period, `uniqueItems`, a `max` on a list or a
  multiple choice, the closed set of member names, and the rule that an answer to a question
  nobody was asked is refused.
  **The consequence worth knowing before you write a definition**: a shape or a floor refuses a
  half-finished answer. `pattern: "^[0-9]{10}$"` on a tax number means "save for later" is
  refused after three digits (`schema.pattern`), and `min: 1` on a number refuses a `0` somebody
  is about to change. That is deliberate — a draft is a stored document, and it passes the same
  published schema, so putting an old version back or printing the record can never bring out
  something that was never allowed — but it is yours to design around: a rule that only makes
  sense of a finished answer can be left to the client, or to whoever reads the confirmed form.
- **Confirmed** — `POST …/confirm` judges what is **already stored** against the strict
  contract and locks the form. After that every write answers `409`, forever. There is no
  unlock.
- **Expired** — past `expireDate` every endpoint answers `410 Gone`, and
  `app:forms:purge-expired` deletes the row and the bytes for good.

Two things are worth knowing before you design anything around this:

- **A form may be born a draft.** Values you already know go in the creation request as `data`.
  They are not a third state: the form saves them through the same transition every later draft
  goes through, so a form is never created holding values it would refuse afterwards. Findings
  about them are rooted at `/data`.
- **A save that changes nothing is not a save.** Send what the form already holds — in any
  member order — and the answer is still `204`, but nothing is stored and no revision appears.
  That is what makes "put this version back" safe to press twice.
- **A save can be made conditional**, so two people filling one form in do not overwrite each
  other: `If-Match` with the number of the save you read
  ([saving without overwriting somebody](#saving-without-overwriting-somebody)). Nothing is
  forced to use it — a client that says nothing saves unconditionally.

## Creating one

```http
POST /api/manage/forms
Content-Type: application/json

{
  "expireDate": "2030-01-31T23:59:59+00:00",
  "definition":   { "items": [ … ] },
  "presentation": { "engine": "core-html", "items": [ … ] },
  "data":         { "email": "ada@example.com" },
  "webhooks":     { "confirm": "https://your-system.example/forms/confirmed" }
}
```

`201 Created`, a `Location` header, and a body carrying **only the id** — everything else in
that response would be a copy of what you just sent. `expireDate` is required and must be in
the future. `presentation`, `data` and `webhooks` are optional.

The id is a UUID and the form's only name. **The definition has no name of its own**: with no
templates and no versioning there is nothing for a second name to group or look up, so it would
only be a label free to drift.

## The definition: what is asked

Every item declares a `name` and may declare `required` (which only bites at confirmation).
Any item may also be asked only sometimes — `askedWhen` and `requiredWhen`, in
[Questions asked only sometimes](#questions-asked-only-sometimes). An item type exists here
because it brings rules of its own — never to tell a frontend which widget to draw.

| `type` | value on the wire | its own options |
|---|---|---|
| `text` | JSON string (non-empty when required) | `maxLength`, `pattern` |
| `select` | one of the declared options | `options` — at least one, no repeats |
| `multiselect` | JSON array of the declared options, each at most once | `options` — at least one, no repeats; `min`, `max` — how many ticks |
| `number` | JSON number | `min`, `max`, `decimals`, `calculated` |
| `date` | `YYYY-MM-DD`, a day that exists | `min`, `max` — calendar dates, `min` no later than `max` |
| `datetime` | RFC 3339 with an offset: `2026-03-01T14:30:00+01:00` | `min`, `max` — moments, `min` no later than `max` |
| `checkbox` | JSON boolean | `mustBeChecked` |
| `collection` | JSON array of objects, one per entry | `items` (a definition of its own, 1–1000), `min`, `max` |
| `file` | the description of an uploaded file: `{id, name, size, type}` | `accept` (media types, at least one, no repeats), `maxSize` — both required |
| anything else | whatever it came as | the plugin's own keys, kept in `extras` |

Seven of those say something worth spelling out:

- **`decimals` bounds precision.** `0` means whole numbers and is published as JSON Schema's
  `integer`. Above zero it is the one rule this API enforces without publishing it as a rule:
  the derived schema carries it as a `description`, and the server checks it exactly, in decimal.
  The reason is that JSON Schema's only word for this is `multipleOf`, defined as division
  yielding an integer — and `1.15 / 0.01` is `114.99999999999999`. Ajv and Python's `jsonschema`
  both refuse `1.15`, `0.07` and `0.29` on `multipleOf: 0.01`, so publishing it would hand every
  client a rule that rejects ordinary money. A value with too many places is refused at its own
  pointer with `form.value.decimals`. Without `decimals`, any number goes.
- **A `datetime` carries an offset, and that is what it is for.** A `date` is a square on a
  calendar and means the same thing to everybody; a time of day without an offset is a reading
  on somebody's wall, and two people answering one form would mean two different instants by it.
  So `2026-03-01T14:30:00+01:00` is the shape, `Z` counts as an offset, and `2026-03-01T14:30:00`
  is refused. Both are said in the published contract, because they have to be said twice:
  `format: date-time` names the shape, and a `pattern` beside it insists on the offset — every
  implementation reads `date-time` a little differently and the common ones let a string with no
  offset through. A period is judged by the **instants**, never by the text, so
  `2026-01-01T00:30:00+01:00` is *before* `2026-01-01T00:00:00Z` however the two sort as
  strings. A bound that is not a moment is `form.field.not-a-moment` where it was written.
- **A date range is published, not just enforced.** `formatMinimum` / `formatMaximum` are the
  keywords ajv-formats uses, and ingot implements them, because standard JSON Schema cannot
  bound a string in time — so the range is checked against the same document a client
  validates against, not somewhere behind it.
- **A `collection` is a question asked repeatedly**, and it is what makes a definition a
  tree: its `items` are a definition of their own, so an entry is a document answering them,
  and every rule of every item inside holds one scope down and points there
  (`/lines/2/quantity`). Names are unique *within* a scope, so an entry may answer `sku` even
  where the form around it also asks for `sku`. It counts rather than requires: `min: 1` says
  "at least one entry" and `required` on a collection is refused, because an empty list would
  satisfy it while answering nothing. `max` holds in both contracts, like every rule about a
  value; `min` waits for confirmation, like `required` itself, and a collection owing entries
  is required of the values document, since an absent member has none of them. A collection may
  hold a collection, and both kits draw that: a list inside the form of an entry, with its own
  add, its own remove and its own counts.
- **A `multiselect` is several of one list, as one value.** Its answer is
  `["urgent", "legal"]` — a set, so no option twice, and that is the first of the two rules
  that make it a type rather than a way of drawing a `select`. The second is counting, and it
  works exactly as a collection's does and for the same reason: `min: 1` says "tick at least
  one", `required` is refused (an empty list would satisfy it while answering nothing), `max`
  holds in both contracts because it is a rule about the value, and `min` waits for
  confirmation because it is an obligation to finish. An item owing ticks is required of the
  values document, since an absent member has none of them. A `min` above the number of
  options is refused (`form.multiselect.impossible-minimum`) — nobody could finish that form;
  a `max` above it is not, because "as many as you like" is a reasonable thing to write. The
  whole contract is published: `type: array`, `items: {enum: […]}`, `uniqueItems`, `minItems`,
  `maxItems`. **Before this existed** the only way to ask it was a `collection` holding a
  `select`, which answered the question and lied about the shape — a list of one-member
  documents, entries that could be reordered and duplicated, and a page that drew a table with
  an *Add* button where somebody wanted three ticks.
- **A signature is a `file`.** There is no signature item type, because a signature brings no
  rules of its own: what it produces is the same `{id, name, size, type}` as any other upload,
  and "these bytes were drawn rather than attached" is not something this service could check
  even if it wanted to. So it is a **widget** (`signature`, the richer kit only) on a `file`
  item that accepts `image/png` — and the page keeps the ordinary picker beside the pad, because
  nobody can draw with a keyboard. A form that already holds a signature **shows it** rather than
  naming the file it is stored as, with the pad behind a *sign again* button.
- **A `file` holds a description, not bytes.** The bytes are uploaded first
  (`POST /api/forms/{id}/files`) and the answer to that is exactly what the values document
  may hold — id, name, size and media type, all four measured by the server. That is what lets
  the item's own two rules be *published*: `maxSize` becomes a maximum on `size`, `accept`
  becomes an enum of `type`. Both are required, because a file item without them would promise
  "any bytes, any size", which no deployment can honour and no client can check. Several files
  is a `collection` holding a `file` — the counting was built once. **The trap worth knowing**:
  `type` is what the server sniffed from the bytes, not what the browser claimed, so a
  definition asking for `.docx` has to list what fileinfo actually reports for it — the upload
  response is where an author sees this immediately. Everything else about files is in
  [Files](#files).
- **`mustBeChecked` is not `required`.** For a box, `false` is an answer, so `required` means
  "decide"; a consent means "agree", and that is published as `const: true` — **in the strict
  contract only**. Having to agree is something finishing the form requires, like `required`
  itself, so a draft still holds a consent nobody has given yet; otherwise "save for later"
  would refuse the very state it exists for.

### Setting it up

Every item type at once, each with its own options, and a values document the form accepts.
`presentation` is optional — leave it out and this is a form only an API client fills in:

```json
{
  "items": [
    { "type": "text",     "name": "customer", "required": true, "maxLength": 60, "pattern": "^[A-Z].*" },
    { "type": "text",     "name": "notes",    "maxLength": 400 },
    { "type": "select",   "name": "country",  "required": true, "options": ["pl", "de"] },
    { "type": "number",   "name": "seats",    "min": 1, "max": 10, "decimals": 0 },
    { "type": "number",   "name": "budget",   "min": 0, "decimals": 2 },
    { "type": "date",     "name": "delivery", "min": "2026-01-01", "max": "2030-12-31" },
    { "type": "checkbox", "name": "terms",    "required": true, "mustBeChecked": true },
    { "type": "checkbox", "name": "news" },
    { "type": "file",     "name": "invoice",  "accept": ["application/pdf"], "maxSize": 1048576 },
    { "type": "collection", "name": "lines", "min": 1, "max": 20, "items": [
      { "type": "text",   "name": "sku",      "required": true, "pattern": "^[A-Z]-[0-9]+$" },
      { "type": "number", "name": "quantity", "required": true, "min": 1, "decimals": 0 }
    ]}
  ]
}
```

An answer to it — the shape `PUT /api/forms/{id}/data` takes, and the shape
`GET /api/forms/{id}/data` gives back:

```json
{
  "customer": "Ada Lovelace",
  "notes": "",
  "country": "pl",
  "seats": 4,
  "budget": 1250.50,
  "delivery": "2026-03-01",
  "terms": true,
  "news": false,
  "lines": [
    { "sku": "A-1", "quantity": 2 },
    { "sku": "B-7", "quantity": 1 }
  ]
}
```

Three things to read off that pair:

- **the names are the whole contract** — no ids, no order, no wrapper. A member the definition
  does not declare is refused (`schema.additionalProperties`), and so is a value of the wrong
  JSON type: `"seats": "4"` is a string, and a string is not a number.
- **`decimals: 2` means at most two places**, which is why `1250.50` is fine and `1250.505` is
  not (`form.value.decimals`). It is described in the schema rather than asserted there — see
  the note above — so a client that only runs the schema will not catch it locally.
  `decimals: 0` publishes the item as an integer, which validators do agree on.
- **a list is an array of objects**, one per entry, each answering the collection's own items.
  `min: 1` bites at confirmation; `max: 20` bites always.

Ask the form what it will take, rather than guessing: `GET /api/forms/{id}/schema` is the
derived JSON Schema, and `?mode=draft` is the lenient one used while filling in.

## Questions asked only sometimes

Two members, on any item: **`askedWhen`** says the question is only put when the condition
holds, and **`requiredWhen`** says the answer is only owed when it holds. Either may sit on any
item (with one exception, below), both may sit on the same item, and every item in a
`collection`'s `items` may carry them too.

```json
{"items": [
  {"type": "checkbox", "name": "hasCompany"},
  {"type": "text", "name": "nip", "required": true, "maxLength": 10,
   "askedWhen": {"item": "hasCompany", "is": true}},
  {"type": "text", "name": "note", "maxLength": 200,
   "requiredWhen": {"item": "hasCompany", "is": true}}
]}
```

A form asking that will not carry a `nip` from anybody who has not ticked the box — not
"ignore it", **refuse it** — and will not close without one from anybody who has.

### The vocabulary

A condition is **data, never an expression**. One object is either a test of one item or one
combinator of other conditions, never both. Six tests:

| Written | Holds when |
|---|---|
| `{"item": "x", "is": "pl"}` | `x` was answered, and its answer is exactly `pl` |
| `{"item": "x", "isNot": "pl"}` | `x` was answered, and its answer is anything else |
| `{"item": "x", "in": ["pl", "de"]}` | `x` was answered, and its answer is one of these |
| `{"item": "x", "notIn": ["pl"]}` | `x` was answered, and its answer is none of these |
| `{"item": "x", "answered": true}` | `x` was answered at all |
| `{"item": "x", "answered": false}` | `x` was not answered |

**Every test but `answered: false` needs the item answered.** That is a decision and not a side
effect: without it, "asked when the country is not Poland" would hold on an empty form, and the
question after it would be standing there before anybody had said where they live.

Three combinators, each holding one to ten conditions:

| Written | Holds when |
|---|---|
| `{"all": [a, b]}` | every one of them holds |
| `{"any": [a, b]}` | at least one holds |
| `{"none": [a, b]}` | not one of them holds |

They nest, three deep at most: `{"all": [{"any": […]}, {"none": […]}]}`. There is deliberately
no `oneOf` (exactly one) — it is the one combination nobody asks for in a form, and it reads as
`any` to everybody who has not checked.

`is` and `isNot` compare against **one value of the item's own kind**: `true`/`false` for a
checkbox, one of the declared options for a `select`, a number for a `number`, text for
anything else. A number is a number however it was written, so `4` and `4.0` are one value.
Items whose answer is not a single value — a `multiselect`, a `file`, a `collection` — can only
be asked `answered`, because comparing a list to one of its members is a condition that could
never hold. An item of a **plugin type** may be compared to anything: this service does not know
what such an item holds, so it is in no position to call the comparison impossible.

### What it does to the published contract

Both members are derived into the JSON Schema the form publishes, so a client validating
locally sees the same rules the server keeps:

```json
{"allOf": [
  {"if": {"required": ["hasCompany"], "properties": {"hasCompany": {"const": true}}},
   "then": {"required": ["nip"]},
   "else": {"properties": {"nip": false}}}
]}
```

- the `else` is the half that matters: **an answer to a question nobody was asked is refused**,
  at that answer's own pointer, with `schema.properties`;
- **every answer that is owed is named in one refusal.** An obligation of the definition's own
  beside one a condition brought about, or two conditional ones, come back together, each at its
  own pointer — so a page marks every control at once instead of sending somebody round again for
  the second. (One thing still arrives in two rounds: inside a list *entry*, an answer the entry
  always owes is reported before a conditional one in the same entry.);
- the `then` is the obligation, so it is in the **strict** contract only — a draft is saved
  without it, exactly as `required` is;
- an item with an `askedWhen` is never in the flat `required` list, because "owed" is a thing
  it can only be when it is asked at all.

Ask the form rather than working it out: `GET /api/forms/{id}/schema` and `?mode=draft`.

### Scope, and what is refused

**A condition names an item declared beside it.** In a form, that is the form's own items; in a
`collection`, the entry's — so each entry decides for itself, and an entry cannot ask about the
form around it (or the other way round). Everything else is refused when the form is created,
where somebody can still fix it:

| Code | Pointer | What it means |
|---|---|---|
| `form.condition.empty` | the condition | Nothing to test with: no predicate and no combinator |
| `form.condition.ambiguous` | the condition | Two things written where one was meant — two predicates, or an `item` beside a combinator |
| `form.condition.no-item` | the condition | A test of nobody's answer: a predicate with no `item` |
| `form.condition.unknown-item` | `…/item` | No item of that name is declared in this scope |
| `form.condition.self-reference` | `…/item` | An item asked for on the strength of its own answer |
| `form.condition.cycle` | the item | A ring: two or more items each waiting on the next |
| `form.condition.not-comparable` | the predicate | A value the item could never hold — a word for a checkbox, an option it does not offer, one value for a multiple choice |
| `form.condition.too-deep` | the condition | Combinators nested more than three deep |
| `schema.additionalProperties` | the member | A member of a condition nobody has heard of — the shape is closed and published, so a typo points at itself (`equals` where `is` was meant) |
| `form.field.required-and-conditional` | `…/required` | `required` and `requiredWhen` on one item: the first already always owes the answer |
| `form.collection.required-not-allowed` | `…/requiredWhen` | A list asks for entries with `min`; `required` and `requiredWhen` would both be satisfied by an empty one |

So: **a list may carry `askedWhen`** (the whole list is not asked for), and never `requiredWhen`.

### Conditions and a list

A `collection` meets a condition in two places, and they are different questions.

**The whole list, asked only sometimes** — `askedWhen` on the collection itself:

```json
{"type": "collection", "name": "pozycje", "min": 1, "askedWhen": {"item": "typ", "is": "firma"},
 "items": [{"type": "text", "name": "co", "required": true}]}
```

Nobody who is not a company is asked for the list at all: the page draws neither it nor its
*add* button, its entries are not collected, and the `min: 1` it owes is only owed when it is
asked (an `askedWhen` takes an item out of the flat `required`, so the obligation moves into the
condition with it).

**One question inside an entry** — `askedWhen` on an item of `items`, naming another item **of
the same entry**:

```json
{"type": "collection", "name": "pozycje", "items": [
  {"type": "select", "name": "rodzaj", "options": ["wgniecenie", "inne"], "required": true},
  {"type": "text", "name": "opis", "required": true,
   "askedWhen": {"item": "rodzaj", "is": "inne"}}
]}
```

Each entry decides for itself: answer *inne* in the second row and only the second row is asked
to describe it. That is what "a condition names an item declared beside it" means — the same
rule that lets two entries hold different answers.

**Two things are refused**, both at creation:

- `requiredWhen` on a `collection` (`presentation`-side sibling: `required` on one) — an empty
  list satisfies "the member is there" while answering nothing, so a list asks for entries with
  `min` and a condition does not change what the word would mean;
- a condition reaching **across scopes** — an entry testing an item of the form around it, or
  the form testing an item inside an entry (`form.condition.unknown-item`, since the name is not
  declared in that scope). Which entry would the form be asking about?

And there is no conditional `min`: a whole list can be asked for or not, and that is the whole
of it.

#### How it behaves, measured

The entry's own contract carries the condition, which is what makes every row judge itself —
`GET …/schema` on the form above answers, under `properties.pozycje.items`:

```json
{"allOf": [{
  "if":   {"required": ["rodzaj"], "properties": {"rodzaj": {"const": "inne"}}},
  "then": {"required": ["opis"]},
  "else": {"properties": {"opis": false}}
}]}
```

so a client validating locally gets the same per-row answers, and `opis` is **not** in the
entry's flat `required`. The draft contract keeps the `else` and drops the `then`, exactly as it
does at the top level.

| What is sent | What comes back |
|---|---|
| `[{"rodzaj":"inne","opis":"rysa"},{"rodzaj":"wgniecenie"}]` | `204` — one row was asked to describe it, the other was not |
| `[{"rodzaj":"inne","opis":"rysa"},{"rodzaj":"wgniecenie","opis":"cokolwiek"}]` | `422` `/pozycje/1/opis` `schema.properties` — the answer nobody asked that row for is **refused**, and the finding names the row |
| the whole list, when the list itself is not asked for | `422` `/firmowe` `schema.properties` |
| confirming a company with none of it answered | `422` naming **all three** at once: `/nip`, `/pozycje/0/opis`, `/firmowe` |

**On the page**, each entry is evaluated against its own answers, so a row added a moment ago
starts with its conditional questions unasked and gains them the moment that row's answer asks
for one; values are collected scope by scope, so adding or removing rows renumbers nothing. A
list inside a list is the same rule one level further down. And a question a row was not asked
is left out of that row entirely — of the document that is saved, and of the printed record.

**One thing still arrives in two rounds**: inside a single row, what the row *always* owes is
reported before a conditional obligation beside it (`/pozycje/0/rodzaj` comes back, and
`/pozycje/0/opis` only after it is answered). Between rows, and between a row and the form
around it, everything owed comes back together.

Runnable, with assertions: [`tests/_requests/10-conditions.http`](../tests/_requests/10-conditions.http).

### What a page does with it

Both kits carry the condition into the markup and ask it again after every keystroke, because
what decides a question may be an answer somebody is typing now:

- a question the condition does not ask is **not on the page**, and its answer is **not
  collected** — which is what makes the document match the contract rather than be refused by
  it;
- a refusal standing beside a question goes when the question does;
- the star appears when `requiredWhen` comes about, with `aria-required` beside it, so it
  reaches somebody who cannot see a star;
- an entry decides for itself, including one added a moment ago;
- the server does the same thing before the first paint, so a page never flashes questions
  nobody is being asked, and the printed record of a confirmed form leaves them out entirely.

### What this is not

- **Not an expression language.** There is no arithmetic, no comparison between two items, no
  string matching. A rule the vocabulary cannot say is a rule this service will not keep.
- **Not a way to change what an item is.** A condition decides whether a question is asked and
  whether its answer is owed. It cannot change an item's type, its options, or its limits.
- **Not conditional counting.** `min` on a list or a multiple choice is not conditional; a
  whole list can be asked for or not, and that is the whole of it.
- **Not cross-scope.** An entry's condition cannot reach out of its entry.

## Numbers worked out from the answers

A `number` item may say what it is worked out from instead of being typed in. **Three words and
one modifier**, and nothing that has to be parsed:

```json
{"type": "number", "name": "amount",  "decimals": 2, "calculated": {"product": ["quantity", "price"]}}
{"type": "number", "name": "net",     "decimals": 2, "calculated": {"sum": ["amount"], "over": "lines"}}
{"type": "number", "name": "total",   "decimals": 2, "calculated": {"sum": ["net", "vat"]}}
{"type": "number", "name": "howMany", "decimals": 0, "calculated": {"count": "lines"}}
```

- **`sum`** names one answer or several; **`product`** names at least two. Both take names and
  nothing else.
- **`over`** makes it an aggregate: the named answers are read in *every entry* of that list and
  the results added. Without it they are answers standing beside the calculated one.
- **`count`** is how many entries a list holds.

Those four lines are a working invoice: a line works out its own amount, the net adds the lines
up, the total adds the vat to it, and the count says how many lines there are. Chains are fine —
a calculated number may read another — and a ring is refused.

**Deliberately absent**: division, percentages, subtraction, rounding modes, a calculation that
depends on a condition, and anything reading another form. Every one of them is the first step of
an expression language, and this model refuses one by name — the same reason conditions are data.

### The value is stored, and the client works it out

This is the part to design around. A calculated number is **a member of the values document like
any other answer**, and the client sends it; the server refuses a wrong one with
`form.value.miscalculated` at that member's own pointer.

Why not have the server fill it in? Because the document this service stores is exactly the JSON
that passed validation, handed back byte for byte — a server that added members would make the
stored document something the client never sent. And why not work it out when the form is read?
Because a `GET` serves what is stored rather than something assembled per request. So the number
is in the document, every reader sees the same one, and nobody recomputes it: an owner reading
`form.confirmed`, the printed record and the page all agree because there is one number.

What a client that cannot add up sends: nothing. A calculated member is owed exactly like any
other — `required` at confirmation, absent in a draft — so a half-filled form is storable and a
*wrong* number never is.

**A missing answer counts as nothing.** A list somebody is still filling in has entries with no
price in them, and the total of what is there so far is the number the page shows and the number
the server expects. The alternative — declining to judge until every entry is complete — would
make a wrong total storable for as long as anything was missing.

**Precision is required.** A calculated number must declare `decimals`: the comparison is decimal
to that many places, because comparing sums of binary floats exactly is a coin toss (`0.1 + 0.2`
is not `0.3`). It is the same rounding the `decimals` gate already does, and a question a person
can check by hand.

### Where a calculation may look

A calculation names answers **declared beside it**, and with `over` it names a list beside it and
answers **inside that list's entries**. That is the one place a rule in this service reaches into
a scope below, and it is worth saying beside the conditions' opposite rule: a condition asks about
*an* answer — "the amount" in a list of three entries is three answers, so the question has no
meaning — while an aggregate asks about *all* of them at once, which is exactly why it has one.
Neither may reach *out* of its own scope: an item inside an entry may total a list that entry
declares and may not read the form around it.

**A condition may not test a calculated number** (`form.condition.on-a-calculated-number`), and
the reason is a loop rather than a taste: hiding an answer takes it out of the document, which
changes the total, which changes the question, which shows the answer again. Ordering the two —
conditions from what somebody typed, totals afterwards — is what makes a page settle in one pass.

### What is refused, and where

`calculated` on an item that is not a `number` is refused by the mapper at its own pointer
(`mapping.unexpected_key`), because the member belongs to the number item. The rest, at creation:

| Code | Pointer | What it means |
|---|---|---|
| `form.calculated.needs-decimals` | `…/decimals` | a calculated number with no precision to compare at |
| `form.calculated.empty` | the calculation | no `sum`, no `product`, no `count` — an `over` on its own says nothing |
| `form.calculated.ambiguous` | the calculation, or `…/over` | two of the three at once, or an `over` beside a `count` |
| `form.calculated.unknown-item` | `…/sum`, `…/product`, `…/over` | a name that is not declared where the calculation can see it |
| `form.calculated.not-a-list` | `…/over`, `…/count` | working across something that is not a `collection` |
| `form.calculated.not-a-number` | `…/sum`, `…/product` | adding up or multiplying something that is not a number |
| `form.calculated.self-reference` | `…/sum`, `…/product` | a number worked out from itself |
| `form.calculated.cycle` | the item | a ring of them: a page working that out would never settle |
| `mapping.min_items`, `mapping.unique_items` | the member, or the repeat | an empty `sum`, a `product` of one, or the same name twice |

And on the way in, from the gate: **`form.value.miscalculated`**, in every scope, at the member's
own pointer, carrying what was sent and saying what the answers come to.

### On the page

A calculated control is drawn **read-only** and worked out again after every keystroke — the same
mechanism as a condition and in the same place. Chains settle whichever way they are written:
entries before the scope that reads them, and within one scope a pass per level, so a total worked
out from another total is right at the same keystroke rather than at the next one. It is collected and sent like any other answer,
which is why a person never meets `form.value.miscalculated`: the page does the same arithmetic
as the server.

Runnable, with assertions: [`tests/_requests/11-calculated.http`](../tests/_requests/11-calculated.http).

## Files

**What an author does**: declare a `file` item with `accept` and `maxSize`, both required. What
a *client* does with it is three steps, and the middle one is the whole mechanism:

1. `POST /api/forms/{id}/files` with one `multipart/form-data` part named `file`. The answer is
   `{id, name, size, type}` — four facts the **server** measured.
2. Put that object into the values, verbatim, where the item's name goes. It is the value; there
   are no bytes in the values document.
3. Save as usual. From that moment the file is attached, and `GET …/files/{fileId}` serves it.

### Setting it up

The item, and then the three calls. A `file` item declares both of its own rules and cannot
leave either out:

```json
{ "type": "file", "name": "invoice", "accept": ["application/pdf"], "maxSize": 1048576 }
```

```http
POST /api/forms/{id}/files
Content-Type: multipart/form-data; boundary=…
(one part named "file")

201 Created
{ "id": "01a02f74-...", "name": "invoice.pdf", "size": 8124, "type": "application/pdf" }
```

That answer **is** the value. Put it back verbatim and save:

```json
{ "invoice": { "id": "01a02f74-...", "name": "invoice.pdf", "size": 8124, "type": "application/pdf" } }
```

Several files is a `collection` holding a `file`, and then the values hold an array of those
same four-member objects:

```json
{ "type": "collection", "name": "attachments", "max": 5, "items": [
  { "type": "file", "name": "scan", "accept": ["image/png", "image/jpeg"], "maxSize": 2097152 }
]}
```

```json
{ "attachments": [ { "scan": { "id": "…", "name": "front.png", "size": 41233, "type": "image/png" } } ] }
```

Everything else here follows from one decision: **the form's own documents are the only index
of its files.**

A form can hold files, and the design turns on that decision: **the form's own documents are
the only index of them**. There is no column about files anywhere in `forms` and no `files`
table — those documents are what passed validation and what is served byte for byte, so a
second record of the same fact could only ever be a copy that drifts.

Everything follows from that:

```
POST /api/forms/{id}/files        bytes in, description out   (no transaction: no column changes)
PUT  /api/forms/{id}/data         the description, echoed → the form now names the file
GET  /api/forms/{id}/files/{f}    only what some save of this form named
DELETE …/files/{f}                only what none of them did
```

**Temporary, then attached.** A file is *temporary* while no stored document names it and
*attached* the moment one does. Nothing moves when that happens — the documents are the record —
and a temporary file has no download route at all, so an upload nobody saved is unreachable by
construction. Not everything uploaded gets saved, so the rest is collected in two places:

1. **the page**, at once: `DELETE …/files/{fileId}` when somebody removes or replaces a file
   before saving. It refuses anything any save of this form names (`409`), so it can never take
   away a file some document — including one somebody could put back — still depends on.
2. **`app:files:purge-temporary`**, once a day: per form, whatever **no save has ever named**
   and which has sat untouched longer than `FILES_TEMPORARY_DAYS`. It lists the store *before* it
   reads a row, so a form whose files are all recent costs no database work; it takes the row
   lock, so it cannot slip between a save's reference check and that save's commit; and it
   reports what it took per species — whole files, half-written ones, and directories whose
   form is already gone. **Those numbers are supposed to sit near zero**: one that keeps
   growing is the only warning that the page has stopped throwing files away.

**A save takes nothing away, and nothing asks about the current values.** Every question about
files — what may be downloaded, what may be thrown away, what may be collected — is asked of
what this form has **ever** named: its current document and every earlier save of it
([History](#history)). So replacing a file leaves the old one fetchable and undeletable, because
the save that named it is still there to be read and put back; and the only thing collected
before the form expires is an upload **no save ever named**. `FormFiles` is where that question
lives, and the definition being immutable is what makes it cheap: every revision is read with
the same one.

`app:forms:purge-expired` remains the end of everything, and both deletions go **the row
first, the bytes second**. The other way round can leave a live form naming files that are
gone — the one state this design does not tolerate — while a directory whose row is already
gone is provably garbage and gets collected by the command above, once its bytes have sat out
`FILES_TEMPORARY_DAYS` like anything else that command looks at. That is what closes the
worry a file item was postponed over: a purge no longer has to succeed in two places at once.

**Both kits draw a file** — `file` in `core-html`, `file` and `dropzone` in `bootstrap` (a
place to drop one, with the progress of the upload drawn while it happens). The shared
convention grows by exactly one thing: a control may carry a JSON payload
(`data-type="json"`), so the hidden control beside the picker holds the description while the
picker itself is only how somebody chooses bytes. The chip that says which file is held is
rendered by the server and filled in by the kit's own script — a kit never writes markup in
JavaScript. The size is checked in the browser before anything is sent; the **kind** of bytes
is checked against what the server sniffed *after* the upload, and a file the item does not
want is taken back at once, because nothing names it yet.

## The presentation: how it is shown

A definition says what is asked; **how a form is shown is a second document**, given at
creation beside it and referencing the same item names. It is optional — a client that draws
forms its own way needs none — and, like the definition, immutable: a form is described once,
and changing that description means deleting the form and creating a new one, exactly as
changing what it asks does.

```json
{
  "engine": "core-html",
  "defaultLocale": "en",
  "items": [
    { "widget": "fieldset", "label": "contact.personal", "items": [
      { "name": "email", "widget": "text", "label": "contact.email", "hint": "contact.email.hint" }
    ]},
    { "name": "terms", "widget": "checkbox", "label": "contact.terms" },
    { "widget": "save", "label": "contact.save", "options": { "appearance": "link" } },
    { "widget": "confirm", "label": "contact.send" }
  ],
  "translations": {
    "en": { "contact.personal": "Personal details", "contact.email": "E-mail", "contact.email.hint": "We only use it to reply", "contact.terms": "I accept the terms" }
  }
}
```

**One recursive shape, no fixed levels.** An item either presents a value (it has a `name` the
definition declares, and holds nothing), or holds other items (a container), or stands on its
own (a decoration). Sections were the first draft: a fixed level of grouping is a guess that
ends up either too shallow or in the way.

**A list is the one item that is both.** An item naming a `collection` holds the form for *one
entry*, and `columns` says which of that entry's items the list itself previews — the heading of
a column being the label that form already gives the item, so the same words live in one place.
Say nothing and every item of the entry is previewed.

```json
{"name": "lines", "widget": "table", "label": "t.lines",
 "columns": ["sku", "quantity"],
 "items": [{"name": "sku", "widget": "text", "label": "t.sku"},
           {"name": "quantity", "widget": "number", "label": "t.qty"}]}
```

A list inside a list is the same shape again, as deep as the definition goes — the definition,
the presentation and the values, side by side:

```json
{ "type": "collection", "name": "lines", "min": 1, "max": 10, "items": [
  { "type": "text", "name": "sku", "required": true },
  { "type": "collection", "name": "parts", "max": 5, "items": [
    { "type": "text", "name": "code", "required": true }
  ]}
]}
```

```json
{ "name": "lines", "widget": "table", "label": "t.lines", "columns": ["sku"],
  "options": { "open": true },
  "items": [
    { "name": "sku", "widget": "text", "label": "t.sku" },
    { "name": "parts", "widget": "table", "label": "t.parts", "columns": ["code"], "items": [
      { "name": "code", "widget": "text", "label": "t.code" }
    ]}
  ]}
```

```json
{ "lines": [ { "sku": "A-1", "parts": [ { "code": "X1" }, { "code": "X2" } ] } ] }
```

`columns` may only name items **that entry** has, and a column can only be something that reads
as text — a list is not a column. `options.open` unfolds every entry's form; leave it out and a
person opens the one they want.

Everything a presentation is judged by is judged **per scope**, then: a name exists here, is
shown once here, and everything here is shown. So an entry may present `sku` even where the
form around it also presents `sku`, and a trigger inside an entry is refused — saving and
confirming are what a *form* does. A list inside an entry is drawn exactly like a list outside
one, as deep as the definition goes; the only thing a list may not be is a **column**, because a
column previews a value as text.

Both kits draw a list as a `table`: the answers so far as rows, each with the form it is
answered in folded underneath, one blank form kept aside for adding another, `min`/`max` carried
into the page so it can grey out its own buttons — the server still being what decides — and
"one more entry" living in the table's own footer, because it is the list's doing and not the
form's.

**A choice can be shown in words.** The definition settles that a value must be one of
`["pl","de","fr"]`; the presentation settles that `pl` reads *Polska*, with `choices` mapping
each option to a translation code:

```json
{"name": "country", "widget": "select", "label": "t.country",
 "choices": {"pl": "t.pl", "de": "t.de", "fr": "t.fr"}}
```

Two questions, and only the second one has a language — which is why the definition still holds
no display text. Those codes are text like any other, so the default catalogue is held to them,
and **every** option must be worded once any of them is: a list that reads half in words and
half in codes is exactly the drift a presentation exists to prevent. Naming a value the item does
not offer is `presentation.choice.unknown`, leaving one out is `presentation.choice.missing`, and
wording the options of something that has none is `presentation.choice.not-allowed`.

**A `hint` goes under the control, in both kits.** Worth knowing before you word one: a
sentence saying "draw in the frame below" ends up under the frame, pointing at itself. Say what
somebody needs to know rather than where to look — the hint is tied to the control it belongs to
(`aria-describedby`), so it is read out *with* the question whatever order it sits in.

**An empty control may say what would go in it.** `placeholder` sits beside `label` and `hint`
— not among the `options` — because it is text a person reads, so it is a translation code like
the other two and the default catalogue is held to it. It reaches `text`, `textarea` and
`number` as the attribute; a `select` has no such attribute, so it words the empty option
instead. Anything that holds no answer (a heading, a group, a trigger) is refused
(`presentation.placeholder.not-allowed`). It is never the label moved inside the box: a control
whose only label is its placeholder has no label the moment somebody types.

**Text is codes, never sentences.** What a code reads like, and in which language, is resolved
from a catalogue — the one carried in the document, or the client's own. The server never
resolves a locale and never reads `Accept-Language`: it serves the document whole, and picking
a language is the client's job, exactly as picking a widget is.

**The engine comes first** because a widget vocabulary is not universal. A kit is an object
implementing `PresentationEngine` and is the authority on what it can draw, so adding one is
adding a class. Two ship here:

- **`core-html`** — plain controls and nothing else: `text`/`textarea`/`hidden`,
  `select`/`radio`, `number`, `date`, `checkbox`/`switch`, nesting with `fieldset`, decorating
  with `heading`/`paragraph`. No stylesheet of anybody else's, no package, one hand-written
  module. The kit that works anywhere.
- **`bootstrap`** — Bootstrap 5, with the controls a styled kit can afford: `radio-buttons` (a
  choice as toggles), `autocomplete` (a choice somebody searches, which the plain kit has no
  answer for at all), `range` and `stepper` (a number moved rather than typed), grouping with
  `card`, `accordion` or `row`, and `alert`/`divider` between groups. Behaviour is Stimulus and
  icons are UX Icons, delivered by AssetMapper — no build step, no package manager. Every item is labelled the same
  way, above its control: a floating label can only float over a text box or a select, so any
  form with a choice group or a slider would end up labelled two ways at once. It is also the
  kit that can be **dressed** (below).

### Skins and the starting colours

A document may also say **which way round the colours start**, with a top-level
`"theme": "light" | "dark"`. It is a preference and not a setting: a reader who has chosen is
answered first, their machine second (`prefers-color-scheme`), and this last — so a document
that prefers dark shows dark to somebody who has never said and whose machine does not ask for
light, and never overrides either of them.

**A skin is how a form looks, and never what it may say.** The `bootstrap` kit offers four —
`default`, `material` (Bootswatch Materia), `flatly` and `lux` — and a document picks one with a
top-level `"skin"`, judged at creation by the same authority that judges its widgets: a name the
kit does not have is `presentation.skin.unknown` at `/skin`, and naming one for a kit that has
none (`core-html` has none, deliberately) is `presentation.skin.unsupported`. A document that
names nothing gets whatever this deployment dresses forms in (`FORMS_SKIN`, default `default`) —
two knobs with two jobs, and the document wins. The rule that keeps a skin a skin is testable and
tested: **the same form under two skins renders byte-identical markup**, differing only in which
stylesheet the page loads. One that needed a class, an element or a control of its own would have
stopped being a way of looking and become a second kit, and would have to be one. All four are
light themes on purpose — dark belongs to whoever is reading, and that half is
[the reader's](#accessibility-what-the-reader-controls-and-what-you-can-default), not the
document's — though a document may say which way the colours *start*.

### Setting it up

One word at the top of the presentation, beside `engine`:

```json
{
  "engine": "bootstrap",
  "skin": "material",
  "defaultLocale": "en",
  "items": [
    { "name": "email", "widget": "text", "label": "t.email" },
    { "widget": "confirm", "label": "t.send" }
  ],
  "translations": { "en": { "t.email": "E-mail", "t.send": "Send it" } }
}
```

| What you write | What the page wears |
|---|---|
| `"skin": "material"` | Materia, whatever the deployment prefers |
| nothing | whatever `FORMS_SKIN` says, and `default` when it says nothing |
| `"skin": "chrome-yellow"` | nothing — the form is refused at creation, `presentation.skin.unknown` at `/skin` |
| `"skin"` with `"engine": "core-html"` | nothing — refused, `presentation.skin.unsupported` |

To re-dress every form that names no skin, set `FORMS_SKIN=flatly` in the deployment's
environment. Documents that name one keep it: the document wins, always.

The plain controls are deliberately the same names in both; everything the richer kit adds is a
way of asking the other has no markup for. So a document written for one is *refused* by the
other rather than half-drawn, which is what naming the engine at the top of the document buys.
An engine this application does not know is accepted with its widgets unchecked — the bargain a
plugin item type gets, and for the same reason.

**What a form does is an item too.** Four widgets say it: `save` and `confirm` write, `reset`
goes back to what the form holds, and `history` opens what it held before. Each is placed wherever
the document wants it, labelled by a code like everything else, and drawn as a button or — with
`options.appearance: link` — as a link. Those names are not a kit's to invent: they say what a
form does, so a kit declares how it draws them, not whether they exist. **At least one `confirm`
is required**, because where the trigger goes is a design decision and leaving it out is not one:
the page would be unfinishable. The other three are opt-in, and that is the whole of the opting:
a document that does not ask for `history` has no panel, and one that does decides where it
sits. Nothing is added at the bottom of the page by the renderer.

**What the server enforces**, in every scope: the form is shown whole — every item the
definition declares appears, exactly once, and a value a client fills in rather than a person is
drawn `hidden`, which is a decision written down instead of an omission; an item that presents a
value holds nothing, unless it names a collection, which must hold the form for one entry and
may preview only items that entry has; a widget is one the engine draws for that item, or one it
can nest with, or one that stands alone; and a carried catalogue names a default locale that is
complete. Other locales may lag behind — that is how translating goes — and codes nobody uses
are fine. Findings carry `presentation.*` codes and pointers into the document as sent
(`/items/0/items/1/name`).

**What it deliberately is not**: no stylesheet of your own (a skin is a name out of a closed
list, never CSS in a document — that would be an injection surface and an unbounded support
burden), no conditional visibility **of its own** — a question asked only sometimes is the
definition's, because it changes what an answer must satisfy
([conditions](#questions-asked-only-sometimes)) — and no way to change any of it afterwards, because the description of a fixed
thing has no reason to drift.

## One form on several pages

A long form is easier to answer in parts, and that is a way of *looking* — so it is the
presentation's, and the definition does not move. Two container widgets, in both kits:

```json
{"widget": "wizard", "items": [
  {"widget": "step", "label": "t.who",  "items": [{"name": "email"}, {"name": "company"}]},
  {"widget": "step", "label": "t.what", "items": [{"name": "damage"}]},
  {"widget": "step", "label": "t.send", "items": [{"widget": "confirm", "label": "t.send"}]}
]}
```

A `wizard` shows one `step` at a time and draws its own marks, its own *back* and *next*, and a
line saying where somebody is. A step holds whatever any container holds — questions, groups,
lists, text, and the form's own triggers where you put them: the last page above is a "review
and send" page, which is a page with no questions and every reason to exist.

**Six things about it are decisions, not details.**

- **Every page is in the markup, and a save sends the whole form.** A step has no contract of
  its own: whatever page somebody is looking at, `PUT …/data` carries every answer on every
  page. That is what makes a wizard presentation rather than a second way of validating.
- **Nothing is gated.** *Next* always moves, and a mark can be pressed to jump. A page that
  refused to let somebody on would be enforcing an obligation the server itself only asks about
  at confirmation — and the kits promise the opposite everywhere else: a ceiling is held before
  it is met, a floor never is.
- **A page a condition emptied is stepped over.** If every question on a page is unasked
  ([conditions](#questions-asked-only-sometimes)), *next* passes it by and its mark is not
  drawn — and it comes back the moment an answer asks for it again. A page holding anything
  else visible is never skipped.
- **A refusal brings its page forward.** Confirm from the last page with an answer owed on the
  first, and the first page is what you are looking at, with the message under the control and
  the caret on it. A message on a page nobody is drawing is no message at all.
- **Where the wizard sits is yours.** A heading above it, the form's triggers inside the last
  step or below the whole thing — whatever the document says. Two wizards side by side are
  allowed: each steps its own pages.
- **Which page somebody is on is not stored.** Not on the server and not in the browser: a
  wizard opens on the first page with something to answer, and the only thing that moves it by
  itself is a refusal.

What is refused at creation:

| Code | Pointer | What it means |
|---|---|---|
| `presentation.step.outside-a-wizard` | `…/widget` | a `step` that is not directly inside a `wizard` — nothing would ever step it |
| `presentation.wizard.no-steps` | `…/items` | a `wizard` with no `step` in it |
| `presentation.wizard.holds-more-than-steps` | `…/items/N/widget` | something beside the pages. A heading for the whole wizard goes *before* it, where it is always visible |
| `presentation.wizard.nested` | `…/widget` | a wizard inside a wizard: which one *next* belongs to has no answer |
| `presentation.wizard.in-an-entry` | `…/widget` | a wizard inside a list entry, for the reason a trigger cannot sit there |

**Deliberately not there**: no validation per page, no branching ("which page comes next" as a
rule would be a second condition language over the one the definition already has), and no step
in the printed record — a labelled step reads as a section there, exactly like a card.

## Widget reference

The tables below are the index; [kits.md](kits.md) is the reference — every control of both
engines, what it draws, what the definition contributes to it, what it can be given, and links
into Bootstrap's own documentation.

Every presented item names a `widget`. Which ones exist depends on the engine the document was
written for, and asking for one the engine does not draw for that kind of item is refused at
creation (`presentation.widget.mismatch`). Leave `widget` out and the item gets the natural one
for its type — the first in each row below.

**Controls, by what the definition asks for:**

| Item `type` | `core-html` | `bootstrap` |
|---|---|---|
| `text` | `text`, `textarea`, `hidden` | `text`, `textarea`, `hidden` |
| `select` | `select`, `radio` | `select`, `radio`, `radio-buttons`, `autocomplete` |
| `multiselect` | `checkboxes`, `multi-select` | `checkboxes`, `checkbox-buttons`, `autocomplete` |
| `number` | `number` | `number`, `range`, `stepper` |
| `date` | `date` | `date` |
| `datetime` | `datetime` | `datetime` |
| `checkbox` | `checkbox`, `switch` | `checkbox`, `switch` |
| `collection` | `table` | `table` |
| `file` | `file` | `file`, `dropzone`, `signature` |
| a plugin type | — | — |

**Everything else an item can be:**

| Kind | `core-html` | `bootstrap` | Holds items? |
|---|---|---|---|
| grouping | `fieldset` | `card`, `accordion`, `row` | yes |
| paging | `wizard` + `step` | the same two | yes |
| saying something | `heading`, `paragraph` | `heading`, `paragraph`, `alert`, `divider` | no |
| about the page itself | `comfort`, `language` | the same two | no |
| doing something | `save`, `confirm`, `reset`, `history` | the same four | no |

**Where a thing goes is always yours.** There is no "top / bottom / off" option anywhere,
because a widget placed in the document is already that, and more: the panel of earlier versions
is the `history` widget, so it sits wherever you put it and does not exist at all in a document
that does not ask for it. The same holds for `save`, `reset`, the reader's switches and the
language switch. The one thing placement cannot do is take the reader's switches away — a
document that places no `comfort` still gets them, at the top.

**Options a widget understands** (`options` on the presented item):

| Option | On | Every value it takes |
|---|---|---|
| `rows` | `textarea` | any whole number ≥ 1; **4** when omitted |
| `appearance` | any action (`save`, `confirm`, `reset`, `history`) | `"link"` — the only value there is; omit it for a button |
| `choices` | `language` | a map of locale → translation code, one per catalogue the document carries |
| `open` | `accordion`, `table` | `true` or `false`; **false** when omitted |
| `width` | any item that is a direct child of a `row` | `1`–`12`, or `"auto"` (as wide as its own content); omit it and the item shares what is left |
| `align` | `row` | `"start"`, `"center"`, `"end"`, `"between"`, `"around"` — the five Bootstrap packs columns with; omit it for `start` |
| `tone` | `alert` | `"primary"`, `"secondary"`, `"success"`, `"danger"`, `"warning"`, `"info"`, `"light"`, `"dark"` — Bootstrap's eight; **`info`** when omitted, and a word that is not one of them draws an alert with no colour at all |
| `columns` | `radio`, `checkboxes` | `true` or `false`; **false** when omitted |
| `height` | `signature` | how tall the pad is, in pixels; **160** when omitted |
| `rows` | `multi-select` | how many options are visible at once; **as many as there are, up to 6** when omitted |

And the four members that are not options, with their values:

| Member | Where | Every value it takes |
|---|---|---|
| `skin` | top of the document | `"default"`, `"material"`, `"flatly"`, `"lux"` for `bootstrap`; `core-html` takes none |
| `theme` | top of the document | `"light"`, `"dark"`; omit it and the reader's machine decides |
| `contrast` | top of the document | `"normal"`, `"high"`; omit it and the reader's machine decides |
| `text` | top of the document | `"normal"`, `"large"`; omit it for normal |

That is the whole list: `options` is **read by the kit, never forwarded to Bootstrap**, so
anything else a document puts there is carried and ignored.
[kits.md](kits.md#what-options-can-say-and-what-it-cannot) sets each one against what the
Bootstrap component it belongs to can do, which is the honest way to see what is and is not
available.

Two rules about the vocabulary that save time later:

- **A widget is a way of *asking*, never a restyling.** The richer kit has `autocomplete`
  because searching a long list is a different act from scrolling one; it does not have a
  "floating label", because that is the same question with the text moved. If what you want is
  a different *look*, that is a [skin](#skins-and-the-starting-colours).
- **A document is written for one engine.** The plain controls carry the same names in both
  kits, but a document naming `bootstrap` widgets is refused by `core-html` rather than
  half-drawn — which is exactly what naming the engine at the top buys you.

## Accessibility: what the reader controls, and what you can default

Three things about a page belong to the person reading it. A document may say how one of them
*starts*; none of them is a document's to decide.

### The defaults

| Switch | The document may start it with | Its machine says | Otherwise |
|---|---|---|---|
| dark colours | `"theme": "dark"` | `prefers-color-scheme: dark` | light |
| high contrast | `"contrast": "high"` | `prefers-contrast: more` | off |
| larger text | `"text": "large"` | nothing — no machine setting says this | off |

**The order is always the same, and the reader is always first:** what they chose on this page
before → what their machine asks for → what the document prefers → off. A stored choice is kept
in that browser and nowhere else, so "off" chosen by somebody whose machine asks for contrast
stays off on the next page — turning a switch off is as much a decision as turning it on.

Which is why a document can only ever **add**. There is no way to write `"contrast": "off"`:
`normal` is the word for "nothing to add", and it changes nothing for a reader whose machine
asks for more. Turning somebody's accessibility need down is not a document's to do; starting a
page in high contrast, for a form whose readers will want it, is.

### Setting it up

Two places in the document, and both are optional. This is the whole of it:

```json
{
  "engine": "bootstrap",
  "skin": "flatly",
  "theme": "dark",
  "contrast": "high",
  "text": "large",
  "defaultLocale": "en",
  "items": [
    { "widget": "row", "options": { "align": "end" }, "items": [
      { "widget": "language",
        "choices": { "en": "t.english", "pl": "t.polish" },
        "options": { "width": "auto" } },
      { "widget": "comfort", "options": { "width": "auto" } }
    ]},

    { "name": "email", "widget": "text", "label": "t.email", "placeholder": "t.email.blank" },
    { "widget": "confirm", "label": "t.send" }
  ],
  "translations": {
    "en": { "t.english": "English", "t.polish": "Polski",
            "t.email": "E-mail", "t.email.blank": "ada@example.com", "t.send": "Send it" },
    "pl": { "t.english": "English", "t.polish": "Polski",
            "t.email": "E-mail", "t.email.blank": "ada@example.com", "t.send": "Wyślij" }
  }
}
```

- **`"theme": "dark"`, `"contrast": "high"`, `"text": "large"`** — how the page starts for a
  reader who has never chosen. Each is optional and each only ever adds: leave one out and the
  machine decides alone, write it and the machine still wins where it says more.
- **`{"widget": "comfort"}`** — where the switches are drawn. **Leave it out and they are drawn
  at the top of the page anyway**: placing it moves them, it does not create them, and nothing
  removes them.
- **`{"widget": "language"}`** — one link per catalogue in `translations`, each named in its own
  catalogue (which is why `t.polish` reads *Polski* in both). Two catalogues here, so two
  entries; with one catalogue it draws nothing at all, so it is safe to place before you know
  how many languages the form will end up carrying.
- **the `row` around them** — how they end up side by side at the right edge: `align: "end"`
  packs the columns to the right, and `width: "auto"` makes each as wide as its own content.
  Drop the row and you get two right-aligned lines instead, one under the other.

The same two widgets work in `core-html`, minus the row (that kit groups with `fieldset` only,
so they stack).

Both kits draw all three switches folded away behind one summary until somebody wants them — the
richer kit as toggle buttons behind an icon, the plain kit as checkboxes — and both remember the
choice **in that browser only**. Nothing is sent to the server: this service records who filled a
form in and knows nothing else about anybody — no profile, no account, nowhere a preference could
live — and a reading preference is a fact about a screen and a pair of eyes rather than about a
form or the person answering it.

This matters when you pick a skin: **high contrast wins over it.** It is not one of the skins
but an overlay on top of whichever one you chose, because an accessibility preference outranks
an aesthetic one — a document must not be able to spend somebody else's contrast on looking
nice. The same goes for dark: the reader's dark palette is painted by the application, so a
skin cannot leave somebody reading grey on grey.

### What every page does without being asked

None of this is a setting, a default or a widget — it is what the kits draw, always, in both of
them:

- a question is announced with its label, its hint, and whether an answer is owed
  (`aria-required`; the star beside the label is marked as decoration, because read out it is
  punctuation in the middle of a question);
- a group of choices is a `radiogroup` named by its question, so the options are never read out
  without it;
- a refused answer says so (`aria-invalid`), its message is tied to the control
  (`aria-describedby`), **and the caret moves there** — or to the button that adds an entry,
  when it is a list that owes one;
- a refusal inside a folded entry unfolds every form on the way to it and marks the rows it is
  inside, so the table still says "look here" once it is folded back up;
- an upload's progress is a number to be read as well as a bar to be seen;
- a new entry takes the caret into the form it just added — but only when a person asked for it,
  since a document being put back onto the page asked for nothing.

[kits.md](kits.md#what-both-kits-do-without-being-asked) says the same from the kit's side.

## Being told what happened

A form can report itself, so your system learns that somebody filled it in without asking.
Two events, both optional and independent, given at creation and immutable afterwards:

```json
"webhooks": {
  "created": "https://your-system.example/forms/created",
  "save":    "https://your-system.example/forms/saved",
  "confirm": "https://your-system.example/forms/confirmed",
  "deleted": "https://your-system.example/forms/deleted"
}
```

Name only `confirm` and you hear when a form is finished; name only `save` and you hear about
every accepted draft; name none — the default — and nothing is ever sent.

`created` is for a receiver that is **not** whoever created the form. You get the id in the
response to `POST /api/manage/forms`, so being told about your own call teaches you nothing — but
the endpoint is yours to name, and a system that mirrors these forms would otherwise meet one for
the first time as a `form.saved` for an id it has never seen. A form born a draft (`data` in the
creation request) reports both, and a delivery run takes the creation first.

`deleted` is worth a word, because half of it you already know. When *you* call
`DELETE /api/manage/forms/{id}` the notification tells you nothing new (`"reason": "requested"`),
and that is why creating a form is reported to nobody either. The other half is the one you
cannot see: `expire_date` passes, the form answers `410`, and `app:forms:purge-expired` removes
it — nobody asked, so `"reason": "expired"` is the only way to learn that a form you were waiting
on has stopped existing.

**What arrives carries no answers.** A `POST` with this body and nothing else:

```json
{
  "event": "form.saved",
  "form": "0192f1c4-…",
  "occurredAt": "2026-03-01T14:30:00+00:00",
  "revision": 4,
  "actor": "u-317"
}
```

`event` is `form.created`, `form.saved`, `form.confirmed` or `form.deleted`. `revision` is which save it was, and
is absent on a confirmation — confirming stores no values, so it is no revision. `actor` is who
did it and is absent on a form that records nobody. `reason` appears on a deletion only, and says
`requested` or `expired`. **The values are not in it on purpose**: read them with
`GET /api/forms/{id}/data`, or read that exact save with `GET /api/forms/{id}/history/{seq}`. One
document, one place — and it means a notification arriving out of order tells you nothing wrong,
because you read the current state anyway.

**Check the signature before you act on it.** Four headers come with the body:

| Header | What to do with it |
|---|---|
| `X-Forms-Event` | route it without parsing the body |
| `X-Forms-Delivery` | the same id across every retry — if you have acted on it, do nothing |
| `X-Forms-Timestamp` | how old this is; refuse anything older than you are willing to accept |
| `X-Forms-Signature` | `sha256=` + HMAC-SHA256 of `<timestamp>.<body>` with the deployment's secret |

```
expected = "sha256=" + hmac_sha256(timestamp + "." + raw_body, FORMS_WEBHOOK_SECRET)
```

Compare it with a constant-time comparison, against the **raw** body rather than one you
re-encoded. A deployment with no secret configured cannot sign, and there a form naming an
endpoint is refused when you create it (`409`, `webhooks-not-signable`) — rather than accepted
and never delivered.

**Answer `2xx` and answer quickly.** Anything else — and anything unreachable — is a refusal, and
the notification comes back with a longer wait each time, doubling from two seconds to an hour,
twelve refusals before this service gives up on it and leaves it where the deployment can see it.
A `4xx` is retried like the rest, because a receiver in the middle of a deploy answers `404` for a
minute. So a slow receiver should answer first and work afterwards.

**Checking what was sent.** Three questions, three places, one fact in each:

| You want to know | Read |
|---|---|
| was this save reported, and when | `notifiedAt` on that save — `GET /api/manage/forms/{id}/history` |
| was the confirmation reported | `confirmNotifiedAt` — `GET /api/manage/forms/{id}` |
| what is stuck | `GET /api/manage/forms/{id}/deliveries` |

A deletion is the exception and cannot be otherwise: there is no form left to read, so its
notification is visible only while it is owed — and only in the deployment's own log, since every
address above needs a form that still exists. Whoever runs the service sees it; the receiver's
own log is the other half.

The first two are stamps on the thing they are about, so they answer "were you told?" without a
second lookup. The third is the work list — what has not been delivered yet and what could not
be:

```json
{ "deliveries": [
  { "delivery": "01a0…", "event": "form.confirmed", "revision": null,
    "occurredAt": "2026-03-01T14:31:00+00:00", "target": "https://…/confirmed",
    "actor": "u-317", "state": "owed", "attempts": 0,
    "nextAttemptAt": "2026-03-01T14:31:00+00:00", "lastRefusal": null }
] }
```

`state` is `owed` (nothing tried yet, or refused and waiting for `nextAttemptAt`) or `abandoned`
(refused `attempts` times and never tried again, with `lastRefusal` saying what your endpoint
answered). A delivered one is **not** here — it stops being work the moment somebody has been
told, and its fact moves to the stamp. So an empty list means either a form that reports nowhere
or one with nothing outstanding. `delivery` is the id that arrived in `X-Forms-Delivery`, so an
entry here and a line in your own log are the same event.

Read-only, deliberately — there is no way to retry or cancel one. What is owed will be tried by
the next run, and a receiver that was broken and is now fixed is the deployment's business
(`app:webhooks:deliver`), not the form's.

**Refusals when you name one:** `/webhooks/save` or `/webhooks/confirm` with
`form.webhook.not_a_url` (it must be an absolute `http`/`https` URL),
`form.webhook.too_long` (2000 characters), `form.webhook.empty` (say nothing rather than `""`),
and `request.unexpected_key` for a third event nobody has. Changing where a form reports itself
means deleting it and creating a new one, like everything else about a form.

## The record of a confirmed form

A confirmed form is closed for good, which is exactly when somebody wants to file it:
`GET /api/manage/forms/{id}/pdf` is that document. Every question the definition declares, the
answer it was given, and the facts a record is filed by — the form's id, when it was created and
confirmed, and the author and confirmer when the form records anybody.

It is **not a page turned into a PDF**, and that is deliberate. A page carries triggers, the
reader's own switches and whichever skin the document chose, so an archival copy would look
different because a form was dressed differently; and rendering one would need a browser at
runtime, which is a demand on every deployment for the sake of one endpoint. The record has a
plain layout of its own and looks the same always.

**It needs no presentation.** A page cannot be drawn without a document saying how, but a record
is of what was asked and what came back — the definition says both. Without a presentation the
labels are the item names in declaration order; with one, it decides the order, the labels and
how each option reads (`Sprzęt (hardware)` — the words the document gave the option, with the
value that was actually sent beside them, because that is what a reader is asking about).

A container **keeps its words and loses its shape**. A card, an accordion and a row are three
ways of looking, so a record draws none of them — but a container with a label carries a sentence
its author wrote about the questions inside it ("When and where"), and that becomes a heading with
those questions under it. A container with no label is stepped through: there is nothing to say
about it.

Answers read back as text: a tick is the page's own *yes* / *no*, a moment keeps its offset
(`2026-03-01 14:30 (UTC+01:00)`), and a list is one block per entry, each with its own questions.

**An image is drawn into the record**, whatever widget asked for it — a signature is an image, and
a record naming the file has described the answer rather than shown it. PNG, JPEG and GIF up to
4 MB; anything else, anything larger, and anything on a deployment without PHP's `gd` extension is
named instead (`signature.png — 8.3 kB, image/png`). Either way the bytes stay where they were,
behind `GET /api/forms/{id}/files/{fileId}`. A question nobody answered says *not answered*, which is not the same as
one answered with nothing.

Nothing is stored: the document is generated on request and is the same every time, because a
confirmed form cannot change. Keep the bytes if you need a frozen artifact.

## History

Every accepted save is kept. A draft save writes the current values onto the row *and* appends a
revision, both from the same event (`DraftSaved`) — so a form's history is not a second record
of anything: it is what the aggregate already reports, persisted instead of dropped. The table is
append-only, `(form_id, seq)` is the whole key, and `seq` is allocated under the row lock the save
already holds.

**A save that stores what is already stored is not a save.** The aggregate compares the incoming
document with the one it holds — as documents, so the order the members arrive in does not
matter, while the order of a list's entries does — and records nothing when they say the same
thing. `PUT …/data` still answers `204`; there is simply no second identical moment to go back
to, and no claim that the form changed at a time when nothing about it did. That is also what
makes putting a version back safe to press twice, and putting back the version somebody is
already on a no-op rather than a new revision.

| Method & path | Answers |
|---|---|
| `GET /api/forms/{id}/history` | `{"revisions": [{"seq", "savedAt", "confirmed"}]}`, newest first. Empty for a form nobody filled in. |
| `GET /api/forms/{id}/history/{seq}` | That save's values, byte for byte, exactly as `GET …/data` serves the current ones. |

`confirmed` is derived and never stored: confirming writes no values, so it is no revision of its
own — the last one is simply what got locked.

**A history has an end.** A deployment says how many saves one form keeps
(`FORMS_HISTORY_LIMIT`, 100 by default; `0` keeps them all), and when a save pushes a form past
it, that form's oldest save leaves in the same statement. Nothing about `seq` changes — it is
allocated once and never reused, so a number that has fallen off the end answers `404` rather
than naming a different save. Worth knowing when a form holds files: a file that only an evicted
save named is no longer a file this form names, so it becomes temporary again and the collector
takes it. That is the same rule as everywhere — a document nobody can restore is a document whose
files stopped mattering — and it is why the limit is a deployment's decision rather than a
document's.

### Setting it up

Nothing to configure in the document: every accepted save is kept, up to the deployment's limit.
What a *document* asks for is the panel — one widget, placed wherever it belongs on the page:

```json
{ "widget": "history", "label": "t.history" }
```

Leave it out and the form has no panel; a client reads the same two endpoints and shows what it
likes. Beside it, `{"widget": "reset", "label": "t.reset"}` is the way back to what the form
actually holds, for somebody who typed over it and changed their mind.

The round trip, as a client makes it — three calls, no special endpoint anywhere:

```http
GET /api/forms/{id}/history
200 { "revisions": [ { "seq": 3, "savedAt": "2026-08-22T14:31:07+00:00", "confirmed": false },
                     { "seq": 2, "savedAt": "2026-08-22T14:12:55+00:00", "confirmed": false },
                     { "seq": 1, "savedAt": "2026-08-22T13:58:01+00:00", "confirmed": false } ] }

GET /api/forms/{id}/history/1
200 { "customer": "Ada Lovelace", "seats": 2 }

PUT /api/forms/{id}/data
{ "customer": "Ada Lovelace", "seats": 2 }
204
```

That third call is the restore: an ordinary draft save of a document the client happened to
read. It meets the same three gates as any other save, and it becomes revision 4 — history is
append-only, so putting something back is a change like any other rather than a rewind. Send it
while the form already holds exactly that, and nothing is recorded at all.

Putting **one answer** back needs no endpoint either: read the revision, take the member you
want, merge it into what the form holds now, and save the result.

**Restoring is not an operation.** There is no `POST …/restore`, and that is deliberate: a client
reads a revision and sends it back through `PUT …/data`, where it meets the same three gates as
any other draft. An old document is not more trustworthy for having been accepted once — the
files it names may be gone — so it is judged again, and refused with findings that name the
member. The restore is recorded as a *new* revision: history is append-only, so putting something
back is a change like any other rather than a rewind.

**Putting one answer back is the client's business too.** Reading a revision hands over a whole
document; picking members out of it and merging them into what the form holds now is what a
client does before it sends the result. Nothing on the server needs to know.

**On the pages, history is two things a document asks for.** `history` draws a panel listing the
moments this form was saved at — moments and nothing else, because a value outside the form it
belongs to says nothing. Each one offers **View** and **Restore**:

- **View** is a link to `/forms/{id}/versions/{seq}`: the same page, drawn from that save's
  document and read-only. That is what makes it cheap and complete at once — every control, every
  list and every attached file is drawn by the code that already knows how, so nothing is
  assembled in the browser and nothing can be edited. The two ways out are at the top of it:
  put this version back, or go back to the current one.
- **Restore** is an ordinary `PUT …/data` with that document, from the panel or from the version
  page, after which the server draws the form again — every control on it has just changed.

`reset` is the same "draw it again" with nothing sent: the way back to what the form actually
holds, for somebody who typed over it and changed their mind. A save refreshes the panel, because
a save makes a new moment and a list that does not show it is lying.

History answers **who** as well, and answers it on one side only. Every accepted save records the
identity a gateway asserted, and it is served by `GET /api/manage/forms/{id}/history` — the
management side. The list a *filler* reads carries `when` and nothing about who, so one person who
was let through to a form learns nothing about who else filled it in.

Nothing about it is ever **drawn on a page**, in either kit, and nothing about it changes how a form
is described: who answers is not an item and not a widget. The one thing a creation request says
about it is `identity`, which is `recorded` unless you write `anonymous` — and `anonymous` means
nobody is stored *even when the deployment asserted somebody*: not whoever filled the form in, not
whoever pressed the button that locked it, and **not whoever created it**. The mode is your own
configuration, so asking for a form that records nobody is asking for that about your own system
too — which costs nothing, since it knows perfectly well that it created the form. See [Who may do what](architecture.md#who-may-do-what) for where the identity
comes from and what a deployment has to configure for it to arrive at all.

## Talking to the API

All request and response bodies are `application/json`, and every error is an RFC 9457
`application/problem+json` document — see [When something is refused](#when-something-is-refused)
for the shape and the codes. Bodies are **closed**: a member no DTO declares is
`request.unexpected_key` rather than something quietly ignored.

**A pointer names the thing that is wrong**, never what surrounds it: a missing answer is
`/email`, and inside a list `/lines/1/sku` — one finding per missing member, rather than one
saying the document (or the entry) is incomplete. JSON Schema reports `required` and
`additionalProperties` per object; ingot unpacks both, because a client that has to put a
message beside a control needs to know which control.

| Method & path | Purpose |
|---|---|
| `POST /api/manage/forms` | Create a form. Body: `{"expireDate": "<RFC 3339>", "definition": {…}, "presentation": {…}?, "data": {…}?, "identity": "recorded"\|"anonymous"?}`. `201` + `Location`, answering with `{"id": …}` alone. |
| `GET /api/manage/forms/{id}` | Full envelope: definition, status, data, timestamps. |
| `GET /api/manage/forms/{id}/pdf` | The archival copy of a **confirmed** form as a PDF: every question, the answer it was given, who closed it and when. `?lang=xx` reads it in one of the presentation's catalogues (`auto`, the default, uses the document's own). `409 form-not-confirmed` for a draft. Needs no presentation. |
| `GET /api/manage/forms/{id}/history` | Every accepted save, newest first, each with the identity that was asserted when it was accepted (`actor`, null on an `anonymous` form). The management side of `GET /api/forms/{id}/history`, which carries no `actor` at all. |
| `DELETE /api/manage/forms/{id}` | `204`. The "definition changed" path is delete + recreate. |
| `GET /api/forms/{id}/schema` | Derived JSON Schema of the form's *values* (`application/schema+json`). `?mode=draft` returns the relaxed variant. |
| `GET /api/schemas/definition` · `GET /api/schemas/presentation` | The meta-schema each of those documents is judged by (`application/schema+json`) — the authoritative contract for what you may write, which is why it is served rather than described. Fixed for a deployment. |
| `GET /api/forms/{id}/presentation` | How the form is shown, as it was given at creation (`404 presentation-not-set` when none). |
| `PUT /api/forms/{id}/data` | Save a draft (repeatable). `204`, `409 form-locked` once confirmed. Optionally conditional: `If-Match: "7"` stores only while the form is still at that save (`412 form-moved-on` otherwise). |
| `POST /api/forms/{id}/confirm` | Strictly validate the stored data and lock the form. `204`; `409` when already confirmed or empty, `422` with the report when invalid. Takes the same `If-Match`, and wants it more: a form locked on a document nobody read cannot be put back. |
| `GET /api/forms/{id}/data` | The current values (`404 form-data-empty` when none). The `ETag` is the number of the save they are — keep it for `If-Match`. |
| `GET /api/forms/{id}/history` | Every accepted save, newest first: `{seq, savedAt, confirmed}`. |
| `GET /api/forms/{id}/history/{seq}` | The values that save stored, byte for byte. Send them back through `PUT …/data` to restore them. |
| `POST /api/forms/{id}/files` | Upload a file for this form. One `multipart/form-data` part named `file`. `201` with the description to put in the values, plus `Location`. |
| `GET /api/forms/{id}/files/{fileId}` | Download a file **the stored values name**. Always `Content-Disposition: attachment` with `X-Content-Type-Options: nosniff`. |
| `DELETE /api/forms/{id}/files/{fileId}` | Throw away an upload nobody saved. `409 file-attached` when the stored values name it. |

Writes answer with a status, not a copy: `PUT …/data` and `POST …/confirm` return `204 No
Content` (or `422` with the report), because the client already knows the values it sent —
read the form if you need its new state.

Error status map: `400` malformed JSON, `404` unknown form, `409` state conflicts,
`204` a write that succeeded, `410` expired form (every endpoint), `412` a precondition the
form has moved past, `413` a body larger than this deployment accepts, `415` a request body
that is not `application/json`, `422` validation reports, `500` opaque fallback.

## Saving without overwriting somebody

A form is one document, so two people filling it in at once are writing over each other — and
without asking, the second save wins silently and the first person's answers are gone. What
closes that is HTTP's own mechanism rather than anything of ours: **a conditional write**.

```
GET  /api/forms/{id}/data        →  200, ETag: "7"
PUT  /api/forms/{id}/data           If-Match: "7"   →  204   (still at 7)
                                                    →  412   (somebody saved: form-moved-on)
POST /api/forms/{id}/confirm        If-Match: "7"   →  204   (locked on the document you read)
                                                    →  412   (somebody saved: read it again first)
```

**Confirming takes it too, and wants it more**: a draft saved over can be saved again, while a
form locked on a document nobody read cannot be put back at all.

Everything about it is optional and nothing changes for a client that says nothing: the save
stays unconditional, exactly as it always was. What the tag is, is the **number of the save** —
`revision` in the form's envelope, `seq` in its history — and not a hash of the document,
because the question is "has anybody saved since I read this" and two saves can perfectly well
store the same answers.

Three shapes are read, and anything else is `400 precondition-not-readable` rather than a save
that quietly went through unconditionally: `"7"`, a list (`"7", "8"`, meaning any of them), and
`*` (any revision, as long as the form is there). `"0"` is legal and means **only if nobody has
filled this in yet** — the one moment there is no document to read a tag off, and the one where
two people opening a fresh form would otherwise not be told.

A refusal takes nothing away: nothing was stored, the form is where it was, and the client's own
next move is the ordinary one — read the values again (with their new tag), show the person what
changed, and send theirs on top of it.

## When something is refused

Every error is an RFC 9457 `application/problem+json` document. Validation problems carry an
`errors` array with one entry per finding: `{pointer, code, message, input?}`. **A pointer names
the thing that is wrong**, never what surrounds it — `/email`, or `/lines/1/sku` inside a list —
so a page can mark the control instead of announcing that the document is incomplete.

**Every independent complaint is in the one answer.** A missing member, a value that broke its
own rule, and an obligation a condition brought about are three findings in one response, each at
its own pointer — a client fixes all of them and sends once. Alternatives are the exception, and
they are not complaints of that kind: where a document must match one of several shapes and
matches none, every shape it failed is named, because none of them is the one it was supposed to
match.

**A page is read in one language, and it is the one your document can answer in.** The reader's
language decides only when you carry a catalogue for it: with `?lang=de` or a German browser and
no `de` catalogue, the questions come out in your `defaultLocale` — and so does everything the
page itself says, refusals included. So a form with a single Polish catalogue is a Polish page
for everybody, and the way to offer a language is to carry it.

**`message` is for you; a page says something else.** The message is written for whoever is
calling the API — `Array should have at most 2 items, 3 found` is exactly right in a log and no
use to somebody who has ticked one box too many — so the **code** is the part meant to be acted
on. Both kits word the codes a person can reach into sentences of their own, in the language the
page negotiated (*Choose at most 2.*, *This answer is needed.*), and fall back to `message` for
anything they have no words for. Those sentences are this application's, in `translations/`, and
no presentation document carries them: an author writes the questions, not the refusals. A page
also holds every ceiling it can before a save is even attempted — the third tick of a `max: 2`
multiple choice cannot be ticked, exactly as a text box will not take a character past
`maxLength` — so most refusals a person could meet never happen.

```json
{
  "type": "urn:problem:ingot-forms:presentation-not-valid",
  "title": "Form presentation is not valid.",
  "status": 422,
  "errors": [
    { "pointer": "/presentation/skin", "code": "presentation.skin.unknown",
      "message": "Engine \"bootstrap\" has no skin named \"chrome-yellow\".", "input": "chrome-yellow" }
  ]
}
```

**Refusals about the definition** (`definition-not-valid`, `422`):

| Code | What it means |
|---|---|
| `form.field.duplicate-name` | two items in the same scope share a `name` |
| `form.field.impossible-range` | `min` is greater than `max` |
| `form.field.not-a-date` | a `min`/`max` on a date is not a calendar day |
| `form.field.not-a-moment` | a `min`/`max` on a datetime is not an RFC 3339 moment with an offset |
| `form.collection.required-not-allowed` | `required` or `requiredWhen` on a collection — use `min` instead |
| `form.multiselect.required-not-allowed` | `required` on a multiple choice — use `min` instead, for the same reason |
| `form.multiselect.impossible-minimum` | `min` asks for more ticks than the item has options |
| `form.collection.too-deep` | lists nested inside lists more than five deep |
| `form.file.not-a-media-type` | an `accept` entry is not a media type |
| `form.data.unknown-field-type` | (at confirmation) the form holds a plugin item type |
| `form.field.required-and-conditional` | `required` and `requiredWhen` on one item |
| `form.condition.empty` | a condition with no predicate and no combinator |
| `form.condition.ambiguous` | two things written where one was meant |
| `form.condition.no-item` | a test that names no item |
| `form.condition.unknown-item` | it tests an item not declared in that scope |
| `form.condition.self-reference` | an item asked for on the strength of its own answer |
| `form.condition.cycle` | items each waiting on the next, in a ring |
| `form.condition.not-comparable` | a value the item could never hold |
| `form.condition.too-deep` | combinators nested more than three deep |
| `form.condition.on-a-calculated-number` | a question asked on the strength of a number worked out from answers |
| `form.calculated.*` | a calculation that could never work out a number — explained one by one in [numbers worked out from the answers](#numbers-worked-out-from-the-answers) |

The eight `form.condition.*` codes are explained one by one in
[Questions asked only sometimes](#questions-asked-only-sometimes).

**Refusals about the presentation** (`presentation-not-valid`, `422`):

| Code | What it means |
|---|---|
| `presentation.item.unknown` | it presents an item the definition does not declare |
| `presentation.item.missing` | the definition declares an item it does not show |
| `presentation.item.duplicate` | it shows the same item twice in one scope |
| `presentation.item.not-a-container` | an item presenting a value was given `items` |
| `presentation.item.not-drawable` | the engine cannot draw that kind of item at all |
| `presentation.widget.mismatch` | the engine does not draw that widget for that item |
| `presentation.collection.no-entry-form` | a list was not given the form for one entry |
| `presentation.column.unknown` | a `columns` entry names something the entry does not have |
| `presentation.confirm.missing` | no `confirm` anywhere — the page would be unfinishable |
| `presentation.trigger.in-an-entry` | `save`/`confirm` inside a list entry: a form does those, not an entry |
| `presentation.step.outside-a-wizard` | a `step` that is not directly inside a `wizard` |
| `presentation.wizard.no-steps` | a `wizard` with no page to step |
| `presentation.wizard.holds-more-than-steps` | a `wizard` holding something that is not a `step` |
| `presentation.wizard.nested` | a wizard inside a wizard |
| `presentation.wizard.in-an-entry` | a wizard inside an entry of a list |
| `presentation.choice.unknown` | `choices` words a value the item does not offer |
| `presentation.choice.missing` | some options worded and others left as codes |
| `presentation.choice.not-allowed` | `choices` on an item that offers no choice |
| `presentation.placeholder.not-allowed` | `placeholder` on something that holds no answer |
| `presentation.translation.missing` | the default catalogue is missing a code the document uses |
| `presentation.locale.unknown` | `defaultLocale` names a catalogue that is not there |
| `presentation.skin.unknown` | the engine has no skin by that name |
| `presentation.skin.unsupported` | that engine takes no skin at all (`core-html`) |
| `presentation.engine.unknown` | `engine` names a kit this deployment does not have |

**Refusals about values** (`422` on `PUT …/data` and `POST …/confirm`):

| Code | Comes from |
|---|---|
| `schema.<keyword>` | the published JSON Schema, named after the keyword that refused: `schema.required`, `schema.type`, `schema.enum`, `schema.const`, `schema.pattern`, `schema.minLength`/`maxLength`, `schema.minimum`/`maximum`, `schema.minItems`/`maxItems`, `schema.uniqueItems`, `schema.format`, `schema.formatMinimum`/`formatMaximum`, `schema.additionalProperties` (a member the form does not declare), `schema.properties` (an answer to a question nobody was asked) |
| `form.value.required` | the second gate on a missing answer |
| `form.value.decimals` | more decimal places than the item allows — the one rule that cannot be published ([`decimals`](#the-definition-what-is-asked)) |
| `form.value.range` | outside the item's own range |
| `form.value.type` | not the kind of value the item holds |
| `form.value.miscalculated` | a number that says what it is worked out from, worked out differently ([numbers worked out from the answers](#numbers-worked-out-from-the-answers)) |
| `form.value.unknown_field` | a member the form does not declare, as that gate names it |
| `form.value.invalid` | anything else that gate refuses |
| `form.file.unknown` | the values name a file this form does not have |
| `form.file.mismatch` | they name a real file but describe it differently than the server measured it |

The `schema.*` list is named after keywords, so it grows with the schema rather than with this
service: whatever `GET /api/forms/{id}/schema` carries can refuse a document under its own name.
The five `form.value.*` codes are ours, which is why they are spelled out.

**Refusals about the request itself:** `request.unexpected_key` (a member the DTO does not
declare — bodies are closed), `form.expire_date.past` (an `expireDate` that has already gone
by), `request.type`, `request.length`, `request.pattern`, `request.choice` (a member of the creation request that is the wrong shape, too long, malformed
or not one of the words offered — `form.identity.unknown` is the same kind of thing, for an
`identity` that is neither `recorded` nor `anonymous`), `400` for malformed JSON, `415` for a
body that is not JSON.

**Status codes:** `204` a write that worked · `400` malformed JSON · `404` unknown form,
revision or file · `409` state conflicts (locked, already confirmed, nothing to confirm, a file
some save still names) · `410` an expired form, on every endpoint · `413` a body over this
deployment's limit · `415` a non-JSON body · `422` a validation report · `500` an opaque
fallback.

## A complete example

An order form: who is ordering, what they are ordering (a list), which extras they want (several
of a closed list), an invoice to attach, a signature to draw, and a consent — plus one question
asked only of a company, and one that is only owed when the delivery is express. Drawn by the
richer kit, wearing `flatly`, in Polish.

```json
{
  "expireDate": "2030-01-31T23:59:59+00:00",
  "definition": {
    "items": [
      { "type": "text",   "name": "customer", "required": true, "maxLength": 60 },
      { "type": "select", "name": "country",  "required": true, "options": ["pl", "de"] },
      { "type": "multiselect", "name": "extras", "options": ["gift", "express", "insured"], "max": 2 },
      { "type": "checkbox", "name": "company" },
      { "type": "text",   "name": "nip", "required": true, "maxLength": 10, "pattern": "^[0-9]{10}$",
        "askedWhen": { "item": "company", "is": true } },
      { "type": "date",   "name": "delivery", "min": "2026-01-01" },
      { "type": "text",   "name": "window", "maxLength": 40,
        "requiredWhen": { "item": "extras", "answered": true } },
      { "type": "file",   "name": "invoice",  "accept": ["application/pdf"], "maxSize": 1048576 },
      { "type": "file",   "name": "signature", "accept": ["image/png"], "maxSize": 262144 },
      { "type": "collection", "name": "lines", "min": 1, "max": 20, "items": [
        { "type": "text",   "name": "sku",      "required": true, "pattern": "^[A-Z]-[0-9]+$" },
        { "type": "number", "name": "quantity", "required": true, "min": 1, "decimals": 0 }
      ]},
      { "type": "checkbox", "name": "terms", "required": true, "mustBeChecked": true }
    ]
  },
  "presentation": {
    "engine": "bootstrap",
    "skin": "flatly",
    "defaultLocale": "pl",
    "items": [
      { "widget": "heading", "label": "t.title" },
      { "widget": "card", "label": "t.who", "items": [
        { "widget": "row", "items": [
          { "name": "customer", "widget": "text", "label": "t.customer", "options": { "width": 8 } },
          { "name": "country",  "widget": "radio-buttons", "label": "t.country",
            "choices": { "pl": "t.pl", "de": "t.de" }, "options": { "width": 4 } }
        ]},
        { "name": "company", "widget": "switch", "label": "t.company" },
        { "name": "nip", "widget": "text", "label": "t.nip" },
        { "name": "delivery", "widget": "date", "label": "t.delivery", "hint": "t.delivery.hint" },
        { "name": "extras", "widget": "checkboxes", "label": "t.extras", "hint": "t.extras.hint",
          "choices": { "gift": "t.gift", "express": "t.express", "insured": "t.insured" } },
        { "name": "window", "widget": "text", "label": "t.window" }
      ]},
      { "name": "lines", "widget": "table", "label": "t.lines", "columns": ["sku", "quantity"],
        "items": [
          { "name": "sku",      "widget": "text",    "label": "t.sku" },
          { "name": "quantity", "widget": "stepper", "label": "t.quantity" }
        ]},
      { "name": "invoice", "widget": "dropzone", "label": "t.invoice", "hint": "t.invoice.hint" },
      { "name": "terms", "widget": "switch", "label": "t.terms" },
      { "name": "signature", "widget": "signature", "label": "t.signature",
        "options": { "height": 180 } },
      { "widget": "save",    "label": "t.save", "options": { "appearance": "link" } },
      { "widget": "confirm", "label": "t.send" },
      { "widget": "reset",   "label": "t.reset" },
      { "widget": "history", "label": "t.history" }
    ],
    "translations": {
      "pl": {
        "t.title": "Zamówienie", "t.who": "Kto zamawia",
        "t.customer": "Imię i nazwisko", "t.country": "Kraj", "t.pl": "Polska", "t.de": "Niemcy",
        "t.company": "Zamawiam na firmę", "t.nip": "NIP",
        "t.delivery": "Data dostawy", "t.delivery.hint": "Najwcześniej od stycznia 2026",
        "t.window": "Preferowane godziny dostawy",
        "t.lines": "Pozycje", "t.sku": "Kod", "t.quantity": "Ilość",
        "t.extras": "Dodatki", "t.extras.hint": "Najwyżej dwa",
        "t.gift": "Pakowanie na prezent", "t.express": "Dostawa ekspresowa", "t.insured": "Ubezpieczenie",
        "t.invoice": "Faktura (PDF)", "t.invoice.hint": "Jeden PDF, do 1 MB",
        "t.signature": "Podpis",
        "t.terms": "Akceptuję regulamin",
        "t.save": "Zapisz na później", "t.send": "Wyślij", "t.reset": "Zacznij od nowa",
        "t.history": "Wcześniejsze wersje"
      }
    }
  }
}
```

Filling it in, from a client's point of view:

```http
POST /api/forms/{id}/files          → 201 {"id":"…","name":"faktura.pdf","size":81234,"type":"application/pdf"}
PUT  /api/forms/{id}/data           { "customer": "Ada", "lines": [{"sku":"A-1","quantity":2}],
                                      "invoice": { …the four members, verbatim… } }      → 204
POST /api/forms/{id}/confirm                                                             → 204
```

**To answer this form on several pages**, wrap the presentation's `items` in one
`{"widget": "wizard", "items": [ … ]}` whose children are `step`s, and move the questions into
whichever page each belongs on. Nothing else changes: not the definition, not the values, not a
single request above — a step is a way of looking
([one form on several pages](#one-form-on-several-pages)).

Working requests for every endpoint, ready to run, live in
[`tests/_requests/`](../tests/_requests) — one file per topic, each with assertions.

## Before you ship a form

- **Every declared item is shown exactly once.** The server checks it, in every scope; an item
  a client fills in rather than a person is `hidden`, which says so.
- **There is a `confirm` somewhere**, and it is not inside a list entry.
- **The default catalogue is complete** — every label, hint and choice code, including the ones
  inside entries. Other locales may lag; the default one may not.
- **Every option of a choice is worded, or none is.**
- **No shape rule stands in the way of "save for later".** A `pattern` or a floor refuses a
  half-finished answer, by design ([the life of a form](#the-life-of-a-form)) — so check each one
  against somebody typing: a ten-digit `pattern` is fine on a field pasted into, and a nuisance
  on one typed slowly.
- **If the form is paged, the last page is where the way to finish belongs.** Nothing enforces
  it — a `confirm` anywhere satisfies the server — but a page somebody has to go *back* from to
  send the form is a page that reads as unfinished. A page holding nothing but triggers is
  never stepped over, so a "review and send" page is safe to make; a page whose only question a
  condition can take away simply disappears, which is a feature and worth checking you meant.
- **Every condition can come about.** A question waiting on an answer nobody can give is a
  question nobody will ever see: work through each `askedWhen` and name the answer that asks
  it. The refusals catch a condition that is *impossible* (an option the item does not offer),
  never one that is merely unreachable.
- **`maxSize` fits under the deployment's own upload limit** (`FILES_MAX_UPLOAD`, 10 MiB by
  default). Yours is the published contract; the deployment's is a wall.
- **`accept` lists what the server will sniff**, not what a browser claims. Check the answer of
  a real upload before promising a type — `.docx` is the classic surprise.
- **The expiry date is far enough away.** Everything about the form, including its history and
  its files, leaves with it.
- **You can throw the form away and make it again.** That is the only way to change either
  document, so an author who cannot recreate a form on demand has a problem waiting.
