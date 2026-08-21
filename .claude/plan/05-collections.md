# 05 — a repeatable subform (collections)

Until now a form is **flat**: `items` is a list of fields, and the values are one object keyed
by item name. This stage makes it a tree. A definition gains an item that holds a *definition* —
a collection of subforms — and a form gains a value shape it has never had: an array of objects.

What the owner asked for, in their words: a list a person can add to, remove from and edit, with
rules of its own (a minimum and a maximum number of entries). Editing happens **in a form, not
in the list**: the table is a preview, and the row's own form opens under it, collapsible.

## Decisions (settled with the owner before writing this)

1. **`type: collection` in the definition, `widget: table` in the presentation.** The definition
   says what is asked — a collection of subforms — and the presentation says how it looks. A kit
   that one day draws the same thing as cards changes no definition.
2. **A collection's value is an array of objects**, each keyed by the collection's own item
   names. Storage does not change at all: still one JSON document, stored as the exact text that
   passed validation. **No migration.**
3. **`min` / `max` only; `required` on a collection is a definition error.** `min: 1` says "at
   least one entry" precisely, and two rules about the same thing are two rules that can drift.
   Which contract each belongs to follows the rule this codebase already keeps: `max` is a rule
   about a value, so it holds in **both** contracts (like `maxLength`); `min` is an obligation to
   finish, so it holds **only in strict** (like `required` and `mustBeChecked`) — otherwise "save
   for later" would refuse an empty list, which is the state it exists for. A collection with
   `min ≥ 1` is also listed in the strict schema's `required`, because an absent member cannot
   satisfy a minimum.
4. **Entries are positional.** A row is a plain object in an array; findings point at
   `/lines/2/quantity`; nothing gives a row an id the server would have to mint and police.
5. **The definition nests to any depth; the first cut cannot *draw* a nested collection.** One
   class makes the model recursive for free, and the owner explicitly wants a collection to be
   allowed inside a subform. What no kit can draw yet is a collection inside a row, so: it is
   exempt from the completeness rule, and a presentation that tries to give it a widget is
   refused. The honest cost, written here so nobody discovers it later: **that data is fillable
   only through the API**, and if it carries `min ≥ 1` the form cannot be finished from a page.
6. **The row is edited in its own form, under its row, collapsible.** The table shows chosen
   columns as text; the form under it holds the row's items. Deliberately *not* "show a subset of
   columns and carry the rest hidden" — the owner's reason is exact: a field nobody sees might
   itself be a collection, and `hidden` has no answer for that.

## The definition

```json
{"items": [
  {"type": "text", "name": "customer", "required": true},
  {"type": "collection", "name": "lines", "min": 1, "max": 20, "items": [
    {"type": "text", "name": "sku", "required": true, "pattern": "^[A-Z0-9-]+$"},
    {"type": "number", "name": "quantity", "required": true, "min": 1, "decimals": 0}
  ]}
]}
```

`CollectionField extends Field` with `name`, `items` (1–1000, like the top level), `min`, `max` —
and `required`, which it must declare because the base class does (see step 0). Whether a
document may *set* it is therefore a rule: `required: true` on a collection is refused, because
`min: 1` says that, and says it about entries rather than about a member being present.

Rules that come with it, each in `Definition/` beside the ones it resembles:

