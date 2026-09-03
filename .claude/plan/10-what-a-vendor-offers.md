# 10 — what a vendor offers, and which of it we want

This is not a stage. Nothing in it has been built, and some of it never will be. It is the
record of one scan: **Form.io**, the most complete commercial form-and-data platform of the
shape this service could grow into, read feature by feature against what we have, so that the
next person proposing a feature can see whether it was already weighed and what it would drag
with it.

Read the statuses as four, not two, because the interesting half of the list is neither "we have
it" nor "we lack it":

| | means |
|---|---|
| **have** | exists here in a comparable form |
| **partial** | the foundation is here; the layer a vendor sells is not |
| **gap** | a real hole — a use case we cannot serve today |
| **not wanted** | the domain model says no, and this document says why |

The form builder is out of scope on purpose: it is known to be needed and its absence is not a
finding. It appears once, in the logic table, only because a vendor's builder is the entry point
to everything else while ours would be a client of an API and two meta-schemas that already exist.

Sources: form.io's own feature, pricing and "open source core vs. enterprise" pages and their
documentation, read 2026-08-28. The split between their free core (OSL-3.0, copyleft) and their
paid modules moves without notice, so every "Enterprise" below is what they said that day.

## The one difference the rest follows from

For Form.io a form is a **template**: it collects an unbounded number of *submissions*, and beside
it stand *Resources* — data models with a generated REST API, which is also what their
authentication is built on. For us a form is **one fillable document**: one definition, one data
set, a required expiry.

Almost everything they have and we do not is downstream of that. A template needs versioning
(which submission was made against which definition?), promotion between environments, a
submission browser, exports, and roles saying who may see whose answers. A single document needs
none of them, which is why the "not wanted" column below is long and not defensive.

## Data model and lifecycle

| Feature | Form.io | Us | Note |
|---|---|---|---|
| Template collecting many submissions | core | **not wanted** | One form = one document is the axiom. Many fillings = many forms, created by the system that owns them. |
| Resources — data models with their own API | core | **not wanted** | That is the step into being an application's database. Our definition is one document's contract, not an entity schema. |
| Definition versioning (Form Revisions) | Enterprise | **not wanted** | The definition is immutable; change means delete and recreate, which dissolves "which version were these answers given against". |
| History of saved data | Enterprise (submission revisions, logs) | **have** | Every accepted save is a revision, bounded by `FORMS_HISTORY_LIMIT`, restored through the ordinary `PUT …/data`, with a page per version. |
| Draft / save for later | core, plus Enterprise auto-save | **have** | `empty → draft → confirmed` with a lenient contract while filling in. Browser-side auto-save would be a thin client of the same endpoint. |
| Collision control | Enterprise | **gap** | Our row lock means no corrupt data, but a second filler silently overwrites the first. The fix is a conditional save ("I hold revision *n*"), not a lock held across requests. |
| Expiry and physical deletion | not in the product | **have** | `expire_date` is required, `410 Gone` past it, and `app:forms:purge-expired` deletes the row and then the bytes. |
| Who did what | Enterprise audit and action logs | **partial** | We record author, confirmer and who entered every save (or nobody, in `anonymous`). There is no log of operations. |

## The item catalogue

Form.io ships 33 components in five families, some of them premium. We have eight types plus the
open-world one, because **a type exists when it brings rules of its own** — how a question is drawn
belongs to the presentation. The two numbers measure different axes: `radio` and `select` are one
item and two widgets here.

