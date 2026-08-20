# 01 — stage 2: CI, mutation testing, OpenAPI, API contract tests

**Status: implemented 2026-08-17** (as-built deltas at the end). Builds on `00-mvp.md`.
Each step ended with a green `make ci`.

**This is a record, in order, of what each step changed and why** — paths and names are those
of the time, and the sections further down say where they moved. For what the code does now,
read `README.md` and `CLAUDE.md`.

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

## As-built deltas (learned during implementation)

### Steps 3–4 reshaped by the user during implementation

1. **No `GET /api/openapi.yaml` endpoint.** The spec is a build artifact, not a runtime
   resource: `make docs` renders `openapi.yaml` into `docs/` (`tools/build-docs.php`,
   dev tooling — it uses require-dev packages the app must not depend on). `docs/openapi.yaml`
   is the effective contract, `docs/api.md` a browsable Markdown reference; the generator
   validates its own output, and `docs` runs before `test` in the `ci` chain because the
   contract tests read it.
2. **Request DTOs, mapped and validated by Symfony; ingot keeps the documents.**
   (First built on an ingot-based `#[MapRequest]` mechanism, then rebuilt on Symfony's own
   at the user's direction — the envelope is framework work, the documents inside it are
   the engine's.) Controllers take `#[MapRequestPayload]` / `#[MapQueryString]` DTOs from
   `src/Http/Request/`; `symfony/validator` + `symfony/serializer` were added, and every
   Symfony constraint sits on a promoted constructor property.
   - **The bridge is a pair of custom constraints** (`src/Http/Request/Constraint/`):
     `ValidFormDefinition` (attribute on `CreateFormRequest::$definition`) hands the decoded
     document to `FormDefinitionProcessor`, and `ValidFormValues` — which carries the form's
     definition and mode — is applied by hand inside the row lock, where the definition is
     known. Findings become violations carrying the engine's exact JSON Pointer in a
     violation parameter (`ViolationPointer::PARAMETER`), because Symfony's property-path
     syntax cannot round-trip a pointer: a field may legitimately be named `a.b`.
     `FormController` lost its manual `prefixPointers()` — the validator context already
     roots the definition's findings under `/definition`.
   - `ViolationReportFactory` maps violations back into the `errors[]` shape: readable
     violation codes win (engine findings), then a constraint's `payload: ['code' => …]`
     (e.g. `form.expire_date.past`), then the constraint's own name (`request.range`), and
     `request.type` when the payload could not be mapped at all. Symfony's opaque UUID
     codes never reach a client.
   - **ingot now receives structures, not JSON text**: `FormDefinitionProcessor::parse()`
     takes an array, `FormDataValidator` takes decoded `\stdClass` values. One boundary
     detail is documented in code: PHP arrays cannot say "JSON object", so `parse()`
     re-decodes once (an *empty* nested object is the one thing that cannot survive), and
     the values endpoint decodes with `assoc: false` so `{}` stays an object.
   - **Value objects**: ids are `Uuid` everywhere above SQL — route arguments (resolved by
     Symfony), repository methods, `FormRecord`/`FormListItem`, the schema cache key.
   - `DeriveMode` became a **backed** enum (`strict`/`draft`). The DTO takes the wire value
     with `#[Assert\Choice]` rather than the enum type, so a wrong mode is answered with
     the accepted list instead of a mapping message naming internal PHP types; `mode()`
     hands the controller the enum.
   - Required members are nullable + `#[Assert\NotNull]` (so "missing" reports as missing,
     not as a type mismatch) with accessors returning the non-null value.
   - Behavior changes: paging outside 1–200 is **rejected** (`422`, `/limit`,
     `request.range`) instead of silently clamped; an undeclared body member is refused
     (`ALLOW_EXTRA_ATTRIBUTES => false` → `request.unexpected_key`); `expireDate` parsing is
     now Symfony's (lenient about non-RFC-3339 strings a date parser accepts, where ingot's
     `#[Format]` was strict) — the published contract still documents `format: date-time`.
   - The schema generation in `tools/build-docs.php` was rewritten accordingly: the marker
     is `x-dto-schema`, and schemas come from the DTO's constructor, its `Assert`
     constraints and swagger-php's `#[OA\Property]` description/example/type/format (the
     ecosystem attribute, the one NelmioApiDocBundle consumes — a bespoke `ApiProperty`
     was written first and dropped). Only prose and client hints may sit next to a marker —
     anything else is a hard error, since it would compete with the DTO for the shape.
   - DTO members are **non-nullable**: an instance means a complete request. A missing or
     mistyped member never reaches the constructor, and `ViolationReportFactory` words that
     failure in the wire's terms ("This member is missing or is not an RFC 3339 date-time.")
     instead of Symfony's PHP-typed message.
   - `SaveFormDataRequest` + `SaveFormDataRequestDenormalizer`: the submitted values are a
     document, not named members, so the DTO takes the whole body. Two details make it
     honest — the payload is decoded with `JsonDecode::ASSOCIATIVE => false` (Symfony's
     JsonEncoder defaults to arrays, which cannot tell `{}` from `[]`, and these values are
     stored verbatim), and a non-object body is *collected* into
     `not_normalizable_value_exceptions` rather than thrown, which is how the serializer
     turns it into a 422 violation instead of a 500.
   - `GET /api/forms` (listing) was dropped at the user's request together with its DTO,
     repository method, `FormListItem`, spec entry and tests — nothing presents such a list
     yet.
