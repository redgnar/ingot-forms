# 09 — who may do what, and who filled this in

The service has no authentication at all: every endpoint is open, and a form's UUID is the only
thing standing between a stranger and somebody's answers. That has been fine for a service
nobody exposed, and it is the last thing missing before one can be.

**And after working through it, the answer is not a guard here.** This service authorises nothing.
A gateway decides what may reach it, a decision point outside — owned by the system that created
the form — decides whether this caller may touch this form, and the only thing that lands here is
one header saying who is calling, recorded beside every save. The routing split is the deliverable,
because it is what makes those rules one line each.

That is a much smaller change than the first three drafts of this plan proposed, and this document
is mostly the reasoning that got it there. What was weighed and dropped is at the end, so nobody
re-derives it.

## What has landed, and what has not

**Both halves are built.** The routing split: four prefixes named in `App\UserInterface\RouteGroup`,
the three management addresses moved under `/api/manage/`, the locale out of the page paths and into
`?lang=` (`PageLocaleListener`), both properties asserted over the whole route collection in
`RouteGroupsTest`, and `app:routes:groups` printing the table. And identity: `Actor` and
`IdentityMode` in the domain, `IdentityIntake` as a value resolver at the boundary, three slots
across two tables, `FORMS_TRUSTED_PROXIES` and `FORMS_IDENTITY_FALLBACK`, and
`GET /api/manage/forms/{id}/history` serving the actor on the management side alone.

What is still missing is **the gateway**, which is a deployment rather than code here: nothing in
this service decides who may act, so until something in front does, a form's UUID is the only
credential it has of its own.

One thing this document was ambiguous about, and building it forced the answer: it said the mode
"governs the filler only", while also putting confirming on the fill side. **Confirming is judged
like a save** — an `anonymous` form records nobody as its confirmer, however much the proxy
asserted. A promise of anonymity that names whoever pressed "send" is not a promise. The author
stays outside the mode, because creating happens on the management side where a caller is always
known.

Two things in this document were wrong about the mechanics, and building it is what found them:

- **A compiler pass cannot see routes.** The route collection is built by the router, not by the
  container, so "refusing to build a container in which any route falls outside the four" is not
  available. `RouteGroupsTest` asserts it instead, and `make ci` is the gate — the same effect one
  step later, and the only place that sees every route at once either way.
- **The deprecation window had to go.** Serving `POST /api/forms` for one release would have left a
  *management* address under the prefix a gateway opens for *filling*, so the compatibility layer
  would itself have been the hole this change closes. A deployment moves three URLs; it has to
  reconfigure its gateway anyway, which is the point of the change. `Deprecation`/`Sunset` remain
  the right shape for a change that is not a security boundary.

## What was decided, and the rest is consequence

Four decisions by the owner, in the order they were taken:

1. **Identity arrives** — a form has an author, every save records who entered it, a form may
   instead be declared anonymous and record nobody. Recording an assertion, never resolving one.
2. **Nothing about an actor is ever displayed.** It is present in the API, on the manage side, and
   no page draws it.
3. **The decision is at the gateway, architecturally.** One header, `X-Forms-Identity`, and no
   further checks here — this service is a tool, not the place that judges who may act. The
   superordinate system fills in whatever binds a form id to an identity, and a decision point in
   front resolves it per request.
4. **The form id is not a public credential.** These paths are not called from the open internet;
   a person reaches a form through the object of the superordinate system that uses it, after that
   system's own authorization. The pages sit behind the same SSO.

Everything below follows from those, plus two settled details: an absent header falls back to a
configured value, and a deployment that configures none gets a refusal rather than a silence; and
the locale leaves the page URLs.

## What is already true, and still matters

- **Two audiences.** The application that creates and deletes forms is a *machine*, known and few.
  The person filling one in reaches the form through that application. Nobody is emailed a bare
  `/forms/{uuid}`.
- **Bytes are already fenced.** No presigned URLs, nothing under `public/`, every download
  `attachment` + `nosniff`, and a file is unreachable unless some save of *that form* names it.
