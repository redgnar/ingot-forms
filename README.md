# ingot-forms

Backend-only forms management service built on the [ingot](https://github.com/redgnar/ingot)
mapping engine. A **form is a single fillable document**: one JSON definition, one data set,
a required expiry date. A form can hold files too — uploaded beside it and named from inside
it, so the values stay one JSON document. It can also **draw itself for a person**: an optional
second document says how, and two kits render it with no build step. Definition templates,
versioning and multi-submission forms are deliberately out of scope.

## Documentation

| Read this | If you want to |
|---|---|
| [docs/configuring-forms.md](docs/configuring-forms.md) | **describe a form**: item types, widgets, skins, files, lists, history, error codes, a worked example |
| [docs/kits.md](docs/kits.md) | **know exactly what a kit can draw**: every control of both engines, control by control, with links to Bootstrap's own documentation |
| [docs/architecture.md](docs/architecture.md) | **work on the service**: layers, the model, storage, the validation gates, the pages, operations, testing |
| [docs/deploying-behind-a-gateway.md](docs/deploying-behind-a-gateway.md) | **put it in front of people**: the six rules a gateway needs, the identity header, the decision point's contract — with a runnable example in [examples/gateway/](examples/gateway) |
| [docs/api.md](docs/api.md) · [docs/openapi.yaml](docs/openapi.yaml) | the generated endpoint reference (`make docs` writes both — never edit them by hand) |
| [tests/_requests/](tests/_requests) | working requests for every endpoint, one file per topic, with assertions |
| [.claude/plan/](.claude/plan) | how it got here: the decisions, their reasons, and what each stage changed — and, in [10](.claude/plan/10-what-a-vendor-offers.md), what a commercial platform offers and which of it we want |

## Domain model

- **One form = one definition + one data set.** No versions, no submission collections.
- **The definition is immutable.** To change it, delete the form and create a new one.
- **Data lifecycle: `empty → draft → confirmed`.** Saving a draft (`PUT …/data`) is
  repeatable and validates values leniently (types, enums, ranges, and the closed property
  set are enforced; required fields are not — partial progress is storable). Confirming
  (`POST …/confirm`) validates the stored data against the full strict contract and locks
  the form for good.
- **A form may be born a draft.** Values a client already knows arrive as `data` in the
  creation request, and they are not a third document or a new state: they are the form's
  first draft, saved by the same transition every later one goes through, judged under the
  same lenient contract, and refused *before* the form exists — a form is never created
  holding something it would not accept later. Findings are rooted at `/data`.
- **Every accepted save is kept, and a save that changes nothing is not one.** A draft save
  overwrites the current values *and* appends a revision, so a form's history is the record of
  what it held and when ([History](docs/configuring-forms.md#history)) — but sending what the form already holds records
  nothing at all, whatever order the members arrived in. Restoring is not an operation: a client
  reads a revision and sends it back through `PUT …/data`, where it meets the same gates as any
  other draft. A history is bounded: `FORMS_HISTORY_LIMIT` (100) is how many saves one form
  keeps, and past it the oldest leaves as the newest arrives — `0` keeps every one of them.
- **`expire_date` is required.** Past it, the form answers `410 Gone` everywhere, and
  `bin/console app:forms:purge-expired` (run it from cron) physically deletes the row.
- **The definition has no name of its own.** It belongs to exactly one form, and that form
  already has an identity — the UUID it is created with. With no templates and no versioning
  there is nothing for a second name to group, look up or match, so what was once a required
  `id` was only a label that could drift; the derived values schema now titles itself by the
  contract it is (`Form values (strict contract)`) instead of borrowing that name.
- **The definition holds no display text.** No item label, no form title: what a question
  reads like, and in which language, belongs to whatever draws the form. The definition says
  what is asked (`name`, `type`) and what an answer must satisfy — a client keys its own copy
  by the item's name.
- **A form can draw itself.** An optional presentation document says how, in one of two kits;
  a skin says what it looks like; and what a *reader* needs — contrast, colours, text size — is
  theirs to set and no document's to decide ([the pages](docs/architecture.md#the-pages)).
- **A form records who filled it in, and this service authorises nothing.** It has an author, a
  confirmer, and who entered every save — an opaque subject a gateway asserts in one header, never
  resolved into a person here and never drawn on a page. A form may instead be declared
  `anonymous` and record nobody, discarding an assertion rather than refusing it. Who *may* act is
  somebody else's answer: the addresses are split into four prefixes, one per audience, so a
  gateway writes one rule each and a decision point outside says who may touch which form — since
  whoever created a form already knows. **There is still no gateway here**, so nothing should be
  exposed without one: a form's UUID is the only credential it has of its own. See
  [Who may do what](docs/architecture.md#who-may-do-what), worked out in
  [`.claude/plan/09-access.md`](.claude/plan/09-access.md).
- **A form can report itself.** `webhooks: {save?, confirm?}` at creation, both optional and
  immutable with the rest of the form, and what arrives is a *notification* — `{event, form,
  occurredAt, revision?, actor?}` — never the values, so the receiver reads the document through
  the API it already has. Written as an outbox row in the same transaction as the save it is
  about, delivered by a worker (`messenger:consume`) or by `app:webhooks:deliver` from cron, and
  signed with `FORMS_WEBHOOK_SECRET` — without which a form naming an endpoint is refused rather
  than told about unsigned. Every telling is kept and readable at
  `GET /api/manage/forms/{id}/deliveries` (`owed`, `told`, `abandoned`), because a failure that is
  provable while a success is not is not an answer to "were you told". See
  [Being told what happened](docs/configuring-forms.md#being-told-what-happened).
- Definitions may contain **unknown (plugin) item types** — they round-trip losslessly
  (`GenericField` + `#[Extras]`), can be drafted, but a form containing one can never be
  confirmed: the server refuses to vouch for a value contract it does not know.

## What a client talks to

```
POST   /api/manage/forms               create one          GET /forms/{id}                 the page
GET    /api/manage/forms/{id}          read it             GET /forms/{id}?lang=pl         in a language
DELETE /api/manage/forms/{id}          delete it           GET /forms/{id}/versions/{seq}  one saved version
GET    /api/manage/forms/{id}/history  saves, with who entered each
GET    /api/forms/{id}/schema          its values schema
PUT    /api/forms/{id}/data            save a draft
POST   /api/forms/{id}/confirm         lock it
GET    /api/forms/{id}/history[/{seq}] what it held, and when
POST   /api/forms/{id}/files           upload bytes; the answer goes into the values
GET    /api/schemas/{document}         the meta-schema of a definition or a presentation
```

**The prefix is the audience**, and that is deliberate: `/api/manage/` is the system that owns
the form, `/api/forms/` and `/forms/` are whoever it let through to one form, `/api/schemas/` is
open to anybody. One rule each for whatever guards them, with the form's id always the segment
straight after the prefix — `app:routes:groups` prints the table.

Those four are written above as they read at the root of a host, which is where this service
stands unless a deployment says otherwise. It does not have to: nothing here knows its own
address, so a gateway may put it under a path of its own — asserting `X-Forwarded-Prefix`, or
declaring `FORMS_BASE_PATH` — and the pages, their assets and the endpoints they write to all move
with it. Static files sit under one prefix of their own (`FORMS_ASSETS_PREFIX`, `/assets/`), which
is the one rule a gateway needs beyond the four. See
[Where this service is installed](docs/architecture.md#where-this-service-is-installed).

Every body is JSON, every error is RFC 9457 `application/problem+json`, and a validation
problem carries one `{pointer, code, message}` per finding, pointed at the member that is
wrong. The full table, with what each answers and when, is in
[configuring-forms.md](docs/configuring-forms.md#talking-to-the-api).

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
make setup      # image, dependencies, both schemas, and the stack serving on :8000
make ci         # validate + cs + openapi + docs + stan + deptrac + test + mutation + audit
```

`make setup` is the whole bootstrap for a fresh checkout; it refuses early with an explanation
if the `ingot` library is not checked out next to this project, which is the one thing it cannot
do for you. Among other things it writes you a `.env`.

**`.env` is yours and is not in the repository; `.env.dist` is the committed one.** Everything
this service reads is documented there with a value a development machine can use, and nothing
in it is a secret — `make setup` (or `make env` on its own) copies it to `.env` and puts a random
`APP_SECRET` in the copy. A clone with no `.env` still runs: Symfony falls back to `.env.dist`
when `.env` is missing. A deployment sets what it needs in the real environment, which outranks
both. Afterwards `make up` and `make down` start and stop the stack — the database volume
survives a `down`, so the data is still there next time.

The `php` service runs PHP's built-in dev server; `docker compose run` invocations from the
Makefile and PhpStorm override that command, so tooling never conflicts with it.

## Development

```bash
make setup        # image, dependencies, both schemas, the stack on :8000
make test-unit    # the fast loop: domain and application, no database
make ci           # everything CI runs — cs, openapi, docs, stan, deptrac, tests, mutation, audit
```

`make ci` green is what "done" means here. Every tool runs through a make target inside the
pinned Docker image — never on the host, whose PHP is not the one this code is written for. The
full target list, the operational knobs and the testing strategy are in
[architecture.md](docs/architecture.md#development).

