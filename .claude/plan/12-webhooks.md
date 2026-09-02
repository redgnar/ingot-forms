# 12 — telling somebody what happened

The first item on [10](10-what-a-vendor-offers.md)'s list, and the one that needed the least
new thinking: the events already exist, are past tense, and are already written down. What was
missing was an outlet. Without it the system that owns a form has to **poll** to learn that
somebody finished filling it in, which is the one thing this service made impossible to do
cheaply — a form has no list endpoint, so polling means asking about ids one at a time.

Two things are told, which is what was asked for: **an accepted save and a confirmation.**

## The decisions, and why they went that way

**1. An outbox, not an HTTP call in the request.** A save must not be slowed down or refused
because somebody else's endpoint is down, and a notification must not exist for a save that
rolled back. Both are had for free by writing the announcement **in the same transaction as
the save**, from the same event — exactly the bargain `form_revisions` already makes with the
row it belongs to ([07](07-history.md)). Delivery is then a separate run, and a receiver being
down is a retry rather than an error somebody has to see.

*Rejected:* `symfony/messenger` with a Doctrine transport. It is the same table plus a worker,
a serializer and a second vocabulary; nothing here needs routing, middleware or several
transports. Also rejected: sending after the response (`kernel.terminate`) — it works until the
process dies mid-call, and then nothing knows the notification was owed.

**2. It is written where the events are, not where the use case is.** `saveDraft()` records
nothing when the incoming document says what the form already holds, so a use case cannot tell
whether anything happened — only the event can. `DoctrineFormRepository` is where events already
become columns and revisions, so it is where an announcement is queued too, through a port
(`Announcements`) so the repository gains a collaborator rather than a second job.

**3. A notification carries no values.** `{event, form, occurredAt, revision?, actor?}` and
nothing else: the receiver reads `GET …/data` or `GET …/history/{seq}` through the API it
already has. This is "a write never answers with the thing it wrote" seen from the outside, and
three things follow — nobody's answers end up in a queue, a log or a proxy; a delivery is small
enough that retrying is free; and **order stops mattering**, because a receiver that reads
current state cannot be confused by two notifications arriving the wrong way round. That is what
makes at-least-once an honest promise rather than a caveat.

**4. An endpoint per form, per event.** ~~One endpoint per deployment~~ — that was the first
draft's answer and the owner overruled it while this was being built, correctly: a service other
people install has more than one system on the other side of it, and "which system owns this
form" is exactly what the creating system knows and this one does not. So `webhooks: {save?,
confirm?}` arrives with the creation request, immutable with the rest of the form, both members
optional and independent. Naming neither is the default and **queues nothing at all** — a queue
for nobody is a table that grows for ever — and the announcement carries the endpoint it was made
for, so a delivery is whole on its own.

What was *not* added with it: a per-form secret. Signing stays the deployment's
(`FORMS_WEBHOOK_SECRET`), which is enough while the receivers belong to one owner, and it keeps
secrets out of the envelope — `GET /api/manage/forms/{id}` serves the endpoints, and a secret
there would be a leak waiting for the first client that logs a response. When a deployment needs
per-form secrets it is another member on the creation request and nothing else moves.

**5. Signed, or refused at creation.** `X-Forms-Signature: sha256=<hmac(timestamp.body)>` with
`FORMS_WEBHOOK_SECRET`, plus `X-Forms-Delivery` (stable across retries, so a receiver can
recognise one it already acted on) and `X-Forms-Timestamp` (so a replay is bounded). A URL set
A form naming an endpoint in a deployment with
no secret is **refused when it is created** — not accepted and discovered later, because every
notification it owed would be refused for the life of the form and its author would find out from
a column in a queue. Same reasoning as `FORMS_TRUSTED_PROXIES` being non-optional: an unsigned
"this form was confirmed" is forgeable by anybody who can reach the receiver, and a receiver
cannot tell the good ones apart afterwards.

**Who answers "can we sign?" is worth a note.** It is the `Webhook` port
(`canSign()`), asked by `CreateForm` — not a container parameter derived from the secret. Two
attempts at the parameter failed for the same reason: Symfony's `bool:` processor only recognises
strings that *look* like booleans, so it reads any real secret as `false`, and `not:not:` is
implemented on top of it, so it does too. The port was the better answer anyway: the thing that
holds the secret is the thing that can say whether it is able to sign, and a parameter would have
been a second answer free to disagree with the first.

**6. A queue holds what is still owed.** A delivery that succeeded is deleted, not marked. What
outlives its telling is only what this service **gave up on** — kept, with the last refusal, so
a deployment can see a broken endpoint instead of watching a counter. Rows leave with their
form by foreign key, `ON DELETE CASCADE`, like revisions: a notification pointing at a form
that no longer exists is worse than none.

**7. A 4xx is retried like everything else.** A receiver mid-deploy answers 404 for a minute,
and a service that treated that as permanent would drop the one notification somebody was
waiting for. Doubling waits from two seconds to an hour, `FORMS_WEBHOOK_ATTEMPTS` (12) refusals
before giving up.

**8. Messenger carries the work; the table stays the truth.** The owner asked for a worker and
for a queue this service has no opinion about, which Messenger is: `doctrine://default` needs no
broker (its table comes with the migrations, because nothing here creates tables at runtime), and
AMQP, Redis or SQS is one DSN. But the message carries **nothing** — `AnnouncementsOwed` is a
nudge that says "go and look" — and that is the load-bearing part. If it carried a delivery id,
the transport's retry policy and the row's own backoff would both be deciding when an endpoint is
tried again, and the two would have to be kept in agreement for ever. This way there is one
policy, in the rows; the transport is allowed to lose a message, because `app:webhooks:deliver`
sweeps the same rows from cron; and a deployment that would rather not run a worker still gets
everything, a minute late.