- **Expiry is already a hard stop**: past `expire_date` every endpoint answers `410`, and the purge
  deletes rows then bytes. With the id no longer a credential, that is housekeeping again rather
  than part of the security model — the sentence in `docs/architecture.md` saying otherwise was
  written for a world where a link was mailed to strangers.
- **`POST /api/forms` and `PUT /api/forms/{id}/data` share a prefix**, so nothing in front can tell
  the two audiences apart by looking at a request. That is the whole problem this plan solves.
- **`framework.trusted_proxies` is not configured.** Nothing here distinguishes a header the client
  sent from a header a proxy added, and Symfony's own machinery only sanitises `X-Forwarded-*` — a
  custom header needs its own check.
- **The pages navigate, not just `fetch`.** The page itself, every internal link built with
  `path()`, and the file download — a plain `<a href>` in both kits
  (`public/js/core-html-form.js:480`, `assets/controllers/file_controller.js:139`,
  `templates/forms/bootstrap/form.html.twig:575`). None of them can carry an `Authorization`
  header. Under decision 4 that stops mattering, and it is worth knowing *why* it stopped
  mattering rather than assuming it never did.
- **The pages have four routes, two of them localized.** `/forms/{id}`,
  `/forms/{id}/versions/{seq}`, and `/{_locale}/…` variants of both
  (`src/UserInterface/Web/Action/ViewFormAction.php:46-59`).
- **Nelmio's UI is not routed.** `config/routes.yaml` imports two attribute resources and nothing
  else, so there is no `/api/doc` endpoint to think about.
- **Everything is checked by suites that talk HTTP.** `tests/UserInterface/**` drives the real
  kernel, `tests/Browser/**` drives Panther against a separate server and sets its fixtures up
  through the API, and `OpenApiComplianceTest` validates real traffic against `docs/openapi.yaml`.
- **The CLI is untouched.** `app:forms:purge-expired` and `app:files:purge-temporary` are console
  commands; a scheduler is not a caller.

## The service authorises nothing

The division of labour, stated once so nobody looks for the missing half in here:

| Question | Answered by |
|---|---|
| may this request reach the service at all | the gateway, per prefix and method |
| may this caller touch **this form** | a decision point outside, asking the superordinate system that created it |
| who is calling | the gateway, asserting `X-Forms-Identity` |
| what is recorded about who called | **this service** |
| may this form be saved without an identity | **this service**, from the form's own mode |

The second row is the one that used to drive a port, three adapters and a capability scheme here.
It leaves for a good reason rather than a convenient one: **whoever created a form already knows
who may touch it, and that answer is theirs to keep.** That sentence was in this plan from the
first draft as a principle; taking it seriously means the binding of a form to a person lives
where the form was created, not here. A decision point gets the form id out of the path, and the
superordinate system tells it what it knows.

What this service must not do is *rely* on any of it. "Not reachable from the internet" is a
property of a deployment, not of a service, and it is true until somebody puts a public ingress in
front of it for a demo. So the two cheap things that stay valuable when that assumption breaks
stay in: the routing split, so a gateway can close the manage side at all, and the trusted-proxy
check on the header, so what is recorded is worth reading.

## Four prefixes, and the form id right after each

This is the deliverable. Two properties, and the second only became necessary once the decision
point moved outside.

**Prefix-clean:** exactly one prefix per group, so a gateway rule is one line with no alternation.

| Prefix | Group | Who |
|---|---|---|
| `/api/manage/` | management | the superordinate system: create, read the envelope, delete, read history with actors |
| `/api/forms/` | filling, API | whoever that system let through to this form |
| `/forms/` | filling, pages | the same person, in a browser |
| `/api/schemas/` | public | anybody, deliberately |

**The form id is always the first segment after the prefix**, so a decision point extracts it with
one pattern per prefix and no guessing. That holds for every route today — including
`/api/forms/{id}/files/{fileId}` and `/api/forms/{id}/history/{seq}` — once two things move.

