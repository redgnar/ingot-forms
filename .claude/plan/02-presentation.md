# 02 — the presentation layer

**Status: implemented 2026-08-19**, in the five steps below, each its own commit with a green
`make ci`. Decisions taken with the owner are marked **(decided)**; where building changed the
plan, the section says so and why.

Builds on the rule written down in `CLAUDE.md`: *a definition says what is asked, never how it
looks*. That rule left a hole — display text and widget choice have to live somewhere — and
this is the document that fills it.

## What this is

A **presentation document attached to one form**, referencing its items by `name`. The
definition says what is asked and what an answer must satisfy; the presentation says how to
show it: order, grouping, which widget, which text.

**(decided)** It lives here, as a sub-resource of a form: `/api/forms/{id}/presentation`, the
same row, the same lifecycle. A separate service would be a cleaner split of responsibility on
paper, but nobody could then check that a presentation refers to items that exist, and every
client would have to ask two places to draw one form.

## Why it is fixed, like the definition

A form is described once, when it is created, and neither document changes afterwards.

The first version of this plan argued the opposite, and the argument was not wrong on its own
terms: the definition is immutable because stored values were judged against it, while a
presentation holds nothing hostage — reordering fields or fixing a code invalidates no answer.
What it missed is that "nothing forbids it" is not a reason to allow it. Nobody could say what
they would change a presentation *for*, and a rule that applies to one document and not the
other has to be explained every time somebody meets it. One rule — describe the form when you
create it, delete and recreate to change either document — is smaller to hold and smaller to
build: no endpoint, no transition, no event, no use case.

The cost is stated plainly: a typo in a code cannot be fixed without discarding the form and
whatever was filled into it. That is exactly what a typo in a definition costs today.

## The document

**(decided)** One recursive shape, no fixed levels: a list of items, where an item either
**presents a value** (it has a `name`, which the definition must declare) or **holds other
items** (a container — a fieldset, a card) or **stands on its own** (a decoration — a heading, a
paragraph). Sections were the first draft and are gone: a fixed level of grouping is an
arbitrary guess that is either too shallow or in the way. Steps and wizards stay out for their
own reason — without conditional logic they buy little, and with it they stop being
presentation (see non-goals).

**(decided)** Human text travels as **codes**, not as sentences, and the document may carry the
catalogue that resolves them:

```json
{
  "engine": "core-html",
  "defaultLocale": "en",
  "items": [
    { "widget": "fieldset", "label": "contact.personal", "items": [
      { "name": "email", "widget": "text", "label": "contact.email", "hint": "contact.email.hint" },
      { "widget": "fieldset", "label": "contact.address", "items": [
        { "name": "country", "widget": "radio", "label": "contact.country" }
      ]}
    ]},
    { "name": "terms", "widget": "checkbox", "label": "contact.terms" }
  ],
  "translations": {
    "en": { "contact.personal": "Personal details", "contact.email": "E-mail", "contact.email.hint": "We only use it to reply", "contact.country": "Country", "contact.terms": "I accept the terms" },
    "pl": { "contact.personal": "Dane osobowe", "contact.email": "E-mail", "contact.email.hint": "Użyjemy go tylko do odpowiedzi", "contact.country": "Kraj", "contact.terms": "Akceptuję regulamin" }
  }
}
```

Every piece of text in the document is a **code**, and the names do not repeat it because it
holds for all of them: a section's `title`, an item's `label` and `hint`. `translations` is
optional — a
deployment with its own catalogue simply omits it — and the server never resolves a locale. It
serves the document whole; picking a language is the client's job, the same way picking a widget
is.

`widget` is optional too: absent means the type's natural one (`text` for a text item, `select`
for a select, and so on).

### The engine

**(decided)** `engine` names which presentation engine the document is written for, and it is
required — it is the thing that decides what the rest of the document is even allowed to say.
A widget vocabulary is not universal: one kit draws a select as `radio`, another has no such
control and offers `chips`; a document that does not say which kit it means cannot be checked
against any.

