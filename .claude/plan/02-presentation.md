# 02 — the presentation layer

**Status: proposed.** Nothing here is implemented. Decisions already taken with the owner are
marked **(decided)**; everything else is a recommendation open to change before work starts.

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

## Why it is mutable, when the definition is not

The definition is immutable because stored values depend on it: changing it would mean data no
longer fits the contract that accepted it. A presentation has no such hold on anything —
reordering fields or fixing a typo invalidates nothing. So it is replaced whole, idempotently,
as often as anybody likes, **including after confirmation**: a confirmed form is still
displayed, and correcting its wording does not touch what somebody agreed to.

## The document

**(decided)** One level of grouping — sections, each with an ordered list of items. Steps and
wizards are left out: without conditional logic they buy little, and with it they stop being
presentation (see non-goals).

**(decided)** Human text travels as **codes**, not as sentences, and the document may carry the
catalogue that resolves them:

```json
{
  "engine": "core-html",
  "defaultLocale": "en",
  "sections": [
    {
      "key": "personal",
      "titleCode": "contact.personal",
      "items": [
        { "name": "email", "widget": "text", "labelCode": "contact.email", "hintCode": "contact.email.hint" },
        { "name": "country", "widget": "radio", "labelCode": "contact.country" }
      ]
    },
    {
      "key": "consents",
      "items": [{ "name": "terms", "widget": "checkbox", "labelCode": "contact.terms" }]
    }
  ],
  "translations": {
    "en": { "contact.personal": "Personal details", "contact.email": "E-mail", "contact.email.hint": "We only use it to reply", "contact.country": "Country", "contact.terms": "I accept the terms" },
    "pl": { "contact.personal": "Dane osobowe", "contact.email": "E-mail", "contact.email.hint": "Użyjemy go tylko do odpowiedzi", "contact.country": "Kraj", "contact.terms": "Akceptuję regulamin" }
  }
}
```

The `…Code` suffix is deliberate: it stops somebody putting one language's sentence where a key
belongs and discovering it only when a second language appears. `translations` is optional — a
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

So the vocabulary is **per engine**, and engines live in a catalogue the domain owns — adding
one is adding an entry, never editing a rule (the same way the item catalogue grows). An engine
declares, for each item type it supports, which widgets it draws.

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

| code | what it refuses | pointer |
|---|---|---|
| `presentation.item.unknown` | a name the form's definition does not declare | `/sections/{i}/items/{j}/name` |
| `presentation.item.duplicate` | the same item shown twice in the document | `/sections/{i}/items/{j}/name` |
| `presentation.widget.mismatch` | a widget the document's engine does not draw for that item type | `/sections/{i}/items/{j}/widget` |
| `presentation.section.duplicate-key` | two sections with one key | `/sections/{i}/key` |
| `presentation.translation.missing` | a code used but absent from the default locale | `/translations/{locale}` |
| `presentation.locale.unknown` | a `defaultLocale` with no catalogue in `translations` | `/defaultLocale` |

Incomplete non-default locales are **accepted**: a catalogue that has English and half of
Polish is how translation actually progresses, and a client falls back to the default. Extra
codes nobody uses are accepted too — a shared catalogue may carry more than one form needs.

It does **not** enforce completeness: a presentation may show some of the items and omit the
rest. Hiding a field changes nothing about what the form accepts, because that is the
definition's business alone — and pretending otherwise would put validation in two places.

### Widgets

**(decided)** Closed vocabulary, validated against the item's type **and the document's
engine**, with a passthrough for plugins. The built-in engine, `core-html`, is what the plan
starts with:

| item type | widgets `core-html` draws |
|---|---|
| `text` | `text`, `textarea` |
| `select` | `select`, `radio` |
| `number` | `number` |
| `date` | `date` |
| `checkbox` | `checkbox`, `switch` |
| unknown (plugin) type | any name, unchecked |

A widget name the engine does not draw, on an item type this application knows, is refused —
the mismatch rule is the whole point of having a vocabulary. On a plugin item nothing is refused,
for the same reason a plugin item's value contract is not judged: we do not know its type, so
we do not pretend to know how it is drawn. Candidates for later, each of which brings a rule
rather than a look: `slider` for a number that declares both bounds, `month` for a date item
bounded within one month.

