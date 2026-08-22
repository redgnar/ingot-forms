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
| [docs/architecture.md](docs/architecture.md) | **work on the service**: layers, the model, storage, the validation gates, the pages, operations, testing |
| [docs/api.md](docs/api.md) · [docs/openapi.yaml](docs/openapi.yaml) | the generated endpoint reference (`make docs` writes both — never edit them by hand) |
| [tests/_requests/](tests/_requests) | working requests for every endpoint, one file per topic, with assertions |
| [.claude/plan/](.claude/plan) | how it got here: the decisions, their reasons, and what each stage changed |

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
  other draft.
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
- Definitions may contain **unknown (plugin) item types** — they round-trip losslessly
  (`GenericField` + `#[Extras]`), can be drafted, but a form containing one can never be
  confirmed: the server refuses to vouch for a value contract it does not know.

## What a client talks to

```
POST   /api/forms                      create one          GET /forms/{id}            the page
GET    /api/forms/{id}                 read it             GET /{_locale}/forms/{id}  the page, in a language
GET    /api/forms/{id}/schema          its values schema   GET /forms/{id}/versions/{seq}
PUT    /api/forms/{id}/data            save a draft
POST   /api/forms/{id}/confirm         lock it
GET    /api/forms/{id}/history[/{seq}] what it held, and when
POST   /api/forms/{id}/files           upload bytes; the answer goes into the values
```

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
do for you. Afterwards `make up` and `make down` start and stop the stack — the database volume
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

