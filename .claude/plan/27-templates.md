# Form templates: the documents a form is made of, stored once

**Built 2026-09-12/13, in nine blocks.** What the code does now is in `CLAUDE.md`,
`README.md`, `docs/configuring-forms.md` (the templates chapter) and
`docs/architecture.md` (storage, and the addresses). What follows is the design as decided with
the owner, and — at the end — what the building corrected.

A form is one fillable document, and that does not change here. What changes is **where the two
documents it is made of live**. Today they are columns on the form's own row, which means a system
creating a thousand claim forms sends the same definition a thousand times and stores it a thousand
times, with nowhere to keep the one it means.

From here on **every form points at a stored definition and, when it has one, a stored
presentation** — always, whether those documents are shared by ten thousand forms or belong to one.
A **form template** is a name and a version history over the same rows: two independent streams, one
of definitions and one of presentations, with exactly one version of each in use. A document created
for a single form is the same kind of row in the same table, in **no template**, and it is collected
when its form goes.

That uniformity is the point. There is one storage story, one hydration path, one cache key and one
deletion order, and "reusable" versus "one-off" is a question about one column rather than about two
different mechanisms.

`00-mvp.md` put templates out of scope and [10](10-what-a-vendor-offers.md) refused the vendor's
template outright. The refusal there was about something else and still stands: for Form.io a form
*is* a template — it collects unbounded submissions, which is what drags in versioning as a
**validation** question ("which version were these answers given against?"), promotion between
environments and a submission browser. Nothing here collects anything. A template is an **authoring**
artifact: it names documents and says which of them is current. A form still has exactly one
definition, one data set and an expiry.

## What this gives up, and what replaces it

Today a definition cannot change after the form is created because the bytes sit on the form's own
row and nothing writes that column. Under a reference, that guarantee has to be **stated and
enforced** instead of being a happy accident of the layout:

- **A stored document is append-only and has no write path at all.** Publishing inserts; nothing
  updates; a version is never edited, only superseded. `NothingRewritesAStoredDocument` asserts it
  over the repository the way `NoWritePathIsSilentTest` asserts its own invariant — by walking the
  code rather than by trusting the comment.
- **A document cannot be deleted while a form points at it.** A foreign key with `RESTRICT` makes
  that structurally impossible rather than checked, which is stronger than what the copy gave us: a
  copy could be orphaned by a bad migration, a reference cannot.

What genuinely moves is the failure mode of an out-of-band write: a manual `UPDATE` on the documents
table would now change what an existing form asks, where before it could not. That is the honest
cost of the change and it is the only one.

## The tables

```
form_definitions      id (uuid, pk) · template_id (uuid, null) · seq (int)
                      document (text) · published_at · by_subject
form_presentations    the same shape, its own stream
form_templates        id (uuid, pk) · name · created_at
                      current_definition_id (fk, not null) · current_presentation_id (fk, null)
forms                 … definition_id (fk, not null, RESTRICT) · presentation_id (fk, null, RESTRICT)
                      (the `definition` and `presentation` text columns are gone)
```

**A version row carries a surrogate id, and that is the one place this departs from
`form_revisions`.** A revision has no id because nothing points at one; a stored document has one
because `forms` points at it — the same argument, answered the other way by the same fact. The
pair `(template_id, seq)` stays as a unique index, so a template's history still reads as 1, 2, 3.

**`template_id IS NULL` is the one-off mark.** A document in no template belongs to the form that
was created with it and leaves with that form. Expressed as the absence of the thing that makes a
document reusable, it cannot disagree with itself the way a separate `one_off` boolean beside a
`template_id` could.

**A template's pointer is a foreign key, not a number.** `current_definition_id` says which row new
forms get; moving it is one write, and a rollback is the same write pointing back.

## Two streams, because they fail differently

A presentation changes often and cheaply: a relabelled option, a different widget, a fixed
catalogue. A definition changes rarely and is where compatibility breaks — it is the model the
answers are shaped by. Numbering them together would make every label fix look like a new model.

And with the documents stored once, the question that motivated the split gets its sharpest possible
answer: **"do these two forms answer the same model?" is `forms.definition_id` compared to
`forms.definition_id`.** One column, exact, no hashing, no diffing of trees, and it holds for forms
created months apart — because there is now only one row either of them could mean.