- `min ≤ max`, and `max ≥ 1` — a collection that may hold no entry at all is not a collection
  (mirrors `NumberField`'s range rule and `DateRangeValidator`).
- **Unique names per scope.** `UniqueFieldNamesValidator` today walks one list; it becomes
  recursive, unique *within* each scope, pointing at `/items/1/items/0/name`. A row may reuse a
  top-level name — it is a different document.
- **`UnknownFieldTypes` recurses.** A plugin type nested three levels down still makes the form
  unconfirmable, and says so at its own pointer.
- The meta-schema (`form-definition.schema.json`) grows a recursive `items` reference; the
  item cap applies per scope. It was raised from 50 to 1000 while building this: 50 was a
  number from the days when a form was flat, and a cap is only there so nothing absurd gets
  stored — the real bound on a request is its size.

## The derived schema

A collection contributes an array schema, and the row is an object schema derived by the same
code that derives the top level — recursion, not a second implementation:

```json
"lines": {
  "type": "array",
  "maxItems": 20,                      /* both contracts */
  "minItems": 1,                       /* strict only */
  "items": {
    "type": "object",
    "properties": {"sku": {...}, "quantity": {...}},
    "required": ["sku", "quantity"],   /* strict only, recursively */
    "additionalProperties": false
  }
}
```

The draft contract relaxes inside rows exactly as it relaxes at the top: no `required`, no
`minLength` forced by it, no `const: true` for a consent, no `minItems`.

## The two gates

The **schema gate** needs nothing new beyond the recursion above, and it says everything there
is to say about a collection: the shape, the counts, and every rule of every item inside it.

The **form gate** therefore gets a deliberate pass-through for a collection (the value reaches
it as an array and is handed on untouched), for the same reason the gate exists at all: it is
there for what a schema *cannot* state, and today a schema states all of this. The day a nested
rule needs a form — one that reads another row, say — the branch becomes a real `CollectionType`
with the row's own `FormValuesType` as its entry. What does change now is
`SymfonyFormValues::wireTypeErrors`: a scalar where a collection belongs is a wire-type
mismatch, reported like every other one.

The battery rule holds unchanged and is what proves the pass-through safe: **the form must never
refuse what the published schema accepts.**

## The presentation

A collection item is the first presentation item that is *both* named and holding — it names the
collection, and its `items` are the row's form:

```json
{"name": "lines", "widget": "table", "label": "t.lines",
 "columns": ["sku", "quantity"],
 "options": {"open": false},
 "items": [
   {"name": "sku", "widget": "text", "label": "t.sku"},
   {"name": "quantity", "widget": "number", "label": "t.qty"}
 ]}
```

- **`columns`** is a new member: item names of that collection, no repeats, each declared. The
  header text is *not* a second label — it is the label the row's form already gives that item,
  so the text lives in one place. Omitted means every item of the row, in the order the row form
  draws them. A nested collection may not be named as a column (nothing can draw it as text).
- **`options.open`** asks for the row form to start unfolded.

`PresentationRules` stops being one-scope and becomes scope-aware, which is most of the work in
this layer:

| rule | today | after |
|---|---|---|
| every declared item shown exactly once | one scope | per scope; a nested collection is exempt |
| an item that presents a value holds nothing | absolute | except a collection, which **must** hold its row form |
| an item names something the form declares | top-level names | names of the scope it sits in |
| a widget is one the engine draws for that item | unchanged | a collection needs a collection widget; a nested one may not be given any |
| at least one `confirm` trigger | unchanged | unchanged — a row form has no triggers of its own |

Both engines declare `table` as the one control for a collection. `core-html` draws a plain
`<table>` with a `<details>` row form under each row; `bootstrap` draws a styled table with the
row form in a collapsible card. A second widget (cards, accordion) is a later, cheap addition.

## Drawing it, and the behaviour

`PresentedNodes` gains a node kind — `collection` — carrying: the resolved label and hint, the
resolved columns (name + header text), one entry per stored row (its cells as text and its row
form's nodes filled with that row's values), a **blank row** rendered from the same row form for
adding, and `min`/`max` so a page can guard its own buttons.

The one convention both kits share, and the only thing that must be written down once and obeyed
twice: **structure carries identity**. A control still says `data-name` and `data-type`; a row
wrapper says which collection and that it is a row. The page's module derives a path from the DOM
*position* at the moment it collects — so adding or removing a row renumbers nothing, because the
document order is the truth — and resolves a finding's pointer (`/lines/2/quantity`) by walking
the same structure back down.

Behaviour, per kit (plain module in `core-html`, a Stimulus controller in `bootstrap`):

- **add** clones the blank row, **remove** drops a row; both guard against `max` and `min`, while
  the server stays the authority that decides.
- a row's cells refresh from its own controls as somebody types, so the list never shows
  something the form under it contradicts.
- confirmed forms draw the table read-only, with no add, no remove, and no triggers — as every
  confirmed form does.

## Risks, and what to check before writing much

- **ingot must map a recursive discriminated union**: `CollectionField::items` is
  `list<Field>` where `Field` is the discriminated base. A spike is step 0. If the mapper cannot
  do it, the fix goes to **ingot first** and is pushed there before anything here — the
  two-repository rule.
- **Mutation testing** covers `src/Domain`, so every branch of the new recursion has to be
  pinned by the unit suite, including the per-scope pointers.
- **The battery**: a new item type gets its two subclasses
  (`tests/Domain/Forms/Definition/Field/CollectionFieldTest`,
  `tests/Infrastructure/Validation/Field/CollectionFieldValuesTest`) and inherits the rest. The
  values table needs nested pointers on both sides — what is accepted as well as what is refused.

## Order & acceptance

Each step ends with a green `make ci`.

0. **Spike** — done, and the answer is that **ingot needs no change**. What it showed, since it
   settles how the next steps are written:
   - a recursive discriminated union maps, two levels deep, and the unknown-type fallback
     recurses with it (a plugin item inside a row still becomes a `GenericField`);
   - `normalize()` is lossless and stable; the stored document lists a collection's members in
     constructor order, as every other item already does;
   - findings point exactly where the mistake is — `/items/0/items/0/items/0/max` for a range
     three scopes down — and a validator registered on the collection class points relative to
     it (`/items/0/min`), so per-scope rules need no hand-built paths;
   - `#[Constraints(minItems: 1)]` holds on the nested list (`mapping.min_items`);
   - the top-level unique-names rule does **not** see a nested scope, which is exactly the work
     step 1 has to do.

   One correction to decision 3 above: `CollectionField` **must** declare `required` in its
   constructor, because `Field` gives it no default and the mapper hydrates through the variant's
   constructor. So "a collection has no `required`" is a **rule**, not a shape: the parameter
   exists and defaults to `false`, and a validator refuses a document that sets it to `true`.
   Giving `Field::$required` a default instead would undo a decision written down there — that
   every variant declares its own.
1. **Domain**: `CollectionField`, meta-schema, its rules, recursion in unique names and unknown
   types. Battery part one.
2. **The contract**: recursive schema derivation in both modes, the wire-type check, the form
   gate's pass-through. Battery part two, and with it the proof that the form refuses nothing the
   schema accepts.
3. **Presentation**: the named-and-holding item, `columns`, scope-aware rules, `table` in both
   engines.
4. **`core-html`**: nodes, the table, the row form, add/remove, cells that keep up. Renderer
   tests.
5. **`bootstrap`**: the same drawn with the richer kit, behaviour as a Stimulus controller.
6. **In a browser, both kits**: add two rows, fill them, remove one, save, confirm; a refusal in
   row two lands in row two; the buttons obey `min` and `max`.
7. **Documents**: `README.md`, `CLAUDE.md`, `tests/_requests/07-collections.http`, and this
   file's as-built section.

## What building it changed

The plan held. What moved, and why:

- **Steps 5 and 6 merged.** Once the browser tests became a battery — one case class, a
  subclass per kit saying which engine it is and how its own triggers are found — driving the
  richer kit was the subclass, not a step.
- **The item cap per scope went from 50 to 1000** on the owner's call: 50 was a number from the
  days when a form was flat, and a cap is only there so nothing absurd gets stored.
- **`RawValueType` was not keeping its own promise.** Its docblock said it takes "object, list
  or scalar", and a simple Symfony field refuses an array unless it says it may hold several
  values — so a collection was refused by the form stage while the schema accepted it. Fixing
  that also closed a hole that predated this plan: a plugin item carrying a JSON list was
  refused the same way. The gates may never disagree in that direction, and now a battery case
  says so.
- **`required` on a collection is a rule, not a shape.** The spike (step 0) found that a
  variant must declare what its base class declares, so the parameter exists and setting it is
  refused with a message naming `min`.
- **Two presentation rules moved or arrived.** "A named item holds nothing" could no longer be
  answered without the definition — only it knows which named items may hold something — so
  that validator is gone and the rule lives in `PresentationRules`. And a trigger inside an
  entry is now refused outright (`presentation.trigger.in-an-entry`), which also keeps the
  confirmation rule honest: a button in a row would otherwise satisfy it while leaving the form
  with nothing of its own to press.
- **Ids cannot live inside an entry.** The same form is drawn once per entry, so `id="item-sku"`
  appeared as many times as there were entries and every label pointed at the first one. Inside
  an entry the label wraps its control instead — which cannot collide and says the same thing.
  Both kits' item macros split into `item` and `control` for it.
- **A new entry arrives unfolded.** Its row is empty, so the form under it is the only thing to
  do with it; folded shut, the page looked broken. The browser is what said so.
- **A browser truncates to `maxlength` on its own**, which corrected a *test* rather than the
  code: refusing a value from the page needs one nothing can clamp, so the case uses a count
  below its minimum.

## Decision 5 was lifted right afterwards

The owner asked for a list inside an entry to be drawable after all, having first checked it did
not mean a list inside a *column* — which it does not, and which stays refused: a column previews
a value as text.

It cost less than the plan feared, because the parts that had to be recursive already were: the
resolver builds an entry's form by the same code that builds the whole form, so a list in it is
just another list node; scopes concatenate (`lines-0-parts-1`), and a blank entry's token is
replaced one level at a time (`lines-NEW-parts-NEW` → `lines-n1-parts-NEW`), which is exactly
what claiming an outer entry should leave behind. What actually changed:

- the two rules that refused it are gone — a list inside an entry is now owed a place like any
  other item of that entry, and the completeness rule has no exceptions left;
- the plain kit's module stopped binding per list and started delegating from the form, because
  a list can now arrive inside a cloned entry (Stimulus needed nothing: it connects controllers
  on added markup by itself);
- "one more entry" moved into the table's own footer. The owner saw it sitting next to "save for
  later" and read the two as one row of buttons, which is exactly the confusion a page should not
  invite: asking for another entry is the list's doing, finishing the form is not.

## Non-goals

No row ids, no reordering, no per-row endpoints — the page still sends the whole values document,
and a write still answers with nothing. No drawing of a collection inside a collection (decision
5). No separate page or panel for editing a row: the form under the row *is* the editor. No
pagination of a long list, and no formatting rules for a cell — a cell shows the value as text.
No change to storage, to the lifecycle, or to how a form is created.
