# How it is built

The technical half: how the code is arranged, why it is arranged that way, what runs at deploy
time and what the tooling checks. If you are describing a form rather than maintaining the
service, [configuring-forms.md](configuring-forms.md) is the document you want.

## Contents

- [Layers](#layers)
- [Who may do what](#who-may-do-what)
- [Where this service is installed](#where-this-service-is-installed)
- [The model, and what it refuses](#the-model-and-what-it-refuses)
- [Storage](#storage)
- [How values are judged](#how-values-are-judged)
- [The published contract](#the-published-contract)
- [The pages](#the-pages)
- [Telling somebody what happened](#telling-somebody-what-happened)
- [The record of a confirmed form](#the-record-of-a-confirmed-form)
- [Where the bytes live](#where-the-bytes-live)
- [Operations](#operations)
- [Development](#development)
- [Testing](#testing)

## Layers

```
src/Domain/Forms/          the model — aggregate, value objects, events, exceptions, and the
                           ports it declares. Framework-free and storage-free.
src/Application/Forms/     use cases (one class, one __invoke) and the ports they need
src/Infrastructure/        the adapters: the row, its Doctrine mapping and the repository
                           that translates both ways, the schema cache, the validation stages
src/UserInterface/Api/     one invokable Action per endpoint, request DTOs, problem+json
src/UserInterface/Web/     the pages that draw a form: an action, a renderer per engine, and
                           the templates each draws with
templates/, assets/        what those pages are made of — markup, and the JavaScript and CSS
                           AssetMapper delivers (one hand-written module for the plain kit)
src/UserInterface/Cli/     console commands
tools/build-docs.php       renders the generated contract into docs/ (dev tooling)
```

Dependencies point inwards only — **UserInterface → Application → Domain**, with
**Infrastructure → Domain, Application** — and `deptrac.yaml` fails the build otherwise. An
action never touches a repository, a cache or an entity manager: it maps a request onto a use
case and a refusal onto a status.

The domain layer depends only on `Ingot\*`, `psr/cache` and `symfony/uid` (a value-object
library, not the framework) — no ORM, no attributes, no configuration — so extracting it into
a reusable package later is a namespace move, not a rewrite.

**That list is checked, not just written down.** deptrac compares *layers*, and a class in no
layer is reported as `Uncovered` rather than as a violation — so until the vendor layers went
into `deptrac.yaml`, `Domain` could have imported an HTTP kernel and the build would have
stayed green. `Ingot`, `Uid`, `MetadataCache` (`psr/cache`) and `Logging` (`psr/log`) are layers
of their own now, everything else outside `App\` lands in `Framework`, and the ruleset says
which inner layer may reach which. PHP's own classes are left out — a model that cannot name
`\DateTimeImmutable` is not a model. `Uncovered` is `0`, which is the point: a fifth dependency
in the model is a decision made in that file rather than by an import nobody noticed.

## Who may do what

**Nobody is anybody here yet, and that is the one thing still missing.** This service has no
authentication, no authorization and no concept of an actor — no user, no tenant, no API key,
no rate limit. A form's UUID is the whole capability: whoever has it may act, and whoever has
not is expected not to guess it (UUIDv7, 74 random bits).

The answer is worked out in [`.claude/plan/09-access.md`](../.claude/plan/09-access.md) and is
**built**: the addresses are split so a gateway can write one rule per audience, and a form records
who created it, who locked it and who entered every save. What remains missing is the gateway
itself, which is a deployment and not code here. The short version, because it decides what this
service does and does not contain:

**This service will authorise nothing.** A gateway decides what may reach it, per prefix and per
method — [deploying-behind-a-gateway.md](deploying-behind-a-gateway.md) is the recipe, with a
runnable example in `examples/gateway/`. A decision point outside — owned by the system that created the form — answers "may this
caller touch *this* form", because whoever created a form already knows who may touch it, and that
answer is theirs to keep. The form id is not a public credential either: a person reaches a form
through the object of the superordinate system that uses it, after that system's own authorization,
and the pages sit behind the same SSO. Nothing under `/api/forms/` or `/forms/` is meant to be
called from the open internet.

**What lands here is one header and one record.** `X-Forms-Identity`, read only from a configured
trusted proxy, written beside every save.

| Question | Answered by |
|---|---|
| may this request reach the service at all | the gateway, per prefix and method |
| may this caller touch **this form** | a decision point outside, asking the system that created it |
| who is calling | the gateway, asserting `X-Forms-Identity` |
| what is recorded about who called | **this service** |
| may this form be saved without an identity | **this service**, from the form's own mode |

**The routing split is the part that is code here**, and it is the deliverable: four prefixes, one
per group, with the form id always the first segment after the prefix — so a gateway rule is one
line and a decision point extracts the id with one pattern per prefix.

| Prefix | Group | Who |
|---|---|---|
| `/api/manage/` | management | the superordinate system: create, the envelope, delete, history with actors |
| `/api/forms/` | filling, API | whoever that system let through to this form |
| `/forms/` | filling, pages | the same person, in a browser |
| `/api/schemas/` | public | anybody, deliberately — it is the contract a client writes against |

`RouteGroup` is where those four are named, and two things moved to make them true.
**`GET|DELETE /api/forms/{id}` became `/api/manage/forms/{id}`** and `POST /api/forms` became
`POST /api/manage/forms`, because the envelope hands over the definition and the values together and
creating a form is not something a filler does; the pages never used the envelope (they read
`…/presentation` and `…/data`), so nothing in the browser noticed. And **the locale left the page
paths**: `/{_locale}/forms/{id}` broke the split twice over — a rule on `^/forms/` misses it
silently, and the form id sat at position 2 or 3 depending on whether a language was in the URL — so
a page is `/forms/{id}` or `/forms/{id}/versions/{seq}`, and a language is `?lang=xx`, put where the
framework already looks for it by `PageLocaleListener` and answered by `Accept-Language` when it is
absent.

**There was no deprecation window, deliberately.** Serving `POST /api/forms` for one release would
have left a management address under the prefix a gateway opens for filling — so the compatibility
layer would itself have been the hole this change closes. A deployment has to move three URLs, and
it has to reconfigure its gateway anyway, which is the whole point of the change.

**Both properties are checked, over the whole collection at once.** `RouteGroupsTest` asserts that
every route falls in exactly one group and that every route naming a form has the id straight after
its group's prefix — no single file shows either, and a route added outside the four would otherwise
land outside every rule in front, silently. It is a test rather than a compiler pass because routes
are not visible when the container is compiled; `make ci` is the gate. And
`bin/console app:routes:groups` prints the table — group, prefix, methods, path — sorted by path, so
a deployment can diff it in its own CI and see a route appear or move. A gateway holding a stale
copy of the route table is the failure this whole section exists to prevent.

**Four of the five permissions are then gateway rules**, with no code here at all:

| Permission | Rule at the gateway |
|---|---|
| create | `POST /api/manage/forms` — exact path |
| manage | `/api/manage/**` — prefix |
| read | `GET` only, under `/api/forms/` and `/forms/` — method |
| confirm | `POST /api/forms/{id}/confirm` — its own path |
| fill | mutating methods under `/api/forms/` |

Two more rules have nothing to do with permissions and are the ones most often forgotten, because
they are not routes at all — no `RouteGroup` claims them and `app:routes:groups` prints them below
the table for exactly that reason:

| What | Rule at the gateway |
|---|---|
| the pages' JavaScript and CSS | `GET` under `FORMS_ASSETS_PREFIX` (`/assets/` by default) — public, cacheable, no identity |
| nothing else | there is no other static path: the plain kit's module moved under the same prefix |

A gateway that passes the forms through and drops their stylesheet has followed the table above to
the letter.

The fifth — "this caller, for *this* form" — is the one a gateway cannot express on its own, and it
is delegated rather than deferred: the decision point receives the form id from the path and asks
the system that created the form. It is a hop on every request, and when it is down it **fails
closed** — a guard that opens under load is not a guard. Which makes it a hard dependency of the
fill side, so it belongs close (a sidecar rather than a service across a network), and positive
decisions may be cached for seconds while refusals are not cached at all: a revocation then takes
effect one cache lifetime late, which is the ordinary bargain.

**What identity is.** Three slots and no fourth: the **author** on the form, asserted at creation;
the **confirmer** on the form, which needs a slot of its own because confirming writes no values and
is therefore no revision of its own; and the **filler** on every revision. "Who last changed this
form" is not stored — the newest revision already answers it. All three go through the same
discard: an `anonymous` form records nobody, the author included.

`Actor` (one opaque subject) and `IdentityMode` are the domain's; `IdentityIntake` is the boundary's
— a value resolver, so an action declares `?Actor` as an argument and passes it to a use case.
Nothing is ambient and nothing is a holder service: the only thing that can attribute a write is
what was resolved for that one request.

`identity: recorded | anonymous` is a third top-level property of a form beside its definition, its
values and its `expire_date` — given at creation with `POST /api/manage/forms`, immutable, and
**defaulting to `recorded`**.
`anonymous` means nobody is stored *even when the proxy asserted somebody* — the filler, the
confirmer and the **author** alike — which is the one half of this that cannot be delegated, so the
aggregate discards it. The author was the exception once, on the reasoning that creating happens
where a caller is always known; that made "this form records nobody" a sentence in a document
rather than a property of the form, and behind a proxy asserting on every request it named the
creator of every anonymous form. The mode is the creator's own configuration: asking for a form
that records nobody is asking for that about oneself as well, and a system that created a form has
not forgotten that it did. Rows written under the old rule were repaired by
`Version20260904090000`, which is possible precisely because this is one column rather than a
forgeable header.

Discarding is never *demanding*: creating a `recorded` form with nobody asserted is allowed, since a
deployment may put its proxy in front of the pages and not in front of whoever creates the forms. It
defaults to `recorded` because the two options fail differently: `anonymous` by default fails
silently and unrecoverably, `recorded` by default fails loudly at the first save.

An absent header falls back to **`FORMS_IDENTITY_FALLBACK`**; with that unset, a save on a
`recorded` form is refused (`IdentityRequired`, `403`). That is deliberately the only thing this
service checks, and it earns its place — it is what makes a proxy that quietly stopped sending the
header visible instead of recording `unattributed` forever. The fallback value should be reserved
and obviously not a person (`unattributed`, not `system`), so a row saying "nobody told us" is a
fact rather than something mistakable for a subject.

**Confirming is judged the same way a save is**: closing a form is something the person filling it
in does, so an `anonymous` form records nobody as its confirmer however much the proxy asserted. A
promise of anonymity that names whoever pressed "send" is not a promise.

**Asserted, never claimed**: identity arrives in `X-Forms-Identity` and nowhere else, and nothing
new enforces that — request bodies are already closed, so a client sending `actor` or `author` gets
`request.unexpected_key`. **Never displayed**: no page draws an actor, no catalogue holds a
word for one, both kits and every skin are untouched. Served on the **manage side only** — the
envelope grows `identity`, the author and the confirmer, and a new
`GET /api/manage/forms/{id}/history` carries the actor per save, while the fill-side history stays
exactly as it is. That is what keeps one person who reached a form from learning who else filled it
in. And **no display label**: with nothing rendering it, its only reader is the system that already
knows how to turn a subject into a person.

`forms` carries `identity_mode` (not null), `author_subject` and `confirmed_by_subject`;
`form_revisions` carries `actor_subject`; all written from the events that already record what
happened (`FormCreated`, `DraftSaved`, `FormConfirmed`), so a column changes because something
happened rather than because a copying routine remembered it. The migration is what makes the column
readable: nothing can backfill who filled a form in last year, so `actor_subject` arrived nullable
and `identity_mode` arrived **NOT NULL, backfilled `anonymous`** — truthful, since nobody was
recorded. With the fallback filling every other hole, `NULL` then means one thing only: an anonymous
form.

**The validation that matters is at the boundary, not in the model.** The header is read only from
an address in `FORMS_TRUSTED_PROXIES` (Symfony's own machinery only sanitises `X-Forwarded-*`, so
consulting the list for a header of ours is the intake's decision — it reuses the list rather than
keeping a second one). It must arrive once: a repeat is refused, and so is a value containing a
comma, because PHP folds repeated headers into one comma-joined string and by the time it is here
`a, b` looks exactly like a subject somebody chose. A deployment whose subjects contain commas — a
certificate DN — namespaces or encodes them. And a header that is there and unusable is a **refusal
(400 `identity-not-valid`)** rather than a quiet fall back to the fallback: falling back would
attribute the save to the wrong person and hide a broken proxy for months.

`Actor` itself is one opaque subject: 1–255 **characters** (not bytes — a cap on bytes would refuse
a legal identifier in half the world's alphabets), no control characters, no leading or trailing
whitespace, valid UTF-8, no normalization, no format imposed and none parsed. It is stored verbatim,
namespaced by the deployment if it ever has two identity sources (`sso:12345`), and never judged
again when read back — the way a stored definition is not. A refusal never echoes the value: a
subject may be somebody's email address, and an exception message travels into logs.

**Recorded, never consulted.** Nothing here decides anything from an actor, and "which forms did
this person fill in" stays as refused as the form-list endpoint. The columns this adds are exactly
what would make both cheap, which is why the fence is written down.

Two operational consequences worth knowing before the columns exist. A **subject can be personal
data** — a deployment whose subjects are email addresses has put personal data in every revision
row; opaque identifiers are the cheaper answer, and the history leaving with its form by foreign key
does the rest. And **`FORMS_HISTORY_LIMIT` evicts old saves**, so the record of who filled a form in
can be evicted while the form still lives: correct for a history limit, wrong for an audit trail, so
a deployment that wants the second sets the limit to `0` and pays for it.

Until identity lands, it is all the deployment's — and the split is what makes each of these one
rule instead of a regular expression:

- **`/api/manage/**` needs a gate.** Creating, the envelope (it hands over the definition and the
  values together) and deleting all live there now, so one prefix rule reaches them and nothing
  else. They have to be unreachable from wherever the people filling forms in are. Nothing here
  will do that for you, and nothing here will notice if you forget.
- **Leave `/api/schemas/**` open.** The two meta-schemas are meant to be readable by anybody — they
  are the contract a client writes its definitions against, and putting them behind the management
  gate makes that contract unreadable to whoever needs it first. This is why the public group is
  named rather than left implicit.
- **A form link is a password today**, because the id is the only credential. It travels in a URL,
  so it lands in browser history, in `Referer` headers, in proxy and access logs, and in whatever a
  person pastes it into. Keep it off the open internet and hand it out from the system that created
  the form — which is what the plan above makes structural.
- **A form link also hands over the history.** `GET /api/forms/{id}/history/{seq}` serves every
  save the form has ever accepted, so sending the link to a second person shows them the first
  person's answers. That is inherent — putting an old document back is an ordinary
  `PUT …/data`, which is what makes it possible at all — and it is worth knowing before a link
  is forwarded.
- **`POST /api/manage/forms` is an unauthenticated write**, and the only one: whatever fronts it is
  where a rate limit goes, and its living under `/api/manage/` is what lets a gateway close it with
  the rest of management.
- **When SSO lands in front, the proxy must answer `401` rather than redirect** for anything that
  is not a navigation. A page saves with `fetch`, `fetch` follows redirects, and a login page
  arriving as `200` with HTML would tell somebody their answers were stored when they were not.
  Both kits already degrade an unparseable refusal to their own wording, so a `401` reads correctly
  with no change here — a redirect does not.

## Where this service is installed

Nothing in here knows its own address. Every URL a page carries is generated — the page itself,
the endpoints it writes to, the module that drives it, the stylesheets — which is what makes the
same build serve a form at `https://forms.example.com/forms/{id}` and at
`https://gateway.example.com/svc/forms/{id}` without an edit. Two mechanisms, one per kind of
gateway, and a deployment picks the one its gateway can do:

| The gateway | What it sends | What is configured here |
|---|---|---|
| answers on its own host | `/forms/{id}` | nothing |
| answers on a path and **strips** it | `/forms/{id}` + `X-Forwarded-Prefix: /svc` | nothing — the header is believed from a trusted proxy |
| answers on a path and **passes it through** | `/svc/forms/{id}` | `FORMS_BASE_PATH=/svc` |
| serves the static files itself | — | `FORMS_ASSETS_PREFIX`, or a CDN URL, and `asset-map:compile` output |

**`X-Forwarded-Prefix` is runtime and free.** It is trusted exactly like `X-Forms-Identity` —
only from an address in `FORMS_TRUSTED_PROXIES`, and for the same reason: it changes what this
service says about itself, so a client must not be able to say it. With it, the router, the asset
package and therefore every address on a page carry the prefix, and the service answers on the
paths it always did.

**`FORMS_BASE_PATH` is build-time, and that is not a wart but the mechanism.** Routes are declared
when the container is compiled, so a prefix they are declared *under* is read there
(`App\Kernel::build()`, `config/routes.yaml`). Consequences worth knowing: changing it needs the
cache rebuilt (a deploy's `cache:clear`, or `make cache-clear`), and nothing warns about a stale
one — which is why `app:routes:groups` prints the base path it is actually serving under, beside
the static prefix that is not a route at all. Write it as `/svc`, `svc` or `/svc/`; all three
normalize to the same routes.

**The four audience prefixes stay fixed on purpose.** A base path is enough to make several of
these services unique behind one host — `/forms-a/api/forms/{id}` and `/forms-b/api/forms/{id}`
collide nowhere — and making the four configurable as well would buy nothing while turning a
gateway rule into a guess and `RouteGroup` into a description of one deployment.
`RouteGroup::of()` takes the base and asks its four questions about what follows it, so the two
properties a gateway depends on hold in every installation; `RouteGroupsTest` asserts them both
ways.

**What is deliberately not supported**: rewriting paths inside the application (if the service
knows its base there is nothing to rewrite, and if it does not, no rewrite fixes the addresses
already in the HTML), and serving the pages and the API from different origins (it buys nothing a
path rule cannot do, and costs CORS, cookie configuration and the page's standing as a client of
the API next to it).

**A page reaches nothing outside this service, and one entry in `importmap.php` is what makes that
true.** `importmap()` puts an import-map polyfill on the page for a browser that has none of its
own, and with no entry in the map AssetMapper falls back to a URL on `ga.jspm.io` — unfetchable in
an installation with no egress, and refused by any CSP that does not name that host. So
`es-module-shims` is required into the import map like every other dependency: committed under
`assets/vendor/`, served under `FORMS_ASSETS_PREFIX`, nothing imports it. An installation that
would rather not carry the 48 KB, and does not care about browsers older than import maps
(Safari 16.4, 2023), sets `framework.asset_mapper.importmap_polyfill: false`.

## The model, and what it refuses

**A form judges what it is asked to hold.** `Form::saveDraft()` and `Form::confirm()` run
the values past the `ValuesValidator` port before storing anything, and the form itself picks
the contract that applies — lenient while filling in, strict at confirmation. Nothing can
store values that do not fit a definition by forgetting to ask; the same methods refuse a
confirmed form (`FormLocked`), a second confirmation and an empty one. A use case is left
with when it happens: one transaction, a locked read, a write.

**A condition is data the definition carries, and it is read in three places that may not
disagree.** `askedWhen` / `requiredWhen` on any item (`Condition`, in
`src/Domain/Forms/Definition/`) is one recursive shape: a test of one item, or a combinator of
other conditions. `DataSchemaDeriver` derives it into the published schema, which is what
*enforces* it — `else: {properties: {nip: false}}` refuses an answer to a question nobody was
asked, and `then: {required: [nip]}` is the obligation, in the strict contract only.
`Condition::holds()` asks the same question of the same document, for the two readers that have
no browser: the server drawing a page before the first paint, and the printed record of a
confirmed form, which must not show a question nobody was put. And each kit asks it again after
every keystroke, because what decides a question may be an answer somebody is typing now. Two of
those readings could drift, so a test holds them together
(`ConditionsAgreeWithTheSchemaTest` puts every condition and a table of documents to both and
insists they agree); the third is pinned by the browser battery. What refuses a condition that
could never work — an unknown item, a ring, a value the item cannot hold — is
`ConditionsMakeSenseValidator`, at creation, where somebody can still fix it. The `form` gate is
told to demand nothing of a conditional item (`FormValuesType`), because only the schema can see
a condition.

**A form counts its own saves, and a caller may hold that number.** `Form::revision()` is how
many saves this form has accepted — `0` for one nobody has filled in — and it is the `seq` of the
newest revision. Both transitions take an optional `ExpectedRevision`, and refuse with
`FormMovedOn` when the caller was looking at an older form than the one it is writing to. The
check is the aggregate's, first in the method and before the values are judged, for the reason
every other invariant is there: a use case that re-asked it would be asking outside the lock,
where the answer can stop being true between the question and the write. At the boundary it is
`If-Match` on `PUT …/data` and `POST …/confirm`, read by `RevisionIntake`, answered with `412`,
and served as the `ETag` of `GET /api/forms/{id}/data` plus `revision` in the envelope. Nothing
about it is mandatory: a caller that says nothing writes unconditionally, exactly as before it
existed.

**Each transition records what happened** (`FormCreated`, `DraftSaved` — which carries the
values it stored — `FormConfirmed`), and `releaseEvents()` hands them over. That is what the
repository writes from: a column changes because something happened, not because a copying
routine remembered it, and a transition no adapter knows how to store stops the write instead
of vanishing from it.

**The aggregate is not a Doctrine entity.** Doctrine maps `FormRecord` — a row with public
fields, ORM attributes and no idea a form exists — and `DoctrineFormRepository` translates
both ways. So the model has no mapping, no constructor to bypass and nothing done to it after
a read, which is what let `Definition` become a value that carries both the stored document
and the structure parsed from it.

## Storage

Storage is two tables, `forms` and `form_revisions`, mapped with portable types only (`uuid`,
`text`, `datetime_immutable` in UTC) so the service installs on PostgreSQL, MySQL/MariaDB or
SQLite alike — point `DATABASE_URL` at it and run the migration, which is built through Doctrine's
schema API rather than raw SQL. The definition is stored **normalized**
(`TreeMapper::normalize()` output) as the exact JSON text that passed validation, and so are
the values: PHP arrays cannot tell an empty object from an empty list, and those bytes are
handed back to clients verbatim. Status is derived from the row (`data IS NULL` /
`confirmed_at`), never stored; state transitions run under `LockMode::PESSIMISTIC_WRITE`.

`form_revisions` is one row per accepted save, holding that save's whole values document as the
same exact text. It is append-only, `(form_id, seq)` is the whole key — a revision is one save of
one form, and nothing points at it from anywhere — and `seq` is allocated under the row lock the
save already holds, because a sequence of the database's own would number across forms. Two
things bound it, and both are the database's rather than a caller's: `form_id` references
`forms.id` **ON DELETE CASCADE**, so a form can never outlive the history it used to have — a
constraint the ORM cannot declare, because a foreign key comes with an *association* and a
revision deliberately has none, so `RevisionsLeaveWithTheirForm` states it on
`postGenerateSchema` instead and `SchemaInSyncTest` keeps the mapping and the migrated database
agreeing about it; and
`FORMS_HISTORY_LIMIT` is how many saves one form keeps, the oldest leaving in the same statement
that appends the newest. The number a save takes comes from `forms.revision`, a count of accepted
saves on the form's own row: the history is not the truth about how many there have been, because
the limit evicts the oldest, and a `MAX(seq)` over what is *kept* would start renumbering saves
the moment it did — besides being one more query on a row the save already holds locked. Neither the row nor the revision can be written without the other —
both come from one `DraftSaved` — which is what makes "the current values are also the newest
revision" true, and everything that asks what a form has **ever** named leans on it.

## How values are judged

**ingot validates the form definition** — meta-schema, typed tree, semantic rules — and
derives the per-form JSON Schema published to clients. **Submitted values pass three gates**
(`src/Infrastructure/Validation/`), cheapest first:

1. the **derived schema**, cached per form and mode — the same document
   `GET /api/forms/{id}/schema` serves, so the server can never be looser than its own
   published contract. Findings carry `schema.*` codes. The cache holds something this code
   derived, so it is thrown away whenever those rules change (`make cache-clear`); in dev it
   is in-memory and never outlives the process.
2. the **Symfony form** built from that definition — every item type becomes the matching
   form type, unknown (plugin) types pass through untouched, and rules a schema cannot state
   have somewhere to live as the catalogue grows. Findings carry `form.value.*` codes. Today
   the schema says everything the catalogue can say, so this stage rarely speaks; the battery
   in `tests/Infrastructure/Validation/Field/` proves it never refuses what the schema
   accepts.

3. the **referenced files** (`ReferencedFilesExist`) — for every `file` position the values
   answer, the store must hold that id under this form, described exactly as the store
   describes it. Findings carry `form.file.unknown` and `form.file.mismatch`, pointed at the
   member that is wrong (`/invoice/id`, `/attachments/0/scan/size`). This is the **one place
   the server is stricter than its published contract**, and it is deliberate: no schema can
   state "this id exists", any more than it can state "this form has not expired". A client
   that echoes the upload's answer back verbatim can never trip it.

**A refused document is looked at more than once, and an accepted one is not.** opis takes a
schema level in *phases* — the keywords of one phase are reported together, and nothing after the
phase that failed — and `allOf` stops at the first branch that did not hold. So a document missing
a member used to hear nothing about the obligations a conditional branch would have named, and a
document failing two branches heard about one. ingot's `OpisSchemaValidator` asks each branch of a
conjunction on its own and merges the answers, which needs no re-pointing, because a branch
applies to the same instance as the schema holding it. `allOf` only, and the reason is the
mirror image: an `anyOf` or `oneOf` branch that did not hold is a road *not taken*, so the
library reports an alternative as **one** finding at the value that fitted nothing, with what
each shape wanted in the message — reported per branch it would say "add this" and "add that"
about a value that needs one of them. Nothing here derives an alternative into a reportable
place (a condition's `any` becomes an `anyOf` inside an `if`, and a failing `if` says nothing at
all), so no form has ever shown a `schema.anyOf`; the rule matters the day one does. It happens **only when the document was refused**, so nothing
on the accepted path pays for it; a branch that names something in the document around it (a
`$ref`, an `$id`, `unevaluatedProperties`) is left to opis, because away from that document it
would be a different question. What still arrives in two rounds is a scope reached through
`properties`/`items`: inside a list entry, an answer the entry always owes is reported before a
conditional one in the same entry.

4. the **calculations** (`CalculationsAgree`) — every `number` that says what it is worked out
   from is worked out again from the same document and compared, rounded to the places the item
   declares. Findings carry `form.value.miscalculated`, at the member's own pointer, in every
   scope. This is the **third place the server is stricter than its published contract**, and the
   reason is the same as the other two: no schema can state "this equals the sum of that across
   that list". The client works the number out and sends it, because a server that filled the
   member in would make the stored document something the client never sent — and the page is a
   client, so it does the same arithmetic after every keystroke.

Values refused by the schema never reach the form: on this project's example definition the
schema answers in ~60 µs where building and running the form costs ~670 µs, so a payload
that was never going to fit is rejected without that work. The store is asked last, so
nothing pays for that gate until the document is otherwise perfect.

The definition meets Symfony validation through a custom constraint
(`src/UserInterface/Api/Request/Constraint/ValidFormDefinition`) on the create DTO; values
are checked by the aggregate itself — `Form::saveDraft()` and `Form::confirm()` judge them
through the `ValuesValidator` port before anything is stored, so unfit values are refused by
the model rather than by whoever remembered to ask. Findings keep their JSON Pointer, and `ViolationReportFactory` turns every violation back into the
same `errors[]` shape — so the error format never depends on which engine refused the
request. A test asserts the form and the published schema reach the same verdict, so the
contract clients validate against cannot drift from what the server enforces.

Requests are mapped into **DTOs by Symfony** before an action runs
(`#[MapRequestPayload]`, `#[MapQueryString]` over `src/UserInterface/Api/Request/`), and validated by
`symfony/validator`. Every DTO member is non-nullable, so an instance means a complete
request; what the mapper could not supply is reported at its pointer before validation
runs. Ids are `Uuid` value objects, request bodies are accepted as `application/json` and nothing
else (`415` otherwise), bodies are closed (an undeclared member is `request.unexpected_key`),
and query strings ignore unknown parameters the way HTTP clients expect. Members document themselves with swagger-php's `#[OA\Property]`
(description, example, type/format), which is what the published schema is generated
from.

## The published contract

The contract is generated from the code by **NelmioApiDocBundle**: routes, the request DTOs
behind `#[MapRequestPayload]`/`#[MapQueryString]` (with their `Assert` constraints and
`#[OA\Property]` prose) and the `#[OA\Response]` attributes on the controllers. What cannot
come from a route — the document's identity and the shapes shared across operations
(`Problem`, `FormEnvelope`, the reusable 400/404/410 responses) — lives in
`config/packages/nelmio_api_doc.yaml`. `make docs` dumps it all into [`docs/`](docs/):

| File | What it is |
|---|---|
| `docs/openapi.yaml` | the contract, dumped by `bin/console nelmio:apidoc:dump` |
| `docs/api.md` | browsable Markdown reference rendered from the same document |

Two of the contracts are **not** in that document, and are served instead. What a definition and
a presentation may say is stated once, in the meta-schema each is mapped through
(`MetaSchema`) — duplicating either into the OpenAPI document would be a second truth, and
naming a path inside this repository would be an address only its authors can reach. So
`GET /api/schemas/definition` and `GET /api/schemas/presentation` hand over the very files the
mapper enforces, and the OpenAPI descriptions point at them.

`tests/UserInterface/Api/OpenApiComplianceTest.php` validates **both halves of every exchange** against
`docs/openapi.yaml`: each request must match the operation it targets (or, when a scenario
deliberately breaks the contract, must be refused by it), each response must match the
documented status, and every documented operation + status needs a scenario. So a DTO, the
implementation, and the published document cannot drift apart in any direction.

The derived schema is what a future frontend validates against (Ajv/Zod) — the server
uses the exact same document, so the contract cannot drift.

## The pages

```
GET /forms/{id}                    the form, drawn by the kit its presentation names
GET /forms/{id}?lang=pl            the same, in a language the reader asked for
GET /forms/{id}/versions/{seq}     an earlier save of it, read-only
```

Deliberately **not** under `/api`. Everything there speaks JSON and reports problems as RFC 9457
documents; a page for a person is a different contract, so it lives at a different root, answers
failures as pages (`_errors: html` on the web routes says which), and is absent from the
published API document.

**A page is a client of the API, not a second way in.** It reads through the same use case the
JSON endpoints use, and what somebody types goes back through `PUT /api/forms/{id}/data` and
`POST /api/forms/{id}/confirm` from the browser. There is no privileged path: whatever a page
can do, any client can.

What each kit can draw, control by control, is [kits.md](kits.md); this is how the two halves
fit together.

**A kit is two halves in two layers.** `PresentationEngine` in the domain says what can be drawn
— that is what a presentation is judged against — and `FormRenderer` in the web layer draws it.
HTML never reaches the domain and the widget vocabulary never leaves it, which is what makes
adding a kit a class plus a template rather than a second understanding of what a form is.

What the two kits share is the **resolved tree** and a markup convention. The tree is typed:
`PresentedNodes::of()` answers with `list<PresentedNode>`, and there are three of those because
there are three genuinely different things to draw — a `ValueNode` (an item and the answer it
holds), a `CollectionNode` (a list, its entries and one blank one), and a `BranchNode`
(everything that presents no value: a container, an action, a decoration). Code walking the tree
asks the type, so a caption can never be asked for its value and nothing has to check whether it
may; `kind` is carried beside it for the templates, which cannot ask `instanceof`. The markup
convention:

| Attribute | Means |
|---|---|
| `data-name`, `data-type` | this element holds the value of that item, and what it is on the wire (`json` for a file description) |
| `data-error="name"` | where a refusal about that item goes |
| `data-collection`, `data-entry`, `data-cell` | a list, one entry of it, one previewed value |
| `data-item`, `data-choice` | one presented item; a group of radios rather than a single control |
| `data-asked-when`, `data-required-when` | the condition this question waits on, as the document it was written as |
| `data-unasked` | this question is not being asked of these answers: hidden, and **its answer is not collected** |
| `data-out-of-sight` | drawn with the `hidden` widget — a client fills it in, and its answer travels |
| `data-star` | the star that says an answer is owed, which a condition can bring about |
| `data-pager="steps"`/`"tabs"` | one form in parts, and which of the two looks it is drawn in |
| `data-page`, `data-page-mark` | one of those parts, and the thing pressed to reach it |
| `data-wizard-nav`, `data-wizard-status` | a wizard's own chrome: the track, and where somebody is in words |
| `data-wizard-back`, `data-wizard-next` | the two buttons a wizard draws for itself, and tabs do not |
| `data-chrome` | this element *acts* rather than says something: a switch, a trigger, a pager's own navigation, an upload's progress. Hidden on paper, and marked rather than listed so the next widget is covered by the convention |
| `PresentedNodes::PENDING` | the token a blank entry carries where its own scope would be |

**Structure carries identity.** Values are collected scope by scope in the order entries appear,
so adding or removing one renumbers nothing, and a pointer like `/lines/1/quantity` is resolved
by walking that same structure back down. A blank entry is markup the server rendered, waiting
in a `<template>` — a kit never builds markup in JavaScript — and cloning one replaces the token
in every `id`, `for`, `name`, `aria-labelledby` and `aria-describedby` it carries, because all
five are names and pointing at another entry's is how a question comes to be read out twice.

**Which questions a page asks is worked out again after every keystroke**, and in each scope on
its own — a condition names an item declared beside it, so an entry's questions are answered by
that entry's answers. Each kit has its own evaluator of the same closed vocabulary (thirty lines
in the plain kit's module, the same in the richer kit's `form` controller), and it repeats until
nothing moves: a question may be asked on the strength of an answer to a question that is not
being asked, and an unasked answer is no answer at all. A ring is refused at creation, so it
always settles. `data-unasked` is the fact the collector reads, which is what keeps a page from
ever producing a document the schema's `else` would refuse.

**A number worked out from the answers is worked out on the page too**, and in a fixed order:
conditions first, then the totals, then which pages of a pager are worth showing. One pass each,
and that is a property of the model rather than luck — a condition may not be asked on the
strength of a calculated number (`form.condition.on-a-calculated-number`), so nothing a total
changes can change which questions are asked. Within the totals it is deepest first — a line's own
amount before the scope that reads it — and inside one scope a pass per level, because a total may
be worked out from a total and a document may declare them in either order; it terminates because
rings are refused at creation. The control is `readonly` and carries
`data-calculated`; the places to write are read off its own `step`, which is where the
definition's `decimals` already is.

**Paging is presentation, and the invariant that says so is in the markup.** `wizard` + `step`
and `tabs` + `tab` are container widgets both kits declare (the four containers they share: a
fieldset is not a card, but paging a form is a structure rather than a look). Every page is
rendered and all but one carry `hidden`, so the collector reads the whole form whatever page is
showing — a page has no contract of its own, and `PUT …/data` carries every answer on every one.
The pager is per kit (a Stimulus controller, and a few dozen lines in the plain kit's module) and
does three things beyond moving: it steps over a page whose every question a condition left
unasked, it draws no mark for such a page, and it takes no part in validation — *next* always
moves, because a page that stopped somebody for being under a floor would enforce an obligation
the server only asks about at confirmation. Which pages are worth showing follows from which
questions are asked, so the form controller announces that it asked them (`form:asked`, and
`refreshPagers()` in the plain kit) rather than the pager reaching into conditions.

**One mechanism, two looks, and the code is named after the mechanism.** A wizard and a strip of
tabs differ in what a reader is told and how they move — an ordered sequence with *back*, *next*
and "Step 2 of 5", or peers in a `tablist` with `aria-selected`, one tab stop and the arrow keys
— and in nothing else, which is why one controller and one module function serve both and the
shared markup says `pager` rather than `wizard`. Three things are read off `data-pager`'s value
and no more: which attribute marks the page being shown, whether the marks are one tab stop with
arrows between them, and whether there is a *next* to disable. It is worth saying why tabs are
not a restyled wizard, since this repository refuses a widget that is: the marks of a wizard have
always been clickable, so *jump anywhere* is not the difference — the roles and the keyboard are,
and neither can be reached from a stylesheet.

**A message nobody can see is not a message.** A refusal about an entry unfolds every form on
the way to it, marks each entry it is inside so the row still says "look here" once folded back
up, sets `aria-invalid` on the control, and moves the caret there. A page of a pager hides a
message the same way, so the refusal brings that page forward first — said at the control
(`pager:reveal`), because whichever pager holds it is the one that has to move.

**The same page prints, and the sheet that says how is short because of one convention.** No
print styles existed until [24](../.claude/plan/24-on-paper.md): `Ctrl+P` printed the screen as it
stood, which for a paged form meant one part of it. `@media print` in both kits now reveals every
`[data-page]`, forces the palette to ink on white whatever the reader chose, flattens a control to
a line, keeps an entry whole on a sheet, and hides everything marked `data-chrome` — one rule
instead of a list of selectors per kit. Two rules could not be written in CSS at all and are
worth knowing about: **a question nobody was asked stays hidden** (the same rule the archival
record keeps, and the one thing print must not undo), and **a folded `details` cannot be opened by
a stylesheet** — measured, including `::details-content` — so each kit opens folds on
`beforeprint` and closes them again on `afterprint`. It is testable, which was the surprise:
Chrome's `Emulation.setEmulatedMedia` through chromedriver's `goog/cdp/execute` makes a real
browser lay the page out for paper, and the battery asks the layout rather than the stylesheet.
This is **not** the record (`GET …/pdf`): that is a confirmed form laid out by a library, on the
management prefix, with the author and the confirmer on it.

**A page is read in one language, and the document decides which.** What the
framework negotiates (`?lang=`, then `Accept-Language`, then the configured default) is what the
reader *asked* for; what the page is drawn in is what the presentation can answer in
(`Words::spokenIn()`, asked once in `ViewFormAction`, which sets it on the translator and hands
it to the renderer). A form carrying only a Polish catalogue answers a reader who asked for
English in Polish — and before this, only its *questions* did: the page's own words, a refused
answer among them, stayed in the language of the request, so a Polish form told somebody in
English that a consent had to be given.

**Two catalogues, on purpose.** The presentation carries the *form's* text as translation codes,
because that is the author's to write; `translations/messages.*.yaml` under `page.*` carries the
sentences this application invented — a stored draft, a closed form, the words on the reader's
own switches. No template holds a sentence, and what the browser needs is handed to it as a
value (`data-form-refused-value`, `data-refused`).

**Front-end assets are AssetMapper's.** `importmap.php` names what the browser may import,
`assets/vendor/` is committed (so a clone, CI and the browser suite need no network),
`assets/icons/` holds UX Icons imported once, and `make assets` refreshes the vendor files. No
build step, no package manager, no CDN. The `bootstrap` kit has one entrypoint per skin, each
importing its own Bootstrap and then the shared `kit.js`, so exactly one Bootstrap is ever
loaded. `core-html` imports nothing: one hand-written module
(`assets/pages/core-html-form.js`, asked for with `asset()` rather than by a path written into
the template) and thirty lines of inline CSS.

**Every address a page carries is generated, and that is a rule rather than a habit.** The page
itself and the four endpoints it writes to come from the router (`FormApi` is the one place that
says which routes a kit needs, handed over as `data-form-api-value` / `data-api`), the module and
the stylesheets come from AssetMapper under `FORMS_ASSETS_PREFIX`. A kit that built
`/api/forms/${id}/data` for itself would be claiming this service stands at the root of a host —
and it would keep drawing perfectly the day it does not, with every save answering 404. See
[Where this service is installed](#where-this-service-is-installed).

**The reader's own switches** (colours, contrast, text size) are attributes on `<html>`, applied
before the first paint by a scrap of inline script and kept in `localStorage` under
`ingot-forms:*`. Nothing reaches the server — an actor is recorded against a save, never resolved
into somebody with settings, so there is nowhere here for a preference to live.
Dark and high contrast are painted by the application's own stylesheet rather than left to a
skin: Bootswatch themes support dark unevenly, and chasing each theme's exceptions is endless,
so the skin keeps its shapes and fonts while the page a reader needs belongs to the reader.

## Telling somebody what happened

A form can name where it reports itself, per event, at creation:

```json
"webhooks": {
  "created": "https://owner.test/forms/created",
  "save": "https://owner.test/forms/saved",
  "confirm": "https://owner.test/forms/confirmed",
  "deleted": "https://owner.test/forms/deleted"
}
```

All four are optional and independent, all are immutable with the rest of the form, and a form
that names none — the default — costs nothing at all: **nothing is queued for it**. `created` is
for a receiver that is not the creator: the creator was handed the id in the response, while a
downstream mirroring these forms would otherwise meet one for the first time as a `form.saved` for
an id it has never seen. `deleted`
carries `reason`: `requested` when somebody called `DELETE`, `expired` when
`app:forms:purge-expired` reaped it. The second is what the event is for — nobody asks the purge
for anything, so an owner waiting on a form has no other way to learn it has gone.

**What arrives is a notification, not the data.** `{event, form, occurredAt, revision?, actor?}`
and no values, because a write never answers with the thing it wrote and this is the same rule
seen from outside: the receiver reads `GET …/data` or `GET …/history/{seq}` through the API it
already has. Three things follow, and they are the reason the shape is worth defending — nobody's
answers end up in a queue, a log or a proxy; a delivery is small enough that retrying is free; and
**order stops mattering**, since a receiver that reads current state cannot be confused by two
notifications arriving the wrong way round. That is what makes at-least-once an honest promise
rather than a caveat.

**It is an outbox, and the transaction is the whole point.** `Announcements::announce()` persists a
row *without flushing*, from inside the save it belongs to — so an announcement cannot exist for a
save that rolled back, a save cannot be committed without it, and nobody's endpoint being down can
slow a save down or refuse it. It is written in `DoctrineFormRepository`, beside the row and the
revision, because that is where the events are: `saveDraft()` records nothing when the incoming
document says what the form already holds, so **only the event knows whether anything happened**.

**Delivery is somebody else's turn.** After the transaction commits, the use case asks a worker to
get on with it (`Announcer::hurry()` → an `AnnouncementsOwed` message on
`MESSENGER_TRANSPORT_DSN`, Doctrine by default so a plain installation needs no broker). That
message **carries nothing**: the rows are the truth, and the nudge only says "go and look". Two
consequences, both wanted — a lost nudge costs latency rather than a notification, and there is
one retry policy instead of two that would have to be kept in agreement. `app:webhooks:deliver`
(cron, beside the two purges) sweeps the same rows, which is what makes the worker optional: a
deployment that would rather not run one pays a minute of latency and nothing else.

| Piece | What it is |
|---|---|
| `Announcement`, `Delivery` | what happened, and one waiting to be told with its id and refusals |
| `Announcements` | the queue: announce, what is due, told, again later, give up |
| `FormDeliveries` + `RecordedDelivery` | what one form still owes or could not deliver, read-only |
| `ReadFormDeliveries` + `ReadFormDeliveriesAction` | `GET /api/manage/forms/{id}/deliveries` |
| `Webhook` | the one call outward, and whether this deployment can sign at all |
| `DeliverAnnouncements` | take what is owed, try it, write down what came of trying |
| `WebhookAnnouncementRecord` + `DoctrineAnnouncements` | the rows |
| `SignedHttpWebhook` | body, headers, signature, timeout |
| `TellWhoeverIsOwed` | the worker's way in — one message, one use case |
| `app:webhooks:deliver` | the sweep |

**Signed, or refused before it exists.** `X-Forms-Signature: sha256=<hmac(timestamp.body)>` with
`FORMS_WEBHOOK_SECRET`, beside `X-Forms-Delivery` (the same id across every retry, so a receiver
can recognise one it already acted on) and `X-Forms-Timestamp` (which bounds a replay). With no
secret configured, a form naming an endpoint is refused at creation (`409 webhooks-not-signable`)
rather than accepted and discovered later: every notification it owed would be refused for the
life of the form, and its author would find out from a column in a queue.

**A refusal is not a failure.** Anything but 2xx, and anything that cannot be reached at all, is
the same thing from here — nobody has been told yet — so the delivery goes back in the queue with
a longer wait each time, doubling from two seconds to an hour, `FORMS_WEBHOOK_ATTEMPTS` (12)
refusals before it is given up on. A 4xx is retried like the rest, deliberately: a receiver
mid-deploy answers 404 for a minute. Rows leave with their form by foreign key, like revisions.

**A success is stamped on the thing it was about; the queue keeps only work.** Two corrections
led here and both are worth knowing. The first design deleted a told row, which left the table
able to prove a notification had been *lost* and unable to prove one had *arrived*. Marking the
row instead answered that, but made a queue that never drains and that holds three states, two of
which are nobody's work. So the fact moved to where the thing it is about lives:

| Question | Where it is answered |
|---|---|
| was this **save** reported, and when | `notifiedAt` on that save — `GET /api/manage/forms/{id}/history` |
| was the **confirmation** reported | `confirmNotifiedAt` on the form — `GET /api/manage/forms/{id}` |
| what is **stuck** | `GET /api/manage/forms/{id}/deliveries` — `owed`, or `abandoned` with the reason |

`told()` writes the stamp and drops the queue row **in one flush**, so a stamped save can never
sit beside a row still claiming somebody is owed the news about it. A deletion stamps nothing —
there is no row left to stamp, which is the whole content of the notification.

**The one announcement that outlives its form.** Every other points at `forms.id` with
ON DELETE CASCADE, because a notification about a form that is gone is worse than none — and a
`form.deleted` *is* that notification. A foreign key cannot say "cascade all of these except that
one", so the identity and the cascade are two columns: `form_id` is what the announcement is about
and is always set, `live_form_id` carries the key and is null for a deletion. The alternative was
dropping the cascade and sweeping announcements by hand on the way out, which trades a guarantee
the database keeps for two statements somebody has to order correctly. It is also why a deletion's
delivery cannot be read through `…/deliveries`: that endpoint reads the form first, and there is
no form. While owed it is visible in the deployment's log and in the table; once told it is gone. `webhook_announcements` is
therefore a work list: `gave_up_at` null is owed, set is abandoned, and nothing else is in there.
`form_revisions.notified_at` is the one column of that table that is ever updated — what a
revision *held* stays append-only, and this is about the telling rather than the document.

One consequence, accepted deliberately: **a stamp leaves when its row leaves.**
`FORMS_HISTORY_LIMIT` evicts old revisions and with them the record that those saves were
reported, which is the same rule the rest of the service follows — a document nobody can restore
is a document whose files stopped mattering. And a save reported *after* its revision was evicted
stamps nothing rather than recreating anything.

**Every delivery writes a record as it happens**, not only the failures: `info` for a telling,
`warning` for a refusal that will be tried again, `error` for one given up on. Each carries the
delivery id that went out as `X-Forms-Delivery`, so a line here and a line in the receiver's own
log are the same event; production logs at `info`, so the successful ones are there too. That is
the other half of the same correction — the queue says *that* somebody was told, the log says it
where somebody is already watching.

**Run one deliverer at a time.** `due()` takes no lock, so two runs would each send what the other
is sending. The promise is at-least-once and the delivery id is what makes a duplicate harmless,
which is a cheaper answer than holding a row lock across an HTTP call.

## The record of a confirmed form

`GET /api/manage/forms/{id}/pdf` answers with the archival copy of a confirmed form: every
question, the answer it was given, and who closed it and when. Four decisions hold it together,
and each is the kind that is cheap now and expensive later.

**It is not the page.** Rendering the existing HTML with headless Chrome was the obvious reuse and
was refused twice over. A deployment of this service needs PHP, a database and somewhere to put
files; wanting a browser as well — or a second container to talk to — is a demand on everybody
who installs it, for the sake of one endpoint. And a page is not a record: it carries triggers,
the reader's own switches and whichever skin the document named, so an archival document would
look different because a form was dressed differently. `dompdf` lays out a plain template of its
own (`templates/record/form.html.twig`), needs only `ext-dom` and `ext-mbstring` — both already in
the image — and is told it may fetch nothing at all, so a renderer that cannot reach out cannot be
made to reach somewhere it should not, or to hang on a network.

**It needs no presentation.** `PresentedNodes` refuses a form that has none, and rightly: a page
has nothing to draw. A record is of what was asked and what came back, and the definition says
both — so `FormRecords` walks the definition, and the presentation, when there is one, decides the
order, the labels and how an option reads. That is what keeps the deployments most likely to want
an archive (the API-only ones) from being the ones that cannot have it. Both readers resolve a
translation code through the same `Words`, so a label cannot read one way on screen and another on
paper.

**A question nobody was asked is not in it.** An `askedWhen` that did not hold of the confirmed
document leaves the item out entirely, because a record says what was asked and what was
answered — and a question that was never put is neither. Printed with a dash beside it, it would
read as an answer somebody withheld. `FormRecords` asks the condition the same way the schema
enforced it and the page drew it ({@see `Condition::holds()`}).

**It is on the management prefix**, which follows from the identity rule rather than from taste:
the record names the author and the confirmer, and an actor is served on that side and nowhere
else (see [Who may do what](#who-may-do-what)).

**Nothing is stored.** A confirmed form cannot change, so the document is the same every time it
is asked for; a copy kept here would be a second representation of the same values, with a
lifecycle of its own to clean up and a rendering that quietly stops matching the code that made
it. Whoever needs a frozen artifact keeps the bytes they downloaded, which is what an archive is.
A draft is refused (`FormNotConfirmed` → `409`): nothing is wrong with a draft, it is simply not a
record of anything yet.

A row is one of **four types** — `Answered` (a question and its answer), `Entries` (a list and
the documents in it), `Section` (a group under a heading) and `Filed` (one answered with a file)
— behind `RecordedRow`, for the reason `PresentedNode` is three: code walking a record asks what
a row is instead of checking which member happens to be there. `kind()` rides along only because
a template cannot ask `instanceof`. `Section` is what a labelled container becomes: the shape
goes, the words stay.

**An image in a record is drawn, not named.** A signature *is* an image, and a record saying
`signature.png — 8.3 kB` has described the answer instead of showing it — so `Filed` carries the
description and `DompdfRecordDocuments` reads the bytes through `FileStore` and hands the
template a `data:` URI. Three things stop it, and each is about what a renderer can be sure of: a
type outside `image/png`, `image/jpeg`, `image/gif`; anything past 4 MB, because a record that has
to be downloaded before it can be opened is not a document to file; and a missing **`ext-gd`**,
which dompdf needs for a PNG with an alpha channel — which is every PNG a browser canvas writes.
That extension is **optional**: without it the row stays a line naming the file, which is a worse
record and not a broken one, and the image this repository builds has it so the default is the
good one. Reading happens in the adapter rather than in `FormRecords` for exactly that reason:
whether an image can become a picture is a question about the renderer, not about the form.

The layers are the usual ones. `RecordSheet` and the rows are the reading (Application, no
rules and no idea what they will be rendered into), `FormRecords` builds it, `RecordDocuments` is
the port — one method wide, a sheet in and bytes out, so nothing about fonts or page sizes reaches
a use case — and `DompdfRecordDocuments` fills it. `FormRecords` is also **the one place in PHP
that turns a stored value into text**: the kits do it in Twig and in JavaScript, each for its own
controls, and a second copy of this reading is the thing to refuse.

## Where the bytes live

**The store** is `league/flysystem` behind one port (`FileStore`): a directory in development,
test and CI, S3 (or anything Flysystem speaks) in production, by configuration rather than by
code. Two objects per file, under the form that owns it — `{formId}/{fileId}` for the bytes and
`{fileId}.json` for the facts — because per-object metadata is the one thing Flysystem cannot
offer portably, and a second file is the same thing everywhere. Writing goes bytes first,
facts second; deleting goes the other way round; and a file whose halves disagree is reported
as *absent*, so anything half-written or half-deleted is invisible rather than wrong.
Ownership is not a column but the location: nothing can be asked for a file without being told
which form it belongs to.

**Nothing but this API ever reaches the bytes.** No presigned URLs, no public directory, and
the store lives outside `public/`. Downloads are always `attachment` with `nosniff`, because
bytes a stranger uploaded, served from this application's own origin, are an XSS vector the
moment a browser decides to render them. The bytes are streamed, so a large file costs a
socket rather than a request's worth of memory.

## Operations

**Configuration.** `.env.dist` is committed and documents every variable this service reads;
`.env` is the local copy (`make env`) and is not in the repository, which is why nothing in
`.env.dist` may be a real secret. Symfony reads `.env` when it is there and `.env.dist` when it
is not, so a fresh clone runs before anybody configures anything. Precedence, highest first: the
real environment a container or unit exports → `.env.local` → `.env` → `.env.dist`. `.env.test`
holds the test database and a fixed `APP_SECRET` that is not one, and is committed because CI
needs it.

- **Deploy (identity):** set **`FORMS_TRUSTED_PROXIES`** to where the proxy is, or
  `X-Forms-Identity` is ignored entirely and every save on a `recorded` form is refused. Set
  **`FORMS_IDENTITY_FALLBACK`** only if that refusal is not wanted — and to something reserved and
  obviously not a person. The proxy must also be configured to *set* the header and strip whatever
  a client sent; nothing here can check that, and the trusted-proxy list is the backstop.
- **Deploy:** clear the pools that hold what this code derived — `bin/console
  cache:pool:clear --all`, which is what `make cache-clear` runs. Neither pool's key says
  anything about the rules behind the entry: `cache.ingot_mapper` keys on class names, and
  `cache.data_schema` on the form UUID and the mode. Both entries stay right for as long as
  the code that produced them does, and a tightened or relaxed rule that is not followed by a
  clear is served from yesterday's document. In dev both pools are in-memory for exactly that
  reason.
- **Deploy (pages):** `bin/console asset-map:compile` writes the mapped assets into `public/`
  for the web server to serve directly — under `FORMS_ASSETS_PREFIX` (`/assets/`), which is also
  the one static rule a gateway needs and the value to change when several of these services share
  a host, or to point at a CDN or bucket (an absolute URL works; the store then needs CORS, because
  a module fetched cross-origin without it is refused by the browser with a perfectly good `200`).
  Both kits need it now: the plain kit's module moved out of `public/js/` so that it, too, follows
  the prefix and gets a digest. In dev and test the framework serves them.
- **Deploy (how pages look):** **`FORMS_SKIN`** dresses every form whose own presentation names
  no skin — `default`, `material`, `flatly` or `lux`. A document naming one wins, which is the
  rule rather than a courtesy: a skin may never change what a document may say.
- **Deploy (where the service stands):** nothing, if it stands at the root of a host or its gateway
  strips the prefix and sends `X-Forwarded-Prefix`. Otherwise **`FORMS_BASE_PATH`**, which is read
  when the container is built — so it belongs in the image or the build, and a change to it needs
  the cache rebuilt. `app:routes:groups` prints what is actually being served, base path and static
  prefix included; diff that rather than trusting the variable.
  See [Where this service is installed](#where-this-service-is-installed).
- **Deploy (webhooks):** set **`FORMS_WEBHOOK_SECRET`** if any form is to report itself — without
  it such a form is refused at creation. **`FORMS_WEBHOOK_TIMEOUT`** (5 seconds) is how long a
  receiver has to answer and **`FORMS_WEBHOOK_ATTEMPTS`** (12) how many refusals one notification
  gets before it is abandoned in the queue; both are a deployment's patience with its receivers
  rather than anything a form can ask for. Run a worker for the delivery nudge
  (`bin/console messenger:consume forms_announcements`), or don't, and let cron do it: the rows are
  the truth and the message is only a nudge. `MESSENGER_TRANSPORT_DSN` says which queue carries it
  (`doctrine://default` needs no broker; its table comes with the migrations, because nothing here
  creates tables at runtime).
- **Cron:** `bin/console app:webhooks:deliver` — tells whoever is owed. It is the sweep for two
  things a nudge cannot cover: a delivery whose endpoint asked to be tried later, and one whose
  nudge was lost. What it prints is worth watching rather than filing: `told` and `retried` are a
  service working, while `abandoned` is a notification nobody will ever get.
- **Cron:** `bin/console app:forms:purge-expired` — expired forms are already invisible
  to the API (410); this fulfils the promise that expired data leaves the system. Next to it,
  `bin/console app:files:purge-temporary` collects uploads no stored document names
  (`--days`, `--limit`, `--after`); what it prints is meant to stay near zero, so a number that
  keeps growing is worth an alert rather than a log line. **A big store is walked in pieces**:
  `--limit` bounds the forms one run *looks at* — each is a listing of its own, which is the
  real cost — and a run that stops at its limit prints where, so the next one continues with
  `--after=<id>` instead of examining the same first forms again. A run that reaches the end
  prints nothing extra, which is how "there is more" and "that was all" tell each other
  apart.
- **History:** `form_revisions` grows by one row per accepted save, each holding that save's
  whole values document. `FORMS_HISTORY_LIMIT` (100) is how many of them one form keeps: when a
  save pushes a form past it, that form's oldest save leaves in the same statement. Set it to `0`
  to keep every save there has ever been, and size the database from how often forms are saved
  rather than from how many exist. A limit is not only about disk — everything that asks what a
  form has **ever** named reads every one of its saves, so an unbounded history is an unbounded
  read on every file download and every purge run. It has one consequence worth knowing: a file
  that only an evicted save named stops being a file this form names, so it becomes temporary
  again and `app:files:purge-temporary` will collect it. That is the same rule as everywhere —
  a document nobody can restore is a document whose files stopped mattering — but it is the one
  place where lowering a number throws bytes away.
- **Files:** `FILES_DIR` (default `var/storage/files`) is where the bytes go, so it has to
  survive a deploy — a volume, not a container layer. A directory is **one node**: more than
  one instance needs a shared volume, or `composer require league/flysystem-async-aws-s3` and
  four lines in `config/packages/flysystem.yaml`. Nothing in the application changes for that,
  because the facts about a file live in a file of their own rather than in a store's own
  metadata. `FILES_MAX_UPLOAD` (10 MiB) is the deployment's ceiling and must stay at or below
  what `docker/php.ini` allows (`upload_max_filesize`, `post_max_size` — 16 MiB) **and** at or
  above the largest `maxSize` any definition served here asks for: `maxSize` is the published
  contract, and a limit no client was told about is a limit that breaks somebody's upload.
  `FILES_PER_FORM` (50) bounds what one form can hold, and `FILES_TEMPORARY_DAYS` (7) is how
  long a file nobody saved may sit. `make storage-clean` empties the dev and test stores.

Retention does not change: revisions live under the same `expire_date` and leave with the form,
rows before bytes. More copies of the same personal data inside the same window — worth knowing
when sizing a database, not a new promise.

## Development

| Command | What it does |
|---|---|
| `make env` | write a local `.env` from `.env.dist`, with an `APP_SECRET` of its own |
| `make install` / `make update` | composer install from the committed lock / move it deliberately (Docker, PHP 8.4) |
| `make migrate` / `make db-test` | migrations for the dev / test database |
| `make cache-clear` | throw away what this code derived (data schemas, mapper metadata) |
| `make assets` | download the vendor JavaScript/CSS named in `importmap.php` |
| `make test` / `make test-unit` | full PHPUnit (needs postgres) / fast domain-only loop |
| `make test-integration` | Http + Infrastructure suite only |
| `make test-filter FILTER=…` | one test or a group: `make test-filter FILTER=FormApiTest::testSaveDraft` |
| `make test-file FILE=…` | one file or directory: `make test-file FILE=tests/UserInterface/Api/FormApiTest.php` |
| `make schema DEFINITION=…` | print the values schema a definition derives (`MODE=draft` for the relaxed one) |
| `make check-values DEFINITION=… VALUES=…` | would the API take this JSON? validates it against that schema |
| `make lint` | `php -l` over every PHP file, in the pinned image |
| `make console CMD="…"` | any `bin/console` command inside the container |
| `make mutation` | Infection over `src/Domain/` (unit suite, no DB), MSI thresholds enforced |
| `make openapi` | validate `openapi.yaml` against the OpenAPI 3.1 schema |
| `make docs` | render `openapi.yaml` into `docs/` (DTO schemas injected, output validated) |
| `make stan` | PHPStan, level `max` + strict rules — zero errors, no baseline |
| `make cs` / `make cs-fix` | php-cs-fixer check / apply |
| `make deptrac` | layer boundaries per `deptrac.yaml` |
| `make audit` | `composer audit` |
| `make ci` | everything CI runs |

## Testing

Three suites. **unit** (`tests/Domain`, `tests/Application`) runs on fakes with no kernel and no
database, and Infection runs over `src/Domain/` against it — so a rule that belongs to the model
has to be pinned there to count. **integration** (`tests/Infrastructure`, `tests/UserInterface`)
runs against the compose postgres with per-test rollback (dama/doctrine-test-bundle). **browser**
(`tests/Browser`) drives headless Chromium through Panther against a server it starts.

Every test body follows GIVEN / WHEN / THEN and the method name says the behaviour rather than
the method under test. Three habits are worth copying:

- **A browser test sets up through the API, never the database** — the browser talks to a
  separate process, so a fixture written inside the test's transaction is invisible to it, and
  going through the API is what makes the test take the same path a person does. Assertions wait
  for state (`eventually`) rather than assuming a click has landed. **It also deletes what it
  planted** (`DeletesWhatItPlanted`): the same separation means nothing it creates rolls back —
  dama wraps the test process's connection while the server commits on its own — so without that
  every run leaves its fixtures behind for good. The cleanup is `DELETE /api/manage/forms/{id}`,
  through HTTP for the reason the fixture was, and a case with its own `tearDown()` has to alias
  the trait's and call it. **Bytes are not in anybody's transaction**, so a suite that uploads
  leaves directories in the test store whatever it deletes — a rolled-back row cannot take
  committed bytes with it, and a directory with no row is exactly what
  `app:files:purge-temporary` is for. `make storage-clean` is the sweep; nothing depends on it,
  which is why it is a chore and not a step.
- **The item catalogue is tested by a battery**, one class per type on each side of the boundary:
  what a definition may carry, and a table of values with the pointer and code each must produce.
  That table is also what proves the server never refuses what its published schema accepts.
- **Two readings of one rule are held together by a test.** A condition is enforced by the
  derived schema and answered again by `Condition::holds()` for readers with no browser, so
  `ConditionsAgreeWithTheSchemaTest` puts every condition to both against a table of documents
  and insists they agree — nothing else would notice a drift, because the form would go on
  validating exactly as its contract says while its own record showed a question nobody was
  asked.
- **`OpenApiComplianceTest` checks both halves of every exchange** against `docs/openapi.yaml`, and
  fails when a documented operation has no scenario — so the DTOs, the code and the contract
  cannot drift apart in any direction.

PhpStorm: Settings → PHP → CLI Interpreter → From Docker Compose → service `php`, then
PHPUnit by Remote Interpreter with `/app/vendor/autoload.php`.