So the vocabulary is **per engine**, and an engine is an object the domain owns — adding a kit
is adding a class that implements `PresentationEngine`, never editing a table. A kit is the
authority on what it can draw: a control per kind of value, what may hold items, what may stand
alone. How a refusal is worded and where it points stays with the rules, and so do the checks
that hold whatever draws the form — an item exists, appears once, is shown at all — because
repeating those per engine is how a new engine comes to forget one.

An engine this application does not know is **accepted**, and then widgets go unchecked. That is
the plugin bargain again: we do not judge the value contract of a type we do not understand, and
we do not judge the controls of a kit we have never heard of. It costs one thing, and it is
worth naming: a document for an unknown engine is stored with its widgets unverified, so the
first place a mistake in it can surface is wherever it gets drawn.

Item-level options an engine needs for itself (a placeholder code, a column count, a mask)
travel in `options` and are kept as they came, exactly like a plugin item's extras. Nothing
here reads them.

## What the server enforces

Six rules, each of them something that can be stated and pointed at:

| code | what it refuses |
|---|---|
| `presentation.item.unknown` | a name the form's definition does not declare |
| `presentation.item.duplicate` | the same item shown twice, anywhere in the tree |
| `presentation.item.missing` | an item the definition declares and the presentation does not show |
| `presentation.item.not-a-container` | an item that presents a value and also holds items |
| `presentation.widget.mismatch` | a widget the engine does not draw — for that item's type, or as something that holds items, or as something standing on its own |
| `presentation.translation.missing` | a code used but absent from the default locale |
| `presentation.locale.unknown` | a `defaultLocale` with no catalogue in `translations` |

Pointers walk the tree as it is written: `/items/0/items/2/name`.

Incomplete non-default locales are **accepted**: a catalogue that has English and half of
Polish is how translation actually progresses, and a client falls back to the default. Extra
codes nobody uses are accepted too — a shared catalogue may carry more than one form needs.

**Amended after building it**: completeness *is* enforced. The original argument — hiding a
field changes nothing about what a form accepts — was true and beside the point: an item nobody
can see is a question nobody can answer, and a required one makes the form impossible to
confirm, with a `422` on every attempt and nothing in the interface to explain it. So every
declared item must appear, exactly once, and a value a client fills in rather than a person is
drawn with the `hidden` widget — a decision written down rather than an omission.

### Widgets

**(decided)** Closed vocabulary, validated against the item's type **and the document's
engine**, with a passthrough for plugins. The built-in engine, `core-html`, is what the plan
starts with:

| what is shown | widgets `core-html` draws |
|---|---|
| a `text` item | `text`, `textarea`, `hidden` |
| a `select` item | `select`, `radio` |
| a `number` item | `number` |
| a `date` item | `date` |
| a `checkbox` item | `checkbox`, `switch` |
| an item of an unknown (plugin) type | any name, unchecked |
| something holding other items | `fieldset` |
| something standing on its own | `heading`, `paragraph` |

A widget name the engine does not draw, on an item type this application knows, is refused —
the mismatch rule is the whole point of having a vocabulary. On a plugin item nothing is refused,
for the same reason a plugin item's value contract is not judged: we do not know its type, so
we do not pretend to know how it is drawn. Candidates for later, each of which brings a rule
rather than a look: `slider` for a number that declares both bounds, `month` for a date item
bounded within one month.

## API

| method & path | purpose |
|---|---|
| `POST /api/forms` | the document arrives here, optional, beside the definition. `201`; `422` with the report when it is not a presentation or does not fit the definition it came with |
| `GET /api/forms/{id}/presentation` | the document as stored. `200`; `404` when the form was created without one (`urn:problem:ingot-forms:presentation-not-set`); `410` expired |

Deliberately not there: nothing that changes a presentation after creation, and **the form
envelope does not carry it** — `GET /api/forms/{id}` keeps serving the definition and the data,
and a second copy of the presentation inside it would be a second truth. A client drawing a
form asks twice, and both answers are cacheable.

Findings about the document are rooted where the client sent it (`/presentation/items/0/name`),
whether they come from the meta-schema or from the rules that need the definition. The create
action re-roots the latter, because the form points inside the document and the client sent it
as one member of a request.