**Move one: the envelope becomes management.** `GET /api/forms/{id}` hands over the definition and
the values together, so under `/api/forms/` it would give everything to anybody the gateway let
near the form. It goes to `/api/manage/forms/{id}` with `DELETE`, and the pages do not care: they
read `…/presentation` and `…/data`.

**Move two: the locale leaves the path.** `/{_locale}/forms/{id}` breaks both properties at once —
a gateway rule on `^/forms/` misses it entirely (silently: an open page, not a broken one), and the
form id sits at position 2 or 3 depending on whether a language is in the URL. An earlier draft
proposed `/forms/{_locale}/{id}`, which fixes the prefix and keeps the id moving; that is
withdrawn. The locale leaves the path altogether:

```
/forms/{id}
/forms/{id}/versions/{seq}
```

and the language travels as `?lang=xx`, negotiated from `Accept-Language` when absent
(`set_locale_from_accept_language` is already on). Under decision 4 a page link is *generated by
the superordinate system*, not typed by a person, so a query parameter costs nobody anything.

What that touches, precisely: two `#[Route]` attributes on `ViewFormAction`, four `path()` calls in
the two kits' language switches (`templates/forms/*/form.html.twig`), one listener setting the
locale from the parameter, one assertion in `CoreHtmlRendererTest` and four request URLs in
`ViewFormActionTest`. Contained, and it deletes two routes rather than adding any.

**A `Deprecation` and `Sunset` window** (RFC 8594) on the three old management addresses for one
release, `deprecated: true` in the generated document, and the fill prefix unchanged throughout.

**The prefixes are checked at build time.** A compiler pass walks the route collection and refuses
to build a container holding a route whose path does not start with one of the four. Not an
attribute on each action — the path *is* the declaration, and the pass is what stops the next
endpoint from landing outside every gateway rule. A route that needs a group its prefix does not
give is the moment to reconsider, and there is no such route today.

**And the gateway configuration should be derivable rather than transcribed.** The failure this
whole section exists to prevent is a gateway holding a stale copy of the route table, so
`bin/console app:routes:groups` is worth the twenty lines. It is the difference between a
deployment reading the routes and a deployment remembering them.

**It prints a table**, not a snippet for one proxy: prefix, group, methods, and whether a form id
follows. A snippet would be pasteable and immediately specific to whichever gateway was guessed at,
and wrong for every other one. The table's job is different — it is the authority a deployment
checks its own rules against — so the one property it owes is **stable ordering**, sorted by
prefix, so that a deployment can diff this output in its own CI and see a route group appear or
move. A table nobody can diff is a table nobody reads twice.

## What a gateway can express, and the one thing it cannot

Four of the five permissions this plan once wanted a port for are path-and-method rules:

| Permission | Rule at the gateway |
|---|---|
| create | `POST /api/manage/forms` — exact path |
| manage | `/api/manage/**` — prefix |
| read | `GET` only, under `/api/forms/` and `/forms/` — method |
| confirm | `POST /api/forms/{id}/confirm` — its own path |
| fill | mutating methods under `/api/forms/` |

"Send somebody a view-only link" is a GET-only rule. "Do not let the filler close the form" is a
blocked `POST …/confirm`. Neither needs a line of code here, and that is the strongest argument
for this shape: the permission vocabulary a port would have carried is already expressed by the
addresses, once the addresses are right.

The one thing a gateway cannot say on its own is **"this caller, for this form"** — it has no idea
which person a given form belongs to. That is not deferred, it is **delegated**: a decision point
(ForwardAuth to OPA, Cerbos, or five lines the superordinate system already has) receives the form
id from the path and answers per request.

Two consequences of that living outside. It is **a hop on every request**, and the fill side is
interactive — a save now waits on two. And when the decision point is down, **it fails closed**:
requests are refused rather than let through. That is decided, and it is the right way round — a
guard that opens under load is not a guard, and the one thing worse than an outage is an outage
nobody can see — but it has to be paid for honestly:

- **The decision point is now a hard dependency of the fill side.** Its availability is the
  availability of every form page and every save, so it belongs *close* — a sidecar rather than a
  service across a network — because a per-request hop that fails closed sits on the critical path.