**The pairing lives in the pointer and is judged whenever the pointer moves.** A presentation is only
ever valid *against* a definition (`PresentationRules::check()` takes both), so a presentation row
does not name one: it is judged at publication against the definition then in use, and judged again
whenever it is activated beside a different one. A pair that does not fit is refused where the
pointer would have moved (`template.pair.mismatch`, carrying the ordinary presentation findings), so
a template's active pair has always been proven to fit.

**Publishing is never activating**, with one exception: creating a template publishes the first
version of each and activates them, because a template with no pair in use is a half-made thing and
there would be nothing to create a form from. Afterwards a version is published as something to move
to, and moving is its own operation — which is what makes a definition change safe to prepare in
advance, and a rollback cost no new version.

## The one-off form, and how its documents are collected

The creation request keeps the shape it has today — a definition inline, optionally a presentation —
and **nothing about the wire contract changes for a client that never uses the catalogue**. What
changes is underneath: the documents are inserted as rows in no template, and the form points at
them.

They are collected in one place and in one order, inside the transaction that already deletes the
form: **the form row first, its one-off documents second.** The first delete releases the `RESTRICT`
that the second would otherwise trip on, and both statements are in one commit, so there is no window
in which a form names a document that is gone. Every path that deletes a form goes through it —
`DeleteForm`, `PurgeExpiredForms`, and the bulk purge below, which is `DeleteForm` in a loop — and
`RowsLeaveWithTheirForm` grows the case, because this is a cascade the mapping cannot declare: it is
conditional on a column.

`purgeExpired()` does it in bulk, and the ids have to be read before the delete rather than returned
by it: `RETURNING` is Postgres's and this persistence layer is platform-neutral.

**A one-off document belongs to exactly one form.** It is created by exactly one creation, and a
creation request may not pin one (`template.version.one-off`), so nothing can make a second form
depend on a row that is due to leave with the first.

## A template in use cannot be deleted, and emptying it is its own act

Deleting a template whose versions some form points at is **refused** — `409 template.in-use`, the
foreign key saying so before the check does. Detaching the versions instead (leaving them to their
forms as one-offs) was considered and dropped: it turns a delete into a silent lifecycle change on
documents that live forms depend on, and "the catalogue entry is gone but the documents quietly
became disposable" is not something an administrator asked for when they pressed delete.

So emptying a template is a **separate address**, and that separation is the whole guard:

```
DELETE /api/manage/form-templates/{template}/forms   →  200 {deleted: n, remaining: m}
```

Five things about it are decisions rather than details.

- **It is the most destructive address in this service**, because it deletes answers people gave —
  drafts and confirmed records alike. Nothing here authorises anybody, so the protection it gets is
  the one this service can actually offer: an address of its own that a gateway can refuse to
  everybody, named as such in `docs/deploying-behind-a-gateway.md`. An in-band confirmation token
  would be a second, weaker authorisation invented in the wrong layer.
- **It is `DeleteForm` in a loop, never a bulk statement.** A form does not leave by having its row
  removed: its files go after the row, its revisions and announcements leave by foreign key, a
  `form.deleted` is queued, and a line is written to the operations log. A `DELETE … WHERE` would
  skip every one of those, which is exactly the class of mistake
  [12](12-webhooks.md)'s two invariants exist to catch.
- **It works in bounded batches and says what is left.** A template with fifty thousand forms cannot
  be emptied inside one request, and an endpoint that tries is one that times out halfway with no
  way to tell what it did. So a call deletes a batch and answers `{deleted, remaining}`; the caller
  repeats until `remaining` is `0`. That makes it resumable and idempotent, and it is why this is
  the one deletion that answers with a body: the count is the thing the caller could not know, the
  same argument that has an upload answering with its description.
- **The reason stays `requested`.** `form.deleted` carries `requested` or `expired`, and the second
  exists because nobody asks `app:forms:purge-expired` for anything. Somebody asked for this one, so
  a receiver learns what is true: these forms were deleted on request.
