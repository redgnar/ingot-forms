# 13 — what one session changed, and what it corrected on the way

Nine commits, 109 files, +6663/−237, in four blocks. Three of them have plans of their own and
this is the index to them; the fourth has none, which is the reason this file exists — the
hygiene work changed how the whole repository is worked on, and nothing else records why.

| Block | Commits | Record |
|---|---|---|
| What a vendor offers | `200c618` | [10](10-what-a-vendor-offers.md) |
| Being installable anywhere | `362e5a3` | [11](11-installability.md) |
| Webhooks, in four passes | `2105462`, `b166d34`, `28946de`, `2e18180` | [12](12-webhooks.md) |
| Hygiene | `c633f14`, `55e04b4`, `760cc66` | this file |

## The vendor scan, and what it was actually for

Form.io read feature by feature ([10](10-what-a-vendor-offers.md)). The output that matters is
not the list but the **four statuses**: half the differences are "a different model" rather than
"a gap", and collapsing those two is how a roadmap fills with work nobody wants done. Seven gaps
worth closing, in order of what they open over what they disturb; three things not to copy (code
inside a document, the form as a template for submissions, a permission system of our own).

The first item on that list is what the rest of the session built.

## Installability, measured rather than read

[11](11-installability.md). Every URL a page carries is generated now, so the same build serves a
form at the root of a host and under a gateway's own path. Two corrections are recorded there
because both were stated confidently and both were wrong: AssetMapper *does* follow
`X-Forwarded-Prefix` (the earlier test failed only because the header was not trusted), and an
environment variable **cannot** be used in a route prefix — which is why `FORMS_BASE_PATH` is
read in `Kernel::build()` and is honestly labelled build-time.

## Webhooks, and the fact that we changed our minds three times

[12](12-webhooks.md) has the whole argument. What is worth reading here is the *shape* of the
revisions, because it is the shape of most design work:

1. **The outbox, from the event.** A row in the same transaction as the save, written from the
   event — because `saveDraft()` records nothing when the document says what the form already
   holds, so only the event knows whether anything happened. Nobody's endpoint can slow a save
   down or refuse it.
2. **"And where is my proof of success?"** The queue kept only what was owed, so a *lost*
   notification was provable and an *arrived* one was not. Two things were added: a log record per
   delivery, and a durable row.
3. **"The queue should hold only work."** Right, and better: the success moved to the thing it is
   about — `form_revisions.notified_at`, `forms.confirm_notified_at` — and the row is dropped in
   the same flush. Three questions, one home each.
4. **`form.deleted`, with a reason.** Half of it the caller already knows; the half that matters
   is `expired`, because nobody asks `app:forms:purge-expired` for anything. It forced the one
   genuinely awkward decision of the session: a foreign key cannot say "cascade all of these
   except that one", so identity and cascade became two columns.

And one refusal worth keeping: **the failure stamp as a JSON column** was asked for, thought
about, and declined — a known shape belongs in columns, `where gave_up_at is not null` is one
indexed scan, and this codebase stores JSON text only where the document is opaque.

## Hygiene — the part with no other record

**Run the narrowest test that covers the change** (`c633f14`). The owner's words: "marnujemy czas
na odpalanie wszystkich testów zawsze". Re-running 1200 tests and a browser battery after every
edit spends minutes proving what the edit could not have touched, and the wait is theirs. CLAUDE.md
now maps what was changed to what to run, and says the rule in both directions: a new field type
does not need the webhook tests, a webhook change does not need a browser. `make ci` did not move
— it stays the gate, it stopped being the loop.

**A `var()` with no fallback deletes the rule it overrides** (`55e04b4`). An empty autocomplete sat
short of every control beside it because `min-height: var(--kit-control-height)` is invalid at
computed-value time on a skin that declares nothing — and an invalid declaration does not merely
fail, it *overrides*. In a headless browser the control measured 14px against an input's 38px. The
fallback lives in the `var()` now, not in a `:root` of the kit's (a skin's stylesheet is imported
first and would be overridden), and the underline a skin needs is a variable the kit reads rather
than a line it paints for everybody. Pinned in the browser battery and checked by reverting the
fix — the test fails with "14 is identical to 38".

**The browser suite left its fixtures behind for ever** (`760cc66`). 4281 forms in the development
database before anybody counted, ~140 per run. Not a leak in the service: every other suite is
rolled back by dama, and a browser test cannot be, because it talks to a separate server process
— which is exactly why its fixtures are created over HTTP. Nobody had followed that sentence to
its end. `DeletesWhatItPlanted` deletes them through the API for the same reason, so a fixture
leaves the way a form leaves. The trap worth remembering: **a class's method beats a trait's
silently**, so the one case with a `tearDown()` of its own went on leaking after the rest had
stopped; the cleanup is a named method it calls itself now.

## What is deliberately still open

- **There is no gateway.** A form's UUID opens everything, which [09](09-access.md) said and
  nothing since has changed. Being installable in more ways is not being safe to expose in any of
  them.
- **A changed `FORMS_BASE_PATH` with a warm cache is silently stale.** An environment variable is
  not a tracked resource; `app:routes:groups` prints what is actually served, and that is the
  whole mitigation.
- **A stamp leaves when its row leaves.** `FORMS_HISTORY_LIMIT` evicting a revision takes the
  record that that save was reported. Accepted: it is the rule the rest of the service follows.
- **Deliveries of a deleted form cannot be read through the API**, because `…/deliveries` reads
  the form first. Visible in the log and the table while owed.

## The shape of the session, if it is worth copying

Everything here was checked by measuring rather than by reading: the page was drawn behind a real
proxy header, the queue was watched in `psql`, the notification was verified by a receiver that
computed the HMAC itself, the browser test was run with the fix removed to prove it was not a
tautology, and the suite's leak was confirmed by counting rows before and after. Four of the
session's decisions were reversed by the owner mid-flight, and each reversal is recorded next to
the thing it changed rather than tidied away — which is the only reason the fourth pass at
webhooks reads as a design rather than as an accident.