| Family | Form.io | Us | Note |
|---|---|---|---|
| Text, number, choice, date, checkbox | Text Field, Text Area, Number, Password, Checkbox, Select Boxes, Select, Radio, Button | **have** | `text`, `number`, `select`, `checkbox`, `date`, `datetime` plus both kits' widgets (textarea, radio, radio-buttons, switch, range, stepper, autocomplete). |
| Multiple choice in one item | Select Boxes, and `multiple` on Select — the value is an array | **gap** | The cheapest real hole in the catalogue. Today it has to be modelled as a collection of one `select`, which is a different data shape and a heavier page. |
| Semantic fields | Email, URL, Phone Number, Currency, Tags, Address, Day, Time | **partial** | All reachable through `text` + `pattern` or `number` + `decimals`, but without a ready rule, browser hint or phone keyboard. Email and phone are the plausible candidates for types with rules of their own. |
| Repeating groups | Data Grid, Edit Grid, Data Map, Container, Nested Form (premium) | **have** | `collection` is one concept doing all of it — nested lists, `min`/`max`, every rule re-asked one scope down, drawn by both kits. |
| Layout and static content | Panel, Tabs, Columns, Field Set, Table, Well, HTML Element, Content | **have** | `fieldset`, `card`, `accordion`, `row` and text blocks. Tabs are missing, but that is one widget, not a missing mechanism. |
| Signature, sketch, marks on an image | Signature, Sketchpad, Tagpad (premium) | **gap** | Signature is the one of the three anybody asks for. Its value would be a file, so the file mechanism already carries it — what is missing is a widget. |
| Survey matrix | Survey | **gap** | Expressible today as a collection of `select`, without the matrix reading as one. |
| CAPTCHA | premium component | **not wanted** | A form here is addressed to somebody by the system that created it, not opened to the public. Bot defence is the gateway's job. |
| Field fed from outside | Data Source, Data Table (premium) | **not wanted** | It would mean this server calling the world while rendering. Options are injected by whoever creates the form. |
| A type the server does not know | Custom Component — code to deploy on both sides | **have** | Ours is the better bargain: an unknown type round-trips losslessly and can be drafted; only confirmation is refused. |

## Logic, validation and the contract

Their definitions may contain code: conditional display, calculated values, custom JavaScript
validators run in a "protected evaluator". It is the strongest and most expensive part of their
model. Ours states rules declaratively and **publishes them** — the per-form JSON Schema at
`GET /api/forms/{id}/schema` is both what a client validates against and the first gate here.

| Feature | Form.io | Us | Note |
|---|---|---|---|
| Conditional logic (show / hide / require) | core, advanced in Enterprise | **gap** | The largest functional hole on the list. Every item is always visible and always equally required. |
| Calculated values | `calculateValue` | **gap** | Summing a list's lines is the standard case. Computable in a client today, required of nobody. |
| Custom validation code | JS expressions, client and server | **not wanted** | Running code carried in a document is a different class of system, and it is exactly what makes their contract unpublishable. |
| A published, machine-readable contract | the definition is JSON, but not JSON Schema | **have** | Our clearest advantage: a schema per form in two modes, meta-schemas at `/api/schemas`, and the rule that the server is never stricter than what it published. |
| Error format | the renderer's own shape | **have** | RFC 9457 `problem+json`, one finding per mistake, pointed at the member that is wrong. |
| Drag-and-drop builder | core, plus an embeddable Enterprise module | **gap** | Out of scope here. Worth recording only that theirs is the entry point to everything, while ours would be a client of the API and the meta-schemas. |

## Drawing a form, and reading it

| Feature | Form.io | Us | Note |
|---|---|---|---|
| A ready page to fill in | JS renderer (Angular, React, Vue), FormView Pro | **have** | Server-rendered, two kits, no build step and no CDN. |
| A renderer to embed in someone else's app | core (`formio.js`) | **gap** | Whoever wants their own interface gets the contract and writes the drawing themselves. For a client building an SPA that is a real cost. |
| Multi-page wizard | core | **gap** | We group (cards, accordion) but do not step. It would be presentation only — the definition would not move. |
| Look and theming | own CSS, Bootstrap themes | **have** | Four skins, under the rule that a skin may never change what a document says: the same form under two skins renders byte-identical markup. |
| Translations | core i18n, Enterprise dynamic translations | **have** | The definition carries no display text at all: the presentation carries codes and a catalogue, and the page answers in the negotiated language. |
| Accessibility | Enterprise ("automatic accessibility") | **have** | Not for sale and not switchable here: `aria-*`, choice groups as `radiogroup`, messages tied to controls, the caret moved to the first refusal, folded sections unfolded on the way. |
| Reader's own comfort settings | no equivalent | **have** | Colours, contrast and larger text, kept in that browser, painted by us rather than by the skin. |
| Offline mode | Enterprise | **gap** | Only half against our model — the draft exists; the browser-side queue does not. Large cost, narrow use. |