- **Emptying and deleting race, and the answer is operational.** A form created from the template
  between the last batch and the delete puts the template back in use. Nothing here stops that —
  the client that creates forms is stopped in front, by the same gateway. If that turns out to bite,
  the fix is a `retired` column, which is listed as deliberately absent below and stays there until
  somebody asks.

`GET …/{template}` serves how many forms point at it, so an administrator sees what a delete would
refuse before trying; the catalogue listing does not, because that would be one count per row.

## Two things that must be got right, or this melts in production

- **The row lock must not spread.** `getForUpdate()` takes a pessimistic write lock on the form's
  row. A `JOIN FETCH` of the documents under that lock would, on Postgres, lock the joined rows too
  — so every save on every form sharing a template version would serialize behind one another. The
  documents are therefore loaded by a **second, unlocked query**: they are immutable, so there is
  nothing for the lock to protect. This is the kind of mistake that passes every test and appears
  the first busy afternoon, so it gets a test that asserts the lock names one table.
- **The migration is a data migration.** Every existing form has to become a form pointing at two
  new rows, and this is the one place raw SQL earns its exception to "migrations built through the
  schema API": the backfill reuses **the form's own id as the id of its backfilled document**
  (they are 1:1 by construction), so the whole thing is `INSERT … SELECT` plus one `UPDATE` per
  table, portable, with no per-platform UUID generation and no PHP loop over a large table. The
  ids being shared is a backfill artifact and nothing reads meaning into it. Then, and only then,
  the two text columns are dropped.

## The cache key stops being a workaround

`cache.data_schema` keys on `(form UUID, mode)` — so a thousand forms sharing a definition compile a
thousand identical schemas. Under a reference the key is `(definition_id, mode)`: a UUID that is
immutable and never reused, naming exactly the document the schema was derived from. Forms sharing a
version share one entry, a one-off form gets its own exactly as it does today, and entries of deleted
documents are simply unreachable. The staleness caveat is unchanged — no key says which rules derived
the entry, so changing `DataSchemaDeriver` still means `make cache-clear`.

This is the piece that was a workaround in the copying design and is a consequence of this one.

## The addresses

The catalogue lives **under management**, at `/api/manage/form-templates/`: the system that owns the
forms owns what they are made of, so this is one audience and not two, and `RouteGroup` keeps its
four cases. Two things follow and both are checked rather than hoped for:

- **No template route may name its parameters `{id}`.** They are `{template}` and `{seq}`, because
  `/api/manage/forms/{id}` is where a decision point outside reads a **form** id with one pattern.
  `RouteGroupsTest` already fails on any route carrying `{id}` outside its group's `idPrefix()`, so
  this enforces itself.
- **A gateway separating the two permissions writes the more specific rule first.** Whoever may
  create a form need not be whoever may change what all the forms ask, and
  `/api/manage/form-templates/` is a prefix inside the management one — so a rule on `^/api/manage/`
  written *before* it silently opens the catalogue to every form-creating caller. That trap is the
  one line here a deployment must read: `docs/deploying-behind-a-gateway.md`, with a runnable rule
  in `examples/gateway/`.

| Address | Does |
|---|---|
| `GET /api/manage/form-templates` | the catalogue: id, name, the pair in use, when it last moved |
| `POST /api/manage/form-templates` | create: publishes definition 1 (and presentation 1) and activates them → `201 {id, definition: 1, presentation: 1}` + `Location` |
| `GET …/{template}` | one template's metadata, the pair in use, and how many forms point at it |
| `PUT …/{template}/name` | rename — the label, never a document |
| `DELETE …/{template}` | delete it and its versions — refused (`409`) while any form points at one |
| `DELETE …/{template}/forms` | empty it: delete a batch of its forms the ordinary way → `200 {deleted, remaining}` |
| `GET …/{template}/definitions` | what was published, newest first |
| `GET …/{template}/definitions/{seq}` | the definition, byte for byte |
| `POST …/{template}/definitions` | publish a definition version → `201 {definition: n}`. Does **not** activate |
| `GET …/{template}/presentations`, `GET …/{template}/presentations/{seq}` | the same, for the other stream |
| `POST …/{template}/presentations` | publish, judged against the definition in use → `201 {presentation: n}` |
| `PUT …/{template}/current` | `{definition, presentation?}` — move the pair, re-judged → `204` |

