# 15 — several of one list

The fourth entry of [10](10-what-a-vendor-offers.md)'s list, and the one it called the cheapest:
*"multiple choice as one item — a type with rules of its own (`options`, counting the ticks), two
widgets, and no more pretending it is a collection."* Built as written, and it stayed cheap
because every mechanism it needed was already here.

## What was wrong with the old way

It could already be asked: a `collection` holding a single `select`. That answered the question
and lied about everything else.

- **The shape.** `[{"tag": "urgent"}]` where the answer is `["urgent"]`. Every consumer of the
  values document had to know that a list of one-member documents meant a set of values.
- **The rules.** Entries can repeat and can be reordered, because entries of a collection are
  documents and order is part of what one means. A set has neither property, and nothing could
  say so.
- **The page.** A table with an *Add* button, a row per tick, and a fold-away form inside each
  row — for a question whose whole answer is three checkboxes.

So the test of "does this bring rules of its own" — the one thing that earns a new item type
here — is answered twice over: `uniqueItems`, and a count of ticks.

## The type

```json
{"type": "multiselect", "name": "tags", "options": ["urgent", "billing", "legal"], "min": 1, "max": 2}
```

**It counts rather than requires**, exactly as a `collection` does and for the same reason:
`required` would only say "the member is there", which `[]` satisfies while answering nothing. So
`min` is how it asks to be answered and `required` is refused. The two bounds part company where
a collection's do — a maximum is a rule about the value and holds while somebody is still filling
the form in, a minimum is an obligation to finish and waits for confirmation.

One rule is its own, and it is the one a collection cannot have: **a minimum above the number of
options is refused** (`form.multiselect.impossible-minimum`), because unlike a list of entries
this item states its own ceiling and a form nobody can finish is a definition mistake. A *maximum*
above the list is allowed — "as many as you like" is a reasonable thing to write and costs
nothing.

The whole contract is statable, which is the other half of why this is a type and not a widget:
`{type: array, items: {enum: […]}, uniqueItems: true, minItems, maxItems}`. Nothing is enforced
past the published schema, so the form stage takes the array as it came.

## One thing moved in the model

"Is a document without this member unfinished?" was a `match` in `DataSchemaDeriver` naming the
types that count. Adding a second one to that list would have been the moment it became a place
to forget, so it is now `Field::mustBeAnswered()` — `required` by default, overridden by the two
items that count. The derived schema asks it, and so does the page when it decides whether to
draw the star and say `aria-required`; neither of them knows which types are special any more.

## Five widgets, and one of them is new machinery

| Kit | Widgets |
|---|---|
| `core-html` | `checkboxes` (natural), `multi-select` |
| `bootstrap` | `checkboxes` (natural), `checkbox-buttons`, `autocomplete` |

Each is a different way of *asking*, which is the bar a widget has to clear: `checkboxes` puts
every option in front of the person, `multi-select` trades that for a fixed amount of space and a
modifier key, `checkbox-buttons` is a bar of toggles for two or three options that deserve to be
seen at once, and `autocomplete` is for a list long enough that scrolling it is the problem.
`multi-select` is deliberately **not** in the richer kit: a `select multiple` is what the plain
kit has, and a fourth name for a question this kit already asks better would be a restyling.

The autocomplete needed nothing new in the domain and one line in the browser: TomSelect draws a
multiple choice when the element says `multiple`, so the controller reads that off the element
rather than being told twice, and adds the `remove_button` plugin — a chip nobody can remove is
an answer somebody cannot change.

## The convention this added

`data-type="strings"`, and it is the first control here whose answer is **not its own value**.
Both kits' collectors read what is picked *inside* the element — checked inputs, or
`selectedOptions` for a select — which is one branch in each and covers every one of the five
widgets, including the autocomplete (it writes back into the select it wraps). Nothing picked
leaves the member out, exactly as an unanswered single choice does; since a save replaces the
whole document, unticking everything and saving is how an answer is taken back.

A summary cell of a list entry joins the words the options were offered under, because a cell
says what an entry holds and a list of codes would not.

## And one thing it fixed on the way

A refusal pointing *inside* a value — `/tags/1`, which is what an enum finding on a member looks
like — used to land nowhere: both pointer walks assumed a name followed by an index meant a
collection and an entry, found no list, and gave up, so the message went to the page-level notice
with nothing marked. Now a name that is not a list falls back to that name's own message slot, so
the refusal lands on the control the person can actually see. The realistic finding
(`maxItems`, `uniqueItems`) points at `/tags` and always landed; this is the one that did not.

## What it cost

| | |
|---|---|
| Domain | `MultiSelectField`, `MultiSelectCountValidator`, one discriminator entry, one deriver branch, `mustBeAnswered()` on three classes, two engine table rows |
| Application | nothing |
| Infrastructure | one branch in `FormValuesType` (take it as it came) |
| UserInterface | one wire type, one natural widget, five widgets of markup, three JS branches per kit |
| Tests | the two batteries, both engines' vocabularies, both renderers, and a browser battery per kit |

No migration, no new endpoint, no new port. A definition is stored as the JSON it arrived as, so
a new item type is not a schema change.

## Two of those decisions were wrong, and were reversed the same day

The first version of this stage said **no client-side counting** and **no `min`/`max` in the
markup**: enforcing a rule in the browser would be a second place it lives, and a number nothing
reads is dead weight. Both were wrong, and the demo found it in about a minute — a third tick on
a `max: 2` item, *Save for later*, and a refusal on a **draft**.

The reasoning that was missing: a maximum is a rule about the *value*, so it holds while somebody
is still filling the form in — and every other such maximum in these kits is already in the
markup. `maxlength` is on the text box, `max` and `step` are on the number, and a list's *add*
button goes dead at `max`. Not because the browser is trusted, but because a page that lets
somebody into a state its own save refuses has wasted their work to tell them something it knew
in advance. So: `data-min`/`data-max` on the group, unticked options disabled at the ceiling (the
searchable one hands the number to TomSelect, which owns the adding), and **the floor left
alone** — too few is allowed in a draft, so there is nothing to stop, and blocking somebody from
unticking their own answer is a trap rather than a form. The server still decides, and the browser
test that ticks past the guard by script is what proves it.

The second thing the demo found was the *message*: `Array should have at most 2 items, 3 found`.
That is the schema validator's sentence, written for whoever is **calling** the API, and it was
being shown verbatim to a person — as every schema refusal had been since the pages existed. The
finding carries a `code`, which is the part meant to be acted on, so the page words the codes
itself (`RefusalWords`, `page.refusal.*` in `translations/`, handed to the browser as one value
like every other page word) and keeps the API's message as the fallback. `{n}` is filled in by
the kit from what the control already carries — which is only possible *because* of the first
fix, and is the second reason those numbers belong in the markup. A list gets its own sentences,
because `minItems` means ticks on a multiple choice and entries on a collection.

That one was never about this stage: *This answer is needed.* replaced `"sku" is required.`
everywhere, and two collection tests that asserted the message contained the item's name now
assert the sentence instead — the item is named by where the message stands, which was always the
design.

## Not built, on purpose

- **No floor enforced in the page.** See above: nothing to stop, and a trap if there were.
- **No "select all".** It is a convenience a document cannot ask for and a kit cannot place, so
  it would be a control appearing where nobody put it.