3. **Contract tests validate requests too**, against the generated document. Each scenario
   declares whether its request matches the contract; the ones that deliberately break it
   (malformed JSON, unknown body key, `limit=500`, `mode=bogus`) must be *refused* by the
   spec — proving the document is as tight as the implementation, not just as loose. The
   coverage guard now compares operation+status **sets**, so one response can be reached by
   several scenarios.

- **`devizzent/cebe-php-openapi` replaces `cebe/php-openapi`** everywhere: the league
  validator depends on that fork (same `cebe\openapi` namespace — installing both would
  collide), it ships the same `php-openapi` CLI, and unlike upstream it validates
  OpenAPI 3.1. `make openapi` uses the fork's binary.
- First Infection run: MSI 73% — closed by strengthening tests, not by lowering thresholds
  (final: 100/100, thresholds kept at plan's 90/100). Structural findings on the way:
  the `#[Constraints(minLength: 1)]` on `Field::$name` was dead code (the engine hydrates
  each variant through its own constructor, so a parent-promoted-param attribute is never
  enforced — removed; the meta-schema owns that rule), and `Field`'s parameter defaults
  were unreachable (every variant forwards all three values — removed).
- `tests/Domain/Forms/Definition/DefinitionConstraintsTest.php` pins the second-line
  defense: definition DTO constraints hold when mapped WITHOUT the meta-schema pre-check
  (a bare `MapperBuilder::create()->build()`), one boundary from both sides per test.
- The coverage guard is static in both directions: it compares the set of documented
  operation+status pairs with the scenario list — no runtime accumulation, no
  test-ordering dependency. Each scenario also asserts the actual status it produced,
  so a scenario cannot silently cover a different response.
- `Source::array()` hands data to the engine untouched (JSON sources decode with
  `assoc: false`), so anything given to ingot must use `\stdClass` for JSON objects —
  otherwise the opis schema pre-check sees PHP arrays and reports `type` violations.
- Symfony's `#[MapQueryString]` answers a bad query string with **404** by default;
  both query DTOs set `validationFailedStatusCode: 422`.
- `Assert\*` attributes target properties, not parameters: the docs generator reads them
  from the promoted property (`ReflectionClass::getProperty()`), which is also where
  Symfony's validator looks.
- `qossmic/deptrac` (abandoned) ships its analysis as a PHAR with an **older bundled
  php-parser**, so it printed `Syntax error, unexpected T_OBJECT_OPERATOR` for the PHP 8.4
  `new X()->y()` form — and silently *skipped* those files while still reporting zero
  errors, a hole in the gate rather than mere noise. Replaced by the maintained
  `deptrac/deptrac` ^4.7: the syntax errors are gone, the files are analysed (Uncovered
  227 → 271, Allowed 36 → 42, Violations still 0) and `composer audit` no longer reports an
  abandoned package.
- Symfony is pinned to `^7.4`; unconstrained transitive components resolve to 8.x, which
  their own constraints allow, so the HTTP stack (http-kernel, http-foundation, routing,
  event-dispatcher) is required explicitly to keep it on one line.

- **Request bodies are `application/json` or nothing**: `acceptFormat: 'json'` on both
  `#[MapRequestPayload]` attributes closes a hole Symfony leaves open by default — a
  form-encoded payload was being mapped happily. A wrong or missing media type is now
  `415 unsupported-media-type` in the usual problem+json shape, documented as a shared
  response and covered by a scenario on both body endpoints.

- **Writes no longer echo the form.** `PUT …/data` and `POST …/confirm` answer `204 No Content`
  instead of the envelope: the client already holds the values it sent, so the copy
  bought nothing and cost a read after the transaction. `FormDataController` dropped its
  `FormEnvelope` dependency, the OpenAPI responses declare no content, and the lifecycle test
  now reads the form separately to prove the state transition.

- **Values are gated by the published schema before the form is built.** `app:forms:schema`
  and `app:forms:check-values` (plus `make schema` / `make check-values`) answer "would the
  API take this JSON?" from a definition file, with no database. The same derived schema —
  cached per form and mode, reusing the entries the schema endpoint fills — now runs as the
  first stage of `ValidFormValues`: measured on the example definition, the schema costs
  ~31 µs on valid values and ~62 µs on broken ones where the form costs ~640–670 µs, so
  refusing early saves an order of magnitude of work and guarantees the server cannot accept
  what the published contract rejects. One consequence worth knowing: values findings speak
  `schema.*` for everything the contract covers, and `form.value.*` is left for what only a
  form can catch.
- **Fixed at the source in ingot** (`src/Schema/OpisSchemaValidator.php`): opis reports
  `additionalProperties` once on the owning object and lists every member it did not
  evaluate — which includes *declared* members that failed their own subschema, so
  `{"age": 7}` came back both as `/age schema.minimum` and as "age is not allowed". The
  adapter now expands that error into one finding per member, each at its own pointer with
  the offending value, and drops members that already carry a finding of their own. ingot's
  own suite covers it (three new cases in `OpisSchemaValidatorTest`, plus the forms example
  updated), and `make ci` there is green at MSI 100%. ingot-forms then dropped the
  workaround it had grown: unknown members are simply `schema.additionalProperties` at
  `/member`.

### Stage 2 ended on a different stack than it started

Two more directions arrived while the contract work was landing, and both are implemented:

- **NelmioApiDocBundle generates the contract.** The hand-written `openapi.yaml` and the
  DTO-schema injector in `tools/build-docs.php` are gone: Nelmio reads the routes, the
  request DTOs behind `#[MapRequestPayload]`/`#[MapQueryString]` (Assert constraints and
  swagger-php `#[OA\Property]` prose included) and the `#[OA\Response]` attributes on the
  controllers. `config/packages/nelmio_api_doc.yaml` carries only what no route can state:
  the document identity and the shared shapes (`Problem`, `FormEnvelope`, the reusable
  400/404/410 responses). `make docs` = `nelmio:apidoc:dump --format=yaml` into
  `docs/openapi.yaml` + `tools/build-docs.php`, now only a Markdown renderer; `make openapi`
  validates the *dumped* document. Details worth remembering: named examples need a
  `summary` (swagger-php refuses otherwise), problem responses must declare
  `application/problem+json` explicitly (`OA\MediaType`, not `OA\JsonContent`), and the
  values endpoint overrides its request body to `FormValues` because the DTO carrying the
  document is not the wire shape.
- **Doctrine ORM instead of hand-written DBAL SQL**, so the service installs on any
  supported platform. One `Form` entity replaces `FormRecord` and owns the transitions
  (`saveDraft()`, `confirm()`, `status()`, `hasExpired()`); `FormRepository` keeps its
  contract but runs on the EntityManager, with `LockMode::PESSIMISTIC_WRITE` in place of
  `SELECT … FOR UPDATE` and a DQL bulk delete (plus `clear()`, since a bulk delete bypasses
  the identity map) in place of `DELETE … WHERE expire_date <= now()`. Portability drove
  three mapping decisions: UTC `datetime_immutable` instead of `timestamptz`, `text`
  instead of `jsonb` (nothing queries inside the documents any more, and storing the exact
  validated JSON keeps `{}` from degrading into `[]`), and a migration built through the
  schema API instead of raw SQL. doctrine-bundle 3.x also dropped `use_savepoints`,
  `auto_generate_proxy_classes` and `report_fields_where_declared` from its config.

## Values are validated by a Symfony form built from the definition (implemented)

The seam held: `ValidFormValues` already owned "validate these values against this
definition", so the implementation behind it was swapped without touching a controller.

- `src/Http/Form/FormValuesType` builds a form from the definition — `TextType` with
  `Length`/`Regex`, `ChoiceType` over the declared options, `NumberType` with `Range`,
  and `RawValueType` (compound-less passthrough) for plugin fields, whose values are stored
  as they came. `FormValuesValidator` submits into it and reports findings as `form.value.*`.
- Strict mode submits with `clearMissing`, which is what makes the required rules fire;
  draft mode leaves missing fields missing. Undeclared fields are refused by the form itself
  (`allow_extra_fields: false` → `form.value.unknown_field`, told apart from a value the
  form cannot transform by the `Form` constraint's own error code).
- **Wire types are checked before the form runs.** A form transforms — `"36"` would become
  the number 36 — while the derived schema tells clients a number is required, so the check
  keeps the server from being looser than its own contract.
- `FormDataValidator` and `FormDataNotValid` are gone from the domain; the one rule that
  stayed is `UnknownFieldTypes` (a definition with a plugin field can never be confirmed).
  `DataSchemaDeriver` is untouched: it still derives what `GET …/schema` publishes.
- The risk this introduces is two views of one definition drifting apart, so
  `tests/Http/Form/FormValuesValidatorTest::testFormAndPublishedSchemaAgree` runs nine value
  documents past both the form and the published schema and asserts they agree.
- Error vocabulary for values changed accordingly: `schema.required` → `form.value.required`,
  `schema.type` → `form.value.type`, and so on; the OpenAPI examples were updated with it.
- Infection scoping to the unit suite is done via
  `--test-framework-options="--testsuite=unit"` on the CLI (the config file has no such
  key), kept in the `make mutation` recipe.

## Four layers, ports and single-action controllers (implemented)

The last shape problem was ownership: an action reached for the Doctrine repository, opened
the transaction and constructed the aggregate itself, so "what the system does" lived in the
HTTP layer and could not be tested without a kernel.

- **`src/Application/Forms/UseCase/`** now holds one invokable per operation — `CreateForm`,
  `SaveFormData`, `ConfirmForm`, `DeleteForm`, `ReadForm`, `PurgeExpiredForms`. The
  transaction, the locked read and the order of steps live there; an action maps a request
  onto one of them and a refusal onto a status.
- **Ports are declared where they are needed**: `Domain\Forms\Port\FormRepository` for the
  model, `Application\Forms\Port\{Transactions,ValuesValidator,DataSchemas}` for the use
  cases. `Infrastructure/` supplies `DoctrineFormRepository`, `DoctrineTransactions`,
  `StagedValuesValidator` and `CachedDataSchemaProvider`; `services.yaml` binds them.
- **The aggregate moved into the domain** and lost its ORM attributes — the mapping is XML in
  `config/doctrine/Form.orm.xml`, so the model stays extractable. Value objects came with it:
  `FormId`, `ExpireDate` (UTC; `future()` refuses a past moment) and `Values` (an object
  document that keeps `{}` distinct from `[]` and hands out the text that was validated).
- **Exceptions are grouped in `Exception/`** per layer and say nothing about HTTP; the status
  is decided in `ProblemExceptionListener`, or in an action where the same state means two
  things (no data is 404 on a read, 409 on a confirm).
- **One class per endpoint**, suffixed `Action`, so each injects only its own dependencies —
  `FormController` and `DataSchemaController` are gone.
- deptrac was rewritten around the four layers (`Domain: ~`, `Application: [Domain]`,
  `Infrastructure: [Domain, Application]`, `UserInterface: [Domain, Application]`) — the UI
  cannot name an adapter. Use cases are tested against in-memory fakes in the unit suite;
  the HTTP contract tests were untouched by the move, which is the evidence behaviour held.

The rules this settled on are written down in `CLAUDE.md` so later work follows them.

## The model stopped being an entity (implemented)

Three problems in a row turned out to be one: Doctrine hydrates an object without calling its
constructor, so the aggregate kept needing repairs after a read — a parser handed over by the
repository, a value object rebuilt on every accessor, and finally a custom DBAL type to avoid
both. The cause was that the domain model *was* the mapped entity.

- **`FormRecord`** is now what Doctrine sees: public fields, ORM attributes, no behaviour and
  no import from the domain, on the same `forms` table and columns as before — so no
  migration. Both directions of the translation live in `DoctrineFormRepository`, its only
  caller. Mapping moved from `config/doctrine/*.xml` to attributes, which is now the right
  call: the class carrying them exists to be a row.
- **`Form` is `final`** (nothing needs to proxy it), holds value objects rather than the
  scalars a column wanted (`FormId`, `Definition`, `ExpireDate`, `Values`), and gained
  `fromState()` — the way an adapter restores what it read, judging nothing and recording
  nothing.
- **`Definition` became the structure, not a label on a string.** A first attempt gave it a
  private constructor and a shallow "is this a JSON object" check, which promised more than it
  proved. It now carries the normalized document *and* the structure, and the only ways in are
  `FormDefinitionProcessor::document()` (a structure the mapper just accepted) and
  `Definition::stored()` (a stored document read back through that same mapper), so the real
  parse is the check. Reading it back happens where the value is built — a lazily resolved
  version existed briefly and bought only the read and delete paths an unmeasured saving, at
  the price of a closure field and a class that could not be `readonly`.
- The risk this introduces is a field mapped in one direction and forgotten in the other, so
  `testEveryPieceOfAFormSurvivesTheRoundTrip` drives a form through save, confirm, a cleared
  entity manager and a fresh read, asserting every piece came back.

## Writes follow the events (implemented)

Recording events and then discarding them made them a description nobody acted on, while
persistence still worked by copying state across.

- `DraftSaved` carries the `Values` it stored, so `save()` no longer looks at the form: it
  walks `releaseEvents()` and turns each one into the columns it happened to. `FormConfirmed`
  stamps `confirmed_at`; `FormCreated` is not an update, because an insert writes the row
  whole — the one place that still reads the form rather than what happened to it.
- "A field copied in one direction and forgotten in the other" becomes "an event type nobody
  handles", and that throws. PHPStan does not accept `match (true)` as exhaustive, so the
  refusal is an explicit `default` rather than a bare `\UnhandledMatchError`.
- `Transactions` stays: `getForUpdate()` uses `LockMode::PESSIMISTIC_WRITE`, which Doctrine
  refuses outside a transaction, and the state a form checks must not change between the check
  and the write. Events say what happened; they do not say that nobody else got in between.

## The schema cache could answer with yesterday's rules (fixed 2026-08-20)

The derived schema is cached per form and mode, and this plan called those entries
"never stale": a definition is immutable and a UUID is never reused, so an entry cannot be
wrong about the form it belongs to. It can be wrong about something this plan did not think
of — the *code* that derived it. The key names the form and the mode and says nothing about
the rules behind the document, so when `mustBeChecked` moved to the strict contract, a form
created minutes earlier kept being refused a draft save from a cached schema that still said
`const: true`.

What changed:

- **In dev, nothing outlives the process that derived it.** `cache.data_schema` and
  `cache.ingot_mapper` both run on `cache.adapter.array` under `when@dev`, because a clear
  that has to be remembered while developing is a clear that will be forgotten. Test and prod
  stay on disk — in the test suite the pool is shared between requests, which is what the
  tests of it exercise.
- **`make cache-clear`** drops both pools and the cache directory, for dev and test; a deploy
  runs the same commands with `APP_ENV=prod`, and `make setup` calls it. It has already earned
  its keep twice: after `mustBeChecked` moved, and again when the definition lost its `id` and
  the ingot mapper's cached metadata still expected the key.
- **What was deliberately not done**: folding a fingerprint of the deriving code into the key
  and pinning it with a golden-hash test. It was offered and turned down — the staleness is a
  development problem, production clears its cache anyway, and a version somebody has to bump
  is one more rule that can drift. The trap is instead written down where it can be read: the
  provider's own test records that an entry already in the pool is trusted, which is *why* a
  rules change means clearing it.