# 01 — stage 2: CI, mutation testing, OpenAPI, API contract tests

**Status: planned.** Builds on `00-mvp.md` (implemented). Each step ends with a green `make ci`.

## 1. CI pipeline (GitHub Actions)

`.github/workflows/ci.yml` running **exactly what developers run**: the Makefile targets
inside the pinned Docker image, with the compose-managed Postgres.

The one CI-specific problem is the composer **path repository**: `ingot/ingot: dev-main`
resolves against a sibling checkout at `../ingot`. The workflow therefore checks out both
repositories into the layout the compose file already expects:

```yaml
jobs:
  ci:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with: { path: ingot-forms }
      - uses: actions/checkout@v4
        with: { repository: redgnar/ingot, path: ingot }
      - run: make install
        working-directory: ingot-forms
      - run: make ci
        working-directory: ingot-forms
```

Notes:
- No PHP matrix: the app pins `php:8.4-cli-alpine` (a library tests 8.4+8.5; an app ships one
  runtime). Revisit when the base image bumps.
- Cache `~/.cache/composer` → the repo's `.cache/composer` (the image sets
  `COMPOSER_CACHE_DIR=/app/.cache/composer`) via `actions/cache` keyed on `composer.json`.
- The dual checkout pins ingot to its current `main`. When ingot reaches Packagist, switch the
  constraint to a version and delete both the `repositories` block and the second checkout.
- Keep `make ci` and the workflow in sync — same rule as in ingot.

## 2. Mutation testing (Infection)

The MVP skipped mutation deliberately (thin adapters + code lifted from a mutation-tested
example). Stage 2 re-adds it **scoped to where it pays**: the domain layer.

- `require-dev`: `infection/infection ^0.34` (+ `infection/extension-installer` allow-plugin).
- `infection.json5`: mutate `src/Domain/` only; run against the `unit` suite so no kernel or
  database is involved (`--testsuite=unit` via `testFramework.phpunit` options); thresholds to
  match ingot's bar: `minMsi: 90`, `minCoveredMsi: 100` — start lower (e.g. 80/95) if the
  first run disagrees, then ratchet up in follow-ups rather than weakening tests.
- `make mutation` target (RUN, no DB) + append to the `ci` chain and the workflow.
- Expected gaps to close: `DataSchemaDeriver` branch coverage per field type × mode,
  `FormDataValidator` error-path precision (exact pointer/code already asserted — mutants
  should die), `FormDefinitionProcessor::normalize()` LogicException guard.

Infrastructure/Http stay out of Infection: their behavior is pinned by functional tests and,
after step 4, by contract tests — mutating DBAL/SQL glue mostly breeds timeouts and
false survivors.

## 3. OpenAPI document

Hand-written **`openapi.yaml`** (OpenAPI 3.1 — its schema dialect *is* JSON Schema 2020-12,
the same dialect ingot emits) at the repo root. Eight operations, small enough that
generation tooling (swagger-php attributes, API Platform) would cost more than it saves and
drift into the framework-coupling we rejected in the MVP.

Content checklist:
- `components.schemas`: `FormEnvelope`, `FormListItem`/`FormList`, `Problem` (RFC 9457 base +
  the `errors[{pointer, code, message, input?}]` extension), `CreateFormRequest`
  (`expireDate` RFC 3339 + `definition` — reference the meta-schema's constraints, don't
  duplicate them: `definition` is `type: object` with a description pointing at
  `src/Domain/Forms/form-definition.schema.json`).
- Per-form **data** schemas are intentionally NOT in the document — they are per-resource and
  served live by `GET /api/forms/{id}/schema`; the spec documents that endpoint's
  `application/schema+json` response instead.
- Every error status the listener produces (400/404/409/410/422) with `application/problem+json`
  content and a named `Problem` example each.
- Serve it: `GET /api/openapi.yaml` as a static file response (one tiny controller or a
  `public/` copy — pick the controller so the path is versioned with the code).
- Validate the document itself in CI: `make openapi` running `vendor/bin/php-openapi validate
  openapi.yaml` (`cebe/php-openapi`, require-dev) — append to `ci`.

## 4. API contract tests (spec ⇔ implementation, both directions)

Goal: every real HTTP response must match `openapi.yaml`, so the spec cannot rot.

- `require-dev`: `league/openapi-psr7-validator`, `symfony/psr-http-message-bridge`,
  `nyholm/psr7`.
- New `tests/Http/OpenApiComplianceTest.php` (integration suite): a `WebTestCase` that, for
  **every operation** in the spec, performs a request producing each documented status code
  (reuse the scenarios FormApiTest already stages: lifecycle, 400/404/409/410/422 paths) and
  asserts the PSR-7-converted response validates against the matching operation+status in
  `openapi.yaml`.
- Coverage guard (the "both directions" part): the test enumerates `paths` from the spec and
  fails if an operation got no request during the run — an endpoint added to the code without
  a spec update is caught by routing tests; an operation added to the spec without coverage is
  caught here.
- Keep FormApiTest as-is (behavioral assertions); the compliance test asserts *shape*, not
  values — no duplication.

## 5. Order & acceptance

1. Workflow (step 1) on a branch — CI must be green running today's `make ci` before anything
   new lands. Acceptance: green run on GitHub for a PR touching only the workflow.
2. Infection (step 2): `make mutation` green locally at the chosen thresholds; add to `ci`
   chain + workflow. Acceptance: MSI report in CI logs, thresholds enforced.
3. `openapi.yaml` + `make openapi` (step 3). Acceptance: document validates; served endpoint
   returns it; README API table gains a pointer to the spec.
4. Contract tests (step 4) in the integration suite. Acceptance: full `make ci` green; a
   deliberate spec mismatch (e.g. drop a 410 response locally) fails the suite.

Out of scope for stage 2: publishing ingot to Packagist (tracked separately — simplifies
step 1 when it happens), auth, deployment pipeline (the CI gate is build+test only).
