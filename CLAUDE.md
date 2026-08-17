# ingot-forms — agent guide

Backend-only forms management service (Symfony 7 API, PostgreSQL jsonb via DBAL, no ORM)
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
- **ingot is consumed via a composer path repository** (`../ingot`, sibling checkout,
  mounted at `/ingot` in Docker so the relative symlink resolves). After pulling new ingot
  commits run `make update`. When ingot reaches Packagist: switch to a version constraint,
  delete the `repositories` block.
- **One error format**: every error response is RFC 9457 `application/problem+json`; validation
  problems carry `errors: [{pointer, code, message, input?}]` mapped 1:1 from ingot's
  `ErrorReport` (`ProblemExceptionListener` is the single mapping point).
- **Requests arrive as DTOs**: controllers take `#[MapRequest]` arguments (`src/Http/Request/`)
  hydrated by ingot — never read `Request` directly, never hand-roll envelope validation. The
  DTO is the single source of truth: it validates the request AND generates the published
  request schema (`x-ingot-schema` markers in `openapi.yaml`, injected by `make docs`).
  Bodies map strictly (closed key set), query strings laxly (string coercion, extras ignored);
  rules no schema keyword covers are `RequestRule` implementations, auto-collected via
  `_instanceof` in services.yaml. Exception: the values payload of `PUT …/data` stays raw —
  its contract is the per-form derived schema.
- State transitions run inside `FormRepository::transactional()` + `getForUpdate()` — never
  add a check-then-write outside the row lock.
- Definitions are stored **normalized** (`TreeMapper::normalize()`); no denormalized columns —
  the listing reads `definition->>'title'` from jsonb.

## Testing (PHPUnit)

- Every functionality gets a test; bodies follow **GIVEN / WHEN / THEN** comments; method
  names describe behavior; error-path tests assert JSON Pointer + error code.
- Suites: `unit` (tests/Domain — no kernel, no DB) and `integration` (tests/Infrastructure,
  tests/Http — real compose Postgres, per-test rollback via dama/doctrine-test-bundle).
- Tests mirror `src/` under `tests/`.

## Quality gates (all must pass before any commit)

**Hard rule: run `make ci` before declaring any task done; a task with a red `make ci` is
not finished.**

Local PHP is 8.1 — all tools run inside the pinned Docker image (`docker/Dockerfile`,
`php:8.4-cli-alpine`); never on the host. `docker compose up -d` serves the API on :8000
(dev server); `docker compose run` is what the Makefile and PhpStorm use.

| Command | What it does |
|---|---|
| `make install` / `make update` | composer install/update (Docker) |
| `make migrate` / `make db-test` | migrations for dev / test database |
| `make test` / `make test-unit` | full PHPUnit (starts postgres) / fast domain-only loop |
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
