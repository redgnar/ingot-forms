# 07 — a form's history, and files that outlive a save

Until now a form holds exactly one values document: each draft save overwrites the last, and
what was there before is gone. This stage keeps every accepted save, lets a client read any of
them back, and makes restoring an ordinary save of an old document rather than a new kind of
write.

What the owner asked for, in their words: open a form's history, and restore *some* values from
a chosen date and time. And the consequence they went looking for: **then no file has to be
deleted until the form expires**, because a document somebody can restore is a document whose
files still matter.

## Decisions

1. **History is what the aggregate already records.** `DraftSaved` carries the whole `Values`;
   the adapter applies it onto the row and forgets it. A revision *is* that event, persisted —
   so this adds no transition, no rule to the model, and nothing to the aggregate at all. The
   write stays event-driven: one more arm in the `match` that already turns what happened into
   columns.
2. **Append-only, one row per accepted save.** `form_revisions(form_id, seq, saved_at, values)`,
   portable types only, the values stored as the exact text that passed validation — the same
   rule the row itself keeps. A revision is never updated and never deleted on its own: it
   leaves with its form.
3. **A form born a draft has revision 1.** `add()` writes the row whole today and drops the
   events; it has to write the first revision too, or a form created with `data` would start
   with a history that disagrees with its own values.
4. **Restoring is not a new write path.** A client reads a revision and sends it back through
   `PUT …/data`. It passes the same three gates, and it is recorded as a *new* revision —
   history is append-only, so restoring is a change like any other rather than a rewind. A
   revision today's rules would refuse says so with findings, at the pointers that say why.
   There is no `POST …/restore`, for the same reason the pages have no write path of their own:
   a privileged way in is a second way in.
5. **Restoring *part* of a document is the client's business.** A revision is handed over
   whole; picking members out of it and merging them into what the form holds now is what a
   client does before it sends the result. The server needs no partial endpoint, and the page
   can offer "put this one field back to what it was at 14:32" without a single new rule behind
   it.
6. **Two read endpoints, and neither hands over documents in bulk.** `GET …/history` lists
   `{seq, savedAt}` — enough to choose one, and nothing more, because a list carrying every
   document is a response nobody asked for. `GET …/history/{seq}` serves that revision's values
   byte for byte, exactly as `GET …/data` serves the current ones.
7. **No file is deleted before its form expires.** The save's own collection (layer 2 of stage
   06) **goes**: a revision that names a file has to stay restorable, and a file that is gone
   makes it unrestorable. The page's `DELETE` and the daily command keep working, with their
   question changed from "the stored values name it" to "**any revision** names it" — the same
   walk, over a few documents instead of one. What is left as garbage is exactly what the age
   threshold was invented for: an upload no document ever named.
8. **A download answers for any revision's file, not just the current one.** `ReadFormFile`
   asks the same question the collectors now ask. Otherwise a client could read a revision
   naming a file and then be told that file does not exist — which is true of the present and
   false of the document it is looking at.
9. **"Who" is a non-goal, and this is the one to say out loud.** This service has no identity of
   any kind — no users, no authentication, nothing a request can be attributed to — so a
   revision can honestly answer *when* and *what*, never *who*. An actor column now would be a
   member nobody can fill, and a changelog with an empty actor reads like an audit log while
   being a diary. Identity is a service-wide change, not a column; when it arrives it lands on
   this table as one more member and nothing about the history's shape moves. Until then the
   endpoint says what it is.
10. **Everything is kept, not the last N.** A cap would cut history off for exactly the forms
    that were edited most, which are the ones somebody wants history for. The expire date is
    the bound, as it is for everything else here. A cap is additive, with a number in config, if
    a real form turns out to be saved thousands of times.
11. **Nothing about the definition or the presentation is versioned**, because neither can
    change. That is what makes an old revision safe to restore without versioning a contract:
    it was judged against the very definition it will be judged against again.
12. **Retention does not change.** Revisions sit under the same expire date and leave with the
    form, rows before bytes. More copies of the same personal data inside the same window — an
    operator's note, not a new promise.

## The shape

```
forms                      unchanged: the current values, as today
form_revisions             form_id, seq, saved_at, values     append-only, one per accepted save
```

`seq` is per form and allocated inside the transaction that writes the row — which is safe for
the reason every other multi-step write here is safe: `getForUpdate()` already holds the row
lock, so nothing can allocate the same number twice. Ordering by `saved_at` would not do: two
saves in one second are two revisions.

```php
// DoctrineFormRepository::apply(), one more arm — the shape this stage is built on
$event instanceof DraftSaved => $this->store($record, $event) && $this->record($event),
```

## The API

| Method & path | Purpose |
|---|---|
| `GET /api/forms/{id}/history` | `{revisions: [{seq, savedAt}]}`, oldest first. `confirmed: true` on the one a confirmation locked — derived, never stored. |
| `GET /api/forms/{id}/history/{seq}` | That revision's values, byte for byte. `404` when there is no such revision. |

Restoring, in full, with nothing new behind it:

```
GET  /api/forms/{id}/history            → choose 14:32, which is seq 7
GET  /api/forms/{id}/history/7          → the document as it was
     …the client keeps what it wants of it and merges…
PUT  /api/forms/{id}/data               → judged by the same three gates, recorded as seq 12
```

