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

## The correction the owner asked for, an hour later

"Czyli nie będę miał potwierdzenia wysłania webhooka?" — and no, not as built. Decision 6 said a
queue holds what is still owed, so a told row was deleted and only the given-up ones survived.
Stated plainly, that meant **a lost notification was provable and an arrived one was not**, which
is the wrong way round: the failure case already had a durable record and the success case, which
somebody actually needs to point at, had none.

Both halves were added:

- **A log record per delivery**, in `DeliverAnnouncements` rather than in the handler: `info` for a
  telling, `warning` for a refusal that will be tried again, `error` for one given up on. Each
  carries the delivery id that went out as `X-Forms-Delivery`, so a line here and a line in the
  receiver's log are the same event. Production logs at `info`, so the successful ones are there
  too. The handler's own summary log went away — a count at that level would have been a second,
  vaguer version of the same records.
- **The row is marked, not deleted.** `delivered_at` joins `gave_up_at`, and the two moments make
  three states with no state column to disagree with them: neither set is `owed`, the first is
  `told`, the second `abandoned`. `due()` filters on both, so a told row costs a run nothing.
- **`GET /api/manage/forms/{id}/deliveries`**, newest first, read through a read-only port
  (`FormDeliveries`) declared apart from the queue for the reason `FormHistory` is declared apart
  from the forms repository: a delivery run has no business holding a per-form reader, and a
  reader has no business settling anything. Management prefix, like the actor-carrying history and
  for the same reason — it names the endpoints a form reports to and who did the reported thing.

Read-only on purpose: no retry-now and no cancel. What is owed will be tried by the next run, and
a receiver that was broken and is now fixed is a deployment's business rather than a form's — a
button here would be a second thing deciding when an endpoint is called, next to the backoff that
already decides it.

**What it cost:** the table now grows by one row per save of a form that reports itself, where
before it drained. They still leave with the form (cascade) and with the expiry purge, and they
hold no values — but `FORMS_HISTORY_LIMIT` does **not** prune them, deliberately: what was told
cannot be untold, and evicting the record of a telling would be evicting the only proof of it.

## One test lesson worth keeping