The published contract gains the document's shape as a component; the six semantic rules are
documented as the error codes they produce, exactly as the definition's rules are today.
Deriving a per-form presentation schema (an `enum` of the names this form declares, a widget
`enum` per item) is possible and would put those rules in the published document too — worth
doing when a client asks for it, not before.

## Model

```
src/Domain/Forms/Presentation/     PresentationDocument (root), PresentedItem (one recursive
                                   shape) + presentation.schema.json (the meta-schema)
    Engine/                        PresentationEngine (a kit: what it draws, what nests, what
                                   stands alone), CoreHtmlEngine, and Engines — the kits this
                                   deployment knows, collected by their interface
    Rule/                          the document's own rules, as mapper validators
    PresentationRules              the rules that need the form's definition and the engine
src/Domain/Forms/ValueObject/       Presentation — the normalized document plus the structure,
                                   never one without the other (as Definition is)
```

**Amended while building step 1**: the plan had a `PresentationValidator` port with an adapter
behind it, mirroring `ValuesValidator`. It is not needed — judging a presentation against a
definition takes no schema, no framework and nothing to inject but the engine catalogue, so
`PresentationRules` is a plain domain service. A port whose implementation would never leave
the domain is ceremony.

`Form` takes the document in its constructor, together with the rules that judge it — the same
double dispatch a values verdict uses, and for the same reason: whether a presentation fits is
a question about *this* form's definition.

```php
public function __construct(FormId $id, Definition $definition, ExpireDate $expireDate, ?Presentation $presentation = null, ?PresentationRules $rules = null, ?\DateTimeImmutable $now = null)
```

So a form that exists is a form whose presentation fits it, and there is no transition to
record: creation is the only moment this can happen. `fromState()` assigns it without judging —
what was stored was judged on its way in — and handing over a document without the rules is a
programming error rather than a quiet acceptance.

`Form::presentation(): ?Presentation` reads it back. The parsing on read follows `Definition`:
the repository holds the parser and builds a whole value object, or leaves it null when the
column is empty.

Application: `CreateForm` takes the presentation beside the definition; `ReadForm` answers with
the document or `PresentationNotSet`. No new port for either — they need what is already
declared.

## Storage

One nullable `presentation` column of portable `text` on `forms`, holding the exact JSON that
passed validation, like the other two documents. One row per form stays true, purge and expiry
need no changes, and `FormRecord` grows one field. The migration goes through the schema API.

## Tests

- **Unit** — the document's own rules, one class per rule, plus a `WidgetCompatibilityTest`
  table (item type × widget → accepted or `presentation.widget.mismatch`) that grows with the
  catalogue the same way the item battery does.
- **Unit** — `FormTest`: a form is created with its presentation and creation is still one
  event; a presentation naming an item the definition lacks means no form comes into existence;
  a document handed over without the rules that judge it is a programming error.
- **Integration** — the round-trip test grows to cover the third document, so a presentation
  that survives save but not read is caught here.
- **Integration/HTTP** — creation with and without the document, the read endpoint across every
  documented status, and a compliance scenario per operation + status, as the contract tests
  demand.
- **`tests/_requests`** — a `05-presentation.http` walking create → read → each way a document
  is refused, run against the dev server rather than eyeballed.

## What comes after this

Naming an engine is what makes **rendering the form here** possible rather than hypothetical:
the document already says which kit it is written for, so a renderer for that kit can be a
second adapter over the same use cases. That is a separate deliverable with its own
dependencies and its own risks — `03-rendering.md`.

## Non-goals

- **Conditional visibility** ("show the VAT id when type = company"). It reads like
  presentation and is not: it changes what an answer must satisfy, so it belongs to the
  definition and to the schema derived from it. When it comes, it comes as a rule about
  `required`, not as a field in a document about looks.
- **Styling** — widths, colours, CSS, spacing. A widget is a kind of control, not a look.
- **Steps and wizards**, until there is a form that needs one for a reason other than length.
- **Server-side locale resolution**, including `Accept-Language`. The document goes out whole.
- **Presentations shared between forms.** A form has one definition of its own, so it has one
  presentation of its own; reuse is a templating question, and templates are a decided non-goal.

## Order & acceptance — as built

Each step ended with a green `make ci`.

