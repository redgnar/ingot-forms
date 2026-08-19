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

## Design principles

The shape of this codebase is **hexagonal architecture with DDD elements**: a model that
knows nothing about the outside, ports declaring what it needs, adapters filling them, and
use cases as the only entry into a transition. Read the layer map below as the concrete form
of that, not as a separate idea.

- **DDD**: an aggregate (`Form`) owns its invariants and transitions, value objects
  (`FormId`, `ExpireDate`, `Values`) carry the rules a primitive cannot, exceptions are the
  vocabulary of refusal, and a repository is an interface the domain declares — never a
  Doctrine detail leaking upwards. The ubiquitous language is what the code is named after.
- **DRY**: one place per rule. One error format and one mapping point, one derived schema
  serving both the published contract and the incoming check, one definition mapper as a
  service. A write never answers with the document a `GET` already serves — a second copy is
  a second truth.
- **YAGNI**: the domain model says no on purpose (no templates, no versioning, no
  multi-submission, no form list endpoint). Do not add a seam, an abstraction or a config
  knob for a case nobody asked for; add it when the second caller appears.
- **SOLID**: one action per endpoint and one `__invoke` per use case (S); the field catalogue
  grows by adding a variant, not by editing a switch (O); adapters are substitutable behind
  their port, which is what lets the unit suite run on fakes (L); ports stay narrow — a use
  case that needs a transaction does not receive an entity manager (I); every layer depends
  on interfaces it declares itself, and `services.yaml` is the only place an implementation
  is named (D).

## Architecture ground rules

The code is laid out in four layers, and the dependency arrows only ever point inwards:
**UserInterface → Application → Domain**, with **Infrastructure → Domain, Application**.
`deptrac.yaml` enforces exactly that; the UI may not name an adapter, ever.

```
src/Domain/Forms/          the model: Form (aggregate), FormStatus, DeriveMode, the definition
                           model and its processors. Framework-free and storage-free — the
                           future standalone package.
    Definition/            the field union and the meta-schema
    Event/                 what happened to a form: FormCreated, DraftSaved, FormConfirmed
    ValueObject/           FormId, ExpireDate, Values, Definition
    Exception/             what the model refuses: DefinitionNotValid, ValuesNotValid,
                           FormNotFound, FormGone, FormLocked, FormAlreadyConfirmed,
                           FormHasNoData
    Port/                  FormRepository, ValuesValidator, DefinitionParser — what the
                           model needs from the outside to keep its own rules
src/Application/Forms/
    UseCase/               one class per thing the system does, each with a single __invoke:
                           CreateForm, SaveFormData, ConfirmForm, DeleteForm, ReadForm,
                           PurgeExpiredForms. This is where a transaction is opened and where
                           the order of steps lives.
    Port/                  Transactions, DataSchemas — what a use case needs and cannot
                           do itself
src/Infrastructure/        the adapters filling those ports
    Persistence/           FormRecord (the row, mapped with ORM attributes),
                           DoctrineFormRepository, DoctrineTransactions
    Cache/                 CachedDataSchemaProvider
    Validation/            the schema gate, the Symfony form and the staged validator
src/UserInterface/
    Http/Action/           one invokable class per endpoint, suffixed Action
    Http/Request|Problem/  request DTOs, problem+json mapping
    Cli/                   console commands
```

Rules that follow from it, and that the tooling checks:

- **A controller is one action.** `#[Route]` + `#[OA\…]` + `__invoke()` in a class named after
  what it does (`SaveFormDataAction`), so a class only injects what that one endpoint needs.
  Never group endpoints to share a constructor.
- **The user interface talks to use cases, never to a repository, a cache or an entity
  manager**, and it never mutates an aggregate. Its job is HTTP: map the request onto a use
  case, map a refusal onto a status.
- **Ports are declared where they are needed and implemented outward.** The domain declares
  what the model needs to keep its own rules (`FormRepository`, `ValuesValidator`,
  `DefinitionParser`); the application declares what a use case needs (`Transactions`,
  `DataSchemas`); `services.yaml` binds each interface to its adapter. Nothing above
  Infrastructure names an implementation class.
