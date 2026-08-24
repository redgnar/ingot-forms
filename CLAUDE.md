# ingot-forms — agent guide

Backend-only forms management service (Symfony 7.4 API, Doctrine ORM — portable across
database platforms)
built on the [ingot](https://github.com/redgnar/ingot) mapping engine. **What the code does now is described here and in `README.md`** — those two are kept current.
`.claude/plan/00-mvp.md` and `01-stage2.md` are the record of how it got here: the decisions,
their reasons, and what each stage changed, in the words of the time. Read them for *why*, not
for *what is*: paths, names and mechanisms in them have since moved on, and each stage's later
sections say where.

## Language

All code, comments, documentation, commit messages: **English**. Conversation with the user: Polish.

## Domain model (do not re-litigate)

A form is a **single fillable document**: one immutable definition + one data set + required
`expire_date`. A definition is a **tree**: a `collection` item declares items of its own, so its
value is an array of objects and an entry is a document answering them. It counts rather than
requires (`min`/`max`; `required` on a collection is refused, because an empty list would satisfy
it while answering nothing), `max` holds in both contracts and `min` only in strict, and a
collection owing entries is required of the values document. A collection may hold a collection, and both kits
draw that — a list inside the form of an entry, as deep as the definition goes. Lifecycle: empty → draft (`PUT …/data`, repeatable, lenient validation without
`required`) → confirmed (`POST …/confirm`, strict validation, locked forever). A form can be
**born a draft**: `data` in the creation request is its first draft, not a third document — the
aggregate's own `saveDraft()` judges it under the same lenient contract, the insert writes it
with the rest of the row, and a form that would refuse those values later is never created
holding them. Definition
change = delete + recreate. **Filling a form in through the API is the foundation** and the page
is a client of it — server-rendered interactivity (Live Components and the like) was weighed and
refused in this session, because it would be a second way in and would move rules the definition
owns into the presentation. Expired forms answer 410 everywhere; `app:forms:purge-expired`
deletes them physically. No templates, no versioning, no multi-submission, and no name
or id on the definition (it belongs to one form, which has a UUID of its own; nothing groups
or looks definitions up, so a second name would only be a label that can drift) —
deliberately.

**How history works.** Every accepted save is kept — and a save that stores what is already
stored is not one: the aggregate compares the documents (member order does not matter, entry
order does) and records nothing when they say the same thing, so putting back the version
somebody is already on is a no-op rather than a second identical moment. Otherwise:
`saveDraft()` already reports a `DraftSaved`
carrying the whole `Values`, so a revision is that event persisted rather than a second record of
anything — the row and the `form_revisions` row are written from the same event, and neither can
happen without the other. Append-only, `(form_id, seq)` is the whole key, `seq` is allocated under
the row lock the save already holds, and the history leaves with its form (rows before bytes) —
**by foreign key**, `ON DELETE CASCADE`, so a form can never outlive what it used to hold, not
even for the width of a crash between two statements. A history has an end:
`FORMS_HISTORY_LIMIT` says how many saves one form keeps and the oldest leaves as the newest
arrives, in the same statement, because `seq` only grows and "at or below newest minus the
limit" *is* the surplus. That is a deployment's number and not a document's: unbounded history
is unbounded cost on every question about what a form has **ever** named.
**Restoring is not an operation**: a client reads a revision (`GET …/history/{seq}`) and sends it
back through `PUT …/data`, where it meets the same three gates and is recorded as a *new* revision.
No `POST …/restore` — a privileged path would be a second way in, and an old document is not more
trustworthy for having been accepted once. Picking single members out of a revision is likewise
the client's business, which is why both pages can offer "put this answer back" with no new server
rule. Reading is a separate, read-only port (`FormHistory`), because `FormRepository` is a
collection of *forms* and a revision is not one.

**On a page, history is asked for like everything else.** `history` and `reset` are
`PresentationActions` beside `save` and `confirm` — so a document that does not ask for them draws
neither, and one that does decides where they sit. The panel lists the moments and nothing they
held: a value outside the form it belongs to says nothing, so looking at one is **a page**,
`/forms/{id}/versions/{seq}`, drawn from that save's document and read-only. That is what makes it
complete without a line of client code — every control, list and attached file is drawn by the
code that already draws the current version — and it is why the way out of it (putting that
version back) is an ordinary `PUT …/data` like every other write from a page. `reset` is the same
page drawn again with nothing sent. **"Who" is a stated non-goal**: this service has
no identity at all, so a revision answers *when* and *what* and nothing else; an actor column now
would be a member nobody can fill. When identity arrives it lands on that table without moving
anything else. Not learning who anybody is stays a non-goal; being
open does not. Today the form's UUID is the whole credential and **it opens everything** —
whoever can fill a form in can delete it, confirm it and download its files — which is the one
thing standing between this service and a deployment, and is written down where a deployer will
read it (`docs/architecture.md`, "Who may do what"). The shape of the fix is settled in
`.claude/plan/09-access.md`: split the addresses (`/api/manage/**` against
`/api/forms/{id}/**`), then one port, `Access::allows(Scope, ?FormId, Request)`, carrying the
scope *and* the form — because nobody has rights over this service, only over some forms — with
the deployment choosing the adapter. Whatever lands, it authorises **an object, not a person**:
a user provider or a role model here would be the second identity this service has always
refused.

**How a file works.** A `file` item's value is not bytes but the **description** of them —
`{id, name, size, type}`, all four measured by the server when the upload landed and echoed
back by the client verbatim. That is the whole design, and everything else follows: values stay
one JSON document, and the item's own rules stay *statable in the derived schema* (`maxSize` is
a maximum on `size`, `accept` an enum of `type`) instead of being enforced past the contract.
Several files is a `collection` holding a `file`; the counting was built once.