- **Positive decisions may be cached briefly, negative ones not at all.** Seconds of positive cache
  take most of the latency and most of the blast radius out; caching a refusal only delays somebody
  regaining access they have just been granted. A revocation then takes effect one cache lifetime
  late, which is the ordinary bargain and worth stating so nobody discovers it as a bug.

## How the pages are reached

Behind the same SSO as the superordinate system. The browser follows a link or a redirect from that
system, the proxy in front of both has already authenticated the person, and it asserts
`X-Forms-Identity` on the way in.

What that buys is the largest single simplification in this plan, and it is worth listing because
three earlier drafts spent most of their length on it:

- **No credential in a URL**, so nothing to leak into history, `Referer` or logs, and no link that
  can be forwarded into an impersonation.
- **No cookie exchange**, so `framework.yaml`'s "no sessions and no browser here, so no CSRF token"
  stays true and CSRF stays out of scope.
- **The three navigations that cannot carry a header keep working untouched** — the page, every
  `path()` link, the `<a href>` download — because the session lives at the proxy and travels with
  the browser by itself.
- **Nothing changes in either kit** beyond the language switch, and that only because the locale
  moved.

What the deployment must do: terminate SSO in front of `/forms/**` and `/api/forms/**`, put both on
an origin the browser reaches, and strip client-supplied copies of the identity header. Serving the
pages on the superordinate system's own origin through a reverse proxy is the ordinary shape; an
iframe on another origin would drag `frame-ancestors`, cookie policy and `SameSite` back in, and is
worth avoiding for exactly that reason.

**And one thing the pages have to learn, which falls straight out of fail-closed plus SSO.** From
now on something in front can answer instead of the service, and what it answers is not
`problem+json`. Half of that is already handled and was checked rather than assumed: both kits parse
a refusal as `response.json().catch(() => ({}))` (`public/js/core-html-form.js:9`,
`assets/controllers/form_controller.js:196`) and fall through to their own generic wording, so an
HTML error page from a proxy already reads as "something went wrong" in the reader's language rather
than as a crash.

The other half is a real gap, and it is the *common* case rather than the exotic one: **an expired
SSO session redirects, `fetch` follows redirects, and a login page arrives as `200` with HTML.**
`response.ok` is then true, `send()` returns true, and the page tells somebody their answers were
saved when they were not. Two things fix it, and both are wanted:

1. **The proxy answers `401` rather than redirecting** when the request is not a navigation —
   oauth2-proxy and every ForwardAuth setup can distinguish one, and this is the correct place for
   the fix. It goes in the deployment notes.
2. **A page requires the status it expects instead of merely `ok`.** `PUT …/data` and
   `POST …/confirm` answer `204`; anything else, however cheerful, is not a save. One line in each
   kit, and it is the difference between a page that trusts its transport and a page that checks.
   A page should never report success on a response it did not understand.

## Identity: what is recorded

**Three slots, and no fourth.**

- **The author** — who created the form. Asserted at creation, immutable.
- **The confirmer** — who locked it forever. Its own slot, because confirming writes no values and
  is therefore no revision of its own ([07](07-history.md)); without it, the most consequential act
  on a form would be the one nobody attributed.
- **The filler** — who entered a particular save. One per revision.

"Who last changed this form" is *not* stored: the newest revision already answers it, and a second
copy is a second truth.

The author and the fill side are **orthogonal**. An anonymous form still has an author, because
somebody created it — and management sits behind the gateway, so an author is always resolvable.
The mode governs the filler only.

**The mode, and what it means now.** `identity: recorded | anonymous`, a third top-level property
of a form beside its definition, its values and its `expire_date`: given at creation, immutable,
**defaulting to `recorded`**.

- `recorded` — the filler is stored with every save.
- `anonymous` — the filler is **not** stored, *even when the gateway asserted one*. That is the one
  half of this that cannot be delegated: the proxy asserts identity on every request, so only this
  service can decide not to keep it. The aggregate discards it, whatever the use case handed in.