## Files and documents

| Feature | Form.io | Us | Note |
|---|---|---|---|
| Attachments | premium component; S3, Azure, URL, base64 | **have** | Directory or S3 by configuration, four facts measured by the server (the media type sniffed from the bytes, not claimed by the browser), `accept` and `maxSize` required and published. |
| Collecting what nobody saved | no equivalent | **have** | A file no stored document names has no download route at all, and `app:files:purge-temporary` removes what no save ever named. |
| PDF generation | their core business: a PDF server, a template designer, overlaying fields on an existing PDF, PDF translations, PDF Plus | **gap** | The single largest thing we do not have in any form, and the most common reason a company buys a platform like this. |
| Cryptographic e-signature of the data | Enterprise | **gap** | We have an immutable history and a confirmation with a subject, but nothing that can be shown to a third party. |
| Print / export one form | Enterprise print-to-PDF | **partial** | The page prints from a browser and that is the whole mechanism — no print stylesheet, no archival rendering. |

## Integrations and automation

Their word for it is **Actions**: any number per form, fired on save. Ours is nothing at all.

| Feature | Form.io | Us | Note |
|---|---|---|---|
| Webhook on save | Webhook action | **gap** | The most valuable missing piece after PDF. The domain events exist and are already persisted; what is missing is an outlet. Today the owning system has to poll us to learn that a form was confirmed. |
| E-mail | Email action, templates, SMTP/SendGrid | **not wanted** | Mail needs an identity and message templates, and we deliberately have neither. It is the owning system's job — for which it needs the webhook. |
| Ready-made integrations (Google Sheets, SQL, third parties) | core and Enterprise | **not wanted** | Every one is another reason for this service to know the outside world. A webhook serves all of them at one price. |
| Pre-filling | Auto Populate, Data Source | **have** | A form can be born a draft: values given at creation are its first save and pass the same gate. |
| Real-time data / change subscription | Enterprise | **gap** | The webhook gap seen from the other side. |
| MCP server, agent tooling | new offering | **partial** | We have no MCP server, but we do have what makes one worth writing: a generated OpenAPI document and meta-schemas at an address. |

## Access, security, tenancy

Read this table carefully. Form.io has a full permission system because it is a platform for
building applications. **We authorise nothing on purpose** — a gateway and a decision point owned
by the creating system do it, which is what [09](09-access.md) settled and what the four route
prefixes are for. But *the gateway does not exist yet*, and that, not any feature below, is the
largest deployment risk this service has.

| Feature | Form.io | Us | Note |
|---|---|---|---|
| Roles and permissions | core (simple) and Enterprise (roles, teams, field-level, value-based) | **not wanted** | Delegated by design: four prefixes, one per audience, with the form id always the segment after the prefix so a gateway rule is one line. |
| Authentication | core accounts; Enterprise OAuth, SAML, LDAP, JWT, 2FA, realms | **not wanted** | No accounts, no directory. The subject arrives in one header and is **recorded, never consulted**. |
| A gateway in front | built into their API server | **gap** | Today a form's UUID opens everything: whoever may fill it in may also delete it and fetch its files. Not a missing feature — a missing deployment — but nothing should be exposed without it. |
| Encrypted fields | Enterprise, up to end-to-end | **gap** | Values are stored as the exact JSON text that passed validation, which is also what is served; encrypting a member would need a design decision, not a switch. |
| Multi-tenancy, environments, promotion | Enterprise | **not wanted** | There is nothing to promote when a definition is not a template. Separating tenants separates instances, or is the gateway's business. |
| Deliberate anonymity | no equivalent — a submission has an owner | **have** | `anonymous` *discards* an asserted identity, so a proxy signing every request cannot build a record by accident. |

## Working with the data, and running it

