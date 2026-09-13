# 28 — what one session changed, and what building it corrected

Twelve commits, 129 files, +10802/−299. Ten of them are **one plan in nine blocks**
([27](27-templates.md)); the other two landed a block the session before this one had built and
never committed, which is why the working tree opened dirty.

One plan and no block without one, so this file is not the index [13](13-what-one-session-changed.md)
and [20](20-what-the-second-session-changed.md) are. What it is for is the half that belongs to no
block: the decision the whole thing turned on, the three corrections the owner made before a line
was written, and what only the running system found.

| Block | Commit |
|---|---|
| A save that could not be delivered (built in the session before) | `684f071`, `eed19a9` |
| The plan itself | `c80a1a7` |
| Documents as rows of their own | `43577df` |
| A form names them rather than holding them | `2f793d4` |
| A document leaves when nothing is made of it | `9c4e424` |
| The catalogue | `073d7a4` |
| Addresses for the catalogue | `1911ff9` |
| Refusing to delete one in use, and emptying on purpose | `d875a93` |
| A form made from a template | `3a7a42a` |
| A schema keyed by the definition | `da4b321` |
| The documents, and the plan closed | `d51119d` |

## The decision it turned on, and it was made twice

The first draft of the plan had a form **copy** the documents a template holds: the catalogue
would have been an authoring convenience at the boundary, and nothing below `CreateForm` would
have learnt that templates exist. It was the cautious answer and it had a good argument — "which
contract were these answers judged against" keeps its exact meaning when the bytes are on the
form's own row.

The owner chose the **reference**, for a reason the draft had underweighted: one structure for
reusable and one-off documents alike, rather than two storage stories that would drift. That
turned out to be right in a way the argument had not reached for. Three things fell out of it that
copying could not have bought:

- **"Do these two forms answer the same model?" became one comparison.** Under copying it would
  have been a diff, or a hash, or a pair of numbers that could disagree with the bytes.
- **The schema cache stopped being a workaround.** The copying draft proposed keying it on a hash
  of the definition text to stop ten thousand forms compiling ten thousand identical documents.
  Under the reference it keys on the id — which is what a schema is a function of — and the
  workaround is simply gone ([block 8](27-templates.md)).
- **`template_id IS NULL` gave one-off a shape.** No flag, no second column that can come to
  disagree with the first, and no lifecycle to invent: a document in no template belongs to the
  one form it was made for.

What it cost is one sentence, and it is worth keeping in view: immutability stopped being a
property of the layout — the bytes sat on a row nothing wrote — and became one that has to be
stated and enforced. An out-of-band `UPDATE` can now change what an existing form asks.

## Three corrections the owner made before anything was built

The plan was written, read and corrected three times, and every one of the three changed the
design rather than its wording. That is the argument for writing a plan at all.

- **The catalogue belongs under `manage`, and is called `form-templates`.** The draft invented a
  fifth route group for it, reasoning that "who may rewrite what all the forms ask" is a different
  audience. It is a different *permission*, which is a carve-out a gateway writes — not a fifth
  prefix every deployment then has to know about.
- **Definitions and presentations version apart.** The draft numbered them together, on the
  grounds that a presentation is only ever valid against a definition and the pair is what gets
  judged. True, and beside the point: a presentation changes often and cheaply while a definition
  is where compatibility breaks, so one numbering would make every label fix look like a new
  model. The pairing lives in the pointer instead, and is judged whenever it moves.
- **A template in use is not deleted, and its versions are not quietly detached.** The draft had
  deleting a template hand its versions to the forms still made of them, as one-offs. It reads as
  tidy and it is a silent lifecycle change on documents live forms depend on. Refusal plus a
  separate, deliberate address for emptying is the honest shape — and it made
  `DELETE …/{template}/forms` the most destructive address this service has, which is now written
  down where a deployer will read it.

## What only the running system found

Nothing in this list would have survived review, and none of it was found by reading.

- **The collector had to learn a second answer, and the block that predicted it still got it
  wrong.** A comment written in block four said, in as many words, that "is anybody still made of
  this?" would need to learn about templates. Block six did not teach it, so deleting the last
  form made of a published version tried to take a row the catalogue still named — and the
  database refused **inside a deletion that had already happened**. The unit suite could not have
  caught it: fakes have no foreign keys. The endpoint test did, on its first run.
- **A fake lied about a count**, and in the one direction a fake must never be wrong in. It
  answered from a list a test had written, so it kept saying a template had two hundred forms
  after all two hundred were deleted — and emptying reads that count *after* deleting, to say what
  is left.
- **Two tables wanted to point at each other.** "The pointer is a foreign key" and "a version
  belongs to a template" are two sentences that read independently and are a cycle. It appeared
  while writing the migration, not while writing the plan.
- **`testEveryDocumentedResponseIsExercised` refused twenty-nine documented responses with no
  traffic behind them.** An invariant this repository already had and the plan had never met.
- **`NotBlank` does not consider `"   "` blank** without a normalizer, so a name of nothing but
  space walked past the DTO and became a 500 — the one shape a refusal must never take.
- **Mutation testing found the same gap twice**: a new exception's *message* is what tests forget,
  because `expectException` says nothing about it. And twice more it found an assertion checking
  *there is something* where it should have checked *it is that one*.

## The habits that paid, and the one that cost

Two of the three from [20](20-what-the-second-session-changed.md) held again: run the narrowest
thing that covers the change and keep `make ci` for the end, and measure before writing a
sentence. A third earned its place here.

**Read the library rather than reasoning about it.** Splitting the data migration into three was
not caution: `DbalExecutor` runs everything a migration adds through `addSql()` *first* and the
schema diff afterwards, so a backfill written beside the column it fills would have run before the
column existed. Ten minutes in `vendor/` settled a question that would otherwise have been settled
by a failing deployment.

And the one that cost: **a claim made in passing is still a claim.** The gateway note first said
the carve-out is an ordering problem. It is not, universally — nginx picks the longest matching
prefix — and the correction had to be made in the document, in the example, and to the owner. What
is true everywhere is narrower and duller: the catalogue is a prefix *inside* the management one,
so a rule for `/api/manage/` already covers it.

## What is deliberately still open

- **The gateway.** Unchanged from [09](09-access.md) and now carrying one more address that a
  deployment must think about. Still not code here.
- **Promotion between environments, a draft state on a version, template inheritance, defaults for
  `expireDate`, `identity` or `webhooks` on a template.** All refused in the plan, none of them
  asked for since.
- **Turning a one-off into a template.** Named in the plan as nearly free under the reference —
  give the row a `template_id` and a `seq` and the form that points at it keeps pointing at it —
  and still unbuilt, because nobody has asked.
