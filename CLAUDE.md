# ingot-forms — agent guide

Backend-only forms management service (Symfony 7.4 API, Doctrine ORM — portable across
database platforms)
built on the [ingot](https://github.com/redgnar/ingot) mapping engine. Design docs live in
`.claude/plan/` — read `00-mvp.md` (domain model + as-built architecture) before touching
anything; `01-stage2.md` documents stage 2 (implemented: CI workflow, mutation testing,
OpenAPI contract + compliance tests).

## Language

All code, comments, documentation, commit messages: **English**. Conversation with the user: Polish.

## Domain model (do not re-litigate)

A form is a **single fillable document**: one immutable definition + one data set + required
`expire_date`. Lifecycle: empty → draft (`PUT …/data`, repeatable, lenient validation without
`required`) → confirmed (`POST …/confirm`, strict validation, locked forever). Definition
change = delete + recreate. Expired forms answer 410 everywhere; `app:forms:purge-expired`
deletes them physically. No templates, no versioning, no multi-submission — deliberately.

## Architecture ground rules

- **`src/Domain/Forms/` stays framework-free and storage-free** (only `Ingot\*` and the
  `psr/cache` interface) — it is the future standalone package. Deptrac enforces
  Domain ← Infrastructure ← Http/Command (`deptrac.yaml`).
- **The definition mapper is a service, not a private detail**: `FormMapperFactory` (domain,
  framework-free) holds its configuration — meta-schema, uniqueness rule, plugin-field
  fallback — and services.yaml registers the built `TreeMapper` as `forms.definition_mapper`,
  injected into consumers. Never rebuild a mapper inside a class that uses it.
- **ingot is consumed via a composer path repository** (`../ingot`, sibling checkout,
  mounted at `/ingot` in Docker so the relative symlink resolves). After pulling new ingot
  commits run `make update`. When ingot reaches Packagist: switch to a version constraint,
  delete the `repositories` block.
  - **A change spanning both repositories goes to ingot first.** Locally the two are always
    in step — the symlink points at your checkout — so a mismatch is invisible until CI,
    which clones `redgnar/ingot` at `main`. Push the library, wait for it to land, then push
    the application; otherwise the workflow builds new expectations against the old library
    and fails for a reason nothing in the diff explains.
- **One error format**: every error response is RFC 9457 `application/problem+json`; validation
  problems carry `errors: [{pointer, code, message, input?}]` mapped 1:1 from ingot's
  `ErrorReport` (`ProblemExceptionListener` is the single mapping point).
- **Two engines, one boundary between them**:
  - **Symfony owns the request envelope.** Controllers take `#[MapRequestPayload]` /
    `#[MapQueryString]` DTOs from `src/Http/Request/`, validated by `symfony/validator`
    (`Assert\*` on promoted constructor properties) — never read `Request` directly, never
    hand-roll envelope parsing. Ids are `Uuid` value objects (route params resolved by
    Symfony, repository and records typed accordingly). A DTO documents itself with
    `#[ApiProperty]`, and `make docs` generates its published schema from constructor +
    constraints + that prose (`x-dto-schema` markers in `openapi.yaml`).
  - **ingot owns the form definition**: the meta-schema, the typed tree and the semantic
    rules, receiving it already decoded (`Source::array()`), never as JSON text. It also
    derives the per-form JSON Schema that `GET …/schema` publishes to clients.
  - **Submitted values pass two gates, cheapest first** (`src/Http/Form/`):
    `SchemaValuesValidator` runs the derived schema (cached per form+mode through
    `CachedDataSchemaProvider::schemaFor()`, no extra read) and, if it reports anything,
    the request is answered there — building the form is ~10× more expensive and would
    add nothing for a payload of the wrong shape. `FormValuesValidator` then runs
    `FormValuesType` (`TextType`/`ChoiceType`/`NumberType`, `RawValueType` for plugin
    fields) for everything a schema cannot say; that is where new rules belong. Keep the
    order — the published contract must answer first, or the server could be looser than
    the document clients validate against.
  - Undeclared members come back as `schema.additionalProperties`, one per member, each at
    its own pointer — that is ingot's behaviour since the fix in `OpisSchemaValidator`, not
    something this application post-processes.
    `tests/Http/Form/FormValuesValidatorTest` pins that the form and that published schema
    reach the same verdict — they are two views of one definition and must not drift.
  - The bridge to Symfony validation is a pair of custom constraints in
    `src/Http/Request/Constraint/`: `ValidFormDefinition` (attribute on the DTO) and
    `ValidFormValues` (carries the form's definition, applied inside the row lock), both
    translating findings into violations with the exact JSON Pointer preserved via
    `ViolationPointer::PARAMETER`.
  - `ViolationReportFactory` turns violations back into the one `errors[]` shape, so the
    error format never depends on which engine refused the request.
  - **JSON only**: every body-mapping attribute sets `acceptFormat: 'json'`, so any other
    media type (or none) is refused with `415 unsupported-media-type` before mapping —
    without it Symfony would happily map a form-encoded payload. Bodies are also closed
    (`ALLOW_EXTRA_ATTRIBUTES => false` → `request.unexpected_key`); query
    strings ignore unknown parameters, as HTTP clients expect. The submitted values of
    `PUT …/data` are a document rather than named members, so `SaveFormDataRequest` is
    mapped whole by `SaveFormDataRequestDenormalizer` (decoded with
    `JsonDecode::ASSOCIATIVE => false`, so `{}` stays an object).
- State transitions run inside `FormRepository::transactional()` + `getForUpdate()` — never
  add a check-then-write outside the row lock.
- **Writes answer with a status, not a document**: `PUT …/data` and `POST …/confirm` return
  `204 No Content` (`422` with the report when refused). Do not re-add an envelope there — the
  client knows what it sent, and building one cost an extra read after the transaction.
- Definitions are stored **normalized** (`TreeMapper::normalize()`); no denormalized columns.
- **Persistence is Doctrine ORM and stays platform-neutral**: the `Form` entity maps portable
  Doctrine types only (`uuid`, `text`, `datetime_immutable` in UTC), both documents are stored
  as the exact JSON text that passed validation (PHP arrays cannot tell `{}` from `[]`, and the
  bytes go back to clients verbatim), and the migration is built through the schema API rather
  than raw SQL. Nothing may reintroduce jsonb/`now()`/`FOR UPDATE` written by hand — the row
  lock is `LockMode::PESSIMISTIC_WRITE` via `FormRepository::getForUpdate()`.

## Testing (PHPUnit)

- Every functionality gets a test; bodies follow **GIVEN / WHEN / THEN** comments; method
  names describe behavior; error-path tests assert JSON Pointer + error code.
- Suites: `unit` (tests/Domain — no kernel, no DB) and `integration` (tests/Infrastructure,
  tests/Http — real compose Postgres, per-test rollback via dama/doctrine-test-bundle).
- Tests mirror `src/` under `tests/`.

## Quality gates (all must pass before any commit)

**Hard rule: run `make ci` before declaring any task done; a task with a red `make ci` is
not finished.** Every tool runs through a make target — never call phpunit, phpstan,
composer or `bin/console` directly, and never on the host. If an isolated run has no
target, add one here rather than reaching for a raw command.

Local PHP is 8.1 — all tools run inside the pinned Docker image (`docker/Dockerfile`,
`php:8.4-cli-alpine`); never on the host. `docker compose up -d` serves the API on :8000
(dev server); `docker compose run` is what the Makefile and PhpStorm use.

| Command | What it does |
|---|---|
| `make install` / `make update` | composer install/update (Docker) |
| `make migrate` / `make db-test` | migrations for dev / test database |
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

## Repo conventions

- **Never commit or push on your own initiative** — finish with a green `make ci`, report,
  and leave git to the user unless explicitly asked in the current conversation.
- **Never add `Co-Authored-By` (or similar) trailers** to commit messages.
- The remote is GitHub (`gh` CLI is fine for this repo).
- Do not commit `vendor/`, `composer.lock`, `var/`, caches, `config/reference.php`, `.idea/`
  (see `.gitignore`).