It defaults to `recorded` because the two options are not symmetric. `anonymous` by default fails
**silently** — six months later nothing was recorded and nothing can be recovered — while
`recorded` by default fails **loudly**, at the first save, in a deployment that has neither a
header nor a fallback. When one default fails loudly and the other silently, "no default, the
request must say" is ceremony; an earlier draft of this plan recommended it, and that
recommendation is withdrawn.

The name changed with the meaning: `required` promised an enforcement that has moved to the
deployment, so it would have been a word that lies. `recorded` says what happens.

**The fallback, and the one check that stays here.** An absent header falls back to
`FORMS_IDENTITY_FALLBACK`. If that is not configured, a save on a `recorded` form is refused
(`IdentityRequired`, `403`) — one comparison, no per-form logic, and the deployment chooses its own
policy with one line in `.env`. An `anonymous` form is unaffected, because it stores nobody either
way.

That is deliberately the *only* thing this service checks, and it earns its place: it is what makes
a misconfigured gateway visible. Without it, a proxy that quietly stops sending the header records
`unattributed` forever and nobody finds out. A deployment that genuinely does not care sets the
fallback and never sees the refusal.

The fallback value should be **reserved and obviously not a person** — `unattributed`, not `system`
and not `admin` — so a row saying "nobody told us" is a fact rather than something mistakable for a
subject.

**Asserted, never claimed.** Identity arrives in the header and nowhere else. There is no `actor`
member in `PUT …/data`, none in `POST /api/manage/forms`, and **nothing new is needed to enforce
that**: request bodies are already closed (`ALLOW_EXTRA_ATTRIBUTES => false`), so a client sending
`actor`, `author` or `filler` gets `request.unexpected_key` today. The promise has a mechanism
behind it rather than a paragraph.

**Nothing is ever displayed.** No page draws an actor, no catalogue holds a word for one, no
`codes()` entry, and both kits and every skin come out of this untouched. It is served on the
**manage side only**:

- `GET /api/manage/forms/{id}` — the envelope grows `identity`, the author and the confirmer.
- `GET /api/manage/forms/{id}/history` — a manage-side list carrying the actor per save.

The fill-side `GET /api/forms/{id}/history` stays exactly as it is, which is what keeps one person
who reached a form from learning who else filled it in. Two routes rather than one response that
varies by caller: a response that changes shape with who asks is a second truth at one address, and
`OpenApiComplianceTest` validates one shape per route.

**And no display label.** With nothing rendering it, a label's only reader is the superordinate
system — which is precisely the party that already knows how to turn a subject into a person. So:
subject only. No name to go stale when somebody changes theirs, and no personal data stored for a
reader that does not exist. If a deployment wants one later it is a nullable column and a paragraph
about personal data.

## What identity has to satisfy

An opaque string this service will never interpret is easy to *under*-validate: there is no format
to check, so the temptation is to check nothing. But an unvalidated opaque string is how a newline
gets into a header and how an audit trail becomes self-written.

**The header, and this is where it matters most.**

- **Read only from a configured trusted proxy.** If anybody can set `X-Forms-Identity`, the trail
  is written by the party being audited. This is the one irreversible thing in the whole plan: data
  recorded under a forgeable header is *permanently* untrustworthy, because there is no way to go
  back and re-verify old rows, and no way to tell them from the good ones. Symfony's
  `trusted_proxies` does not extend to custom headers, so this is the intake's own check.
- **Single-valued, or refused.** PHP folds repeated headers with commas, so `a, b` would otherwise
  *become* the subject. A repeat is either an injection attempt or a misconfigured proxy chain.
- **Length checked first**, because headers arrive large and cheap.
- **A malformed header is a refusal, not a fallback.** Falling back would let a save through as
  `unattributed` and hide the misconfiguration for months, which is the failure this design is
  arranged to make loud.
- **ASCII only.** RFC 9110 field values are octets with no stated encoding and every proxy in a
  chain treats non-ASCII differently. A deployment whose subjects are not ASCII namespaces or
  encodes them itself.

