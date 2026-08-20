# ingot-forms

Backend-only forms management service built on the [ingot](https://github.com/redgnar/ingot)
mapping engine. A **form is a single fillable document**: one JSON definition, one data set,
a required expiry date. Definition templates, versioning, multi-submission forms and file
uploads are deliberately out of scope for this MVP — see `CLAUDE.md` for why a file item is a
different kind of problem, and the shape it would take if it arrives.

## Domain model

- **One form = one definition + one data set.** No versions, no submission collections.
- **The definition is immutable.** To change it, delete the form and create a new one.
- **Data lifecycle: `empty → draft → confirmed`.** Saving a draft (`PUT …/data`) is
  repeatable and validates values leniently (types, enums, ranges, and the closed property
  set are enforced; required fields are not — partial progress is storable). Confirming
  (`POST …/confirm`) validates the stored data against the full strict contract and locks
  the form for good.
- **A form may be born a draft.** Values a client already knows arrive as `data` in the
  creation request, and they are not a third document or a new state: they are the form's
  first draft, saved by the same transition every later one goes through, judged under the
  same lenient contract, and refused *before* the form exists — a form is never created
  holding something it would not accept later. Findings are rooted at `/data`.
- **`expire_date` is required.** Past it, the form answers `410 Gone` everywhere, and
  `bin/console app:forms:purge-expired` (run it from cron) physically deletes the row.
- **The definition holds no display text.** No item label, no form title: what a question
  reads like, and in which language, belongs to whatever draws the form. The definition says
  what is asked (`name`, `type`) and what an answer must satisfy — a client keys its own copy
  by the item's name.
- Definitions may contain **unknown (plugin) item types** — they round-trip losslessly
  (`GenericField` + `#[Extras]`), can be drafted, but a form containing one can never be
  confirmed: the server refuses to vouch for a value contract it does not know.

## Item catalogue

Every item declares a `name` and may declare `required` (which only bites at confirmation).
An item type exists here because it brings rules of its own — never to tell a frontend which
widget to draw.

| `type` | value on the wire | its own options |
|---|---|---|
| `text` | JSON string (non-empty when required) | `maxLength`, `pattern` |
| `select` | one of the declared options | `options` — at least one, no repeats |
| `number` | JSON number | `min`, `max`, `decimals` |
| `date` | `YYYY-MM-DD`, a day that exists | `min`, `max` — calendar dates, `min` no later than `max` |
| `checkbox` | JSON boolean | `mustBeChecked` |
| anything else | whatever it came as | the plugin's own keys, kept in `extras` |

Three of those say something worth spelling out:

- **`decimals` bounds precision.** `0` means whole numbers and is published as JSON Schema's
  `integer`; `2` is money, published as the step every value must land on (`multipleOf: 0.01`).
  Without it, any number goes.
- **A date range is published, not just enforced.** `formatMinimum` / `formatMaximum` are the
  keywords ajv-formats uses, and ingot implements them, because standard JSON Schema cannot
  bound a string in time — so the range is checked against the same document a client
  validates against, not somewhere behind it.
- **`mustBeChecked` is not `required`.** For a box, `false` is an answer, so `required` means
  "decide"; a consent means "agree", and that is published as `const: true` — **in the strict
  contract only**. Having to agree is something finishing the form requires, like `required`
  itself, so a draft still holds a consent nobody has given yet; otherwise "save for later"
  would refuse the very state it exists for.

## Presentation

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
  form with a choice group or a slider would end up labelled two ways at once.

The plain controls are deliberately the same names in both; everything the richer kit adds is a
way of asking the other has no markup for. So a document written for one is *refused* by the
other rather than half-drawn, which is what naming the engine at the top of the document buys.
An engine this application does not know is accepted with its widgets unchecked — the bargain a
plugin item type gets, and for the same reason.

**Saving and confirming are items too.** `save` and `confirm` are placed wherever the document
wants them, labelled by a code like everything else, and drawn as a button or — with
`options.appearance: link` — as a link. Those names are not a kit's to invent: they say what a
form does, so a kit declares how it draws them, not whether they exist. **At least one `confirm`
is required**, because where the trigger goes is a design decision and leaving it out is not one:
the page would be unfinishable. Nothing is added at the bottom of the page by the renderer.

**What the server enforces**: the form is shown whole — every item the definition declares
appears, exactly once, and a value a client fills in rather than a person is drawn `hidden`,
which is a decision written down instead of an omission; an item that presents a value holds
nothing; a widget is one the engine draws for that item, or one it can nest with, or one that
stands alone; and a carried catalogue names a default locale that is complete. Other locales may lag behind — that is how translating goes — and codes nobody uses
are fine. Findings carry `presentation.*` codes and pointers into the document as sent
(`/items/0/items/1/name`).

**What it deliberately is not**: no styling, no conditional visibility (that changes what an
answer must satisfy, so it belongs to the definition), and no way to change it afterwards,
because the description of a fixed thing has no reason to drift.

## Requirements

- Docker (all tools run inside the pinned `php:8.4-cli-alpine` image — the host PHP is never used)
- The `ingot` library checked out **next to this project** (`../ingot`) — it is consumed
  through a composer `path` repository; `vendor/ingot/ingot` is a relative symlink that
  resolves on the host and inside the container (mounted at `/ingot`). If the symlink ever
  breaks in your setup, switch the repository option to `"symlink": false` and run
  `make update` after every library change. After pulling new ingot commits, run
  `make update` to refresh the pinned reference. Once ingot is published to Packagist,
  replace `"dev-main"` with a version constraint and delete the `repositories` block.

## Setup

```bash
make setup      # image, dependencies, both schemas, and the stack serving on :8000
make ci         # validate + cs + openapi + docs + stan + deptrac + test + mutation + audit
```

`make setup` is the whole bootstrap for a fresh checkout; it refuses early with an explanation
if the `ingot` library is not checked out next to this project, which is the one thing it cannot
do for you. Afterwards `make up` and `make down` start and stop the stack — the database volume
survives a `down`, so the data is still there next time.

The `php` service runs PHP's built-in dev server; `docker compose run` invocations from the
Makefile and PhpStorm override that command, so tooling never conflicts with it.

## API

All request/response bodies are `application/json`; every error is an RFC 9457
`application/problem+json` document. Validation problems carry an `errors` array with one
`{pointer, code, message, input?}` entry per finding — the same format for schema,
type-mapping, and semantic errors (it comes straight from ingot's `ErrorReport`).

Requests are mapped into **DTOs by Symfony** before an action runs
(`#[MapRequestPayload]`, `#[MapQueryString]` over `src/UserInterface/Api/Request/`), and validated by
`symfony/validator`. Every DTO member is non-nullable, so an instance means a complete
request; what the mapper could not supply is reported at its pointer before validation
runs. Ids are `Uuid` value objects, request bodies are accepted as `application/json` and nothing
else (`415` otherwise), bodies are closed (an undeclared member is `request.unexpected_key`),
and query strings ignore unknown parameters the way HTTP clients expect. Members document themselves with swagger-php's `#[OA\Property]`
(description, example, type/format), which is what the published schema is generated
from.

**ingot validates the form definition** — meta-schema, typed tree, semantic rules — and
derives the per-form JSON Schema published to clients. **Submitted values pass two gates**
(`src/Infrastructure/Validation/`), cheapest first:

1. the **derived schema**, cached per form and mode — the same document
   `GET /api/forms/{id}/schema` serves, so the server can never be looser than its own
   published contract. Findings carry `schema.*` codes. The cache holds something this code
   derived, so it is thrown away whenever those rules change (`make cache-clear`); in dev it
   is in-memory and never outlives the process.
2. the **Symfony form** built from that definition — every item type becomes the matching
   form type, unknown (plugin) types pass through untouched, and rules a schema cannot state
   have somewhere to live as the catalogue grows. Findings carry `form.value.*` codes. Today
   the schema says everything the catalogue can say, so this stage rarely speaks; the battery
   in `tests/Infrastructure/Validation/Field/` proves it never refuses what the schema
   accepts.

Values refused by the schema never reach the form: on this project's example definition the
schema answers in ~60 µs where building and running the form costs ~670 µs, so a payload
that was never going to fit is rejected without that work.

The definition meets Symfony validation through a custom constraint
(`src/UserInterface/Api/Request/Constraint/ValidFormDefinition`) on the create DTO; values
are checked by the aggregate itself — `Form::saveDraft()` and `Form::confirm()` judge them
through the `ValuesValidator` port before anything is stored, so unfit values are refused by
the model rather than by whoever remembered to ask. Findings keep their JSON Pointer, and `ViolationReportFactory` turns every violation back into the
same `errors[]` shape — so the error format never depends on which engine refused the
request. A test asserts the form and the published schema reach the same verdict, so the
contract clients validate against cannot drift from what the server enforces.

| Method & path | Purpose |
|---|---|
| `POST /api/forms` | Create a form. Body: `{"expireDate": "<RFC 3339>", "definition": {…}, "presentation": {…}?, "data": {…}?}`. `201` + `Location`, answering with `{"id": …}` alone. |
| `GET /api/forms/{id}` | Full envelope: definition, status, data, timestamps. |
| `DELETE /api/forms/{id}` | `204`. The "definition changed" path is delete + recreate. |
| `GET /api/forms/{id}/schema` | Derived JSON Schema of the form's *values* (`application/schema+json`). `?mode=draft` returns the relaxed variant. |
| `GET /api/forms/{id}/presentation` | How the form is shown, as it was given at creation (`404 presentation-not-set` when none). |
| `PUT /api/forms/{id}/data` | Save a draft (repeatable). `204`, `409 form-locked` once confirmed. |
| `POST /api/forms/{id}/confirm` | Strictly validate the stored data and lock the form. `204`; `409` when already confirmed or empty, `422` with the report when invalid. |
| `GET /api/forms/{id}/data` | The current values (`404 form-data-empty` when none). |

Writes answer with a status, not a copy: `PUT …/data` and `POST …/confirm` return `204 No
Content` (or `422` with the report), because the client already knows the values it sent —
read the form if you need its new state.

Error status map: `400` malformed JSON, `404` unknown form, `409` state conflicts,
`204` a write that succeeded, `410` expired form (every endpoint), `415` a request body that
is not `application/json`,
`422` validation reports, `500` opaque fallback.

## API contract

The contract is generated from the code by **NelmioApiDocBundle**: routes, the request DTOs
behind `#[MapRequestPayload]`/`#[MapQueryString]` (with their `Assert` constraints and
`#[OA\Property]` prose) and the `#[OA\Response]` attributes on the controllers. What cannot
come from a route — the document's identity and the shapes shared across operations
(`Problem`, `FormEnvelope`, the reusable 400/404/410 responses) — lives in
`config/packages/nelmio_api_doc.yaml`. `make docs` dumps it all into [`docs/`](docs/):

| File | What it is |
|---|---|
| `docs/openapi.yaml` | the contract, dumped by `bin/console nelmio:apidoc:dump` |
| `docs/api.md` | browsable Markdown reference rendered from the same document |

`tests/UserInterface/Api/OpenApiComplianceTest.php` validates **both halves of every exchange** against
`docs/openapi.yaml`: each request must match the operation it targets (or, when a scenario
deliberately breaks the contract, must be refused by it), each response must match the
documented status, and every documented operation + status needs a scenario. So a DTO, the
implementation, and the published document cannot drift apart in any direction.

The derived schema is what a future frontend validates against (Ajv/Zod) — the server
uses the exact same document, so the contract cannot drift.

## The page

Besides answering with documents, the service can **draw a form for a person**:

```
GET /forms/{id}            the form, drawn by the kit its presentation names
GET /{_locale}/forms/{id}  the same, in a language the URL pins
```

Deliberately **not** under `/api`. Everything there speaks JSON and reports problems as RFC 9457
documents; a page for a person is a different contract, so it lives at a different root, answers
failures as pages, and is absent from the published API document.

**It is a client of the API, not a second way in.** The page reads through the same use case the
JSON endpoints use, and what somebody types goes back through `PUT /api/forms/{id}/data` and
`POST /api/forms/{id}/confirm` — from the browser, with a small module and no build step. There
is no privileged path: whatever the page can do, any client can. A stored draft is confirmed by a
notice on the page, which lasts exactly as long as it is true: the next attempt clears it, and so
does the first thing somebody changes afterwards.

**What the page says in its own name is translated by the application**, not by the document: a
stored draft, a closed form, a refusal with nothing to point at, and the error pages all come
from `translations/messages.*.yaml` under `page.*` keys, in the language the request negotiated.
So there are two catalogues on purpose — the presentation carries the *form's* text as codes,
because that is the author's to write, and this one carries the sentences this application
invented. Neither template holds a sentence, and the browser gets the one it needs handed to it
(`data-form-refused-value`, `data-refused`) rather than hardcoded in a controller.

**The language is the framework's negotiation**: `_locale` in the URL wins, `Accept-Language`
decides otherwise, `default_locale` has the last word, and the response varies on that header. A
code missing from the chosen language falls back to that language without its region, then to
the document's `defaultLocale`, then to the code itself — visible rather than blank.

**Read this before exposing it.** This service has no authentication of any kind, so the page is
protected by exactly what protects the API: the network it sits behind. It exposes nothing new —
whoever can reach the port can already `GET /api/forms/{id}/data` — but it does make that
comfortable in a browser, which is a good reason to keep the boundary honest.

A presentation written for a kit this deployment cannot draw answers `409`; a form nobody
described, `404`. Adding a kit is adding a `PresentationEngine` (what it can draw) and a
`FormRenderer` (how) — everything else is shared: `PresentedNodes` resolves the presentation and
the definition into the tree both templates draw, so a second kit costs a class, a template and
a stylesheet rather than a second understanding of what a form is. The pages are driven in a
real browser by the `browser` test suite, so every kit gets the same proof.

**Front-end machinery, where a kit wants it.** `core-html` deliberately has none. `bootstrap`
uses **AssetMapper**, **Stimulus** and **UX Icons**: `importmap.php` names what the browser may
import, `assets/vendor/` holds those files and `assets/icons/` the icons (both committed, so a
clone, CI and the browser tests need no network), `assets/controllers/` holds the behaviour,
`assets/styles/` the handful of rules that are the kit's own (an icon's size, mostly — UX Icons
renders an SVG with no width or height on purpose), and `make assets` refreshes the vendor
files. No build step and no package manager. What the two kits share is a *convention*, not an
implementation: `data-name` and `data-type` say which item a control holds and what it is on the
wire, `data-error` marks where a refusal about it goes.

## Architecture

```
src/Domain/Forms/          the model — aggregate, value objects, events, exceptions, and the
                           ports it declares. Framework-free and storage-free.
src/Application/Forms/     use cases (one class, one __invoke) and the ports they need
src/Infrastructure/        the adapters: the row, its Doctrine mapping and the repository
                           that translates both ways, the schema cache, the validation stages
src/UserInterface/Api/     one invokable Action per endpoint, request DTOs, problem+json
src/UserInterface/Web/     the pages that draw a form: an action, a renderer per engine, and
                           the templates each draws with
templates/, public/js/     what those pages are made of — markup and one small module
src/UserInterface/Cli/     console commands
tools/build-docs.php       renders the generated contract into docs/ (dev tooling)
```

Dependencies point inwards only — **UserInterface → Application → Domain**, with
**Infrastructure → Domain, Application** — and `deptrac.yaml` fails the build otherwise. An
action never touches a repository, a cache or an entity manager: it maps a request onto a use
case and a refusal onto a status.

The domain layer depends only on `Ingot\*`, `psr/cache` and `symfony/uid` (a value-object
library, not the framework) — no ORM, no attributes, no configuration — so extracting it into
a reusable package later is a namespace move, not a rewrite.

**A form judges what it is asked to hold.** `Form::saveDraft()` and `Form::confirm()` run
the values past the `ValuesValidator` port before storing anything, and the form itself picks
the contract that applies — lenient while filling in, strict at confirmation. Nothing can
store values that do not fit a definition by forgetting to ask; the same methods refuse a
confirmed form (`FormLocked`), a second confirmation and an empty one. A use case is left
with when it happens: one transaction, a locked read, a write.

**Each transition records what happened** (`FormCreated`, `DraftSaved` — which carries the
values it stored — `FormConfirmed`), and `releaseEvents()` hands them over. That is what the
repository writes from: a column changes because something happened, not because a copying
routine remembered it, and a transition no adapter knows how to store stops the write instead
of vanishing from it.

**The aggregate is not a Doctrine entity.** Doctrine maps `FormRecord` — a row with public
fields, ORM attributes and no idea a form exists — and `DoctrineFormRepository` translates
both ways. So the model has no mapping, no constructor to bypass and nothing done to it after
a read, which is what let `Definition` become a value that carries both the stored document
and the structure parsed from it.

Storage is a single `forms` table, mapped with portable types only (`uuid`, `text`,
`datetime_immutable` in UTC) so the service installs on PostgreSQL, MySQL/MariaDB or SQLite
alike — point `DATABASE_URL` at it and run the migration, which is built through Doctrine's
schema API rather than raw SQL. The definition is stored **normalized**
(`TreeMapper::normalize()` output) as the exact JSON text that passed validation, and so are
the values: PHP arrays cannot tell an empty object from an empty list, and those bytes are
handed back to clients verbatim. Status is derived from the row (`data IS NULL` /
`confirmed_at`), never stored; state transitions run under `LockMode::PESSIMISTIC_WRITE`.

## Operations notes

- **Deploy:** clear the pools that hold what this code derived — `bin/console
  cache:pool:clear --all`, which is what `make cache-clear` runs. Neither pool's key says
  anything about the rules behind the entry: `cache.ingot_mapper` keys on class names, and
  `cache.data_schema` on the form UUID and the mode. Both entries stay right for as long as
  the code that produced them does, and a tightened or relaxed rule that is not followed by a
  clear is served from yesterday's document. In dev both pools are in-memory for exactly that
  reason.
- **Deploy (pages):** `bin/console asset-map:compile` writes the mapped assets into `public/`
  for the web server to serve directly. Only the `bootstrap` kit's pages need it; the API does
  not, and in dev and test they are served by the framework.
- **Cron:** `bin/console app:forms:purge-expired` — expired forms are already invisible
  to the API (410); this fulfils the promise that expired data leaves the system.

## Development

| Command | What it does |
|---|---|
| `make install` / `make update` | composer install/update (Docker, PHP 8.4) |
| `make migrate` / `make db-test` | migrations for the dev / test database |
| `make cache-clear` | throw away what this code derived (data schemas, mapper metadata) |
| `make assets` | download the vendor JavaScript/CSS named in `importmap.php` |
| `make test` / `make test-unit` | full PHPUnit (needs postgres) / fast domain-only loop |
| `make test-integration` | Http + Infrastructure suite only |
| `make test-filter FILTER=…` | one test or a group: `make test-filter FILTER=FormApiTest::testSaveDraft` |
| `make test-file FILE=…` | one file or directory: `make test-file FILE=tests/UserInterface/Api/FormApiTest.php` |
| `make schema DEFINITION=…` | print the values schema a definition derives (`MODE=draft` for the relaxed one) |
| `make check-values DEFINITION=… VALUES=…` | would the API take this JSON? validates it against that schema |
| `make lint` | `php -l` over every PHP file, in the pinned image |
| `make console CMD="…"` | any `bin/console` command inside the container |
| `make mutation` | Infection over `src/Domain/` (unit suite, no DB), MSI thresholds enforced |
| `make openapi` | validate `openapi.yaml` against the OpenAPI 3.1 schema |
| `make docs` | render `openapi.yaml` into `docs/` (DTO schemas injected, output validated) |
| `make stan` | PHPStan, level `max` + strict rules — zero errors, no baseline |
| `make cs` / `make cs-fix` | php-cs-fixer check / apply |
| `make deptrac` | layer boundaries per `deptrac.yaml` |
| `make audit` | `composer audit` |
| `make ci` | everything CI runs |

Tests follow the GIVEN / WHEN / THEN structure; integration suites run against the real
compose postgres with per-test rollback (dama/doctrine-test-bundle).

PhpStorm: Settings → PHP → CLI Interpreter → From Docker Compose → service `php`, then
PHPUnit by Remote Interpreter with `/app/vendor/autoload.php`.