`410` for an expired form and `404` for an unknown one, everywhere, as elsewhere. A confirmed
form's history is readable and cannot grow: `PUT …/data` is already refused with `409`.

## What it costs the file story

Stage 06 collected files in three layers. Layer 2 goes, and the other two ask a different
question:

| | before | after |
|---|---|---|
| the page's `DELETE …/files/{id}` | refused when the stored values name it | refused when **any revision** names it |
| a save, after its commit | deleted what it superseded | **nothing** — a superseded file is a restorable one |
| `app:files:purge-temporary` | collected what the values do not name | collects what **no revision** names |
| `app:forms:purge-expired` | the whole form, rows then bytes | unchanged, plus the revisions |
| `GET …/files/{fileId}` | answers for what the values name | answers for what **any revision** names |

The rule that replaces layer 2 is simpler than layer 2 was: **a file leaves when its form
does**, and the only thing collected earlier is an upload no document ever named. The cost is
storage — every replaced file waits for the expire date — bounded by `FILES_PER_FORM` and by
that date. `FileReferences` gains one method (`in(Form, iterable<Values>)`, or a small
`namedByAny()`) and every caller of the old question changes to it, which is the whole diff.

One thing to be honest about: for forms that exist **before** this ships, history starts empty,
and files their saves already collected are already gone. Nothing can bring those back, and the
plan should not pretend otherwise.

## Order & acceptance

Each step ends with a green `make ci`.

0. **Stop collecting on save.** `SaveFormData` goes back to what it was before stage 06's step 5
   — one transaction, no post-commit deletion, no `FileStore` and no logger. Its three
   superseded-file tests go with it, and the browser battery's `…AndOutOfTheStore` case turns
   into its opposite: after remove-and-save the document no longer names the file **and the
   bytes are still there**, which is now the promise. Small, immediate, and independent of
   everything below — but honest about what it does *not* buy yet: the daily command still asks
   about the current values, so a superseded file survives the save and is collected a week
   later. "Nothing goes before the form expires" arrives with step 4, and cannot arrive sooner,
   because before there is a history there is no way to tell a superseded file from an abandoned
   one.
1. **The table and the write.** Migration through the schema API, `FormRevisionRecord`, the
   `match` arm, the first revision on insert, and `expiredIds`/`removeExpired` taking revisions
   with the form. Tests: a save appends exactly one revision holding exactly the text that was
   validated; a form born a draft has one; the round-trip test grows a revision assertion; the
   purge leaves neither table holding anything.
2. **Reading it.** `ReadFormHistory` (a list) and the two actions, with the OpenAPI responses and
   the contract scenarios that keep the document and the traffic in step. Integration tests for
   the order, the `confirmed` marker, `404` on an unknown `seq`, `410` on an expired form.
3. **Restoring, proven end to end.** No code — a test: read a revision, send it back, watch it
   become the newest revision, and watch a revision that breaks today's rules be refused with
   the findings that say which member.
4. **The files question moves.** `FileReferences` answers "named by any revision", and the three
   callers (the download, the page's discard, the daily command) ask that instead. Tests: a
   superseded file is still downloadable, still refused by `DELETE`, and never collected however
   old; an upload no revision named is still collected on the threshold.
5. **The page.** History is **chrome, not presentation** — no document carries a code for it, the
   same way none carries one for "your answers are saved" — so no engine and no presentation
   vocabulary changes. Each kit draws its own: a list of moments, and for one of them the values
   it held, with "put this field back" per member (the page already knows which control holds
   which member — `data-name` plus the entry's scope — so this is a read and a write into a
   control, not a new mechanism). Browser battery per kit: change something, save, open the
   history, put the old value back, save, and see the API agree.
6. **Documentation**: `README.md` (the endpoints, the restore flow, the file rule that replaced
   layer 2, the retention note), `CLAUDE.md` (history as persisted events, restoring as an
   ordinary save, "who" as a stated non-goal), `tests/_requests/09-history.http`, `make docs`,
   and this plan's own afterword.

Acceptance, one sentence each: every accepted save is readable afterwards; any of them can be
sent back and becomes the newest; a file a revision names is downloadable and undeletable for as
long as the form lives; an upload no document ever named still goes on the schedule; and after
`app:forms:purge-expired` neither table nor the store holds anything of that form.

## Risks, and what to check before writing much

- **`seq` under the lock** — the insert must be in the same transaction as the row write. It is
  today; a test that saves twice in one run is what keeps it that way.
- **The test database needs the migration** (`make db-test`), and dama's per-test rollback covers
  the rest.
- **How large a revision list gets.** No pagination in the first cut, deliberately; the trigger
  for adding `?since=`/`?limit=` is a real form with thousands of saves, and it is additive.
- **The values battery does not change**, but the round-trip test does: a form is now a row plus
  a history, and `testEveryPieceOfAFormSurvivesTheRoundTrip` should say so.
- **Reading N documents to answer "named by any revision"** is the one new cost on a hot path
  (the download). Bounded by revision count; if it ever matters, the answer is a cheap index of
  ids per form, derived and rebuildable — not a second truth.

## Non-goals

**Who** — deliberately, and it is the one worth repeating: identity comes later, service-wide,
and lands on this table as one member. Also: no `POST …/restore`; no server-computed diffs (a
client can diff two documents it fetched); no history of the definition or the presentation
(neither can change); no pruning or caps; no undoing a confirmation; no history of *reads*; and
no history endpoint that hands over every document at once.