**`Actor`, a domain value object** — `subject` and nothing else, since there is no label and no
second header:

- **Required, 1–255 characters.** 255 because it is what an OIDC `sub` is expected to fit in, what
  an email address fits in, and what the `name` cap on a file descriptor already is here.
- **No control characters** — no CR, no LF, nothing in C0 or C1. The one character rule that is not
  taste: a newline in a value read out of a header is header injection, and the same value reaching
  a log line is log injection.
- **Refuse leading and trailing whitespace; never trim.** Trimming silently changes an identifier,
  and `" 42"` and `"42"` really are two subjects. Refusing says so; trimming lies about it.
- **Valid UTF-8, no normalization.** Normalizing is interpreting — with the consequence, worth
  stating rather than discovering, that two Unicode spellings of one name are two people here.
  Which is one more argument for a subject being a machine identifier.
- **No format imposed, none parsed.** Not a UUID, not an email, not a URI. The only operation this
  service performs on a subject is `===`.
- **Stored verbatim, and namespaced by the deployment if it ever has two identity sources**
  (`sso:12345`). No `issuer` member and no second header: zero cost today, the option preserved,
  and no migration if it is ever needed.
- **A stored actor is never judged again.** `Actor::stored()` builds without validating, the way
  `Definition::stored()` does, so tightening a rule later cannot make old rows unreadable. Reading
  is not the moment to judge.

**The mode, at the API boundary.** `identity` is a member of the creation request with a default,
held to the two words by `symfony/validator` and reported the way every envelope violation is
(`request.choice` at `/identity`). A member with a default value is still non-nullable, so the
house rule that an instance means a complete request holds.

**And one thing that is not a rule.** A definition may declare an item called `filler`, and
somebody may answer it with their name. That is an *answer*: it lives in the values document, it is
judged by the derived schema like any other text, and this service draws no line between the two.
Worth writing once, because the first person to see both will ask.

## What the aggregate refuses

| Situation | Answer |
|---|---|
| a save on a `recorded` form with no actor and no fallback configured | `IdentityRequired`, `403` — nothing is wrong with the document, so there is no pointer to give |
| an actor arriving at an `anonymous` form | **discarded, not refused** — the proxy asserts on every request; refusing would break every legitimate caller |
| anything trying to change the author, the confirmer or the mode | no transition accepts one; immutable like the definition |

Three rules deliberately **absent**, each because it belongs elsewhere:

- **No rule that the same person fills every save.** Several people answering one form in turn is
  ordinary; if a link should be usable by exactly one person, the decision point says so.
- **No rule that the confirmer is the author or a filler.** Somebody closing what another person
  answered is the point of `confirm` being its own address.
- **No refusal at creation.** An earlier draft refused creating a `recorded` form when nothing was
  asserted — "a form nobody could ever save is not a form". With a deployment-level fallback that
  is no longer true: whether a form can be saved depends on configuration, which is not the
  creation's business.

And one real conflict, decided rather than glossed: **a save that stores what is already stored
records nothing** ([07](07-history.md)), and that swallows a fact an audit trail might want —
person B re-submitting person A's answers unchanged writes no revision, so the newest revision
still names A. The no-op rule wins, because the history records *changes*: "B looked at this and
agreed" is an access-log fact, not a version of a document.

## What is stored, and the migration

`forms` grows `identity_mode` (not null), `author_subject`, `confirmed_by_subject`.
`form_revisions` grows `actor_subject`. Portable types, like everything else there.

The **events carry it**, because the write follows the record: `FormCreated` carries the author,
`DraftSaved` carries the actor beside the `Values` it already carries, `FormConfirmed` carries the
confirmer. A column changes because something happened, and a transition no adapter knows how to
store stops the write rather than vanishing from it.

**The migration is what makes the column readable.** Nothing can backfill who filled a form in last
year, so `actor_subject` arrives nullable and `identity_mode` arrives **NOT NULL, backfilled
`anonymous`** — truthful, since nobody was recorded. Together with the fallback, that leaves
exactly one meaning per state:

- a `recorded` form always has an actor on every new revision, even if it is `unattributed`;
- an `anonymous` form never has one;
- so **`NULL` means "anonymous form"**, and nothing else. The three-way ambiguity an earlier draft
  had to reason around is gone, because the fallback fills the hole that produced it.

## What does *not* change

Worth listing, because three drafts of this plan changed all of it and this one does not:

- **`404` for an unknown form and `410` for an expired one, to everybody.** With no authorization
  here, there is no guard running before the use case, so no `403`-instead-of-`404` and no
  `401`-before-`410`. The oracle argument — a caller learning whether an id exists — belongs to
  whatever refuses in front.
- **No `WWW-Authenticate`, no `unauthorized` or `forbidden` problem type**, and no new
  `page.error.*` key except the one `IdentityRequired` needs.
- **No `Access` port, no `Credential`, no `Scope` as a question, no adapters, no `FORMS_ACCESS`.**
- **Nothing in either kit**, no template beyond the language switch, no Stimulus controller, no
  `translations/` entry for an actor.
- **The CLI, the purges, the file store, the schema cache, the validation stages.** Untouched.

## What this costs to build

- the route moves, the locale removal and the deprecation window;
- a compiler pass over the route collection, and `app:routes:groups`;
- the identity intake: read the header, validate it, fall back or refuse, hand an `Actor` to the
  action, which passes it to the use case as an argument — no ambient state, no holder service;
- `Actor`, the mode, `IdentityRequired`, three columns plus one, three events, one migration;
- two manage-side reads (the envelope, the history) growing members, and one new manage-side route;
- one line in each kit: a save requires the `204` it expects rather than any `ok`, so a login page
  arriving as `200` cannot read as success;
- `FORMS_IDENTITY_FALLBACK` and trusted-proxy configuration, in `.env.dist` and
  `docs/architecture.md`;
- `make docs`, because the moved routes and the new members are the published contract, and
  `OpenApiComplianceTest` validates real traffic against it.

## What this does to the test suites

- **The suites need somebody to be**, and the fallback is it: `FORMS_IDENTITY_FALLBACK` in
  `.env.test` means every existing fixture keeps working *and* the recording path is exercised on
  every single one of them. That is strictly better than a dev-only special case, because the
  mechanism under test is the mechanism that runs in production.
- **Anonymity is a promise, so it is a test.** An asserted identity, a form declared `anonymous`, a
  save, and `actor_subject` still `NULL`. In the unit suite against the aggregate, because Infection
  runs there and a promise the model keeps is a rule the model should be pinned on.
- **The header rules are a table**: a folded header, a control character, an over-long value, a
  request from an untrusted source — each with the status it earns.
- **The prefix pass is its own test**: a route added outside the four prefixes fails the build.
- **The browser suite gains nothing to do.** No token to mint, no link to build, no navigation to
  prove — which is the clearest measure of how much decision 4 removed.

## Risks worth naming

- **"Not publicly reachable" is a deployment property.** It is true until somebody exposes this for
  a demo. The routing split and the trusted-proxy check are what make that survivable, and neither
  costs anything.
- **A forgeable header is irreversible.** Everything else here can be changed later; rows written
  under an untrusted header cannot be repaired or even identified.
- **A subject can be personal data** — a deployment whose subjects are email addresses has put
  personal data in every revision row. Opaque identifiers are the cheaper answer. The history
  leaves with its form by foreign key, which does the rest.
- **`FORMS_HISTORY_LIMIT` evicts old saves**, so the record of who filled a form in can be evicted
  while the form still lives. Correct for a history limit, wrong for an audit trail; a deployment
  that wants the second sets the limit to `0` and pays for it. Say so where operations reads.
- **Disclosure between fillers is closed by routing, not by discretion.** It holds for exactly as
  long as nobody adds the actor to the fill-side history "for convenience".
- **Recording an identity creates pressure to consult it.** "The author may manage" and "which
  forms did this person fill in" are each one small step from a column that now exists, and both
  put policy or a query surface in a service arranged to have neither.
