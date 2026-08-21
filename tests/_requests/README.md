# HTTP requests (PhpStorm HTTP Client)

Runnable examples of every endpoint and every documented failure, written as `.http`
files. They are documentation you can execute: each request carries `client.test(...)`
assertions, so a whole file either passes or points at what changed.

These are **not** part of the PHPUnit suites (`make test` only looks at `tests/Domain`,
`tests/Infrastructure` and `tests/UserInterface`) — they talk to a running service over HTTP.

## Running them

1. Start the service: `docker compose up -d` — the dev server listens on
   <http://localhost:8000> and the database is migrated by `make migrate`.
2. Open a file and pick the **dev** environment in the run gutter (the selector at the
   top right of the editor). `dev` points at `http://localhost:8000`; `docker` points at
   `http://php:8000` for running from inside the compose network.
3. Run a single request with the ▶ next to it, or the whole file with ⏩ (*Run all
   requests in file*). Files are numbered because requests reuse what earlier ones
   captured — `01-lifecycle.http` creates a form and hands its id to every request below.

| File | What it covers |
|---|---|
| `01-lifecycle.http` | create → draft → complete → confirm → locked → delete |
| `02-schema.http` | the derived values schema, strict and draft, plus an unknown mode |
| `03-validation-errors.http` | 400, 415 and the whole 422 catalogue: envelope (`request.*`) and values (`form.value.*`) |
| `04-state-and-expiry.http` | 404 / 409 conflicts, and 410 once a form's expire date passes |
| `05-presentation.http` | how a form is shown: given at creation, read back, and every way a document can be refused |
| `06-bootstrap-kit.http` | the same form written for the richer kit, and the widgets only it draws |
| `07-collections.http` | a question asked repeatedly: counting, entries, nesting, and what a presentation of a list may not do |
| `08-files.http` | bytes in, the description echoed into the values, the download, and every way a file is refused or thrown away |

`04-state-and-expiry.http` is the one file to run by hand rather than in one go: it
creates a form that expires in five seconds, so the last requests only answer `410`
after you wait.

## Conventions worth knowing

- Every request body is `application/json`. Any other media type is answered with `415`;
  there is one request that demonstrates it.
- Errors are RFC 9457 problem documents: `type` is a URN, and validation problems carry
  `errors: [{pointer, code, message, input?}]` — the pointer is an RFC 6901 JSON Pointer
  into the offending document, `""` meaning its root.
- The ids captured with `client.global.set(...)` live in PhpStorm's global variables, so
  they survive between files while the IDE is open.
- The authoritative contract is `docs/openapi.yaml` (generated — see `make docs`). If a
  response here surprises you, that document and `tests/UserInterface/Api/OpenApiComplianceTest.php`
  are the two places that define the truth.
