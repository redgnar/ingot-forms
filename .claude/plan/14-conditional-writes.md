# 14 — two people, one form

The seventh and last entry of [10](10-what-a-vendor-offers.md)'s list, and the smallest of the
real ones: *"protection against a silent overwrite — a conditional draft save: I hold revision
*n*, store this only if it is still the newest, closing the one case where two people lose
work."* Built as written, on the history that was already there.

## What was wrong

A form is **one** document — that is the whole domain model, and everything good about it follows
from there. But one document with two people in it is two people writing over each other, and
until now the second save simply won: it was accepted, it became the newest revision, and the
first person's answers left the current values without anybody being told. Nothing was lost
forever — every save is kept, so the earlier document could be read back from
`GET …/history/{seq}` and put back through `PUT …/data` — but *nobody knew to*. A silent
overwrite is not a data-loss bug, it is a **nobody-was-told** bug, and the fix is a way of
being told.

## The shape

HTTP has this exact mechanism, so nothing here is ours:

```
GET  /api/forms/{id}/data        →  200, ETag: "7"
PUT  /api/forms/{id}/data           If-Match: "7"   →  204   (still at 7)
                                                    →  412   (form-moved-on)
POST /api/forms/{id}/confirm        If-Match: "7"   →  204 | 412
```

Five decisions inside that, each of which could have gone another way:

**The validator is the number of the save, not a hash of the document.** What `If-Match` is
asked here is "has anybody saved since I read this", and two saves can store the same answers —
a content hash would call that "unchanged" and let the overwrite through. So the tag is the
form's revision, which is the `seq` of its newest save.

**A count of accepted saves lives on the form's own row.** `forms.revision`, applied from the
`DraftSaved` event like every other column. It was tempting to keep asking `MAX(seq)` over the
history — the query already existed, for numbering revisions — but the history is not the truth
about how many saves there have been: `FORMS_HISTORY_LIMIT` evicts the oldest, and a count over
what is *kept* would start renumbering saves the moment it did. The column also removed a query
from every save, on a row the save already holds locked, and now numbers the revision as well.

**The check is the aggregate's, and it is asked first.** `Form::saveDraft()` and
`Form::confirm()` take an optional `ExpectedRevision` and throw `FormMovedOn`; the use cases only
pass it through. Two reasons, and neither is taste: a precondition asked outside the transaction
has a gap between the question and the write, which protects nothing; and the order matters —
whether the values fit is a different question whose answer would not help a caller that is
looking at the wrong form, so the stale caller hears about being stale.

**It is optional, and that is not a compromise.** A client that sends no header saves exactly as
it did before any of this existed. A mandatory precondition would break every existing caller to
protect a case they may not have — two people in one form is a possibility, not a rule — and both
pages, which write through the same endpoint, would have needed a conversation about what to tell
somebody whose save was refused. That conversation is a page's and is not in this stage.

**`"0"` is a legal expectation.** "Store this only if nobody has filled it in yet" is the same
question asked at the beginning, and it is the one moment a client *cannot* ask by holding a
validator: an empty form has no document to read an `ETag` off (`GET …/data` is `404`). It is
also the case that would otherwise be silent for the same reason as all the others — two people
opening a fresh form.

## Confirming takes it too

[10](10-what-a-vendor-offers.md) named only the draft save. The confirmation was added in the
same breath because it is the same mechanism and the more dangerous half: a save that lands
between somebody reading a form and confirming it means they lock a document they never saw, and
unlike an overwritten draft **that one cannot be put back**. A form is confirmed forever.

## What a refusal is not

`412` and not `409`, deliberately: the form is in a state the transition could perfectly well
start from, and the only thing wrong is which form the caller was looking at. Nothing was
stored, the form is where it was, and the client's next move is the ordinary one — read the
values again with their new tag, show the person what changed, send theirs on top.

A header that is neither a quoted revision, a list of them, nor `*` is `400
precondition-not-readable` — never a save that quietly goes through unconditionally. A client
that meant to protect somebody's work and spelled the header wrong has to hear about it, which
is the whole reason `W/"7"` is refused as well: a weak validator means "close enough for
display", which is not a comparison this endpoint makes.

## What it cost

| | |
|---|---|
| Domain | `ExpectedRevision` (a value with an invariant), `FormMovedOn`, `Form::revision()`, one line at the top of two transitions |
| Application | one optional argument, passed through, on `SaveFormData` and `ConfirmForm` |
| Infrastructure | one integer column, backfilled from `MAX(seq)`; one query *removed* |
| UserInterface | `RevisionIntake` (a value resolver on `If-Match`), an `ETag`, a `revision` member, two `412`s in the contract |

No new endpoint, no new port, no new table, and nothing at all for a client that does not ask.

## Not built, on purpose

- **The pages do not send `If-Match`.** They are clients of this like anything else, so the
  mechanism is available to them the day somebody decides what a person should be *shown* when
  their save is refused — reload and lose what they typed? show both? mark what changed? That
  is a page design, not a rule, and inventing one here would have been inventing the wrong one.
- **No lock, no lease, no "somebody else is editing this".** That needs presence, expiry and a
  way to steal a lock, and it answers a different question. This one is answered by the
  document itself.
- **Nothing conditional on delete.** A delete is idempotent and removes the whole form; there is
  no version of it to be wrong about.