**The values document is the only index of a form's files** — no column, no `files` table,
because the values are what passed validation and what is served byte for byte, so a second
record would only be a copy that drifts. A file is *temporary* while no stored document names
it and *attached* the moment one does; nothing moves at that point, and a temporary file has no
download route, so an upload nobody saved is unreachable by construction. Every question about a
file — what may be downloaded, thrown away or collected — is asked of what the form has **ever**
named, its current document and every earlier save of it (`FormFiles`, cheap because the
definition is immutable, so every revision is read with the same one). What is not saved is
collected in two places — the page at once (`DELETE …/files/{id}`, refused for anything any save
names) and `app:files:purge-temporary` on a schedule (whatever no save ever named and has sat
longer than `FILES_TEMPORARY_DAYS`, plus directories whose row is already gone). **A save takes
nothing away**: a document somebody can put back is a document whose files still matter, so
replacing a file leaves the old one fetchable until its form goes. Deleting a form and
purging one both go **the row first, the bytes second**: the other way round can leave a live
form naming files that are gone, while a directory with no row is provably garbage and gets
collected. That is what closed the old worry about a purge having to succeed in two places.

Three exceptions this buys, each deliberate and each stated where it lives: the upload is the
one endpoint whose body is not JSON, the download is the one that does not answer with a
document, and `ReferencedFilesExist` is the one gate that is stricter than the published
contract — no schema can state "this id exists", any more than "this form has not expired",
and a client echoing the upload's answer can never trip it.

## Design principles

The shape of this codebase is **hexagonal architecture with DDD elements**: a model that
knows nothing about the outside, ports declaring what it needs, adapters filling them, and
use cases as the only entry into a transition. Read the layer map below as the concrete form
of that, not as a separate idea.

- **DDD**: an aggregate (`Form`) owns its invariants and transitions, value objects
  (`FormId`, `ExpireDate`, `Values`) carry the rules a primitive cannot, exceptions are the
  vocabulary of refusal, and a repository is an interface the domain declares — never a
  Doctrine detail leaking upwards. The ubiquitous language is what the code is named after.
- **DRY**: one place per rule. One error format and one mapping point, one derived schema
  serving both the published contract and the incoming check, one definition mapper as a
  service. A write never answers with the document a `GET` already serves — a second copy is
  a second truth.
- **YAGNI**: the domain model says no on purpose (no templates, no versioning, no
  multi-submission, no form list endpoint). Do not add a seam, an abstraction or a config
  knob for a case nobody asked for; add it when the second caller appears.
- **SOLID**: one action per endpoint and one `__invoke` per use case (S); the field catalogue
  grows by adding a variant, not by editing a switch (O); adapters are substitutable behind
  their port, which is what lets the unit suite run on fakes (L); ports stay narrow — a use
  case that needs a transaction does not receive an entity manager (I); every layer depends
  on interfaces it declares itself, and `services.yaml` is the only place an implementation
  is named (D).

## Architecture ground rules

The code is laid out in four layers, and the dependency arrows only ever point inwards:
**UserInterface → Application → Domain**, with **Infrastructure → Domain, Application**.
`deptrac.yaml` enforces exactly that; the UI may not name an adapter, ever.