One action per endpoint, one `__invoke` per use case, and a write never answers with the thing it
wrote: publishing returns the number, not the document.

**A published version is judged exactly as a creation is**, by the same `FormDefinitionProcessor`,
`PresentationProcessor` and `PresentationRules`, with findings rooted at `/definition` and
`/presentation` the way `CreateFormAction` roots them. The existing asymmetry carries over: an
unknown **engine** is refused at publication, a **stored** one still reads back, because reading is
not the moment to judge again.

## What a creation request looks like

```json
{ "template": { "id": "…" }, "expireDate": "…", "identity": "recorded" }
{ "definition": { … }, "presentation": { … }, "expireDate": "…" }
```

**Exactly one of `template` or `definition`** (`form.source.missing`, `form.source.ambiguous`), and
`presentation` may not accompany `template` (`form.source.presentation-with-template`) — that is a
per-form presentation override wearing a disguise, and a presentation belongs to a stream that can
be pointed at. `definition` and `presentation` may be named inside `template` to pin versions;
omitted, the pair in use is resolved **inside the transaction**, so two forms created either side of
an activation each hold what was current when they were made. A pinned pair never activated together
needs no new gate: the `Form` constructor judges any presentation against the definition it came
with, and the finding is rooted at `/template`.

This costs one rule: **`definition` stops being non-nullable**, so "every DTO member is non-nullable,
so an instance means a complete request" becomes "…after validation", the choice being a class-level
constraint. A second creation endpoint would duplicate `expireDate`, `data`, `identity` and
`webhooks` and give the gateway two addresses meaning one thing.

Nothing else moves onto the template: not `expireDate`, not `identity`, not `webhooks`. A template
says what is asked and how it is shown; when a form expires, whether it records anybody and who
hears about it are the creator's own configuration.

## What else has to move

- **The envelope** gains `template: {id, definition, presentation}` — ids and, when there is a
  template, its name and the two seqs. The definition is still served byte for byte; it is simply
  read from one row further away.