`due()` is a queue for the whole deployment, and the suite runs against the **dev** database
(compose's `DATABASE_URL` outranks `.env.test`, and the suites load no dotenv files at all). So a
test that counted what is due was really asserting something about every other form in that
database — it passed while the table happened to be empty and went red the moment a demo form was
left owing something. Tests here ask about *their own* rows (`owedIdsAmong()`), which is the right
discipline regardless of which database they run on.

## And where the success actually belongs

"Chodziło mi raczej o przechowywanie sukcesu — bo rozumiem że sama obsługa powinna zostać jak
jest, ale tam powinny być tylko rzeczy do zrobienia albo z błędem." Right, and better than what
the correction above did: marking a told row answered the question but left a queue that never
drains, holding three states of which two are nobody's work.

So the fact moved to the thing it is about. `told()` writes `form_revisions.notified_at` for a
save, `forms.confirm_notified_at` for a confirmation — which has no revision, because confirming
writes no values — **and drops the queue row in the same flush**, so a stamped save can never sit
beside a row still claiming somebody is owed the news. `webhook_announcements` is now what its
name always suggested: work. `gave_up_at` null is owed, set is abandoned, nothing else lives
there.

Three questions, one home each, which is the part worth keeping:

| Question | Answered by |
|---|---|
| was this save reported, and when | the save's own `notifiedAt` (management history) |
| was the confirmation reported | the form's `confirmNotifiedAt` (envelope) |
| what is stuck | `GET /api/manage/forms/{id}/deliveries` — `owed` or `abandoned` with the reason |

Costs, both accepted:

- **A stamp leaves when its row leaves.** `FORMS_HISTORY_LIMIT` evicts old revisions and with them
  the record that those saves were reported. That is the rule this service already follows
  elsewhere — a document nobody can restore is a document whose files stopped mattering — and a
  save told about *after* its revision was evicted stamps nothing rather than recreating anything.
- **`form_revisions` gained its first updatable column.** What a revision *held* stays
  append-only; `notified_at` is about the telling, and it is written once. Said in the record's
  own docblock, because "append-only" was a claim somebody would otherwise read too widely.

## On putting the failure there too, as JSON

Asked, and worth an answer rather than a change: **no, and not as JSON.**

A failure is not a fact about a save the way a telling is — it is *work in a bad state*, and
what makes it actionable is exactly what a stamp cannot hold: `attempts`, `next_attempt_at`,
`last_refusal`, and the fact that a run has to be able to *find* it. `select … where gave_up_at is
not null` is one indexed scan on a small table; the same question against a JSON member on
`form_revisions` is a JSON path predicate over the largest table in the schema, and this codebase
stores documents as JSON text only where they are *opaque payloads* (a definition, a values
document, a presentation). A delivery outcome has a known shape, and known shapes are columns
here: a JSON blob would give up type checking and portable querying to buy an extensibility
nobody has asked for.

What is worth doing if the split ever chafes: the history response can carry the *failure* as a
derived member read from the queue, the way it now carries the stamp — no new column, no second
copy, and it cannot drift. Offered, not built.

## `form.created`, refused and then built

Decision 2 in "What is deliberately not in it" said no `form.created`, because the system that
created the form was handed the id in the response. The owner asked for it anyway, and the
refusal turns out to have been about the wrong party.

**The creator is not the receiver.** The endpoint is named *by* whoever creates the form, and
what it names is usually something else: a downstream that mirrors these forms, an audit sink, a
workflow that has to know the object exists. For any of those, the first thing this service ever
told them about a form was `form.saved revision 1` — for an id they had never seen. A lifecycle a
receiver can follow now has no hole at the start.

It is announced from `FormCreated`, in `add()`, and **before** the first draft's announcement when
a form is born holding one, so a receiver hears that a form exists before it hears what it holds.
It carries the author and no revision (nothing is stored yet) and no reason (nothing has gone).
Its `live_form_id` is set like the other two that describe a living form, so a creation nobody
delivered before the form was deleted leaves with it — which is right: the deletion announcement
says the rest.

What has *not* changed is why `form.created` was refused in the first place. If your receiver is
your own caller, name no endpoint: being told what you just did is a round trip that teaches
nothing, and the queue stays empty for forms nobody asked to hear about.

## Three bugs of one shape, and the two tests that close it

`form.created` shipped, and then shipped twice more. Every failure was the same shape: **an event
was added and a place that has to act on it was not.**

1. **The nudge was gated on `$data`.** It was written when the only thing a creation could owe was
   the first draft's announcement. With `form.created` queued for every creation, the gate left it
   waiting for the sweep. Fixed by asking unconditionally — the queue is what knows whether
   anything is owed.
2. **`stamp()` was not total.** A creation fell through to the revision branch, looked for the save
   numbered `null`, threw, killed the handler, and Messenger retried the message: a receiver got
   the same notification **five times** and the row never left the queue. Fixed as a `match` with
   `default => throw`, and it exposed a third thing — a delivered `form.created` had nowhere to be
   recorded, so `forms.created_notified_at` joined `confirm_notified_at`.
3. **`DeleteForm` and `PurgeExpiredForms` had no announcer.** A deletion queued its row and nobody
   told a worker, so `form.deleted` waited for cron. The purge nudges once per *run*, not per form:
   a worker asked to look drains everything owed.

All three were found by **running it**, and none of the unit tests written beside those changes
could have caught any of them — which is the interesting part, and the reason for two invariants
rather than three more cases:

- `NoWritePathIsSilentTest` walks every path that reaches the repository with a write — create,
  create-with-data, save, confirm, delete, purge — and asserts each asks a worker to look. A sixth
  write path means a sixth case, and the docblock says so.
- `testEveryEventThereIsCanBeToldAndSettled` reads the events off `Announcement`'s own constants by
  **reflection**, queues one of each, tells it, and asserts it leaves the queue. An event it cannot
  build fails the test with a message naming the other place to teach (`stamp()`). Checked against
  the bug rather than trusted: with the `CREATED` arm removed it fails.

The lesson worth carrying: when a list grows (events), the tests that matter are the ones that
*enumerate* it rather than sample it.
