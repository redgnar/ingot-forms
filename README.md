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
  "decide"; a consent means "agree", and that is published as `const: true`.

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
make install    # build the image + composer install
make migrate    # apply migrations to the dev database (starts postgres)
make ci         # validate + cs + openapi + docs + stan + deptrac + test + mutation + audit
```

Serve locally (dev only): `docker compose up -d` → API at `http://localhost:8000`
(the `php` service runs PHP's built-in dev server; `docker compose run` invocations
from the Makefile and PhpStorm override that command, so tooling is unaffected).

## API

All request/response bodies are `application/json`; every error is an RFC 9457
`application/problem+json` document. Validation problems carry an `errors` array with one
`{pointer, code, message, input?}` entry per finding — the same format for schema,
type-mapping, and semantic errors (it comes straight from ingot's `ErrorReport`).

Requests are mapped into **DTOs by Symfony** before an action runs
(`#[MapRequestPayload]`, `#[MapQueryString]` over `src/UserInterface/Http/Request/`), and validated by
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
   published contract. Findings carry `schema.*` codes.
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
(`src/UserInterface/Http/Request/Constraint/ValidFormDefinition`) on the create DTO; values
are checked by the aggregate itself — `Form::saveDraft()` and `Form::confirm()` judge them
through the `ValuesValidator` port before anything is stored, so unfit values are refused by
the model rather than by whoever remembered to ask. Findings keep their JSON Pointer, and `ViolationReportFactory` turns every violation back into the
same `errors[]` shape — so the error format never depends on which engine refused the
request. A test asserts the form and the published schema reach the same verdict, so the
contract clients validate against cannot drift from what the server enforces.

| Method & path | Purpose |
|---|---|
| `POST /api/forms` | Create a form. Body: `{"expireDate": "<RFC 3339>", "definition": {…}}`. `201` + `Location`, answering with `{"id": …}` alone. |
| `GET /api/forms/{id}` | Full envelope: definition, status, data, timestamps. |
| `DELETE /api/forms/{id}` | `204`. The "definition changed" path is delete + recreate. |
| `GET /api/forms/{id}/schema` | Derived JSON Schema of the form's *values* (`application/schema+json`). `?mode=draft` returns the relaxed variant. |
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

`tests/UserInterface/Http/OpenApiComplianceTest.php` validates **both halves of every exchange** against
`docs/openapi.yaml`: each request must match the operation it targets (or, when a scenario
deliberately breaks the contract, must be refused by it), each response must match the
documented status, and every documented operation + status needs a scenario. So a DTO, the
implementation, and the published document cannot drift apart in any direction.

The derived schema is what a future frontend validates against (Ajv/Zod) — the server
uses the exact same document, so the contract cannot drift.

## Architecture

```
src/Domain/Forms/          the model — aggregate, value objects, events, exceptions, and the
                           ports it declares. Framework-free and storage-free.
src/Application/Forms/     use cases (one class, one __invoke) and the ports they need
src/Infrastructure/        the adapters: the row, its Doctrine mapping and the repository
                           that translates both ways, the schema cache, the validation stages
src/UserInterface/Http/    one invokable Action per endpoint, request DTOs, problem+json
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

- **Deploy:** clear the ingot mapper metadata pool — its keys derive from class names
  only: `bin/console cache:pool:clear cache.ingot_mapper`. The derived-schema pool
  (`cache.data_schema`) never goes stale (definitions are immutable, UUIDs never reused).
- **Cron:** `bin/console app:forms:purge-expired` — expired forms are already invisible
  to the API (410); this fulfils the promise that expired data leaves the system.

## Development

| Command | What it does |
|---|---|
| `make install` / `make update` | composer install/update (Docker, PHP 8.4) |
| `make migrate` / `make db-test` | migrations for the dev / test database |
| `make test` / `make test-unit` | full PHPUnit (needs postgres) / fast domain-only loop |
| `make test-integration` | Http + Infrastructure suite only |
| `make test-filter FILTER=…` | one test or a group: `make test-filter FILTER=FormApiTest::testSaveDraft` |
| `make test-file FILE=…` | one file or directory: `make test-file FILE=tests/UserInterface/Http/FormApiTest.php` |
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