- **`Operations`**: `template.created`, `template.definition-published`,
  `template.presentation-published`, `template.current-moved`, `template.deleted`, at `info` with
  the publisher as asserted, and a refused publication at `warning`. Emptying a template writes one
  line of its own (`template.forms-purged`, with the batch's count) beside the ordinary per-form
  `deleted` lines — the batch is what somebody asked for, the deletions are what happened. No
  identity mode applies — a template has no anonymity to keep — and the **name is not logged**, only
  the id.
- **Docs**: `configuring-forms.md` (what a template is, the two streams, the new refusal codes),
  `architecture.md` (the three tables, the ports, the use cases, the lock note),
  `deploying-behind-a-gateway.md` + `examples/gateway/`, then `make docs`.
- **Tests**: a battery per use case against fakes; an integration battery per endpoint; the
  publication refusal table with pointer and code; an activation that re-judges the pair; the
  one-off collection on every deletion path; that emptying a template takes the same road a single
  delete takes — files, announcements and log lines included; the lock scope; and
  `testEveryPieceOfAFormSurvivesTheRoundTrip` reading its documents through the reference.
  `NoWritePathIsSilentTest` stays as it is — a template write announces nothing, because webhooks
  are per form.

## Build order

1. The two document tables and the repository that writes them append-only, with `forms` still
   carrying its columns. Nothing reads the new rows yet.
2. The migration: backfill, point `forms` at the rows, drop the two columns. Hydration through the
   reference, the unlocked second query, the round-trip test.
3. One-off collection on all three deletion paths.
4. `form_templates`, publishing, activating, reading — use cases against fakes.
5. The API under `/api/manage/form-templates/`, with the gateway document and example.
6. Emptying a template: the batched loop over `DeleteForm`, the refusal on a template in use, and
   the count on the metadata read.
7. Creation from a template: the DTO choice, resolution inside the transaction, the envelope.
8. The cache key.
9. Documentation, and `make ci` green at the end of each block.

Everything lives under `App\…\Forms\` (`Domain/Forms/Template/`, use cases beside the existing ones,
rows beside `FormRecord`), so `deptrac.yaml` needs no new layer: a template is about the two
documents a form is made of and shares every rule that judges them.

## Deliberately absent

A per-form presentation override; creating a form from a template's definition while asking for no
presentation; deduplicating two identical one-off documents (that is content hashing and a reference
count, for a few kilobytes); placeholders or variables inside a document (that is code in a document,
refused in [10](10-what-a-vendor-offers.md) and refused again here); a draft state on a version (an
administrator's tool holds the working copy); template inheritance; defaults for `expireDate`,
`identity` or `webhooks`; promotion between environments; a soft "retired" state; notifying anybody
when a template changes; and any endpoint listing the forms created from a template.

**Promoting a one-off into a template** is not in this plan and is worth naming, because the reference
makes it nearly free: give the row a `template_id` and a `seq`, and the form that already points at
it keeps pointing at it. It is one write and no data movement — the first thing to revisit if
somebody asks "make this form's definition reusable".

## What the building corrected

- **Two tables wanted to point at each other.** A template names the pair it uses and a document
  names the template numbering it, which is a cycle: neither row can be inserted first. One of
  the two keys had to give and it is the document's — `template_id` is a plain indexed column.
  "A template points at a version that exists" is worth having the database enforce; "a version
  belongs to a template that exists" buys a cascade this plan does not want, since deleting a
  template is refused while any form is made of one of its versions.
- **The collector had to learn what the plan said it would, and did not.** Collecting a form's
  documents asked "is anybody still made of this?" and meant *any form*; since templates arrived
  a template points at the pair it uses too, under a key that refuses to let it go. Deleting the
  last form made of a published version therefore tried to take a row the catalogue still named,
  and the database said no **in the middle of a deletion that had already happened**. One clause
  fixes it — a form's documents are collected only when they are in no template — and the unit
  suite could never have found it, because fakes have no foreign keys. The endpoint test did, on
  its first run.
- **Emptying a template has to delete expired forms too.** The API treats an expired form as gone
  everywhere, so `DeleteForm` refuses one — but a row is a row to a foreign key, and leaving it
  would make the template undeletable until the reaper next ran. It leaves the reaper's way, with
  `form.deleted` carrying `expired`, because its disappearance was promised before anybody asked
  for this.
- **Pinning a version states the pair whole.** The plan allowed pinning and did not say what
  naming half of one meant. It means the other half is *none*, exactly as at the pointer: the
  alternative would have the server judge a pair the request never named, and the answer would
  change under the client the next time anybody moved the pointer.
- **`Form` learnt which stored documents it is made of, and the default is what made that
  cheap.** Nobody saying which means its own — which is what every form's definition was before
  there was a catalogue — so not one of the seventy-eight places that construct a `Form` had to
  change.
- **`ValuesValidator::assertFit()` takes two identities now**, and that is the distinction rather
  than clutter: the form is what the *files* a document names belong to, the definition is what
  the schema is derived from. They were one argument only because there used to be one id.
- **Two names stopped being true.** `RowsLeaveWithTheirForm` described cascades when that was all
  it did; it now also states what a form and a template may not lose, and is
  `ConstraintsTheMappingCannotDeclare`. And `template.pair.mismatch` was never invented: a pair
  that does not fit is a presentation that does not fit a definition, which this service already
  has a word and a report shape for.
- **A fake lied in the one direction a fake must not.** `InMemoryFormTemplateCatalogue` counted
  from a list a test had written, so it went on saying a template had two hundred forms after all
  two hundred were deleted — and emptying reads that count *after* deleting, to say what is left.
- **Three things the tooling caught that review had not.**
  `testEveryDocumentedResponseIsExercised` refuses a documented response nobody triggers, and
  twenty-nine of them had no traffic. Symfony's `NotBlank` does not consider `"   "` blank without
  a normalizer, so a name of nothing but space reached the model and became a 500. And mutation
  testing found the same gap twice — a new exception's **message** is what tests forget, since
  `expectException` says nothing about it — plus two assertions that checked *there is something*
  where they should have checked *it is that one*.
- **The nudge moved out of the transaction.** Creating a form opened none before this, so nothing
  had ever asked a worker to look at rows that were not committed yet.
