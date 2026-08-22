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

## What building it changed

Seven steps, each ending green. Nothing was added to the aggregate, no transition was invented,
no `POST …/restore` appeared and no actor column crept in — the twelve decisions held. What moved
is worth writing down.

**Step 0 bought exactly as little as the plan admitted.** Taking the collection out of the save
path stopped a *save* from deleting anything, but the daily command still asked about the current
values, so a superseded file survived the commit and went a week later. The promise only landed in
step 4. Writing that down before building it meant nobody had to discover it.

**`removeExpired` moved its date check out of SQL and into PHP.** It used to be `DELETE … WHERE id
= :id AND expire_date <= :now`, which is a nice way to make a wrong id harmless — but the
revisions cannot be deleted by that same statement, and two statements with two separate
conditions are two things that can drift. One read plus a check in the open keeps the property and
says it where it can be read.

**The revisions column is called `data`, not `values`.** `VALUES` is reserved in SQL, and quoting
identifiers per platform is a fight nobody needs; `data` is also what the same thing is called on
`forms`.

**Reading history became its own port.** `FormRepository` is a collection of forms and stays one:
a revision is not a form, no rule of the model depends on reading one, and a narrow port is what
keeps a use case from receiving a database. Writing stays with the repository, because a revision
is written by the same event that writes the row.

**The row's `data` turned out to be a cache of the newest revision.** Both are written from the
same event, so the history is the complete record — which is why the file question can be answered
from the history alone, newest document first, short-circuiting on the first hit. That realisation
is what made step 4 cheap.

**`FileReferences` shrank to one method.** `in(Form)` — "the files this form's values name" — lost
its last caller the moment the question became "the files this form has *ever* named", and the new
question lives in `FormFiles` because it needs the history port. A domain walk that takes one
document at a time turned out to be the right shape all along.

**Three tests inverted their premise**, and that is the feature landing rather than a regression:
a file a later draft stopped naming is now downloadable, undeletable, and never collected however
old. Each of them says why in the test, because a flipped assertion with no explanation is how a
promise quietly changes.

The page cost three bugs, all found by tests rather than by thinking:

- **The panel wore a widget's clothes.** Giving it Bootstrap's `card` class made three existing
  assertions count page chrome as a container the document asked for. The fix is both halves: the
  panel dresses plainly, and assertions that were about the form now say `form …`.
- **A new Stimulus controller does not exist until the cache is cleared.** The controllers map is
  generated and cached, so `history_controller.js` was invisible — the same rule this project
  already keeps for derived schemas, one directory over.
- **An unscoped `details summary` in an older browser test** started clicking the history panel
  instead of the form's accordion. A selector that says "the page's only foldable thing" stops
  being true the moment the page grows another one.

And one lesson about the tests themselves: `eventually()` returns the first non-null answer, so
waiting for a value to *change* needs the comparison inside the closure. Waiting for
`held('nickname')` returned the old value instantly and turned a real check into a coin toss.

## What the owner asked for afterwards

Six notes, once they had used it. Two of them overturn what this plan decided, and both were right.

1. **The panel belongs where the document puts it, and only if it asks.** Step 5 called history
   "chrome, not presentation" and drew it at the top of every page. That was wrong on both counts:
   a tool nobody asked for is a page saying the wrong thing, and *where* it goes has been the
   presentation's business since actions were put in the document. So `history` is now a
   `PresentationActions` widget beside `save` and `confirm` — opt-in by construction, placed by the
   document, labelled by it, and drawn by each kit as its own panel. The panel's own words stay in
   `translations/`; only the trigger's label comes from the document, exactly like the other three.
2. **A list of values means nothing.** The member-by-member dump is gone. A value outside the form
   it belongs to says nothing, and the form itself is the only context that gives it meaning.
3. **Looking at a version is a page, not a browser trick.** `GET /forms/{id}/versions/{seq}` draws
   the same form from that save's document, read-only — so every control, every list and every
   attached file is right, drawn by the code that already knows how, with no new client code at
   all. Disabled fields and buttons fall out of the read-only path that already existed. This is
   what replaced "put this answer back into the control", and it is strictly more honest: what you
   see is that whole document, not a few members of it.
4. **Two things per moment: View and Restore.** Restore is the ordinary `PUT …/data` this plan
   already settled on; from the list or from the version page, the same one.
5. **The way out is always at the top of a version page**, and there are two: put this version
   back, or go back to the current one.
6. **`reset` was missing from the start.** The way back to what the form holds is the same
   operation as "back to the current version" — draw the page again, send nothing — so it is one
   behaviour with two names, and the second name is a fourth action a document can ask for.

And one behaviour nobody had to ask for twice: a save refreshes the panel, because a save makes a
new moment and a list that does not show it is lying about what the form remembers.

## What the owner asked for after that

**A save that changes nothing is not a save.** Putting back the version somebody is already
looking at, or pressing save twice, used to append a second identical moment — an earlier
version to go back to that is where you already are, stamped at a time when nothing about the
form changed. The rule belongs to the aggregate, which is the only thing that knows what a form
holds: `saveDraft()` compares the incoming document with the current one and records nothing when
they say the same thing. Comparison is by document, not by text — a page collects values in the
order its controls sit, and that must not read as a change — while a list's entry order and the
difference between `1` and `1.0` do count, because those change what is stored and served byte
for byte. `PUT …/data` still answers `204`; there is simply nothing new to go back to. The page
needed no rule of its own, which is the point: one rule, in the place that owns it.