```
src/Domain/Forms/          the model: Form (aggregate), FormStatus, DeriveMode, the definition
                           model and its processors. Framework-free and storage-free — the
                           future standalone package.
    Definition/            the field union and the meta-schema
    Event/                 what happened to a form: FormCreated, DraftSaved, FormConfirmed
    File/                  FileReferences — which files one document names, and where
    ValueObject/           FormId, ExpireDate, Values, Definition, FileId, FileDescriptor,
                           FileReference, MediaType
    Exception/             what the model refuses: DefinitionNotValid, ValuesNotValid,
                           FormNotFound, FormGone, FormLocked, FormAlreadyConfirmed,
                           FormHasNoData
    Port/                  FormRepository, ValuesValidator, DefinitionParser — what the
                           model needs from the outside to keep its own rules
src/Application/Forms/
    UseCase/               one class per thing the system does, each with a single __invoke:
                           CreateForm, SaveFormData, ConfirmForm, DeleteForm, ReadForm,
                           UploadFormFile, ReadFormFile, DiscardFormFile, ReadFormHistory,
                           PurgeExpiredForms, PurgeTemporaryFiles. This is where a transaction
                           is opened and where the order of steps lives.
    File/                  IncomingFile, FileStream, CollectedFiles — an upload on its way
                           in, an open file on its way out, and what a collector took —
                           plus FormFiles: which files a form has ever named
    History/               FormRevision — one accepted save, as something to choose by
    Port/                  Transactions, DataSchemas, FileStore, FormHistory — what a use
                           case needs and cannot do itself
src/Infrastructure/        the adapters filling those ports
    Persistence/           FormRecord and FormRevisionRecord (the rows, mapped with ORM
                           attributes), DoctrineFormRepository, DoctrineFormHistory,
                           DoctrineTransactions
    Cache/                 CachedDataSchemaProvider
    Files/                 FlysystemFileStore — keys, the sidecar of facts, sniffing, deletes
    Validation/            the schema gate, the Symfony form, the reference gate and the
                           staged validator
src/UserInterface/
    Api/Action/            one invokable class per endpoint, suffixed Action
    Api/Request|Problem/   request DTOs, problem+json mapping
    Web/                   the pages that draw a form: an action, a renderer per
                           engine, PresentedNodes (which resolves the tree they all
                           draw) with Node/ holding that tree's types, and the
                           templates each draws with (assets/ holds what a kit's page
                           imports in the browser)
    Cli/                   console commands
```

Rules that follow from it, and that the tooling checks:

- **The web pages are a second adapter, not a second way in.** `src/UserInterface/Web/` draws a
  form for a person: it reads through the same use case the JSON endpoints use, and what
  somebody types goes back through the API from the browser. No domain logic lives there, no
  endpoint of its own writes anything, and nothing under `/api` renders. The two sides even
  report failures differently — `problem+json` is the API's contract, a page answers with a page
  (`_errors: html` on the web routes is what says which) — because a browser is no client of RFC
  9457. What a person is told about their own doing — that a draft was stored, that a form is
  closed, that something went wrong — is the page's own text, and it lives in
  `translations/messages.*.yaml` under `page.*`, resolved in the language the request
  negotiated. A presentation describes the form, not the chrome around it, so no document
  carries a code for a sentence the adapter invented — and no template or controller holds
  that sentence either: a template asks the catalogue (`|trans`), and what the browser needs
  is handed to it as a value (`data-form-refused-value`, `data-refused`). Two catalogues, and
  the split is the point: the form's text is the author's, these words are ours.
- **A skin is how a page looks; it may never change what a document may say.** The engine
  declares its skins beside its widgets (`skins()`), a presentation may name one (`skin`, judged
  at `/skin`: `presentation.skin.unknown`, or `presentation.skin.unsupported` for a kit that has
  none), and `FORMS_SKIN` dresses whatever names nothing — document wins. Four ship for
  `bootstrap`: `default`, `material`, `flatly`, `lux`, one vendored stylesheet each behind one
  entrypoint each, so exactly one Bootstrap is ever loaded. The invariant is tested: **the same
  form under two skins renders byte-identical markup**. A skin needing a class or an element has
  become a second kit and must be one.
- **How a page *reads* is the reader's; where the switches sit is the document's.** Colours
  (system/light/dark), high contrast and larger text are attributes on `<html>`, applied before
  the first paint by inline script and kept in that browser's `localStorage` — never on the
  server, which has no identity to hang them on. Each is a plain on/off, folded away behind one
  summary; a document may say which way the colours *start* (`theme`), and is answered after the
  reader and after their machine. A document places them with the `comfort` widget and cannot
  remove them: a page whose document places none draws them at the top
  (`PresentedNodes::draws()` is what the renderer asks). Beside it, `language` offers the same
  page in every catalogue the document carries, each named in its own catalogue, and draws
  nothing when there is only one. Both are `decorations` — they stand alone and say nothing
  about the form — and both are marked as detours, so unsaved answers travel with the reader. Contrast is **not** a skin but an overlay on top
  of whichever one the document chose: an accessibility preference outranks an aesthetic one.
  Both kits offer all three: the richer one as a bar of buttons driven by a Stimulus
  controller, the plain one as radios and checkboxes its own module reads — "no machinery"
  always meant no framework, and that kit has had a hand-written module since it was born. With
  nothing chosen both fall back to `prefers-color-scheme`, `prefers-contrast` and
  `prefers-reduced-motion`. **Dark and high contrast are painted by us, not by the skin**:
  Bootswatch themes support dark unevenly and repaint buttons with literal colours, so chasing
  each theme's exceptions is endless — the skin keeps its shapes and fonts, the reader gets a
  page they can read.
