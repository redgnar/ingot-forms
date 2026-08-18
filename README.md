# ingot-forms

Backend-only forms management service built on the [ingot](https://github.com/redgnar/ingot)
mapping engine. A **form is a single fillable document**: one JSON definition, one data set,
a required expiry date. Definition templates, versioning, and multi-submission forms are
deliberately out of scope for this MVP.

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
- Definitions may contain **unknown (plugin) field types** — they round-trip losslessly
  (`GenericField` + `#[Extras]`), can be drafted, but a form containing one can never be
  confirmed: the server refuses to vouch for a value contract it does not know.

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

Requests are mapped into **DTOs by Symfony** before a controller runs
(`#[MapRequestPayload]`, `#[MapQueryString]` over `src/Http/Request/`), and validated by
`symfony/validator`. Every DTO member is non-nullable, so an instance means a complete
request; what the mapper could not supply is reported at its pointer before validation
runs. Ids are `Uuid` value objects, request bodies are accepted as `application/json` and nothing
else (`415` otherwise), bodies are closed (an undeclared member is `request.unexpected_key`),
and query strings ignore unknown parameters the way HTTP clients expect. Members document themselves with swagger-php's `#[OA\Property]`
(description, example, type/format), which is what the published schema is generated
from.

**ingot validates the form definition** — meta-schema, typed tree, semantic rules — and
derives the per-form JSON Schema published to clients. **Submitted values pass two gates**
(`src/Http/Form/`), cheapest first:

1. the **derived schema**, cached per form and mode — the same document
   `GET /api/forms/{id}/schema` serves, so the server can never be looser than its own
   published contract. Findings carry `schema.*` codes.
2. the **Symfony form** built from that definition — text, select and number fields become
   the matching form types with their constraints, unknown (plugin) field types pass
   through untouched, and richer rules have somewhere to live as the field catalogue grows.
   Findings carry `form.value.*` codes.

Values refused by the schema never reach the form: on this project's example definition the
schema answers in ~60 µs where building and running the form costs ~670 µs, so a payload
that was never going to fit is rejected without that work.

Both meet Symfony validation through custom constraints
(`src/Http/Request/Constraint/`): `ValidFormDefinition` on the create DTO, and
`ValidFormValues`, which carries a form's definition and runs inside the row lock. Findings
keep their JSON Pointer, and `ViolationReportFactory` turns every violation back into the
same `errors[]` shape — so the error format never depends on which engine refused the
request. A test asserts the form and the published schema reach the same verdict, so the
contract clients validate against cannot drift from what the server enforces.

| Method & path | Purpose |
|---|---|
| `POST /api/forms` | Create a form. Body: `{"expireDate": "<RFC 3339>", "definition": {…}}`. `201` + `Location`. |
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

`tests/Http/OpenApiComplianceTest.php` validates **both halves of every exchange** against
`docs/openapi.yaml`: each request must match the operation it targets (or, when a scenario
deliberately breaks the contract, must be refused by it), each response must match the
documented status, and every documented operation + status needs a scenario. So a DTO, the
implementation, and the published document cannot drift apart in any direction.

The derived schema is what a future frontend validates against (Ajv/Zod) — the server
uses the exact same document, so the contract cannot drift.

## Architecture

```
src/Domain/Forms/     framework-free, storage-free — the future standalone package
                      (FormMapperFactory configures the mapper; DI injects it as a service)
src/Infrastructure/   Doctrine ORM entity + repository, PSR-6 schema cache
src/Http/             controllers + problem+json mapping
src/Http/Request/     request DTOs (Symfony-validated) + constraints bridging to the engines
src/Http/Form/        the form built from a definition — what validates submitted values
src/Command/          app:forms:purge-expired
tools/build-docs.php  renders openapi.yaml into docs/ (dev tooling, not shipped)
```

Boundaries are enforced by deptrac (`Domain ← Infrastructure ← Http/Command`). The domain
layer depends only on `Ingot\*` and the `psr/cache` interface, so extracting it into a
reusable package later is a namespace move, not a rewrite.

Storage is a single `forms` table behind one Doctrine entity, mapped with portable types
only (`uuid`, `text`, `datetime_immutable` in UTC) so the service installs on PostgreSQL,
MySQL/MariaDB or SQLite alike — point `DATABASE_URL` at it and run the migration, which is
built through Doctrine's schema API rather than raw SQL. The definition is stored
**normalized** (`TreeMapper::normalize()` output) as the exact JSON text that passed
validation, and so are the values: PHP arrays cannot tell an empty object from an empty
list, and those bytes are handed back to clients verbatim. Status is derived from the row
(`data IS NULL` / `confirmed_at`), never stored; state transitions run under
`LockMode::PESSIMISTIC_WRITE`.

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
| `make test-file FILE=…` | one file or directory: `make test-file FILE=tests/Http/FormApiTest.php` |
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
