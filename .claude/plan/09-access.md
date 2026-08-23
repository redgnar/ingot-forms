# 09 — who may do what, without this service learning who anybody is

The service has no authentication at all: every endpoint is open, and a form's UUID is the only
thing standing between a stranger and somebody's answers. That has been fine for a service
nobody exposed, and it is the last thing missing before one can be.

The owner's framing, which this plan takes as the constraint rather than the question:
**flexible, and with as little of it here as possible.** This service handles forms. Deciding
who somebody is belongs to whatever already knows — an identity provider, a gateway, the
application that created the form.

## What is already true

Worth knowing before adding anything, because some of it is load-bearing:

- **Two audiences, and they are not the same kind of thing.** The application that creates and
  deletes forms is a *machine*, known and few. The person filling one in is a *stranger with a
  link*, unknown by design — this service has no identity, no accounts, no sessions, and
  [07](07-history.md) went as far as refusing an actor column in the history table.
- **Bytes are already fenced.** No presigned URLs, nothing under `public/`, every download
  `attachment` + `nosniff`, and a file is unreachable unless some save of *that form* names it.
- **Expiry is already a hard stop**: past `expire_date` every endpoint answers `410`, and the
  purge deletes rows then bytes.
- **A form's UUID is, today, the whole capability.** UUIDv7 is not guessable in practice, but it
  travels in URLs, browser history, referrers and logs — and it never expires while the form
  lives. That is the actual hole.

## The foundation: group the addresses first

Whatever guards them, the guard needs to tell the two audiences apart by looking at the request.
Today it cannot: `POST /api/forms` and `PUT /api/forms/{id}/data` share a prefix.

| Group | Routes | Who |
|---|---|---|
| **manage** | `POST /api/manage/forms`, `GET /api/manage/forms/{id}`, `DELETE /api/manage/forms/{id}` | the owning application |
| **fill** | `…/data`, `…/confirm`, `…/files*`, `…/history*`, `…/schema`, `…/presentation` under `/api/forms/{id}/`, and the pages at `/forms/{id}*` | whoever holds a link to *that one form* |

Moving three routes under `/api/manage/` is the whole change, and it is what makes every variant
below a two-line rule instead of a regular expression over methods. It is a breaking change to
the published contract; the honest way is to serve both prefixes for one release, with the old
three documented as deprecated.

Note what the split says: **`GET /api/forms/{id}` (the envelope) is management**, because it
hands over the definition and the values together. The page does not use it; it reads
`…/presentation` and `…/data`.

## Variants

### A. All of it outside, service unchanged

A gateway (Traefik, nginx, Kong, an ALB) authenticates and the service trusts the network.
Management behind OAuth2 client credentials or mTLS; filling behind a signed link the gateway
verifies — nginx's `secure_link`, or [ForwardAuth](https://doc.traefik.io/traefik/middlewares/http/forwardauth/)
to a tiny verifier.

- **For:** nothing to build here at all. Exactly the brief.
- **Against:** the fill link must be scoped to *one form*, so the gateway has to compare the
  token's form id with the id in the path. Few gateways do that out of the box, so "no logic in
  the service" quietly becomes "bespoke logic in the gateway", in Lua or in a sidecar, tested by
  nobody. And a misconfigured route is an open form — the failure is silent and total.

### B. One thin seam here, everything real outside *(recommended)*

The service asks **one** question and does not answer it itself:

```
Access::allows(Scope $scope, ?FormId $form, Request $request): bool
```

`Scope` is `Create`, `Manage` or `Fill`; the form is null only for `Create`, because there is no
id yet. One listener maps each route onto a scope and an id, and turns "no" into `401`/`403`.

**Two questions with no form id would have been the wrong shape**, and the reason is worth
stating: nobody has "administrative rights" over this service — they have rights over *some
forms*. A credential that may delete any form is a credential that deletes the wrong one
eventually, and the service cannot be the thing that stops it if the question it asks does not
name the form.
Three adapters ship, chosen in `services.yaml` like every other port:

1. **`TrustsTheGateway`** — reads what an authenticating proxy asserts (`X-Forms-Scope: manage`,
   `X-Forms-Form: {uuid}`), the [oauth2-proxy pattern](https://github.com/oauth2-proxy/oauth2-proxy)
   every service behind ForwardAuth uses. Requires the network to guarantee nobody else can set
   those headers — which is a deployment fact, and is documented as one.
2. **`SignedToken`** — verifies an HMAC (or JWT) bearer token carrying `{form, expires}` against
   a key in configuration. No user store, no session, no identity: it authorises **an object,
   not a person**, which is the only way to add access control here without contradicting the
   domain. This is the [signed-URL pattern](https://docs.cloud.google.com/storage/docs/access-control/signed-urls)
   S3 and every "unsubscribe" link use.
3. **`OpenDoor`** — what dev, test and the browser suite run with, and what the service does
   today.

- **For:** flexible by configuration; the deployment picks its own answer. Testable here — the
  browser suite can run against a signed link and prove the page still works. Roughly 200 lines
  and one port.
- **Against:** it *is* logic here, however thin, and it needs its own tests and documentation.

### C. Symfony Security proper

`security.yaml`, firewalls per route group, a custom authenticator, voters.

- **For:** the framework's own road; nothing surprising to a Symfony developer.
- **Against:** it drags in a user provider and a role model to express "this token may touch this
  one form", which is not a user and not a role. The most machinery for the least fit.

### D. A per-form token, issued at creation

`POST …/forms` answers `{id, token}`; the token is stored hashed beside the form and required by
every fill route.

- **For:** self-contained, works with no gateway and no shared secret, and revocation is a
  column.
- **Against:** it puts a credential in this service's own storage — the first identity-ish state
  in a service whose whole design says it has none — and brings rotation, revocation and "resend
  the link" with it.

### E. mTLS for management only

Certificates between the owning application and this service; filling stays open or takes B's
signed token.

- **For:** the strongest machine-to-machine answer, and no tokens to leak.
- **Against:** certificate distribution and rotation; nothing to say about the fill side.

## Where the policy lives, which is the actual question

Verifying a caller is easy and this service should not do it. Deciding **whether this caller may
touch this form** is the hard half, and there are exactly three honest places to keep it. Each
answers `allows()` without the service holding a policy.

### 1. In the token — a capability

The caller presents proof minted for *this* form: `{scope: "fill", form: "01a0…", exp: …}`,
signed with a key both sides know. The service verifies the signature, compares the form in the
token with the form in the path, and checks the clock. Creating needs a token with
`scope: "create"` and no form.

Who may delete form X is then decided by **whoever mints tokens** — the owning application,
which knows what it created and for whom. Nothing about that decision reaches this service.

- **Best when** the owning application already has its own users and rules, which is the usual
  case. It hands out narrow, short-lived tokens the way it hands out signed download links.
- **Costs** a shared key (or a public one, if the tokens are JWTs the owner signs), key
  rotation, and a way to get a token to a browser (below).
- **Revocation is by expiry**, not by list — which is the standard bargain and the reason the
  expiry should be short for `fill` and very short for `manage`.

### 2. On the form — an opaque owner label

`POST …/forms` takes an optional `owner` (any string: `"crm"`, `"tenant-42"`, a UUID), stored
beside the form and never interpreted. The gateway asserts who the caller is
(`X-Forms-Owner: tenant-42`), and `allows()` is one string comparison.

- **Best when** there are a few long-lived callers and no token infrastructure — one column and
  an equality check, and the service still has no idea what a tenant *is*.
- **Costs** one nullable column and one member in the creation request. It is the first thing
  this service would store *about* a caller, which is a line worth crossing deliberately.
- **Note** it says nothing about the fill side: the stranger with the link has no owner. That
  half still wants a capability, so this is usually combined with 1.

### 3. Outside, per request — a decision point

The gateway (ForwardAuth) or a listener here asks something else: "may this caller do `manage`
on `01a0…`?" — [OPA](https://www.openpolicyagent.org/), Cerbos, or a five-line service the
owning application already has, because it already knows its own rules.

- **Best when** the rules are real: teams, roles, delegation, "support may read but not delete".
- **Costs** a network hop on every request (cacheable) and one more thing that has to be up.
- **The gateway can do this on its own**, with no seam here at all: the form id is *in the path*,
  so a ForwardAuth service sees everything it needs. That is variant A, and this is the shape it
  should take — not a rule per prefix, but a decision per request.

### The usual combination

**Capability for the fill side, one of 2 or 3 for the manage side.** The stranger holds a token
for one form and nothing else; the machine is answered by whatever already knows what it owns.

And one refinement worth having from the start: a fill capability should carry **what it may do**
as well as which form — `read` for somebody who may only look at the answers, `fill` for somebody
who may change them. It is one member in the token and it is the difference between sending a
link to the person who fills the form and to the person who checks it.

## What the world does

- **Machine to machine**: an API gateway in front, with OAuth2 client credentials (a JWT) or
  mTLS; API keys where the stakes are lower. The gateway validates, the service trusts.
- **A stranger with a link**: a signed URL with an expiry — S3, Google Cloud Storage, DocuSign,
  every "unsubscribe" link. The token authorises one object for a while.
- **Putting SSO in front of an application that has none**: oauth2-proxy behind Traefik or nginx
  ForwardAuth, the application reading asserted headers.

Which is variant B with `TrustsTheGateway` in front of it — the recommendation is the ordinary
answer, not a clever one.

## Recommendation

1. **Split the addresses** (`/api/manage/**` vs `/api/forms/{id}/**` + `/forms/**`). Do this
   whatever comes next; it costs three routes and makes every other option cheap.
2. **Add the `Access` port** — one question, carrying the scope *and* the form — with the three
   adapters (variant B), defaulting to `OpenDoor` so nothing changes for anybody who does not
   configure it.
3. **Put the policy in a capability for the fill side**: tokens minted by the owning
   application, scoped to one form, saying `read` or `fill`, short-lived.
4. **For the manage side, start with the gateway asking a decision point** (3) if the owner has
   one, and fall back to the `owner` label (2) if it does not. Do not put roles here.
5. **Deploy with a gateway** doing the real work (`TrustsTheGateway`), and keep `SignedToken`
   for deployments that have no gateway to lean on.

## How a browser gets its token

The pages are API clients, so whatever the fill side uses has to survive a page load and reach
`fetch`. Three ways, in order of how much they cost:

1. **In the link, used as-is**: `/forms/{id}?t=…`, the page renders it into a `data-` attribute,
   the module sends it as `Authorization: Bearer …`. Nothing stored, nothing to expire on the
   server, and the token is in the URL — history, referrer, logs.
2. **Exchanged on first load**: the link's token is swapped for a `HttpOnly`, `SameSite=Strict`
   cookie scoped to `/forms/{id}`, and the URL is cleaned with `history.replaceState`. Keeps it
   out of the places URLs leak into; adds a cookie, and with it CSRF to think about.
3. **Not in the URL at all**: the owning application posts the form to the browser it already
   has a session with. Only possible when the person filling is somebody that application
   already knows — which is exactly the case this service was built not to require.

Start with 1, because it is what the service can be tested against, and leave 2 as a separate
decision with its own risks.

## Risks worth naming before writing any of it

- **A token in a URL is a token in the browser history, the referrer and the access log.** Short
  expiry, `Referrer-Policy: no-referrer` on the pages, and — if it matters — the page exchanging
  the link token for a cookie on first load, which is a second seam and should be a separate
  decision.
- **Cookies bring CSRF.** Today the page writes with `fetch` and JSON to its own origin; adding
  a cookie makes that a target. A bearer header, or `SameSite=Lax` plus a custom header, keeps it
  where it is.
- **A trusted header is trusted.** `TrustsTheGateway` is only as good as the network in front of
  it, and the failure is silent. It must refuse to start unless the deployment says out loud
  that a gateway is there.
- **Rate limiting and abuse are not authentication** and are still missing: uploads are capped
  per form and per file, nothing else is.
- **A form with no owner and no token is open to anybody who knows its id**, whichever policy
  home is chosen. Whether creating a form *without* saying who may manage it should be refused
  is a decision, and refusing it is the safer default — but it makes the creation request
  stricter than it is today.
- **`OpenDoor` in production** would be the whole point, undone. Whether the default should be
  "open" (nothing breaks, unsafe by omission) or "closed" (safe, and every existing deployment
  stops working) is the one decision in this plan that is genuinely the owner's.

## Non-goals, and they are the same ones as ever

No accounts, no roles, no login page, no password anything, no session store, no actor column.
When identity arrives it arrives as *something the gateway asserts*, and the most this service
will ever do with it is write it into a revision.