- **The domain speaks in value objects, not primitives**: `FormId` instead of a raw uuid,
  `ExpireDate` (UTC, and `future()` cannot be constructed in the past), `Values` (a JSON
  object, keeping `{}` distinct from `[]` and handing out the exact text that was validated).
  A new concept that has an invariant gets a value object, not a `string`.
- **The aggregate owns its transitions and refuses what breaks them.** `saveDraft()` and
  `confirm()` throw `FormLocked`, `FormAlreadyConfirmed`, `FormHasNoData` themselves, and
  neither stores anything that does not fit the form's own definition: the validator is
  handed in as an argument (the verdict needs machinery the model does not carry), but which
  contract applies — lenient while filling in, strict at confirmation — is the form's own
  business. A caller cannot skip that check, which is the point: it is an invariant, not a
  courtesy. The definition travels as `Definition`, which holds both shapes it is needed in
  and never one without the other: the normalized document that is stored and served byte for
  byte, and the structure a rule can be asked about. One cannot be held unproved — it is
  built either from a structure the mapper just accepted (`FormDefinitionProcessor::document`)
  or from a stored document read back through that same mapper (`Definition::stored`), and
  the reading back happens there and then — a definition is whole from the moment it exists,
  with no deferred work hidden behind an accessor.
- **The aggregate is not an entity.** Doctrine maps `FormRecord` — a row with public fields,
  ORM attributes, no behaviour and no idea a form exists — and never the model. Both
  directions of the translation live in `DoctrineFormRepository`, and `Form::fromState()`
  restores what was read without judging or recording anything, because reading is not
  something that happens to a form. That costs a mapping in both directions and buys a model
  with no mapping, no constructor to bypass and no fix-up after a read;
  `testEveryPieceOfAFormSurvivesTheRoundTrip` drives a form through storage and back so a
  half-mapped field is caught here rather than in production.
- **The port stays a collection** (`add`, `get`, `getForUpdate`, `save(Form)`, `remove`,
  `purgeExpired`), and every method that writes names the form it writes.
- **Transitions record what happened, and the write follows the record.** Each one appends a
  `FormEvent` — past tense, the moment it happened at, and whatever it changed (`DraftSaved`
  carries its `Values`); `releaseEvents()` hands them over and forgets them. `save()` applies
  them onto the row instead of copying state across, so a column changes because something
  happened, and a `match` without a default means a transition nobody taught the adapter
  about stops the write rather than vanishing from it. An insert is the exception: a new row
  is written whole. A refused transition records nothing.
- **Exceptions live in `Exception/` next to the layer that raises them**, carry the id they
  are about, and say nothing about HTTP. Which status a refusal deserves is decided in
  `ProblemExceptionListener` (or in an action, where the same state means different things —
  no data is 404 on a read, 409 on a confirm).
- **One error format**: every error response is RFC 9457 `application/problem+json`; validation
  problems carry `errors: [{pointer, code, message, input?}]` mapped 1:1 from ingot's
  `ErrorReport` (`ProblemExceptionListener` is the single mapping point).
- **Requests arrive as DTOs**: actions take `#[MapRequestPayload]` / `#[MapQueryString]`
  arguments from `src/UserInterface/Http/Request/`, validated by `symfony/validator` — never
  read `Request` directly, never hand-roll envelope validation. Every DTO member is
  non-nullable, so an instance means a complete request. Bodies are JSON only
  (`acceptFormat: 'json'` → 415 otherwise) and closed (`ALLOW_EXTRA_ATTRIBUTES => false` →
  `request.unexpected_key`); query strings ignore unknown parameters. A DTO documents itself
  with swagger-php's `#[OA\Property]`, and NelmioApiDocBundle turns routes + DTOs +
  `#[OA\Response]` into the published contract (`make docs`).