Dispatched **after** the commit, from the use case rather than the repository: a message handled
before its transaction lands finds nothing owed, and one sent for a transaction that rolled back
is about something that never happened. A failure to dispatch is swallowed and logged — the save
has already been answered, and turning a stored draft into a 500 because a broker is down would
be the only real damage available.

**9. One deliverer at a time.** `due()` takes no lock, so two runs would each send what the
other is sending. The promise is at-least-once and the delivery id is what makes a duplicate
harmless, so the honest thing is to say it in the documentation rather than to buy
`SKIP LOCKED` and hold a row lock across an HTTP call.

## What it is made of

| Piece | Where |
|---|---|
| `Announcement` — what happened, in the shape it is told | `Application/Forms/Webhook/` |
| `Delivery` — one announcement waiting, with its id and refusals | `Application/Forms/Webhook/` |
| `Announcements` — the queue: announce, due, told, again, give up | `Application/Forms/Port/` |
| `Webhook` — the one call outward, throwing `WebhookRefused` | `Application/Forms/Port/` |
| `DeliverAnnouncements` — take what is owed, try it, write down what came of it | `Application/Forms/UseCase/` |
| `WebhookAnnouncementRecord` + `DoctrineAnnouncements` | `Infrastructure/Persistence/` |
| `SignedHttpWebhook` — body, headers, signature, timeout | `Infrastructure/Webhook/` |
| `Announcer` + `MessengerAnnouncer` — the nudge and the transport | `Application/Forms/Port/`, `Infrastructure/Webhook/` |
| `TellWhoeverIsOwed` — the worker's way in, beside the console commands | `UserInterface/Messenger/` |
| `app:webhooks:deliver` — the sweep, for cron beside the two purges | `UserInterface/Cli/` |

## What is deliberately not in it

- **No deployment-wide endpoint.** Every form says where it goes, or goes nowhere.
- **No per-event subscription beyond the two, and no filtering.** A form names an address for a
  save, for a confirmation, or for neither.
- **No `form.created`.** The system that created the form was handed the id in the response.
- **No delivery of what a form holds.** See 3.
- **No ordering guarantee.** See 3.
- **No API for the queue.** It is operational state, read with `app:webhooks:deliver` and, if
  somebody must, with SQL. An endpoint would be a second way to learn what the queue already
  tells the deployment that runs it.

## What landed, and the two things that surprised me

Built as described, with these details worth keeping:

- **`webhook_announcements`** holds no values: form, target, event, `occurred_at`, `revision`,
  `actor_subject`, `attempts`, `next_attempt_at`, `gave_up_at`, `last_refusal`. Told rows are
  deleted; given-up rows stay. `fk_webhook_announcements_form` cascades, and
  `RowsLeaveWithTheirForm` (renamed from `RevisionsLeaveWithTheirForm`) now states both keys the
  mapping cannot, so `SchemaInSyncTest` stays green.
- **The suites load no dotenv files.** `phpunit.xml.dist` sets `bootstrap="vendor/autoload.php"`,
  so `.env.test` is read by console commands and not by tests: the test secret had to go in
  `phpunit.xml.dist` as an `<env>`. Worth knowing before adding another test-only variable — and
  it explains why the suites run against the compose `DATABASE_URL` rather than the one in
  `.env.test`.
- **Composer resolved `symfony/messenger` to 8.0** next to a framework pinned at `^7.4`, exactly
  the trap CLAUDE.md warns about. Pinned to `^7.4` by hand; the lock now holds 7.4.18.