- **A kit is two halves in two layers**: `PresentationEngine` in the domain says what can be
  drawn (that is what a presentation is judged against), `FormRenderer` in the web layer draws
  it. HTML never reaches the domain, and the vocabulary never leaves it. Two kits ship:
  `core-html` (plain controls, no machinery at all, one hand-written module in `public/js/`)
  and `bootstrap` (Bootstrap 5 with `card`/`accordion`/`row`, `autocomplete`, `range`,
  `stepper`, `radio-buttons`; behaviour in Stimulus controllers under
  `assets/controllers/`, delivered by AssetMapper). What is shared is the *resolved tree* — `PresentedNodes::of()`
  answers with `list<PresentedNode>`, and there are three: `ValueNode` (an item and its answer),
  `CollectionNode` (a list, its entries and a blank one), `BranchNode` (everything presenting no
  value). Three types rather than one bag with the members of all three, so code walking the tree
  asks the type instead of checking whether a member is there; `kind` rides along only because a
  template cannot ask `instanceof`. A new member of a node goes on the one type that has it — and a markup convention — `data-name`/`data-type` on the thing holding a
  value, `data-error` where a refusal goes, and for a list **structure carries identity**
  (`data-collection`, `data-entry`, `data-cell`): values are collected scope by scope in the
  order entries appear, so adding or removing one renumbers nothing, and a pointer is resolved
  by walking that same structure down. A blank entry is markup the server rendered, waiting in
  a `<template>` — a kit never builds markup in JavaScript — and it carries
  `PresentedNodes::PENDING` where an entry's scope would be, which a page replaces when it
  clones one. **An id and a radio group are scoped by entry** (`item-lines-1-sku`,
  `unit--lines-1`): the same form is drawn once per entry, so without that a label points at
  another entry's control and radios of different entries become one group. Behaviour is
  delegated rather than bound per list, because a list can arrive inside a cloned entry. What is
  never shared is the machinery: adding a control to a kit never means touching the other one. A widget a kit adds must be a different way of **asking**,
  never a second name for a control the other kit already draws — and never a restyling of one
  (a floating label was tried and removed: it moved the same question's text, and it could not
  be applied to a choice group or a slider, so every page mixing them was labelled two ways).
- **A page that cannot be looked at still has to work, and that is not an option a document
  asks for.** Both kits do all of it: `aria-required` says an answer is owed (the star is marked
  decoration — read out it is punctuation inside the question), `aria-describedby` ties the hint
  and the refusal line to the control while both are still empty, a choice group is a
  `radiogroup` named by its caption (a caption pointing at no control is a question nobody
  hears), a refused control carries `aria-invalid`, and **the caret moves** to the first refused
  answer — or to the button that adds an entry when it is the list that owes one. An upload's
  progress is a number to read as well as a bar to see, and a new entry takes the caret only when
  a person asked for it (`event.isTrusted`), because a document being put back asked for nothing.
  Cloning an entry rewrites every reference along with the ids and the radio group: a caption or
  a message is a name too.
- **A skin's literal colours beat an indirection.** Overriding `--bs-*` is not enough on its
  own: Bootswatch repaints `color` and `background-color` outright hundreds of rules later, and
  its dark rules are `[data-bs-theme=dark] .btn-…` — one specificity point above a bare class.
  So a legibility rule states the property as well as the variable, and is written twice (bare,
  and prefixed with `[data-bs-theme]`) to tie on specificity and win on order, ours being the
  last stylesheet on the page. And **check it in a browser**: a computed style read straight
  after flipping an attribute can be stale, so a screenshot is the ground truth rather than
  `getComputedStyle`.
- **A message nobody can see is not a message.** An entry is answered in a form folded away
  under its row, so placing a refusal is not enough: both kits unfold every form on the way to
  it and mark each entry it is inside (`entry-invalid` in the plain kit, `table-danger` in the
  richer one), so the row still says "look here" after somebody folds it back up. Clearing the
  messages clears the marks.
- **Front-end assets are AssetMapper's, and only where a kit asked for them.** `importmap.php`
  names what the browser may import, `assets/vendor/` is committed (so a clone, CI and the
  browser suite need no network), `make assets` refreshes it and a deploy runs
  `asset-map:compile`. Icons are UX Icons imported into `assets/icons/` with
  `make console CMD="ux:icons:import tabler:…"` — in the repository, never fetched at runtime. No build step, no package manager, no CDN — and no new write path: a
  Stimulus controller is still nothing but a client of `/api`.
- **A controller is one action.** `#[Route]` + `#[OA\…]` + `__invoke()` in a class named after
  what it does (`SaveFormDataAction`), so a class only injects what that one endpoint needs.
  Never group endpoints to share a constructor.
- **The user interface talks to use cases, never to a repository, a cache or an entity
  manager**, and it never mutates an aggregate. Its job is HTTP: map the request onto a use
  case, map a refusal onto a status.
