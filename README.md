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

Requests are mapped into **DTOs by ingot** before a controller runs: `#[MapRequest]` on a
controller argument hands off to `src/Http/Request/`, where the DTO's constructor *is* the
request contract — one declaration that validates the request, produces the error report,
and generates the published request schema (see [API contract](#api-contract)).

| Part | Mapping rules |
|---|---|
| JSON body | strict: real types, closed key set (an unexpected member is `mapping.unexpected_key`) |
| query string | lax: `"10"` becomes `10`, unknown parameters are ignored the way HTTP clients expect |

Rules a schema cannot express live next to the DTO as a `RequestRule` — e.g. "expireDate
must be in the future" (`form.expire_date.past`). The values payload of `PUT …/data` has no
DTO on purpose: its contract is the schema derived from that one form's definition.

| Method & path | Purpose |
|---|---|
| `POST /api/forms` | Create a form. Body: `{"expireDate": "<RFC 3339>", "definition": {…}}`. `201` + `Location`. |
| `GET /api/forms` | List non-expired forms (`?limit=` ≤ 200, `?offset=`). |
| `GET /api/forms/{id}` | Full envelope: definition, status, data, timestamps. |
| `DELETE /api/forms/{id}` | `204`. The "definition changed" path is delete + recreate. |
| `GET /api/forms/{id}/schema` | Derived JSON Schema of the form's *values* (`application/schema+json`). `?mode=draft` returns the relaxed variant. |
| `PUT /api/forms/{id}/data` | Save a draft (repeatable). `409 form-locked` once confirmed. |
| `POST /api/forms/{id}/confirm` | Strictly validate the stored data and lock the form. `409` when already confirmed or empty, `422` with the report when invalid. |
| `GET /api/forms/{id}/data` | The current values (`404 form-data-empty` when none). |

Error status map: `400` malformed JSON, `404` unknown form, `409` state conflicts,
`410` expired form (every endpoint), `422` validation reports, `500` opaque fallback.

## API contract

[`openapi.yaml`](openapi.yaml) (OpenAPI 3.1 — the same JSON Schema 2020-12 dialect ingot
emits) holds the paths and the prose. Request *shapes* are not written by hand: each
`x-ingot-schema` marker is replaced by the schema ingot generates from that request DTO
when `make docs` renders the document into [`docs/`](docs/):

| File | What it is |
|---|---|
| `docs/openapi.yaml` | the effective contract — hand-written paths + DTO-generated request schemas |
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
src/Infrastructure/   DBAL repository (postgres jsonb), PSR-6 schema cache
src/Http/             controllers + problem+json mapping
src/Http/Request/     request DTOs, #[MapRequest] and its resolver, semantic rules
src/Command/          app:forms:purge-expired
tools/build-docs.php  renders openapi.yaml into docs/ (dev tooling, not shipped)
```

Boundaries are enforced by deptrac (`Domain ← Infrastructure ← Http/Command`). The domain
layer depends only on `Ingot\*` and the `psr/cache` interface, so extracting it into a
reusable package later is a namespace move, not a rewrite.

Storage is a single `forms` table; the definition is stored **normalized**
(`TreeMapper::normalize()` output) in a `jsonb` column, and the listing reads the title
with `definition->>'title'` — no denormalized columns. Status is derived from the row
(`data IS NULL` / `confirmed_at`), never stored.

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