- **A gateway rule that does not match is an open address**, and the failure is silent. The prefix
  pass and the export command are the two things standing against it, and neither can verify the
  gateway itself.
- **Rate limiting is not authentication and is still missing.** Uploads are capped per form and per
  file; nothing else is. Whatever fronts this is where that goes.

## Non-goals

**The actor column is no longer one — it is the plan.** Everything around it still is, and the list
matters more now than when identity was simply refused, because a recorded subject is what each of
these would grow out of:

- **No accounts, no user store, no directory, no login page, no password anything, no session
  store, no user provider.** The service records an assertion and never resolves one.
- **No authorization of any kind here.** Not by identity, not by scope, not by prefix. The service
  reads a header and stores it.
- **No querying by identity.** "Which forms did this person fill in" is the refused form-list
  endpoint in a new hat, and the columns this adds are exactly what would make it cheap.
- **No identity in the definition or the presentation.** Who answers is not an item and not a
  widget.
- **No claimed identity.** No `actor` member in any request body, ever.
- **No issuing.** This service mints no credentials, and now verifies none either.
- **Nothing displayed.** No page, no catalogue, no kit.

What identity *is*, stated once: three opaque strings — an author, a confirmer, and one filler per
save — asserted by whatever authenticated the caller, validated for transport and for nothing else,
written from the events that already record what happened, and handed back only to the side that
created the form.

## What was weighed and dropped

The three drafts before this one built a guard here. The arguments are worth keeping in compressed
form, because each was a real dead end rather than a preference.

- **An `Access` port with three adapters** (`TrustsTheGateway`, `SignedToken`, `OpenDoor`), chosen
  by `FORMS_ACCESS`, answering `allows(Scope, ?FormId, Credential)`. Dropped when the decision moved
  outside. Two findings from it survive: the signature could not name a `Request`, because
  `deptrac.yaml` puts it in `Framework` and the application may not touch it; and the adapters would
  have had to be *composed*, because one deployment can have two kinds of caller — neither of which
  matters now, and both of which would have cost a rewrite to discover later.
- **Per-form capability tokens** — signed, expiring, carrying a permission set. Dropped with the
  port. What killed it independently was the browser: three of the page's operations are navigations
  that cannot carry a header, a cookie scoped to `/forms/{id}` is *not* sent to
  `/api/forms/{id}/data`, and the download link would have needed either an in-memory buffer or a
  second signed-URL mechanism. That section was the largest single cost in the plan and it existed
  only to deliver a token to a browser.
- **`_scope` as a route default** with a compiler pass checking its presence. Replaced by the pass
  checking prefix membership, which is simpler and enforces the property the gateway actually
  depends on. `_scope` comes back the day a route needs a group its prefix does not give.
- **Symfony Security** with a custom authenticator and voters. Its real win was deny-by-default
  machinery somebody else maintains; it lost because a user provider's job is to *resolve* a subject
  into a user, and this design's whole promise is that a subject is recorded and never resolved.
  Installing the framework whose central abstraction is the one operation we refuse is how a fence
  becomes a suggestion.
- **A per-form credential this service issues and stores hashed.** The only option with real
  revocation, and the only one with no external moving parts — dropped because it makes this service
  an issuer, puts the first secret in its storage, and cannot record *who* filled a form unless the
  credential is minted per person, which is an account with the word filed off.
- **An `owner` label on the form**, compared against an asserted header. Absorbed: the `author` is
  that column, asserted rather than claimed, and stored because it is worth knowing rather than
  because a policy needs it.
- **`X-Forms-Scope` and `X-Forms-Form` asserted by the gateway.** They were meant as the *grant*
  against the route's *demand*, but a plain SSO proxy cannot assert a grant — it knows who somebody
  is, not which form they were sent. Once the decision point answers per request, a grant header is
  redundant: what should not pass never arrives.
- **A per-form `identity: required` that refused a save.** Became a deployment-level fallback plus
  one refusal, once the owner's fourth decision made the gateway responsible for who is calling.