- **Ports are declared where they are needed and implemented outward.** The domain declares
  what the model needs to keep its own rules (`FormRepository`, `ValuesValidator`,
  `DefinitionParser`); the application declares what a use case needs (`Transactions`,
  `DataSchemas`); `services.yaml` binds each interface to its adapter. Nothing above
  Infrastructure names an implementation class.
- **The domain speaks in value objects, not primitives**: `FormId` instead of a raw uuid,
  `ExpireDate` (UTC, and `future()` cannot be constructed in the past), `Values` (a JSON
  object, keeping `{}` distinct from `[]` and handing out the exact text that was validated).
  A new concept that has an invariant gets a value object, not a `string`.
- **The aggregate owns its transitions and refuses what breaks them.** `saveDraft()` and
  `confirm()` throw `FormLocked`, `FormAlreadyConfirmed`, `FormHasNoData` themselves, and
  neither stores anything that does not fit the form's own definition: the validator is
  handed in as an argument (the verdict needs machinery the model does not carry), but which
  contract applies — lenient while filling in, strict at confirmation — is the form's own
  business. A caller cannot skip that check, which is the point: it is an invariant, not a
  courtesy. The definition travels as `Definition`, which holds both shapes it is needed in
  and never one without the other: the normalized document that is stored and served byte for
  byte, and the structure a rule can be asked about. One cannot be held unproved — it is
  built either from a structure the mapper just accepted (`FormDefinitionProcessor::document`)
  or from a stored document read back through that same mapper (`Definition::stored`), and
  the reading back happens there and then — a definition is whole from the moment it exists,
  with no deferred work hidden behind an accessor.
- **The aggregate is not an entity.** Doctrine maps `FormRecord` — a row with public fields,
  ORM attributes, no behaviour and no idea a form exists — and never the model. Both
  directions of the translation live in `DoctrineFormRepository`, and `Form::fromState()`
  restores what was read without judging or recording anything, because reading is not
  something that happens to a form. That costs a mapping in both directions and buys a model
  with no mapping, no constructor to bypass and no fix-up after a read;
  `testEveryPieceOfAFormSurvivesTheRoundTrip` drives a form through storage and back so a
  half-mapped field is caught here rather than in production.
- **The port stays a collection** (`add`, `get`, `getForUpdate`, `save(Form)`, `remove`,
  `purgeExpired`), and every method that writes names the form it writes.
- **Transitions record what happened, and the write follows the record.** Each one appends a
  `FormEvent` — past tense, the moment it happened at, and whatever it changed (`DraftSaved`
  carries its `Values`); `releaseEvents()` hands them over and forgets them. `save()` applies
  them onto the row instead of copying state across, so a column changes because something
  happened, and a `match` without a default means a transition nobody taught the adapter
  about stops the write rather than vanishing from it. An insert is the exception: a new row
  is written whole. A refused transition records nothing.
- **Exceptions live in `Exception/` next to the layer that raises them**, carry the id they
  are about, and say nothing about HTTP. Which status a refusal deserves is decided in
  `ProblemExceptionListener` (or in an action, where the same state means different things —
  no data is 404 on a read, 409 on a confirm).
- **One error format**: every error response is RFC 9457 `application/problem+json`; validation
  problems carry `errors: [{pointer, code, message, input?}]` mapped 1:1 from ingot's
  `ErrorReport` (`ProblemExceptionListener` is the single mapping point).
