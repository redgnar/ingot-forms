# 26 — a save that could not be delivered

**Built 2026-09-09.** What the code does now is in `CLAUDE.md`, `docs/kits.md` (what both kits do
without being asked) and `docs/architecture.md` (the pages chapter). What follows is the design as
decided, and what the building corrected.

**Planned the same day.** The row in [10](10-what-a-vendor-offers.md)'s table marked **gap** and
described as "only half against our model — the draft exists; the browser-side queue does not.
Large cost, narrow use." That reading was right about the cost and wrong about where the value is,
which the first measurement showed.

## What is actually wrong today

Measured before designing anything: **a save that cannot reach the server is silent.** The kits'
`send()` awaits `fetch`, and a network failure *rejects* rather than answering — the rejection
leaves an `async` click handler and goes nowhere. No message, no notice, nothing marked. Somebody
on a train presses *Save for later*, sees no confirmation, presses it again, and has no idea
whether either attempt landed.

So the order of value in this block is the opposite of the row's: first **say it did not happen**,
then keep the answers, and only then send them again. The queue is the third thing, not the first.

## What the browser will and will not tell us

Also measured, and it settles the design: under Chrome's own network emulation `fetch` rejects with
a `TypeError` — a real network failure — while **`navigator.onLine` keeps answering `true`**. That
flag is not evidence and is not used: it lies on a captive portal (associated, no route) and it
lied here. What drives the queue is **what happened to a request**, and nothing else.

The `online` event is used, but only as one of four moments to try again, never as the fact:
a failed send keeps the answers, and they are sent when the browser fires `online`, when the page
is opened again, or when somebody presses save themselves.

## What is kept, and where

`localStorage`, one entry per form (`ingot-forms:owed:{id}`), holding the document the page
collected, the revision the page was drawn from, and when. `localStorage` and not the detour stash
already in the kits: that one is `sessionStorage` and answers a different question (answers
carried across a look at an earlier version, inside one tab). A save that did not happen has to
outlive the tab, because a laptop closed on a train is the case.

Not IndexedDB: a form's answers are a small JSON document, `localStorage` is what the kits already
use for the reader's own switches, and a database for one row is a lifecycle nobody asked for.
Every read and write is wrapped, because storage can be turned off — and then this feature simply
does not exist, which is what the detour stash already does.

## The sharp question: the form moved on

A delayed save carries **`If-Match`** with the revision the page was drawn from; an immediate save
stays unconditional, as it is today. That asymmetry is the whole point rather than an
inconsistency: *a save you make now is about the form you are looking at; a save that waited is
about a form you last saw some time ago.* The API has taken an optional precondition since
[14](14-conditional-writes.md) precisely so a client can say which of the two it is.

On `412` the page **does not merge and does not choose**. Merging two people's answers is a
decision no page can make, and picking one silently is what `If-Match` exists to prevent. So it
says what happened and offers the two ways out a person can actually decide between:

- **see what is there now** — reload, which drops nothing: the answers stay kept until a save
  succeeds, so somebody can look and come back;
- **store mine anyway** — the same document without the precondition, which is a deliberate
  overwrite of somebody else's save, worded as one.

What is **dropped rather than retried**: `404` (the form has gone), `410` (it expired) and `409`
(it is confirmed, or locked) — a queued draft that can never be stored is not a queue entry, and
saying so once beats trying for ever. A `422` shows the refusals the way any save does and stops
being owed: answers that do not fit the contract will not fit it later either, and they are still
on the page for somebody to correct.

## What cannot be queued

**An upload.** A values document may only name file ids the server issued, and issuing one means a
request that reads the bytes and measures them. So a file picked while nothing can reach the server
is refused with a sentence rather than pretended, and a queued document names only files that were
already uploaded. This is the one place where "offline" is genuinely half of what the words
promise, and it is better said than discovered.

**Two tabs** share one entry, last writer wins locally, and the revision check is what protects the
server — the same answer two people get, because to this service they are two clients.

## No service worker

Deliberately, and it is the cost the roadmap row was really about. A worker would mean a second way
in (something that talks to the API when no page is open), a scope and lifecycle to manage,
a registration step in a kit that promises no build step, and answers served from a cache that this
service's own `Cache-Control` says nothing about. What this block builds is a page that keeps what
it could not send. What it is not is a service that sends things when nobody is looking.

## Steps

1. Both kits: a failed send says so and keeps the answers (this is the defect, and it is worth
   shipping on its own).
2. The revision reaches the page (`chrome.revision`, from the aggregate the renderer already
   holds), and a delayed send carries it as `If-Match`.
3. The four moments to try again, and the answers for `412`, `404`/`410`/`409` and `422`.
4. A browser battery per kit under real network emulation: a save that fails is said and kept, the
   same page opened again sends it, a form that moved on offers both ways out, and a confirmed form
   drops what can never be stored.
5. `docs/kits.md`, `docs/architecture.md`, `CLAUDE.md`, and the row in
   [10](10-what-a-vendor-offers.md).

## Deliberately absent

A service worker, a cache of the page itself, IndexedDB, a queue of *several* saves for one form
(the newest document is the only one anybody wants — a form is one fillable document, and its
history is the server's), queued uploads, queued confirmations (closing a form is a decision to
make when you can see what you are closing), and any automatic merge.

## What the building corrected

- **The browser fires `online` by itself, and my tests raced it.** Restoring the network with
  Chrome's emulation makes the page retry at once — which is exactly right and cost three failing
  cases: a conflict staged *after* the network came back was staged after the retry had already
  succeeded, so no `412` ever happened and the entry was gone. Every case that stages somebody
  else's save or a deletion now does it **while the network is still away**. The page's own
  `dispatchEvent('online')` in the tests is belt on braces, kept because it says what the case is
  about.
- **Navigating while offline poisons the session, not just the case.** One test opened the page
  again with the network down: `net::ERR_INTERNET_DISCONNECTED` from WebDriver, and the two cases
  after it failed for reasons of their own making. It was rewritten as the flow it was really
  reaching for — look at what the form holds, then put my answers back — which is deterministic and
  tests the more interesting half.
- **A battery that names one thing has to name it the same way in both kits.** The richer kit's
  *store mine anyway* button carried only its Stimulus action, so one battery could not press it in
  both kits; it carries `data-owed-anyway` now, like the plain kit's.
- **`navigator.onLine` was measured before it was trusted, and it failed the measurement**: it kept
  answering `true` with nothing reachable. It is read nowhere.
- **The one claim that was already true had nothing pinning it.** "A file picked while nothing can
  be reached is refused rather than pretended" was a promise about behaviour both kits already had
  (a caught `fetch`, an XHR `error` listener) — and nothing tested it, so it was a claim rather than
  a fact. It is a case now, in both kits.
- **A new notice took a colour another test was identifying things by.** A renderer test asked for
  `.alert-warning` and meant *the alert the document asked for*; the page's own chrome now uses the
  same tone (a save that did not arrive is a warning too), so the test was naming whichever came
  first in the markup. It names `role="note"` now — the thing that actually tells a document's alert
  from the page's — which is a better test than it was before.
- **The notice is shown even when nothing could be kept.** Storage can be off (a private window),
  and then the save still failed: saying so is the part that matters, and the second chance is what
  is lost. The detour stash already made that bargain.
