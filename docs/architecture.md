# How it is built

The technical half: how the code is arranged, why it is arranged that way, what runs at deploy
time and what the tooling checks. If you are describing a form rather than maintaining the
service, [configuring-forms.md](configuring-forms.md) is the document you want.

## Contents

- [Layers](#layers)
- [The model, and what it refuses](#the-model-and-what-it-refuses)
- [Storage](#storage)
- [How values are judged](#how-values-are-judged)
- [The published contract](#the-published-contract)
- [The pages](#the-pages)
- [Where the bytes live](#where-the-bytes-live)
- [Operations](#operations)
- [Development](#development)
- [Testing](#testing)

## Layers

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

## The model, and what it refuses

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

## Storage

Storage is a single `forms` table, mapped with portable types only (`uuid`, `text`,
`datetime_immutable` in UTC) so the service installs on PostgreSQL, MySQL/MariaDB or SQLite
alike — point `DATABASE_URL` at it and run the migration, which is built through Doctrine's
schema API rather than raw SQL. The definition is stored **normalized**
(`TreeMapper::normalize()` output) as the exact JSON text that passed validation, and so are
the values: PHP arrays cannot tell an empty object from an empty list, and those bytes are
handed back to clients verbatim. Status is derived from the row (`data IS NULL` /
`confirmed_at`), never stored; state transitions run under `LockMode::PESSIMISTIC_WRITE`.

## How values are judged

**ingot validates the form definition** — meta-schema, typed tree, semantic rules — and
derives the per-form JSON Schema published to clients. **Submitted values pass three gates**
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

3. the **referenced files** (`ReferencedFilesExist`) — for every `file` position the values
   answer, the store must hold that id under this form, described exactly as the store
   describes it. Findings carry `form.file.unknown` and `form.file.mismatch`, pointed at the
   member that is wrong (`/invoice/id`, `/attachments/0/scan/size`). This is the **one place
   the server is stricter than its published contract**, and it is deliberate: no schema can
   state "this id exists", any more than it can state "this form has not expired". A client
   that echoes the upload's answer back verbatim can never trip it.

Values refused by the schema never reach the form: on this project's example definition the
schema answers in ~60 µs where building and running the form costs ~670 µs, so a payload
that was never going to fit is rejected without that work. The store is asked last, so
nothing pays for that gate until the document is otherwise perfect.

The definition meets Symfony validation through a custom constraint
(`src/UserInterface/Api/Request/Constraint/ValidFormDefinition`) on the create DTO; values
are checked by the aggregate itself — `Form::saveDraft()` and `Form::confirm()` judge them
through the `ValuesValidator` port before anything is stored, so unfit values are refused by
the model rather than by whoever remembered to ask. Findings keep their JSON Pointer, and `ViolationReportFactory` turns every violation back into the
same `errors[]` shape — so the error format never depends on which engine refused the
request. A test asserts the form and the published schema reach the same verdict, so the
contract clients validate against cannot drift from what the server enforces.

Requests are mapped into **DTOs by Symfony** before an action runs
(`#[MapRequestPayload]`, `#[MapQueryString]` over `src/UserInterface/Api/Request/`), and validated by
`symfony/validator`. Every DTO member is non-nullable, so an instance means a complete
request; what the mapper could not supply is reported at its pointer before validation
runs. Ids are `Uuid` value objects, request bodies are accepted as `application/json` and nothing
else (`415` otherwise), bodies are closed (an undeclared member is `request.unexpected_key`),
and query strings ignore unknown parameters the way HTTP clients expect. Members document themselves with swagger-php's `#[OA\Property]`
(description, example, type/format), which is what the published schema is generated
from.

## The published contract

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

## The pages

```
GET /forms/{id}                    the form, drawn by the kit its presentation names
GET /{_locale}/forms/{id}          the same, in a language the URL pins
GET /forms/{id}/versions/{seq}     an earlier save of it, read-only
```

Deliberately **not** under `/api`. Everything there speaks JSON and reports problems as RFC 9457
documents; a page for a person is a different contract, so it lives at a different root, answers
failures as pages (`_errors: html` on the web routes says which), and is absent from the
published API document.

**A page is a client of the API, not a second way in.** It reads through the same use case the
JSON endpoints use, and what somebody types goes back through `PUT /api/forms/{id}/data` and
`POST /api/forms/{id}/confirm` from the browser. There is no privileged path: whatever a page
can do, any client can.

**A kit is two halves in two layers.** `PresentationEngine` in the domain says what can be drawn
— that is what a presentation is judged against — and `FormRenderer` in the web layer draws it.
HTML never reaches the domain and the widget vocabulary never leaves it, which is what makes
adding a kit a class plus a template rather than a second understanding of what a form is.

What the two kits share is the **resolved tree** (`PresentedNodes`) and a markup convention:

| Attribute | Means |
|---|---|
| `data-name`, `data-type` | this element holds the value of that item, and what it is on the wire (`json` for a file description) |
| `data-error="name"` | where a refusal about that item goes |
| `data-collection`, `data-entry`, `data-cell` | a list, one entry of it, one previewed value |
| `data-item`, `data-choice` | one presented item; a group of radios rather than a single control |
| `PresentedNodes::PENDING` | the token a blank entry carries where its own scope would be |

**Structure carries identity.** Values are collected scope by scope in the order entries appear,
so adding or removing one renumbers nothing, and a pointer like `/lines/1/quantity` is resolved
by walking that same structure back down. A blank entry is markup the server rendered, waiting
in a `<template>` — a kit never builds markup in JavaScript — and cloning one replaces the token
in every `id`, `for`, `name`, `aria-labelledby` and `aria-describedby` it carries, because all
five are names and pointing at another entry's is how a question comes to be read out twice.

**A message nobody can see is not a message.** A refusal about an entry unfolds every form on
the way to it, marks each entry it is inside so the row still says "look here" once folded back
up, sets `aria-invalid` on the control, and moves the caret there.

**Two catalogues, on purpose.** The presentation carries the *form's* text as translation codes,
because that is the author's to write; `translations/messages.*.yaml` under `page.*` carries the
sentences this application invented — a stored draft, a closed form, the words on the reader's
own switches. No template holds a sentence, and what the browser needs is handed to it as a
value (`data-form-refused-value`, `data-refused`).

**Front-end assets are AssetMapper's.** `importmap.php` names what the browser may import,
`assets/vendor/` is committed (so a clone, CI and the browser suite need no network),
`assets/icons/` holds UX Icons imported once, and `make assets` refreshes the vendor files. No
build step, no package manager, no CDN. The `bootstrap` kit has one entrypoint per skin, each
importing its own Bootstrap and then the shared `kit.js`, so exactly one Bootstrap is ever
loaded. `core-html` imports nothing: one hand-written module in `public/js/` and thirty lines of
inline CSS.

**The reader's own switches** (colours, contrast, text size) are attributes on `<html>`, applied
before the first paint by a scrap of inline script and kept in `localStorage` under
`ingot-forms:*`. Nothing reaches the server — there is no identity here to hang a preference on.
Dark and high contrast are painted by the application's own stylesheet rather than left to a
skin: Bootswatch themes support dark unevenly, and chasing each theme's exceptions is endless,
so the skin keeps its shapes and fonts while the page a reader needs belongs to the reader.

## Where the bytes live

**The store** is `league/flysystem` behind one port (`FileStore`): a directory in development,
test and CI, S3 (or anything Flysystem speaks) in production, by configuration rather than by
code. Two objects per file, under the form that owns it — `{formId}/{fileId}` for the bytes and
`{fileId}.json` for the facts — because per-object metadata is the one thing Flysystem cannot
offer portably, and a second file is the same thing everywhere. Writing goes bytes first,
facts second; deleting goes the other way round; and a file whose halves disagree is reported
as *absent*, so anything half-written or half-deleted is invisible rather than wrong.
Ownership is not a column but the location: nothing can be asked for a file without being told
which form it belongs to.

**Nothing but this API ever reaches the bytes.** No presigned URLs, no public directory, and
the store lives outside `public/`. Downloads are always `attachment` with `nosniff`, because
bytes a stranger uploaded, served from this application's own origin, are an XSS vector the
moment a browser decides to render them. The bytes are streamed, so a large file costs a
socket rather than a request's worth of memory.

## Operations

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
  to the API (410); this fulfils the promise that expired data leaves the system. Next to it,
  `bin/console app:files:purge-temporary` collects uploads no stored document names
  (`--days`, `--limit`); what it prints is meant to stay near zero, so a number that keeps
  growing is worth an alert rather than a log line.
- **History:** `form_revisions` grows by one row per accepted save, each holding that save's
  whole values document. Bounded by the form's expire date and by nothing else — there is no cap,
  deliberately, because a cap cuts history off for exactly the forms that were edited most. Size
  it from how often forms are saved rather than from how many exist.
- **Files:** `FILES_DIR` (default `var/storage/files`) is where the bytes go, so it has to
  survive a deploy — a volume, not a container layer. A directory is **one node**: more than
  one instance needs a shared volume, or `composer require league/flysystem-async-aws-s3` and
  four lines in `config/packages/flysystem.yaml`. Nothing in the application changes for that,
  because the facts about a file live in a file of their own rather than in a store's own
  metadata. `FILES_MAX_UPLOAD` (10 MiB) is the deployment's ceiling and must stay at or below
  what `docker/php.ini` allows (`upload_max_filesize`, `post_max_size` — 16 MiB) **and** at or
  above the largest `maxSize` any definition served here asks for: `maxSize` is the published
  contract, and a limit no client was told about is a limit that breaks somebody's upload.
  `FILES_PER_FORM` (50) bounds what one form can hold, and `FILES_TEMPORARY_DAYS` (7) is how
  long a file nobody saved may sit. `make storage-clean` empties the dev and test stores.

Retention does not change: revisions live under the same `expire_date` and leave with the form,
rows before bytes. More copies of the same personal data inside the same window — worth knowing
when sizing a database, not a new promise.

## Development

| Command | What it does |
|---|---|
| `make install` / `make update` | composer install from the committed lock / move it deliberately (Docker, PHP 8.4) |
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

## Testing

Three suites. **unit** (`tests/Domain`, `tests/Application`) runs on fakes with no kernel and no
database, and Infection runs over `src/Domain/` against it — so a rule that belongs to the model
has to be pinned there to count. **integration** (`tests/Infrastructure`, `tests/UserInterface`)
runs against the compose postgres with per-test rollback (dama/doctrine-test-bundle). **browser**
(`tests/Browser`) drives headless Chromium through Panther against a server it starts.

Every test body follows GIVEN / WHEN / THEN and the method name says the behaviour rather than
the method under test. Three habits are worth copying:

- **A browser test sets up through the API, never the database** — the browser talks to a
  separate process, so a fixture written inside the test's transaction is invisible to it, and
  going through the API is what makes the test take the same path a person does. Assertions wait
  for state (`eventually`) rather than assuming a click has landed.
- **The item catalogue is tested by a battery**, one class per type on each side of the boundary:
  what a definition may carry, and a table of values with the pointer and code each must produce.
  That table is also what proves the server never refuses what its published schema accepts.
- **`OpenApiComplianceTest` checks both halves of every exchange** against `docs/openapi.yaml`, and
  fails when a documented operation has no scenario — so the DTOs, the code and the contract
  cannot drift apart in any direction.

PhpStorm: Settings → PHP → CLI Interpreter → From Docker Compose → service `php`, then
PHPUnit by Remote Interpreter with `/app/vendor/autoload.php`.