## API

| method & path | purpose |
|---|---|
| `PUT /api/forms/{id}/presentation` | replace the whole document. `204`; `422` with the report; `404` unknown form; `410` expired; `415` non-JSON body |
| `GET /api/forms/{id}/presentation` | the document as stored. `200`; `404` when none was ever set (`urn:problem:ingot-forms:presentation-not-set`); `410` expired |

Deliberately not there: no `DELETE` (a `PUT` replaces; removing presentation entirely is a case
nobody has), and **the form envelope does not carry it** — `GET /api/forms/{id}` keeps serving
the definition and the data, and a second copy of the presentation inside it would be a second
truth. A client drawing a form asks twice, and both answers are cacheable.

The published contract gains the document's shape as a component; the six semantic rules are
documented as the error codes they produce, exactly as the definition's rules are today.
Deriving a per-form presentation schema (an `enum` of the names this form declares, a widget
`enum` per item) is possible and would put those rules in the published document too — worth
doing when a client asks for it, not before.

## Model

```
src/Domain/Forms/Presentation/     PresentationDocument (root), Section, PresentedItem, Widget
                                   + presentation.schema.json (the meta-schema)
    Engine/                        EngineCatalogue — which widgets an engine draws for which
                                   item type; data, not rendering, because it is what rule 3
                                   is checked against
    Rule/                          UnknownItems, DuplicateItems, WidgetFitsItem, ...
src/Domain/Forms/ValueObject/       Presentation — the normalized document plus the structure,
                                   never one without the other (as Definition is)
src/Domain/Forms/Port/              PresentationValidator — judges a presentation against the
                                   definition it claims to present
```

`Form` gains one transition, and it is the aggregate's own rule for the same reason values are:
a presentation is only valid *against this form's definition*.

```php
public function present(Presentation $presentation, PresentationValidator $validator, ?\DateTimeImmutable $now = null): void
```

It records `PresentationChanged`, carrying the document — so the repository writes from what
happened, like every other transition. It refuses nothing on account of status: `FormLocked`
does not apply here, which is the point of the mutability argument above.

`Form::presentation(): ?Presentation` reads it back. The parsing on read follows `Definition`:
the repository holds the parser and builds a whole value object, or leaves it null when the
column is empty.

Application: `SetFormPresentation` (transaction, locked read, `present()`, `save()`) and
`ReadFormPresentation` (`get()`, then the document or `PresentationNotSet`). No new port for
either — they need what is already declared.

## Storage

One nullable `presentation` column of portable `text` on `forms`, holding the exact JSON that
passed validation, like the other two documents. One row per form stays true, purge and expiry
need no changes, and `FormRecord` grows one field. The migration goes through the schema API.

## Tests

- **Unit** — the document's own rules, one class per rule, plus a `WidgetCompatibilityTest`
  table (item type × widget → accepted or `presentation.widget.mismatch`) that grows with the
  catalogue the same way the item battery does.
- **Unit** — `FormTest`: presenting records `PresentationChanged`; a presentation referring to
  an item the definition lacks is refused and nothing is stored; a confirmed form still accepts
  a new presentation.
- **Integration** — the round-trip test grows to cover the third document, so a presentation
  that survives save but not read is caught here.
- **Integration/HTTP** — both endpoints across every documented status, and a compliance
  scenario per operation + status, as the contract tests demand.
- **`tests/_requests`** — a `05-presentation.http` walking set → read → replace → the three
  refusals.

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

## Order & acceptance

Each step ends with a green `make ci`, and nothing is committed without the owner asking.

1. **The document, alone.** Model, meta-schema, processor, the six rules, unit tests. Nothing
   in the aggregate yet, so the whole step is provable without a kernel or a database.
2. **The transition.** `Form::present()`, the `PresentationValidator` port, the
   `PresentationChanged` event, the two use cases against in-memory fakes.
3. **Storage.** Column, migration, `FormRecord`, the write applied from the event, the
   round-trip test extended.
4. **HTTP.** Request DTO, two actions, the `presentation-not-set` problem, the contract
   regenerated (`make docs`) and the compliance scenarios.
5. **Documents.** README's item catalogue gains a presentation section, `CLAUDE.md` gains the
   rules this settled on, `tests/_requests` gains its examples.