| Feature | Form.io | Us | Note |
|---|---|---|---|
| Browsing submissions | Form Manager, tables, filters | **not wanted** | There is not even a list of forms here: one at a time, by id. A list would need ownership and permissions, which is precisely what we do not have. |
| CSV / JSON export | core | **not wanted** | Exporting a set of submissions means nothing when a submission is one document; reading the form through the API is the equivalent. |
| Reports and visualisation | Enterprise module | **not wanted** | A layer over many forms, which is the owning system's work. |
| Admin console | Enterprise developer portal, CORS UI, team management | **gap** | Everything goes through the API and console commands. Deliberate, but every deployment will want something to look into a form with. |
| Self-hosting | offered, ~$330 per month per environment for the Enterprise API server (their SaaS ~$300); the free core is copyleft OSL-3.0 | **have** | Our code, our licence, no per-environment fee and no vendor to depend on. This is the column that belongs in any cost conversation. |
| Quality regime | not comparable from outside | **have** | Worth putting on the balance: static analysis with no baseline, layer boundaries enforced by a tool, mutation testing of the model, a generated OpenAPI. Keeping eight item types costs a fraction of keeping thirty-three. |

## What is worth taking, in order

Ordered by what it opens over what it disturbs. The first three serve use cases we cannot serve
at all today; the rest are comfort.

**Since written, four of these are built**: the webhook (1) in [12](12-webhooks.md), four design
passes and rather more than "an outlet"; the conditional save (7) in
[14](14-conditional-writes.md), as `If-Match` on both write endpoints; the multiple choice (4) in
[15](15-multiple-choice.md), which was as cheap as this list said it would be; and the PDF (3) in
[16](16-the-record.md) — where the one sentence of this entry that proved wrong was "the page
already knows how to draw" it. The gateway that
["a permission system of our own"](#what-not-to-copy-and-why) delegates to has its recipe and a
runnable example in [09](09-access.md) — the deployment itself is still somebody's to do.

1. **A webhook on save and on confirmation.** The events exist, are past tense, and are already
   written; what is missing is an outlet. Without it, every owning system has to poll. Most value
   for the least movement in the model — and the design question is small and familiar: delivery
   is an adapter behind an application port, and a failed delivery must not fail the save.
2. **Conditional logic.** A form where half the questions depend on the first answer cannot be
   built here. One thing has to be settled before anything is written: a condition that changes
   the **rules** belongs to the definition (and must therefore be derivable into the published
   schema, which is where JSON Schema's `if`/`then` earns its keep), while one that only changes
   the **view** belongs to the presentation. Deciding that is the work; the rest follows.
3. **A PDF of a confirmed form.** Not their template designer — one road: a confirmed document to
   an archival PDF. The page already knows how to draw every value, list and attachment.
4. **Multiple choice as one item.** The cheapest entry on the list: a type with rules of its own
   (`options`, counting the ticks), two widgets, and no more pretending it is a collection.
5. **A multi-page wizard.** Presentation only — stepping and a progress bar over what both kits
   already draw.
6. **A handwritten signature.** A widget whose value is an ordinary file; the file mechanism
   carries it unchanged.
7. **Protection against a silent overwrite.** A conditional draft save — "I hold revision *n*;
   store this only if it is still the newest" — closing the one case where two people lose work.

## What not to copy, and why

- **Code inside a document.** Their expressions and JS validators are where their flexibility and
  their whole baggage come from: a protected evaluator, rules implemented twice, and a contract
  that cannot be published. Ours are declarative precisely because they are meant to be announced.
- **The form as a template for submissions.** It is the source of definition versioning, promotion
  between environments, and the question of which version an answer was given against. One
  immutable definition per document dissolves all three.
- **A permission system of our own.** Accounts, roles, teams and realms are a separate product
  inside the product. Delegating to a gateway and to the creating system's decision point is
  cheaper and more honest — on the one condition that the gateway is actually built.

## Caveats

- Their side is a vendor's own description, read on one day. The core/Enterprise split and the
  prices change without notice, and nothing here is good enough for a purchasing decision.
- Our side is the state of the repository, not a plan. Nothing in "what is worth taking" exists.
- The counts (33 components against 8 types) are deliberately annotated everywhere they appear:
  they measure different axes, and the bare numbers lead somewhere wrong.