1. **The document, alone** (`94ade06`). Model, meta-schema, processor, the rules, unit tests —
   provable without a kernel or a database, as intended.
2. **The transition** (`38657e7`). `Form::present()`, the `PresentationChanged` event, the two
   use cases against in-memory fakes.
3. **Storage** (`2976fcb`). A nullable `presentation` column, its migration, and the write
   applied from the event — one branch in the repository's `match`, and nothing else moved.
   The round-trip test now drives all three documents through storage and back.
4. **HTTP** (`9b196a1`). The request DTO and its denormalizer, both actions, two new problem
   types, the regenerated contract and a compliance scenario per documented status.
5. **Documents** (`10116cc`). README, `CLAUDE.md`, and `tests/_requests/05-presentation.http` —
   whose requests were run against the dev server rather than eyeballed, since nothing tests
   them automatically.

Steps 2 and 4 built a presentation that could be replaced; `70ea64f` took that back, for the
reason the next section gives. The sections above describe where it landed.

## Reversed after building it (`70ea64f`): a presentation is set once

Shipped, the layer let a presentation be replaced at any time, and the argument for it was that
it holds no stored answer hostage. Used, that freedom had no purpose: if a definition is fixed
at creation, there is no reason for its description to drift either, and "why can I change one
and not the other" is a question with no good answer.

So a presentation is given at `POST /api/forms` beside the definition, optional, and immutable
with it. `PUT /api/forms/{id}/presentation` is gone, along with `Form::present()`, the
`PresentationChanged` event and the `SetFormPresentation` use case; the constructor judges the
document against the definition it arrives with, and the repository writes it with the row.
Fixing a typo in a code now costs what fixing a definition costs — delete and recreate — which
is the price of one rule instead of two.

The create body is decoded non-associatively, which the retired `PUT` did for its own document:
without it `"translations": {"en": {}}` arrives as an empty list and a client gets a puzzling
`schema.type`. Both processors now take an object or an array.

Two things surfaced only because the `.http` examples were run rather than read. Findings about
one document were rooted two different ways — `/presentation/translations/en` from the
meta-schema, `/items/0/name` from the rules that need the definition — and the create action now
re-roots the latter. And the compliance scenario for an unknown member had been passing on a
`minProperties` generated from a constraint that went away with the array-typed definition, so
the published `CreateFormRequest` now says `additionalProperties: false` outright, which is what
the server has always enforced.

## What building it changed

- **Sections became a tree.** The first draft grouped items one level deep. A fixed level is a
  guess that ends up either too shallow or in the way, so an item now either presents a value,
  holds other items, or stands on its own — and the engine catalogue gained the two vocabularies
  that go with it (what may nest, what may stand alone).
- **`titleCode` / `labelCode` / `hintCode` lost the suffix.** Every piece of text in the
  document is a code; the names do not each repeat it, the class docblocks say it once.
- **No numeric limits.** No `minItems`, no `maxItems`, no depth cap: a presentation may show
  nothing, and nesting is bounded in practice by what JSON decoding will take.
- **The `PresentationValidator` port never happened.** Judging a presentation against a
  definition needs no schema, no framework and nothing injectable but the engine catalogue, so
  `PresentationRules` is a plain domain service. A port whose implementation would never leave
  the domain is ceremony.
- **The model carries no constraint attributes.** Mutation testing found twenty escapees that
  were all the same mistake: the attributes repeated what the meta-schema already said. Unlike
  a definition — which keeps its own constraints so it survives being mapped without a schema —
  a presentation is only ever read through this domain's mapper, so the schema is the one place
  its rules live.

## Amended again: the engine is an object

The first version made an engine three tables in an `EngineCatalogue`, with one rules class
applying them. It became a `PresentationEngine` implementation instead, collected by a tagged
iterator, because a kit that says what it can draw is the same thing that will one day draw it
— and because a rule only one kit has ("my radio needs more than one option", "my slider needs
both bounds") has nowhere to live in a table. Adding a kit is now adding a class.

## What is left

Rendering a form here, which is `03-rendering.md` and deliberately not part of this: it brings
a template engine, a locale decision the API dodges, and the question of authentication, which
this service does not have at all.