- **A finding points at the thing that is wrong**, never at what surrounds it: a missing answer
  is `/email` and not `''`, `/lines/1/sku` and not `/lines/1` — one finding per missing member,
  so a page can mark the control instead of saying the document is incomplete. Anything that
  reports per object (JSON Schema's `required` does) is unpacked in ingot's
  `OpisSchemaValidator`, which is also where `additionalProperties` is unpacked the same way.
- **Bytes live beside the form, never in it.** `FileStore` is an **application** port (the
  model has rules about a *reference*, never about storage), filled by one adapter over
  `league/flysystem` — a directory in dev/test/CI, S3 in production, by configuration. Every
  operation is scoped to a form and no path crosses the port, so ownership is structural: a
  file cannot be asked for without saying which form it belongs to. Two objects per file
  (`{formId}/{fileId}` and `{fileId}.json`), written bytes-then-facts and deleted the other
  way round, so a half-finished operation leaves something **invisible** rather than wrong —
  `describe()` answers only for a file whose halves are both there and agree. Nothing but
  `ReadFormFileAction` ever reaches the bytes: no presigned URL, no public directory, always
  `attachment` + `nosniff`, always streamed. A delete never happens inside a transaction that
  writes a column, but a delete of a *temporary* file does take the form's **row lock** — the
  lock is ordering, not atomicity, and it is what turns the reference gate from a check into a
  guarantee: without it a file could vanish between the gate accepting it and the commit
  naming it.
- **Requests arrive as DTOs**: actions take `#[MapRequestPayload]` / `#[MapQueryString]`
  arguments from `src/UserInterface/Api/Request/`, validated by `symfony/validator` — never
  read `Request` directly, never hand-roll envelope validation. Every DTO member is
  non-nullable, so an instance means a complete request. Bodies are JSON only
  (`acceptFormat: 'json'` → 415 otherwise, the upload being the one exception — bytes are not a
  JSON document, and it takes `#[MapUploadedFile]`, which is the same rule in the shape a file
  arrives in) and closed (`ALLOW_EXTRA_ATTRIBUTES => false` →
  `request.unexpected_key`); query strings ignore unknown parameters. A DTO documents itself
  with swagger-php's `#[OA\Property]`, and NelmioApiDocBundle turns routes + DTOs +
  `#[OA\Response]` into the published contract (`make docs`).
- **How a form is shown is a second document, given with the first and just as immutable.**
  A presentation arrives with `POST /api/forms`, optional, names the definition's items, and
  never changes afterwards: a fixed thing has no reason for its description to drift, and
  changing either document means delete and recreate. It is one recursive shape (an item
  presents a value, or holds items, or stands alone — no fixed levels), its text is translation
  codes with an optional catalogue, and the widget vocabulary belongs to the engine the
  document names — a `PresentationEngine` implementation, so a new kit is a new class rather
  than an edited table. The `Form` constructor judges it against the definition it came with — the
  rules handed in the way a values validator is — so a form that exists is a form whose
  presentation fits it: every declared item shown, exactly once, with a control that engine
  draws, and at least one `confirm` trigger — where it goes is the document's business, leaving
  it out would only make the page unfinishable. An item a client fills in rather than a person is drawn `hidden`, which says so
  instead of leaving it out.
- **Text a person reads is a code, and codes are members, not options.** `label`, `hint`,
  `placeholder` and `choices` are translation codes held to the default catalogue by `codes()`;
  `options` is where things nobody translates live (`rows`, `width`, `open`, `tone`, `align`,
  `appearance`). A new piece of *text* goes beside the first four, never into `options`.
  (Twig aside, learnt twice in one session: a `{# … #}` comment cannot sit **inside** a
  `{% … %}` tag — it parses as code and the template dies with "Unexpected character".)
- **How a choice reads is the presentation's, not the definition's.** An item's `options` are
  the values a client may send; `choices` on the presented item maps each of them to a
  translation code, so `pl` can read *Polska* without the definition ever carrying display
  text. All or nothing: once a document words one option it words them all
  (`presentation.choice.missing`), a value the item does not offer is
  `presentation.choice.unknown`, and only an item that offers a choice may carry `choices` at
  all. The codes join `codes()`, so the default-locale catalogue is held to them like every
  label.
- **Every rule about a definition or a presentation is a rule about one scope.** A form
  declares items; so does every collection in it. Names are unique among items declared
  together, an unknown type is refused wherever it sits, a schema is derived for an entry by
  the same code that derives it for the whole document (`DataSchemaDeriver::objectSchema()`),
  and a presentation's "exists / shown once / shown at all" are asked again inside every list.
  Findings point where the mistake is — `/items/1/items/0/name` in a definition,
  `/lines/2/quantity` in values. Adding a rule means asking where its scope is before asking
  anything else.
- **A definition says what is asked, never how it looks.** There is no presentation in it:
  `textarea` is one way to show a text item, `radio` one way to show a select, and both are
  the client's business. So an item type is added when it brings **rules of its own** — a date
  has a shape and a period, a checkbox has a value that must be exactly `true` — and never
  when it would only tell a frontend which widget to draw. A type with no rules of its own is
  a second name for one we already have, and every rule it copies is a rule that can drift.
- **A contract stated once is a contract that has to be reachable.** The rules of a definition
  and of a presentation live in their meta-schemas (`MetaSchema` says where) and are deliberately
  not duplicated into the OpenAPI document — so the API serves them, at
  `GET /api/schemas/{definition|presentation}`, byte for byte from the files the mapper enforces.
  Naming a path under `src/` in a published description is naming an address the reader does not
  have.
- **ingot owns the form definition** — meta-schema, typed tree, semantic rules — and derives
  the per-form JSON Schema. When a rule cannot be expressed in that schema, the schema is
  where to fix it: a date range is published and enforced as `formatMinimum` /
  `formatMaximum` (ingot's own keywords, spelled the way ajv-formats spells them) rather than
  enforced past the contract in the form stage. It receives decoded structures (`Source::array()`), never JSON
  text. The definition mapper is a service (`FormMapperFactory` → `forms.definition_mapper`);
  never rebuild a mapper inside a class that uses it.
- **Submitted values pass two gates, cheapest first**: the derived schema (cached per form and
  mode, ~10× cheaper) answers first, so the server can never be looser than its published
  contract; the Symfony form built from the definition then adds what a schema cannot say.
  Keep that order.
- **A cached artifact is only as current as the code that derived it.** `cache.data_schema`
  keys on the form's UUID and the mode, `cache.ingot_mapper` on class names — neither key
  says a word about the rules behind the entry, so a changed rule leaves both serving
  yesterday's document. Change what a definition derives and `make cache-clear` is part of
  the change, not an afterthought; a deploy runs the same command. In dev both pools are
  in-memory (`when@dev` in `config/packages/framework.yaml`), because a clear that has to be
  remembered while developing is a clear that will be forgotten. **A changed constructor is a
  changed mapper**: adding a member to `PresentationDocument` or to a field class leaves the
  test suite mapping documents without it until the pool is cleared, because a class whose
  constructor grew has exactly the name it had yesterday.
- **A use case orchestrates, it does not decide.** State transitions run inside
  `Transactions::run()` + `FormRepository::getForUpdate()`, so the state the form checks
  cannot change between the check and the write — but what may happen is the aggregate's
  call, and a use case that re-checks a rule the model already keeps has duplicated it.
- **A write never answers with the thing it wrote** — that is what `GET` is for, and a
  second copy is a second truth. `PUT …/data` and `POST …/confirm` return `204 No Content`
  (`422` with the report when refused); `POST /api/forms` returns `201` with `{"id": …}` and
  a `Location` header, because the id is the only part the client could not already know. An
  upload answers with the whole description for the same reason: the bytes are what was
  written, and the id plus the three facts the server measured are exactly what the client
  could not have known — echoing them back into the values is the mechanism itself.
  The same holds one layer down: a use case that creates or changes something returns `void`
  or an identity, never the aggregate.
- **Persistence stays platform-neutral**: portable Doctrine types only (`uuid`, `text`,
  `datetime_immutable` in UTC) on `FormRecord`, both documents stored as the exact JSON text
  that passed validation, migrations built through the schema API rather than raw SQL.
- **ingot is consumed via a composer path repository** (`../ingot`, sibling checkout,
  mounted at `/ingot` in Docker so the relative symlink resolves). After pulling new ingot
  commits `make install` is enough — a symlink has nothing to fetch; `make update` is for
  moving the other dependencies, and it rewrites the committed lock. When ingot reaches Packagist: switch to a version constraint,
  delete the `repositories` block.
  - **A change spanning both repositories goes to ingot first.** Locally the two are always
    in step — the symlink points at your checkout — so a mismatch is invisible until CI,
    which clones `redgnar/ingot` at `main`. Push the library, wait for it to land, then push
    the application; otherwise the workflow builds new expectations against the old library
    and fails for a reason nothing in the diff explains.

## Testing (PHPUnit)

- Every functionality gets a test; bodies follow **GIVEN / WHEN / THEN** comments; method
  names describe behavior; error-path tests assert JSON Pointer + error code.
- Suites: `unit` (tests/Domain, tests/Application — no kernel, no DB), `integration`
  (tests/Infrastructure, tests/UserInterface — real compose Postgres, per-test rollback via
  dama/doctrine-test-bundle) and `browser` (tests/Browser — Panther driving headless Chromium
  against a server it starts). Infection runs the unit suite over `src/Domain/`, so a rule that
  belongs to the model has to be pinned there to count.
- **Some assertions are only possible in a browser.** A server-side crawler sees `<template>`
  content as ordinary nodes; a browser does not, which is what makes "every name this page
  points at exists" a real test there and a tautology here. Cloning bugs — ids, radio groups,
  `aria-*` references — belong in the browser battery for that reason.
- **A browser test sets up through the API, never the database.** The browser talks to a
  separate server process, so a fixture written inside the test's transaction is invisible to
  it — and going through the API is what makes the test take the same path a person does.
  Assertions wait for state (`eventually`) rather than assuming a click has landed.
- **The item catalogue is tested by a battery, one class per type.** A new kind of item gets
  two subclasses and inherits everything else: `tests/Domain/Forms/Definition/Field/…Test`
  (which option combinations a definition may and may not carry, what the item contributes to
  the strict and draft schema, and that every option survives being stored) and
  `tests/Infrastructure/Validation/Field/…ValuesTest` (a table of values with the pointer and
  code each must produce, judged through the whole staged validator). The values table is
  also what proves the form never refuses what the published schema accepts — the server may
  not be stricter than its own contract; the other direction is fine, since the schema runs
  first and is what clients were told. Test what a type accepts on **both** sides of every
  boundary: the refused side alone leaves the limit itself unpinned.
- Tests mirror `src/` under `tests/`, layer for layer. Use cases are tested against in-memory
  fakes of their ports (`tests/Application/Forms/Fake/`) — no kernel, no database — so what
  they check is the orchestration: the transaction, the locked read, the order of steps.

## Quality gates (all must pass before any commit)

**Hard rule: run `make ci` before declaring any task done; a task with a red `make ci` is
not finished.** The one exception is a change that touches **only Markdown or comments** —
nothing in the chain reads `docs/`, `README.md` or a comment, so running it there spends
minutes to prove nothing. A change set that mixes prose with code is a code change. Every tool runs through a make target — never call phpunit, phpstan,
composer or `bin/console` directly, and never on the host. If an isolated run has no
target, add one here rather than reaching for a raw command.

Local PHP is 8.1 — all tools run inside the pinned Docker image (`docker/Dockerfile`,
`php:8.4-cli-alpine`); never on the host. `docker compose up -d` serves the API on :8000
(dev server); `docker compose run` is what the Makefile and PhpStorm use.

| Command | What it does |
|---|---|
| `make install` / `make update` | composer install/update (Docker) |
| `make migrate` / `make db-test` | migrations for dev / test database |
| `make cache-clear` | drop the derived pools (data schemas, mapper metadata) — after a rules change |
| `make storage-clean` | empty the file store (dev and test): bytes only, forms keep their references |
| `make assets` | download the vendor JavaScript/CSS named in `importmap.php` into `assets/vendor/` |
| `make test` / `make test-unit` | full PHPUnit (starts postgres) / fast domain-only loop |
| `make test-integration` | Http + Infrastructure suite only |
| `make test-filter FILTER=…` / `make test-file FILE=…` | one test (or group) / one file or directory |
| `make lint` | `php -l` over every PHP file, in the pinned image |
| `make console CMD="…"` | any `bin/console` command inside the container |
| `make schema DEFINITION=…` / `make check-values DEFINITION=… VALUES=…` | derive the values schema from a definition file / validate a values file against it, no database involved |
| `make mutation` | Infection over `src/Domain/` only (unit suite, no DB), minMsi 90 / minCoveredMsi 100 |
| `make openapi` | validate `openapi.yaml` (OpenAPI 3.1) |
| `make docs` | render `openapi.yaml` → `docs/openapi.yaml` + `docs/api.md` (DTO schemas injected); `docs/` is generated, never edit it by hand |
| `make stan` | PHPStan level `max` + strict rules — zero errors, no baseline |
| `make cs` / `make cs-fix` | php-cs-fixer check / apply |
| `make deptrac` | layer boundaries |
| `make audit` | composer audit |
| `make ci` | everything CI runs |

PHPStan: no baseline, no `@phpstan-ignore` unless truly unavoidable (then explain why).
Formatting is php-cs-fixer's job; every PHP file starts with `declare(strict_types=1);`.

## Where documentation goes

Four places, and putting something in the wrong one is how two of them start disagreeing:

| File | Holds | Written by |
|---|---|---|
| `README.md` | what this service is, the domain model, setup, and links to the rest | hand |
| `docs/configuring-forms.md` | everything somebody needs to **describe a form**: item types, widgets per engine, skins, files, lists, history, every refusal code, a worked example | hand |
| `docs/kits.md` | what each kit draws, control by control: markup, what the definition contributes, options, Bootstrap links | hand |
| `docs/architecture.md` | everything somebody needs to **work on the service**: layers, the model, storage, the gates, the pages and their markup conventions, operations, testing | hand |
| `docs/api.md`, `docs/openapi.yaml` | the endpoint reference | `make docs` — **never by hand** |

So: a new item type, a new refusal code or a new skin → `configuring-forms.md`, and a new
**widget** → `kits.md` as well, which is the one document that claims to list them all. A new
port, adapter, cache, command or env var → `architecture.md`. A rule the model keeps → this file
*and* whichever of the two describes it to its reader. The README grows a line only when the
thing is one of the first five facts about the project.

## Repo conventions

- **Never commit or push on your own initiative** — finish with a green `make ci`, report,
  and leave git to the user unless explicitly asked in the current conversation.
- **Never add `Co-Authored-By` (or similar) trailers** to commit messages.
- The remote is GitHub (`gh` CLI is fine for this repo).
- **`composer.lock` is committed.** Without it every install resolves afresh, and anything
  arriving transitively is free to jump a major — `symfony/cache` reached 8.0 next to a
  framework pinned to `^7.4` and turned CI red while three local configurations stayed green.
  With the lock, CI installs the set that was tested. `make install` obeys it; `make update`
  is the deliberate act of moving it, and the new lock goes in the same commit as whatever
  needed it. A path repository holds no version to lock, so pulling new ingot commits needs no
  lock change — the symlink already points at the checkout.
- Do not commit `vendor/`, `var/`, caches, `config/reference.php`, `.idea/`
  (see `.gitignore`).
- **`.env` is not in the repository; `.env.dist` is.** Every variable is documented there with a
  value a development machine can use, and **nothing in it may be a real secret** — Symfony falls
  back to `.env.dist` when `.env` is missing, so a clone runs either way, and `make env` writes
  the local copy with an `APP_SECRET` of its own. A new variable is added to `.env.dist` (and to
  `docs/architecture.md`, which is where operations reads); adding it only to your own `.env` is
  how the next person finds out it exists by reading a stack trace.