- **A definition says what is asked, never how it looks.** There is no presentation in it:
  `textarea` is one way to show a text item, `radio` one way to show a select, and both are
  the client's business. So an item type is added when it brings **rules of its own** — a date
  has a shape and a period, a checkbox has a value that must be exactly `true` — and never
  when it would only tell a frontend which widget to draw. A type with no rules of its own is
  a second name for one we already have, and every rule it copies is a rule that can drift.
- **ingot owns the form definition** — meta-schema, typed tree, semantic rules — and derives
  the per-form JSON Schema. When a rule cannot be expressed in that schema, the schema is
  where to fix it: a date range is published and enforced as `formatMinimum` /
  `formatMaximum` (ingot's own keywords, spelled the way ajv-formats spells them) rather than
  enforced past the contract in the form stage. It receives decoded structures (`Source::array()`), never JSON
  text. The definition mapper is a service (`FormMapperFactory` → `forms.definition_mapper`);
  never rebuild a mapper inside a class that uses it.
- **Submitted values pass two gates, cheapest first**: the derived schema (cached per form and
  mode, ~10× cheaper) answers first, so the server can never be looser than its published
  contract; the Symfony form built from the definition then adds what a schema cannot say.
  Keep that order.
- **A use case orchestrates, it does not decide.** State transitions run inside
  `Transactions::run()` + `FormRepository::getForUpdate()`, so the state the form checks
  cannot change between the check and the write — but what may happen is the aggregate's
  call, and a use case that re-checks a rule the model already keeps has duplicated it.
- **A write never answers with the thing it wrote** — that is what `GET` is for, and a
  second copy is a second truth. `PUT …/data` and `POST …/confirm` return `204 No Content`
  (`422` with the report when refused); `POST /api/forms` returns `201` with `{"id": …}` and
  a `Location` header, because the id is the only part the client could not already know.
  The same holds one layer down: a use case that creates or changes something returns `void`
  or an identity, never the aggregate.
- **Persistence stays platform-neutral**: portable Doctrine types only (`uuid`, `text`,
  `datetime_immutable` in UTC) on `FormRecord`, both documents stored as the exact JSON text
  that passed validation, migrations built through the schema API rather than raw SQL.
- **ingot is consumed via a composer path repository** (`../ingot`, sibling checkout,
  mounted at `/ingot` in Docker so the relative symlink resolves). After pulling new ingot
  commits run `make update`. When ingot reaches Packagist: switch to a version constraint,
  delete the `repositories` block.
  - **A change spanning both repositories goes to ingot first.** Locally the two are always
    in step — the symlink points at your checkout — so a mismatch is invisible until CI,
    which clones `redgnar/ingot` at `main`. Push the library, wait for it to land, then push
    the application; otherwise the workflow builds new expectations against the old library
    and fails for a reason nothing in the diff explains.

## Testing (PHPUnit)

- Every functionality gets a test; bodies follow **GIVEN / WHEN / THEN** comments; method
  names describe behavior; error-path tests assert JSON Pointer + error code.
- Suites: `unit` (tests/Domain — no kernel, no DB) and `integration` (tests/Infrastructure,
  tests/Http — real compose Postgres, per-test rollback via dama/doctrine-test-bundle).
- **The item catalogue is tested by a battery, one class per type.** A new kind of item gets
  two subclasses and inherits everything else: `tests/Domain/Forms/Definition/Field/…Test`
  (which option combinations a definition may and may not carry, what the item contributes to
  the strict and draft schema, and that every option survives being stored) and
  `tests/Infrastructure/Validation/Field/…ValuesTest` (a table of values with the pointer and
  code each must produce, judged through the whole staged validator). The values table is
  also what proves the form never refuses what the published schema accepts — the server may
  not be stricter than its own contract; the other direction is fine, since the schema runs
  first and is what clients were told. Test what a type accepts on **both** sides of every
  boundary: the refused side alone leaves the limit itself unpinned.
- Tests mirror `src/` under `tests/`, layer for layer. Use cases are tested against in-memory
  fakes of their ports (`tests/Application/Forms/Fake/`) — no kernel, no database — so what
  they check is the orchestration: the transaction, the locked read, the order of steps.

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
