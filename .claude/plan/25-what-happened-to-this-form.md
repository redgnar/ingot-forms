# 25 — what happened to this form

**Built 2026-09-09.** What the code does now is in `CLAUDE.md` and `docs/architecture.md`
(the Operations chapter). What follows is the design as decided, and what the building corrected.

**Planned the same day.** The row in [10](10-what-a-vendor-offers.md)'s table marked **partial** and
left alone longest: *"Who did what — we record author, confirmer and who entered every save (or
nobody, in `anonymous`). There is no log of operations."* Three fields say who a form **belongs
to**; nothing says what was **done** to it — and the entry anybody actually wants is the one this
service can least easily give: *who deleted it*, when the row is gone.

## A log, not a table

The first decision, and everything follows from it. Rows were the obvious shape and are the wrong
one:

- **The most valuable entry outlives its form.** A deletion, an expiry purge, a refusal on a form
  somebody then abandoned — a table either cascades with `forms.id` and loses exactly those, or
  needs the deliberate exception `webhook_announcements` already carries (`live_form_id` null for
  a deletion), plus a retention limit, plus a purge command, plus an endpoint, plus a lifecycle to
  document. That is a lot of mechanism for a question operations tooling answers already.
- **Retention is a deployment's business.** `FORMS_HISTORY_LIMIT` exists because unbounded history
  is unbounded cost *inside* this service; a log needs no number from us at all — rotation,
  shipping and retention are what a deployment already has for the delivery lines it is reading
  today.
- **There is one mechanism for this and it is in use.** Every webhook delivery already writes a
  structured line as it happens (`info` told, `warning` refused-will-retry, `error` gave-up),
  JSON to stderr, with a fixed field shape. A second way to answer "what happened" is the drift
  this repository refuses by name.
- **And it leaves the model alone**: no column, no cascade question, no read port, no address — the
  same answer the queue got ("no API for the queue").

So: `psr/log`, which the inner layers may already lean on, and a fixed vocabulary rather than
prose somebody greps for.

## What is written down

Every operation that **changed** a form, and every one that was **refused**. Not reads: what was
looked at is the gateway's business, and a request log is not this service's to keep.

| Line | Carries | Level |
|---|---|---|
| `A form was created.` | its mode, and whether it was born holding a draft | info |
| `A draft was stored.` | the revision it became, and who stored it | info |
| `A form was confirmed.` | the revision it closed at, and who closed it | info |
| `A form was deleted.` | `reason: requested`, and who asked | info |
| `An expired form was collected.` | `reason: expired`, and nobody | info |
| `A file was uploaded to a form.` | the file's id, its size and the type the server sniffed | info |
| `A file was discarded before any save named it.` | the file's id | info |
| `A change to a form was refused.` | which operation, and the code that refused it | warning |

A sentence rather than a code, because these are read by a person looking at a log rather than
matched by a machine — and the fields beside them are what a query is for.

**A refusal is `warning` and not `error`**, for the reason a receiver being down is: somebody's
work did not get stored, which is worth seeing, and nothing here is broken. `error` stays what it
is today — what no retry will fix.

## Two rules about what a line may say

- **The actor follows the form's own mode.** An `anonymous` form records nobody, and a log that
  wrote the asserted subject anyway would rebuild precisely what the mode exists to discard — the
  one half of identity that cannot be delegated. So the line asks the **form**, never the request:
  `identity: recorded` gets `actor`, `anonymous` gets none, and a refused *creation* asks the mode
  the request wanted.
- **Never the values, and never a file's name.** The same reason a notification carries no values:
  a log is shipped, indexed and kept, and `umowa-jan-kowalski.pdf` is a person's name in a place
  nobody meant to put one. Ids, counts, codes and revisions — nothing a person wrote.

## Where the lines are written

One class, `Application/Forms/Operations`, holding the vocabulary and the field shape over a
logger — not a port, because `psr/log` is already the abstraction and there is nothing to
substitute. The use cases call it, because a use case is where an operation happens.

**Refusals are logged in the use cases too**, which costs a `catch … rethrow` in the three that
write, and the alternative was worse: `ProblemExceptionListener` sees every refusal in one place,
but it does not know the form's identity mode without loading the form — so it would either log an
actor an anonymous form is supposed to discard, or log no actor at all and answer the wrong
question. A refusal from the CLI would also miss it. The use case knows all three: the form, the
mode, and who was asserted.

## Steps

1. `Operations` with the lines above. `PurgeTemporaryFiles` keeps its own line and is deliberately
   left out: it says what a *run* collected rather than what happened to one form, which is the
   same reason a delivery run reports itself in its own words.
2. The write use cases: `CreateForm`, `SaveFormData`, `ConfirmForm`, `DeleteForm`,
   `PurgeExpiredForms`, `UploadFormFile`, `DiscardFormFile`.
3. Tests against `RecordingLogger` — the fake already exists — asserting what is said, at which
   level, and the two rules: an anonymous form's line names nobody, and no line carries a value or
   a file name.
4. `docs/architecture.md` (the Operations chapter, beside the delivery lines a deployment is
   already reading), `CLAUDE.md`, and the row in [10](10-what-a-vendor-offers.md).

## Deliberately absent

A table, an endpoint, a retention setting, a correlation id of our own (a gateway's request id is
already on the way through and inventing a second one would mean two ways to follow one request), a
line per read, and any line carrying what somebody answered.

## What the building corrected

- **The log nearly made a broken form undeletable, and an older test caught it.** The first
  version read the form to learn its mode, which for a stored document whose rules have moved on
  throws — so `DELETE` started answering 409 for exactly the forms somebody most needs to get rid
  of. That property is deliberate and had a test written long before this block. The mode is *asked
  for* now and not insisted on: unreadable means the line names nobody, which is honest, because
  the form could not be asked. **A record of what happened may never be the reason something does
  not.**
- **The fake was wrong about the one property that saved us.** `InMemoryForms::remove()` built the
  aggregate before removing it, which production does not — it removes a *row*. So the unit suite
  would have agreed with the bug, and the integration suite is what disagreed. The fake refuses
  what production refuses now, and only that.
- **A line names who did *this*, not who owns the form.** The first shape put the author on every
  line, because `about($form)` was convenient — so a save by somebody else was written down under
  the author's name. Each line takes the actor of its own operation, and the author is the actor of
  exactly one: the creation. An upload and a discard name nobody at all, which is the model rather
  than an omission — what records somebody is a *save*, and bytes nobody has saved are not part of
  any document yet.
- **The order of the members is the order somebody reads them in**: which form, who, what it
  became. Trivial, and it was the difference between a test asserting the shape and a test
  asserting a shape.
