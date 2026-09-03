# Deploying behind a gateway

This service **authorises nothing**. Every endpoint it has is open, and a form's
UUID is the only thing standing between a stranger and somebody's answers. That
is deliberate — the reasoning is in
[`.claude/plan/09-access.md`](../.claude/plan/09-access.md) and summarised in
[architecture.md](architecture.md#who-may-do-what) — and it means one thing for a
deployment: **nothing here may be exposed without something in front of it.**

This is the other half of that sentence. Six rules, one header, and one question
a path cannot answer. A worked, runnable example lives in
[`examples/gateway/`](../examples/gateway).

## What the service owes a gateway

Two properties, and they are asserted over the whole route collection by
`RouteGroupsTest` so they cannot drift:

- **one prefix per audience**, with no alternation needed to match it;
- **the form id is always the segment straight after the prefix**, so one pattern
  per prefix finds it.

`bin/console app:routes:groups` prints the table, sorted by path, plus the base
path and the static prefix it is actually serving under. Diff that output in your
own CI: a gateway holding a stale copy of the route table is the failure this
whole arrangement exists to prevent, and a rule that no longer matches is an
*open address* — nothing goes red, because the requests that come back right are
the same ones.

## The six rules

| # | Address | Who | Method |
|---|---|---|---|
| 1 | `/api/schemas/` | anybody, deliberately — a contract stated once has to be reachable | GET |
| 2 | `FORMS_ASSETS_PREFIX` (`/assets/` by default) | anybody; not a route, served as files, cacheable, holds no form data | GET |
| 3 | `/api/manage/forms` | the system that owns the forms — **never the open internet** | POST |
| 4 | `/api/manage/**` | the same system: the envelope, the history with actors, the deliveries, the PDF record, delete | any |
| 5 | `/api/forms/{id}/**` | whoever that system let through to *that* form | GET reads, `POST …/confirm` confirms, other mutating methods fill |
| 6 | `/forms/{id}` and `/forms/{id}/versions/{seq}` | the same person, in a browser | GET |

Four of the five permissions from the plan are a path and a method, with no code
anywhere: create is rule 3, manage is rule 4, read is `GET` under rules 5–6,
confirm is its own address, and filling is the mutating methods under rule 5. The
fifth — "this caller, for *this* form" — is the question below.

**Rule 2 is the one people forget.** A gateway that passes the forms through and
drops their stylesheet has followed every other rule to the letter. And if several
of these services share a host, give each its own `FORMS_ASSETS_PREFIX` so their
static files cannot collide.

## Who sets which header

The short answer, because the example can read the other way round at first
glance: **nothing outside the gateway sends any of these.** A browser sends a
session cookie and a form's UUID in the path, and that is all it ever sends.

| Header | Set by | Read by | Where its value comes from |
|---|---|---|---|
| `X-Forms-Identity` | the gateway | this service | whatever authenticated the caller: an SSO session, an mTLS certificate, an identity proxy's own header |
| `X-Forwarded-Prefix`, `X-Forwarded-For`, … | the gateway | this service | the gateway's own knowledge of where it is mounted |
| `X-Forms-Decision-*` | the gateway | the decision point | the request the gateway is deciding about; these never reach this service |
| `X-Demo-User`, `X-Manage-Key` | **nobody** — they are make-believe | the example's nginx | they exist so the example runs without an identity provider, and they are the first two lines a deployment deletes |

The example marks both stand-ins in place. Where it says

```nginx
set $subject $http_x_demo_user;  # ← DELETE ME
```

a deployment says one of

```nginx
set $subject $ssl_client_s_dn;                    # mTLS
set $subject $upstream_http_x_auth_request_email; # oauth2-proxy / Authelia
set $subject $cookie_session;                     # your own session, looked up
```

## The one header

The service records who filled a form in. It reads that from
`X-Forms-Identity`, and **only from an address listed in
`FORMS_TRUSTED_PROXIES`** — with that unset the header is ignored entirely and
every save on a form created as `recorded` is refused with `403
identity-required`. That refusal is the safe direction: a header any client could
set is not an assertion about anybody, and rows written under a forgeable one can
never be repaired or even told apart from the good ones.

So the gateway must do three things, and the third is the one that is easy to
miss:

1. authenticate the caller however this deployment does it;
2. put the resulting subject in `X-Forms-Identity` — an opaque string, never
   parsed or resolved by this service, at most 255 characters;
3. **set it on every request**, so whatever the client sent is *replaced*. In
   nginx, `proxy_set_header X-Forms-Identity $subject;` does that even when
   `$subject` is empty.

Set `FORMS_TRUSTED_PROXIES` to the gateway's address, and — if the gateway mounts
this service under a path of its own and strips it — let `X-Forwarded-Prefix`
through, which is what makes every address the pages carry come out prefixed. See
[Where this service is installed](architecture.md#where-this-service-is-installed)
for the two ways of doing that.

## The one question

*May this caller touch this form?* Whoever created the form already knows, and
that answer is theirs to keep — so the gateway asks them, per request, and the
service is not involved. With nginx that is `auth_request`: a subrequest whose
2xx means yes and whose anything-else means no, including a timeout. **It fails
closed**, which is the right way round.

What the decision point is given, and it is everything the answer can depend on:

| Header | Meaning |
|---|---|
| `X-Forms-Decision-Form` | which form, extracted from the path by the gateway |
| `X-Forms-Decision-Subject` | who is asking, as the gateway authenticated them |
| `X-Forms-Decision-Method` | `GET` reads, `POST …/confirm` confirms, other mutating methods fill |
| `X-Forms-Decision-Path` | the address, for anything the method does not say |

What it answers: `204` yes, `403` no. Nothing else, and no body — a decision
point that explains itself in prose is a decision point somebody will parse.

**Cache it.** Drawing one page is a dozen requests, and without a cache that is a
dozen questions. One minute keyed by (form, subject, method) makes it one, and
keeps revoking access from being a deployment. `examples/gateway/nginx.conf` does
exactly that.

## Running the example

```bash
docker compose -f examples/gateway/docker-compose.yml up -d
```

One command brings up all of it. The example `include`s the repository's own
compose file rather than describing the service a second time, and declares the
same project name, so it *adds* a gateway and a stand-in decision point to what
is already running — which is why the service appears nowhere in the example's
own file:

```
        :8080                   :80                    :8000
  you ────────► gateway (nginx) ──────────► php   (the service)
                     │
                     │ auth_request /_may_i     :9000
                     └───────────────────► decision-point  (stands in for the
                                                            owning system)
```

`php` and `decision-point` are compose **service names**, which is what compose
puts in DNS — deliberately not container names, which change with the name of
the directory somebody cloned into.

The stand-in runs on a stock `php:8.4-cli-alpine` with this one directory
mounted, and that is a statement rather than a convenience: it shares nothing
with this service — not the image, not the code, not the database — because in
your deployment it is not a container at all but an address in a system you
already have. PHP only because a one-file HTTP stub is shortest there.

Everything outside talks to `:8080` and to nothing else. The service's own
`:8000` stays published here only because the repository's compose file
publishes it for development; a deployment does not expose it at all, which is
what makes the gateway the only way in.

The example's stand-in decision point allows `demo-*` to read and fill and
`owner-*` to do anything, which is enough to see every rule work — and the
`X-Demo-User` header in the calls below is the make-believe from the table
above, standing in for a login. Verified, in that order:

```
GET  /api/schemas/definition (anybody)         → 200
POST /api/manage/forms (no key)                → 403
POST /api/manage/forms (with key)              → 201
PUT  /api/forms/{id}/data (nobody)             → 403
PUT  /api/forms/{id}/data (stranger-9)         → 403
PUT  /api/forms/{id}/data (demo-7)             → 204
GET  /forms/{id} (demo-7, the page)            → 200
GET  /assets/pages/kit-XwCuSdQ.js (anybody)    → 200
GET  /api/forms/ (names no form)               → 404
GET  /nope (in no group)                       → 404

GET /api/manage/forms/{id}/history → revision 1, actor=demo-7
```

That last line is the point of the whole arrangement: the identity the *gateway*
asserted is what the service recorded, and nothing else could have set it.

## Five ways this goes wrong

- **SSO that redirects instead of refusing.** A page saves with `fetch`, and
  `fetch` follows redirects: a login page arriving as `200` with HTML would tell
  somebody their answers were stored when they were not. Answer `401` for
  anything that is not a navigation. Both kits already turn an unparseable
  refusal into their own wording, so a status reads correctly.
- **`/api/manage/` reachable from outside.** `POST /api/manage/forms` is an
  unauthenticated *write* as far as this service is concerned: whatever fronts it
  is where the client authentication and the rate limit go.
- **A decision point that fails open.** Check what your gateway does when the
  subrequest times out. nginx's `auth_request` refuses; not every gateway does.
- **An upstream resolved once.** A gateway that resolves the service's address at
  startup refuses to start while the service is down and keeps a stale address
  after it moves. The example uses a resolver and a variable for that reason.
- **No egress.** Webhooks go *out* from the service to whatever a form named, so
  a network that only allows inbound traffic will queue notifications until they
  are given up on. `GET /api/manage/forms/{id}/deliveries` is where that shows.

## What this does not do

It does not make the service safe on its own, and it is not a product: there is
no gateway in this repository, only the rules one has to implement and an example
small enough to read. The service will go on authorising nothing — that is what
keeps "who may act" in the system that already knows the answer.
