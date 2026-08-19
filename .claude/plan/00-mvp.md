# 00 — ingot-forms: MVP forms management backend

**Status: implemented 2026-08-17.** This is the approved implementation plan, annotated with
as-built deltas at the end.

## Context

`ingot` (sibling checkout at `../ingot`) is a generic JSON→typed-PHP-tree library,
deliberately kept domain-free. Its design docs (`../ingot/.claude/plan/00-mvp-analysis.md` §7,
`../ingot/.claude/plan/01-forms-context.md`) name a forms project as the first real
consumer, and its `examples/Forms/` already prototypes the whole pipeline (definition →
meta-schema validation → derived data schema → data validation).

Decision made with the user: **build the app first, extract a reusable forms package later
only if a second consumer appears.** The one discipline that keeps extraction cheap: a
framework-free, storage-free `Domain/Forms` layer. Backend-only — the derived JSON Schema is
the full API contract for a future frontend.

## Domain model (user-defined)

A **form is a single fillable document**, not a template (definition templates may come
later, explicitly not now):

- One form = one definition + **one data set** (1:1, no submissions collection, no versions).
- Definition is **immutable** after creation — to change it, delete the form and create a new one.
- Data lifecycle: **empty → draft → confirmed**. Two write actions: *save* (`PUT` data,
  repeatable, overwrites the draft) and *confirm* (locks the form — no further data edits, ever).
- **`expire_date` is required** at creation. After it passes, the form's data is to be removed
  from the system: the API treats an expired form as gone (410 for all reads and writes), and
  a purge command physically deletes expired rows.

### Validation semantics

- **Save (draft)**: validates provided values against a *draft variant* of the derived schema —
  types, enums, ranges, `additionalProperties: false` all enforced, but `required`/required-driven
  `minLength` relaxed, so partial progress can be stored.
- **Confirm**: validates the stored data against the **full strict** derived schema; any
  `GenericField` in the definition → `422 form.data.unknown-field-type` (the server cannot
  vouch for a value contract it does not know).

Stack (user confirmed): **Symfony 7** (API-only, hand-written skeleton, no Flex),
**PostgreSQL jsonb via Doctrine DBAL** (no ORM), **new sibling repo** at
`../ingot-forms` consuming `ingot/ingot: dev-main` via a composer `path` repository.

## Architecture

```
src/Domain/Forms/     framework-free, storage-free — extraction candidate
  Definition/         FormDefinition, Field union, Text/Select/Number/GenericField,
                      UniqueFieldNamesValidator (lifted from examples/Forms/)
  form-definition.schema.json
  FormDefinitionProcessor   lenient mapper: parse/normalize/fromStored
  DataSchemaDeriver         definition → values JSON Schema, DeriveMode::Strict|Draft
  FormDataValidator         validateDraft() / validateFinal()
  DefinitionNotValid / FormDataNotValid   RuntimeException + ErrorReport
src/Infrastructure/
  Persistence/        FormRepository (DBAL), FormRecord/FormListItem/FormStatus,
                      FormNotFound, FormGone
  Cache/CachedDataSchemaProvider   PSR-6, key form_schema.{uuid}.{mode}, no TTL
src/Http/
  Controller/         FormController, FormDataController, DataSchemaController
  Problem/            ProblemException, ProblemResponseFactory,
                      ProblemExceptionListener (RFC 9457)
  FormEnvelope        canonical response shape
src/Command/PurgeExpiredFormsCommand    app:forms:purge-expired (cron-able)
```

Deptrac: Domain ← Infrastructure ← Http/Command; Domain depends only on `Ingot\*` and the
`psr/cache` interface. Quality gates mirror ingot minus `mutation` and `deps`
(re-added in stage 2, see `01-stage2.md`).

## Database (single table, raw-SQL doctrine migration)

```sql
CREATE TABLE forms (
    id            uuid        PRIMARY KEY,          -- UUIDv7 from symfony/uid
    definition    jsonb       NOT NULL,             -- normalized (TreeMapper::normalize), immutable
    expire_date   timestamptz NOT NULL,
    data          jsonb,                            -- NULL = empty; draft or confirmed values
    data_saved_at timestamptz,
    confirmed_at  timestamptz,                      -- non-NULL = locked
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_forms_expire ON forms (expire_date);
```

- Status is derived, never stored, and no part of the definition gets a column of its own —
  the document is served whole. (Both halves of the original note have since gone: a
  definition no longer carries a `title`, and no endpoint lists forms.)
- Every repository read/write guards `expire_date > now()` (expired → `FormGone` → 410);
  `app:forms:purge-expired` physically deletes expired rows.
- State transitions run inside `transactional()` + `SELECT ... FOR UPDATE`, so validation and
  state checks cannot race.

## REST API (prefix /api, errors = application/problem+json)

| Endpoint | Purpose |
|---|---|
| `POST /api/forms` | body `{expireDate, definition}`; 201 + Location; definition pointers re-rooted under `/definition` |
| `GET /api/forms` | list non-expired (limit ≤ 200 / offset) |
| `GET /api/forms/{id}` | full envelope |
| `DELETE /api/forms/{id}` | 204 — the "definition changed" path is delete + recreate |
| `GET /api/forms/{id}/schema` | derived values schema (`application/schema+json`); `?mode=draft` |
| `PUT /api/forms/{id}/data` | save draft; 409 `form-locked` once confirmed |
| `POST /api/forms/{id}/confirm` | strict validation of stored data; 409 already-confirmed / empty; 422 report |
| `GET /api/forms/{id}/data` | current values (404 `form-data-empty`) |

Status map: 400 malformed JSON (report contains only `source.malformed_json`), 404 unknown,
409 conflicts, 410 expired (every endpoint), 422 validation reports
(`errors: [{pointer, code, message, input?}]`, `input` only when scalar), 500 opaque fallback
(skipped in debug).

## As-built deltas (learned during implementation)

- `config/reference.php` is auto-generated by Symfony 7.4 — git-ignored, excluded from cs-fixer.
- The default HttpKernel logger prints to test output (PHPUnit risky) — `when@test` binds
  `logger` to `Psr\Log\NullLogger`.
- The planned `property.uninitializedReadonly` PHPStan ignore was never needed (all DTO
  members are constructor-owned) — removed.
- `docker compose up` serves the API on :8000 via PHP's built-in server (`command:` on the
  `php` service); `docker compose run` (Makefile/PhpStorm) overrides it, port published only by `up`.
- jsonb reorders object keys — value round-trip assertions compare canonicalized arrays.
- Path-repo symlink `vendor/ingot/ingot -> ../../../ingot` resolves on host and in the
  container thanks to the `../ingot:/ingot:ro` mount (same depth relative to `/app`).

Out of scope (unchanged): templates, versioning, auth, multi-tenancy, i18n of messages,
conditional field logic, file uploads, frontend. Stage 2 adds CI, mutation testing, OpenAPI,
and contract tests — see `01-stage2.md`.
