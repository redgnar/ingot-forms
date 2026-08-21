# 06 — files: a store behind Flysystem, an upload endpoint, and a `file` item

Until now every answer a form holds is JSON: one document, stored as the exact text that passed
validation, in one column. A file is the first answer that is **bytes**, and bytes do not fit in
that document. `CLAUDE.md` has said all along how this would arrive when it arrived — *"an upload
endpoint returning an id, with the item holding a reference: values stay JSON, the contract stays
the contract, and the weight lands where it belongs"* — and that is what this stage builds.

What the owner asked for, across three passes: a service for uploading, a real store for the bytes,
files that stay downloadable **for as long as the form is available**, downloads that always go
**through the form**, the configuration of a `file` item; then, said plainly — what a user uploads is
**temporary** and has to *become* a file attached to the form for good, reachable through the API;
and finally: **do not marry S3** — put a filesystem abstraction in between.

The last one is the reason this plan looks different from its first draft, and not only in the
adapter. See the closing section for what it changed and why.

## Decisions

1. **Values hold a descriptor, and the server authors it.** The value of a `file` item is a small
   object — `id`, `name`, `size`, `type` — and every one of those four is what the *server* measured
   at upload and returned. The client echoes it verbatim. This is the load-bearing decision: it is
   what makes `maxSize` and `accept` statable in the derived schema (`size.maximum`, `type.enum`), so
   a file item's rules are published and enforced where this codebase enforces rules, instead of
   "past the contract".
   *Rejected:* the value is a bare id string — then the schema says nothing but "a uuid", every rule
   about a file is invisible to clients, and every page that wants to write "invoice.pdf (2.1 MB)"
   has to ask a second endpoint.
2. **The bytes live behind `league/flysystem`, configured per deployment.** A local directory in
   development, test and CI; S3 (or anything Flysystem speaks) in production, by configuration
   rather than by code. `league/flysystem-bundle` wires one named storage; `FlysystemFileStore` is
   the only class that knows it exists.
3. **`FileStore` stays the port, and Flysystem lives strictly behind it.** The port speaks the
   application's language — a form, a file id, a descriptor — and never a path. That is what keeps
   `SaveFormData` and the validator from knowing where bytes live, and it is also the answer to
   "isn't Flysystem an abstraction over an abstraction": it is not a *second* abstraction, it is what
   spares us writing a second and third **adapter** behind the first.
4. **One location per file, and "temporary" is a fact about the values, not about the key.** Bytes go
   to `{formId}/{fileId}`, facts to `{formId}/{fileId}.json`, once, at upload, and nothing ever moves
   them. A file is *temporary* while no stored document names it and *attached* the moment one does —
   because the values document is the only thing that can be authoritative about that. §"The
   lifecycle" is the whole of it.
5. **Our own upload endpoint, not tus.** One `POST` with a multipart body, streamed into the store.
   The comparison and the trigger that would make us revisit it are in §"Upload: our controller or
   tus".
6. **A file belongs to a form from the moment it is uploaded.** `POST /api/forms/{id}/files`, never
   `POST /api/files`. Ownership is not a column, it is the location: a file uploaded to form A cannot
   be named by form B, because nothing ever looks outside form B's own directory.
7. **Only what the values reference can be downloaded.** `GET /api/forms/{id}/files/{fileId}` answers
   by walking the form's definition for its `file` items, reading those positions out of the values,
   and looking for that id. "Downloaded through the form" is then not a convention in the URL — the
   form's own document *is* the index of what may be fetched, and an uploaded file nobody saved is
   unreachable by construction. No presigned URLs, no public directory, ever: a browser never speaks
   to the store.
8. **A file lives exactly as long as its form, and a temporary file not even that long.** Not
   everything uploaded gets saved, so collecting the rest happens in three layers, cheapest and
   soonest first: the page deletes what it removed or replaced before saving; a save deletes what it
   superseded, right after it commits; and `app:files:purge-temporary` collects, per form, whatever
   nobody ever saved and is older than `FILES_TEMPORARY_DAYS`. `app:forms:purge-expired` remains the
   end of everything. No reference counting anywhere — every layer asks the values.
9. **A delete never happens inside a transaction that also writes the row**, because a store delete
   does not roll back. After the commit it may, and that is where the save's own collection runs: the
   decision is made on the locked row, the deleting happens once the row is safe, and a delete that
   fails is picked up by the command rather than failing somebody's save. A delete may, however, be
   **serialized by the row lock** — see decision 10; there the lock is ordering, not atomicity, and the
   transaction writes nothing at all.
10. **Deleting a temporary file takes the form's row lock**, the same one a save takes. It is what turns
    the reference gate from a check into a guarantee: without it, a file can vanish between the gate
    accepting it and the commit naming it, and the row ends up naming bytes that are gone. With it
    there is only one order of events — either the save commits first and the delete then finds the
    file referenced and refuses, or the delete goes first and the save is refused honestly with
    `form.file.unknown`.
11. **Many files is `collection` + `file`, not a `multiple` option.** Stage 05 already built the item
    that asks something repeatedly, with `min`/`max` counting entries and a pointer per entry. A
    second way to say "several" would be a second set of counting rules that can drift.
12. **`accept` and `maxSize` are both required on a file item.** A file item without them is a
    contract that says "any bytes, any size", which no deployment can honour and no client can check.
    Refusing that at definition time costs nothing.
13. **The upload knows nothing about which item it is for.** It takes bytes for a form, full stop. An
    item's `accept`/`maxSize` are published in the schema and enforced at the save gate, and the page
    compares the descriptor it just received against the item's own rules, so nobody learns at
    confirmation time. Addressing the item in the upload URL would need a second addressing scheme (an
    item inside a collection has a *definition* path, `lines/scan`, not a value path) to enforce a
    rule that is already enforced.
14. **The upload is the one endpoint whose body is not JSON; the download the one that does not answer
    with a document.** Stated as deliberate exceptions in `CLAUDE.md` rather than left to be
    discovered. Base64 in a JSON envelope was the alternative: +33% on the wire and the whole payload
    through the JSON parser, for nothing.

### Why Flysystem, and where it stops

Five operations is all this needs — put, describe, open, count, delete — and Flysystem covers every
one of them portably: `writeStream`, `fileSize`/`lastModified`, `readStream`, `listContents`,
`delete`/`deleteDirectory`. What it does *not* cover portably is per-object user metadata (S3 has it,
a directory does not), and that is exactly why the facts travel in a **sidecar** `{fileId}.json`
rather than in object metadata: a sidecar is just another file, so it works identically on every
adapter, and `describe()` is one read that answers all four members.

Where the abstraction stops is worth writing down too. A local directory is **one node**: a
deployment running more than one instance needs a shared volume or an S3-style adapter, and that is a
configuration decision with an operational consequence, not a code change. And the store no longer
gives us a lifecycle policy for free, which is why cleanup is a command of ours (decision 8) — a
portable answer instead of a bucket feature, and an exact one, because it asks the values rather than
a timer.

The S3 adapter is deliberately **not installed yet**: `league/flysystem-async-aws-s3` plus four lines
of configuration is the whole change when a deployment needs it, and until then it is a dependency
nobody pays for. Nothing in our code will have to be revisited that day, because the sidecar means
our adapter never relies on a store-specific feature.

### Why not the database

It is the option to name explicitly, because it looks cheapest. `bytea` (or a `BLOB`) would put
megabytes into the row store this project keeps deliberately portable and deliberately small: every
backup, every replica and every WAL segment carries the bytes; a read is all-or-nothing (no ranged
reads, no streaming without large-object APIs, which are **not** portable — `pg_largeobject` has no
equivalent on the other platforms `FormRecord` is written to run on); and Doctrine hands you the whole
value as a PHP string, so a 10 MB download is 10 MB of request memory. It also breaks the one rule
persistence has here — *portable Doctrine types only* — on its first day. Behind the port it remains
possible for a deployment that may run nothing but a database, and Flysystem makes even that a
one-adapter job.

## The store

```
Application/Forms/Port/FileStore          every operation scoped to a form; no path ever crosses it
Application/Forms/File/IncomingFile       a temporary path + the name the client sent
Application/Forms/File/FileStream         an open handle + the descriptor a response needs
Infrastructure/Files/FlysystemFileStore   keys, the sidecar, sniffing, listing, deletes
Domain/Forms/ValueObject/FileId           a uuid v7, like FormId
Domain/Forms/ValueObject/FileDescriptor   id + name + size + type, with the invariants a member cannot carry
Domain/Forms/File/FileReferences          walks a definition's file positions and reads them out of the values
```

An **Application** port, not a Domain one, for the same reason `Transactions` is one: the model has
no rule about bytes. The value objects are the Domain's, because they appear inside the values
document and in the walk over it.

```php
interface FileStore
{
    /** Writes an upload and returns what the server measured while doing it. */
    public function put(FormId $form, FileId $file, IncomingFile $upload): FileDescriptor;

    /**
     * What the store recorded about this form's file, or null if it holds no such file.
     *
     * Null unless **both** halves are there and agree: the sidecar's facts and bytes whose size
     * matches them. A sidecar whose bytes are gone would otherwise let the gate accept a reference
     * to nothing, which is the one thing the gate exists to prevent.
     */
    public function describe(FormId $form, FileId $file): ?FileDescriptor;

    /** @throws FileMissing when the bytes are not there */
    public function open(FormId $form, FileId $file): FileStream;

    /** How many files this form holds — the upload budget. */
    public function countFor(FormId $form): int;

    /** Everything this form holds. Idempotent. */
    public function forget(FormId $form): void;

    // The three the housekeeping command needs, and the only reason they exist:
    /** @return iterable<FormId> every form the store holds files for */
    public function formsWithFiles(): iterable;

    /**
     * This form's files last written before the given moment — counted by *either* half, so a
     * lone blob and a lone sidecar are both candidates for collection.
     *
     * @return list<FileId>
     */
    public function writtenBefore(FormId $form, \DateTimeImmutable $moment): array;

    public function delete(FormId $form, FileId $file): void;
}
```

**Layout and writes.** `{formId}/{fileId}` for the bytes, `{formId}/{fileId}.json` for the facts, and
the write order is bytes first, sidecar second. A crash between the two leaves bytes that
`describe()` reports as *nothing* — so the gate refuses the reference, the client uploads again, and
the housekeeping command collects the remains. The reverse order would advertise a file whose bytes
are not there.

**Sniffing and sanitizing happen in the adapter**, because the adapter is what records them: one place
authors the four facts and `describe()` reads back exactly what was written. The type comes from the
bytes (`symfony/mime`, `ext-fileinfo`), the size from the stream, and the name is `basename`d,
stripped of control characters, capped at 255 bytes and replaced by `file` if nothing survives — so
the schema's `pattern` on `name` is always satisfiable by the descriptor we hand out.

## How a file is found, and in which order things are written

There is **no column about files anywhere in `forms`**, and there is no `files` table: the values
document is the index of what a form holds. That is not an omission, it is the same rule the rest of
this service keeps — the values are what passed validation and what is served byte for byte, so a
second record of the same fact could only ever be a copy that drifts.

```
GET /api/forms/{formId}/files/{fileId}
   -> forms->get(formId)              one row read; expiry answers 410 here, as everywhere
   -> Values::fromJson(row.data)      the decode every read already does
   -> FileReferences::in(form)        walks the definition's file positions, reads them out of the values
   -> a descriptor with that id?      no -> 404. This is the whole of the authorization
   -> store->open(formId, fileId)     {formId}/{fileId}, facts from {fileId}.json
```

So there are two indexes and both are natural: the **values** index a form's files logically (which
item holds which id, under which pointer), and the **store's per-form directory** indexes them
physically (what is there for this form, whatever the row says). Every question this service actually
asks is answered by one of them — "give me this file of this form" costs one row read and one stream
open, cheaper than a join; "what does this form hold" is the walk the page already does to draw its
chips; "which form does this file belong to" never arises, because no route names a file without its
form. What they cannot answer is aggregate housekeeping — storage per form, per tenant, everything
uploaded last week — and that is the honest cost, in a service that does not even have a form list
endpoint.

If such a question ever arrives, the answer is a **derived** index: a table rebuildable from the
values and a store listing, authoritative about nothing, changing neither the contract nor the
descriptor.

**What replaces the foreign key.** Nothing in the database enforces that the values name files that
exist — gate 3 does, at the moment of writing, and after that the invariant holds because every path
that deletes bytes consults the values first. The other direction (bytes nothing names) is a legal
state with a name: *temporary*, indexed by the store listing, collected by the command.

**And the ordering that keeps both true**, in one line each:

- **content before reference** — bytes are written by an earlier request, and gate 3 refuses a
  reference to bytes that are not there. Nothing in the save transaction writes to the store.
- **reference before content, when removing** — `DeleteForm` and the purge remove the row first and
  the bytes second. The reverse order can leave a live form naming files that are gone, which is the
  one state this design does not tolerate; a directory whose row is already gone, on the other hand,
  is exactly what `app:files:purge-temporary` collects.
- **nothing in a transaction but the row** — a rollback can lose nothing, and a store that is briefly
  unreachable cannot fail a save. Deletes run after the commit, and whatever they miss the command
  collects.
- **a temporary file is deleted under the form's row lock** — the check that a referenced file exists
  is only a guarantee if nothing can remove that file while the save it belongs to is in flight. The
  lock is the whole of it: `DELETE …/files/{fileId}` and `app:files:purge-temporary` take
  `getForUpdate` on the form, read the values that are really stored, and delete only then. Neither
  writes a column, so nothing here needs to roll back.

## Upload: our controller or tus

**What tus would buy.** Resumable, chunked uploads (`ankitpokhrel/tus-php` on the server,
`tus-js-client` or Uppy in the browser): a dropped connection resumes at the offset instead of
starting over.

**What it would cost, here specifically.** A second HTTP protocol surface next to the API — `POST`,
`PATCH`, `HEAD`, `OPTIONS` with `Tus-Resumable`, `Upload-Offset`, `Upload-Length` — answering in tus
headers rather than `problem+json`, so "one error format" stops being true of this service. A **second
store** for partial uploads, with its own backend and its own expiry job, which is the multiplication
this plan spends its whole length avoiding. And a front-end dependency vendored into `assets/vendor/`
in a repository whose front-end rule is "no build step, no package manager", where Uppy alone is
larger than every asset committed so far.

**What our controller costs.** One route, `#[MapUploadedFile]`, a stream into the store — and no
resumability: a 9 MB upload that dies at 8 MB starts again.

**Verdict: our own controller.** These forms ask for invoices, scans and photos, and the ceiling is a
per-item `maxSize` an author writes down; at those sizes resumability is a cost with no payer.
Progress bars, which is what people usually want tus for, need nothing:
`XMLHttpRequest.upload.onprogress` reports them on a plain multipart `POST`, and that is what the
Bootstrap kit's `dropzone` will draw.

**When to revisit, and with what.** The trigger is a real case above roughly 50 MB, or uploads over
mobile networks. The answer then is most likely *not* tus but **presigned multipart straight to the
store**: `POST …/files/reserve` hands back a file id and a presigned URL, the browser `PUT`s the
parts, `POST …/files/{fileId}/complete` makes the server measure the result and author the descriptor
exactly as it does today. The bytes never touch PHP, resumability comes from the store's own
multipart API, and — the point — *the reference the values hold does not change*, so it is additive
behind the same port. It also stops being portable, which is the price and the reason it waits for a
real case. tus stays the answer only if the browser must never speak to the store **and** uploads
must be resumable.

### The endpoint

```php
#[Route('/api/forms/{id}/files', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
public function __invoke(
    Uuid $id,
    #[MapUploadedFile(new Assert\File(maxSize: '%files.max_upload%'), name: 'file')]
    UploadedFile $file,
): JsonResponse
```

`multipart/form-data`, one part named `file`. `#[MapUploadedFile]` keeps the rule that envelopes are
validated and never hand-read off the `Request`: an uploaded file is not a payload to parse, and its
size limit is a constraint like any other. `201` with the descriptor and a `Location` header pointing
at the download — the id is the part the client could not know, and the other three are what the
server measured, not a copy of the thing it stored.

Guards, all of them rules that already exist somewhere: unknown form `404`, expired `410`, confirmed
`FormLocked` `409` (a locked form's values can never change again, so bytes for it are dead on
arrival), over the deployment ceiling `422` `form.file.too-large`, over the budget `409`
`form.file.budget`, a body that is not multipart `415`.

Three size failures deserve naming, because they do not arrive the same way:

- **over `upload_max_filesize`** — the part arrives invalid (`UPLOAD_ERR_INI_SIZE`) and the constraint
  turns it into a `422` with a pointer, like any violation;
- **over `post_max_size`** — PHP discards the *entire* body, so there is no part at all and the
  mapping would report "missing file". A small listener on this route compares `Content-Length` with
  the ini limit *before* mapping and answers `413` with a problem document, so the page can say
  something true;
- **not multipart at all** — `415`, the same answer the JSON endpoints give for the mirror-image
  mistake.

`UploadFormFile` reads the form and deliberately does **not** lock it: no column changes, and the
worst a race with a `confirm` can do is leave a temporary file nobody will reference — which is
decision 8's problem, already solved.

## The lifecycle: temporary, attached, and what collects the rest

This is what the owner asked to see in detail, and it is where the Flysystem decision paid for
itself: there is no promotion step, no second prefix, and no store write in the save path at all.

**Temporary** means: the bytes are in the store under this form, and no *stored* document names them.
It is a fact about the values, so it needs no marker of its own — and it flips the instant a draft
save is accepted, without anything moving. A temporary file has no download route (decision 7): the
page that just uploaded it holds the descriptor and can preview from the local `File` object, and
after a reload the page draws from the stored values, where a temporary file by definition does not
appear.

**Attached** means: named by a document that was accepted and stored. Reachable through
`GET /api/forms/{id}/files/{fileId}` for as long as the form is, confirmed or not. Nothing in
`SaveFormData` has to *make* this happen — the reference gate has already established that the file
is there, and the row starting to name it is the whole transition.

Five things can happen to a file, and every one of them is decided by asking the values:

| transition | when | who does it |
|---|---|---|
| temporary → attached | a stored document names it | nothing moves; the save *is* the transition |
| temporary → gone, at once | somebody removes or replaces it before saving | the page, through `DELETE …/files/{fileId}` |
| attached → gone | the save that superseded it commits | `SaveFormData`, after the commit |
| temporary → gone, eventually | nobody ever saved it | `app:files:purge-temporary` |
| anything → gone | the form expired, or was deleted | `app:forms:purge-expired`, `DeleteForm` |

### What a save collects

```php
$superseded = $this->transactions->run(function () use ($id, $values): array {
    $form = $this->forms->getForUpdate($id);
    $before = $this->references->in($form);        // what the stored document named
    $form->saveDraft($values, $this->valuesValidator);
    $after = $this->references->in($form);         // what it names now
    $this->forms->save($form);

    return FileReferences::dropped($before, $after);   // by id, so a reference that only moved stays
});

foreach ($superseded as $file) {
    $this->files->delete($id, $file->id);          // after the commit, best effort
}
```

The comparison is made **on the locked row**, so it is against the document that was really there and
not against something a concurrent request replaced; the deleting happens **after the commit**, so a
rollback can never take bytes with it and a store that is briefly unreachable cannot fail somebody's
save. Whatever the loop misses, the command collects — which is what makes best effort honest here
rather than a shrug.

What it deliberately does *not* collect is a temporary file that no stored document has ever named:
from the server's side that is indistinguishable from a file another tab uploaded a second ago and is
about to save. Superseded files are safe precisely because the server was told about them, so anyone
still holding one is holding a stale document — and a refusal (`form.file.unknown`) is a better answer
for that request than silently reviving what it points at.

### What the page collects

**`DELETE /api/forms/{id}/files/{fileId}`** — the mirror of the upload, for the page's own "remove"
control and for the moment somebody picks a second file into the same item. It deletes a file **only
while nothing stored names it**, and answers `409 form.file.attached` otherwise, so this endpoint can
never take away a file a saved document depends on; `404`/`410` as everywhere else. It reads those
values on the **locked** row (decision 10), so it cannot slip between a save's reference check and its
commit. It is the cheapest
layer and the one that catches most of the churn, because most abandoned uploads are abandoned by
somebody who is still on the page.

### What the command collects

**`app:files:purge-temporary`**, once a day, is what makes "temporary" true rather than aspirational:

```
for each formId the store holds files for:
    form = repository->readForCleanup(formId)          // no expiry guard: this is physical cleanup
    if (form === null) -> forget(formId)               // no row: these bytes cannot belong to anything
    else: transactions->run(fn () =>                   // the row lock, for ordering only (decision 10)
        form  = repository->getForUpdate(formId)       // re-read: the values may have moved on
        named = FileReferences::in(form)               // the definition's file positions, read out of the values
        for each file written before now - FILES_TEMPORARY_DAYS and not in named -> delete it
    )
```

The age threshold is what keeps it from racing a person who is still filling the form in: days, not
minutes, and a form left open longer than that simply asks for the file again — the reference gate says
`form.file.unknown` and the page says so where the control is.

It is also the safety net for everything else. Bytes whose row is already gone are provably garbage, so
a purge whose store delete failed is repaired here rather than leaking forever, and so is a save whose
best-effort delete did not land. That closes the "a purge has to succeed in two places" worry the file
item was postponed over.

### Zombies: every way a file ends up outside `data`

The command is defined negatively — *whatever the values do not name* — which is why one mechanism
covers every species. Worth enumerating them anyway, because a list is how you find out whether the
mechanism has a hole:

| how it is made | collected by |
|---|---|
| uploaded, then the tab was closed; or the save was refused for some other field | the command, after the threshold |
| picked a second file into the same item before saving | the page's `DELETE`, at once; the command if the call never happened |
| superseded by a save that stored a different file | the save, after its commit; the command if that delete failed |
| bytes written, sidecar never written (a crash between the two) | the command — it counts a lone half as a candidate |
| sidecar left behind by a delete that half-failed | the command, same rule |
| the form's row is already gone (a failed store delete in `DeleteForm` or the purge) | the command, `forget(formId)` — no row means these bytes cannot belong to anything |
| the form expired while the file was still temporary | `app:forms:purge-expired`, with the whole directory |

Two rules make that table exhaustive rather than hopeful. **`describe()` requires both halves and
agreeing sizes**, so a half-written or half-deleted file is *invisible* — the gate refuses to name it
and the download cannot serve it, which means a zombie is never anything but garbage. And **the age
threshold applies to every candidate**, including lone halves: a blob written a millisecond ago whose
sidecar is still on its way must not look like a corpse.

One case that looks like a zombie and is not: the same file id named by **two** positions (a descriptor
copied into two items, or into two entries of a list). `FileReferences::dropped()` compares by id, and
the command keeps anything any position names, so dropping one of the two references leaves the file
alone.

**How the command stays cheap.** It lists a form's directory *first* and only reads the row if
something there is older than the threshold — so the common case (a form whose files are all recent,
or all attached and untouched) costs one listing and no database work at all. `--limit` bounds a run,
and a run that stops early is a run that resumes tomorrow; nothing about the rule is stateful.

**And how you find out it stopped working.** The command reports what it collected, per species: files
freed, rowless directories forgotten, lone halves removed. Those numbers are supposed to be near zero,
because layers one and two are supposed to catch almost everything — a number that keeps growing is
the signal that the page's `DELETE` or the post-commit delete is broken, and it is the only warning
this design gets.

## Download

`GET /api/forms/{id}/files/{fileId}` — `ReadFormFile` reads the form (`410` when expired), asks
`FileReferences` whether the stored values name this id (`404` when they do not, indistinguishable
from a file that never existed — deliberately), then streams it:

- `Content-Type` from the recorded type, `Content-Length` from the recorded size;
- `Content-Disposition: attachment; filename*=UTF-8''…` with the recorded name;
- `X-Content-Type-Options: nosniff`, and always an attachment, never inline — bytes a stranger
  uploaded, served from this application's own origin, are an XSS vector the moment a browser renders
  them;
- a `StreamedResponse` over the store's handle, so a 10 MB file is 10 MB through a socket and not
  10 MB of PHP memory.

Bytes that are missing for a file the values name is a broken invariant, not a client mistake: `404`
to the caller and an error in the log, loudly, rather than a shrug.

## The `file` item

```json
{"items": [
  {"type": "text", "name": "customer", "required": true},
  {"type": "file", "name": "invoice", "required": true,
   "accept": ["application/pdf", "image/png"], "maxSize": 5242880},
  {"type": "collection", "name": "attachments", "max": 10, "items": [
    {"type": "text", "name": "caption"},
    {"type": "file", "name": "scan", "accept": ["image/jpeg"], "maxSize": 2097152}
  ]}
]}
```

`FileField extends Field` with `name`, `required`, `accept` (non-empty, unique, each entry a media
type) and `maxSize` (bytes, positive). It earns a type of its own the way every type here has to —
**rules of its own**, not a widget: what the bytes may be and how many of them there may be are things
no other item can say.

- **No wildcards** (`image/*`) in the first cut: the derived schema states `accept` as an `enum`, which
  is exactly what a fixed list is, and a wildcard would have to become a `pattern` — two ways of
  saying one thing. Additive later if anybody asks.
- `maxSize` **is the contract**, so a deployment's own limits (`upload_max_filesize`, `post_max_size`,
  `FILES_MAX_UPLOAD`) must be at least the largest `maxSize` any definition on it uses. An ops note,
  not a definition rule: a rule that read deployment configuration would make a stored definition
  unreadable (`FormUnreadable`) the day somebody lowers a limit, which is the worst possible place to
  learn about it.
- The **trap worth writing down**: `type` is what the server sniffed from the bytes, not what the
  browser claimed. A definition asking for `.docx` must list what fileinfo actually reports. The
  upload response is where an author sees this, immediately.

### What a value looks like, and what the schema says

```json
{"customer": "Ada",
 "invoice": {"id": "01a0f3…", "name": "invoice.pdf", "size": 214003, "type": "application/pdf"}}
```

```json
{"type": "object",
 "properties": {
   "id":   {"type": "string", "format": "uuid"},
   "name": {"type": "string", "minLength": 1, "maxLength": 255, "pattern": "^[^/\\\\\\u0000-\\u001f]+$"},
   "size": {"type": "integer", "minimum": 1, "maximum": 5242880},
   "type": {"enum": ["application/pdf", "image/png"]}},
 "required": ["id", "name", "size", "type"],
 "additionalProperties": false}
```

The four members are `required` in **both** contracts, which looks like it breaks the rule that
`required` waits for confirmation. It does not: that rule is about an *obligation to answer*, and a
person may leave a question for later. Nobody types a descriptor — it arrives whole from one response
— so a half descriptor is a client error, in the same category as `maxLength`. The question a person
can still leave for later is the file itself, and that is the item's own `required`, behaving exactly
like every other item's.

## Three gates now, and the honest cost

1. the derived schema — shape, size, media type. Cached per form and mode, unchanged.
2. the Symfony form — nothing to add for a file item (`RawValueType`, the way a collection is taken as
   it came), because everything a file's value *is* has been said in the schema.
3. **`ReferencedFilesExist`** — for every `file` position in the definition, if the values name a
   descriptor there, the store must hold that id **under this form** and its recorded facts must equal
   the claim. Findings: `form.file.unknown` at `/invoice/id`, `form.file.mismatch` at the member that
   differs (`/invoice/size`). Last in the order because it is the only gate that talks to another
   store: nothing pays for it until the document is otherwise perfect.

Gate 3 is **the one place in this codebase where the server is stricter than its published contract**,
and that needs saying out loud. No schema can state "this id exists" — a question about the world, the
same category as "this form has expired", which the contract has never covered either. Two things keep
it from being a trap: a client that echoes the upload response verbatim can never trip it, and it
lives in its own class rather than inside the form stage, so
`testTheFormNeverRefusesWhatThePublishedSchemaAccepts` stays literally true and keeps protecting what
it was written to protect.

A consequence that falls out for free: a form **born with data** cannot reference a file. There is no
form to have uploaded to yet, so the reference is unknown and gate 3 refuses it — no special case, no
new rule, and the plan says so before somebody discovers it.

## Drawing it

`core-html` gains `file`; `bootstrap` gains `file` and `dropzone` — a different way of *asking* (drag
it in, with a progress bar from `upload.onprogress`), not a restyling of the plain control, which is
the standing test for whether a kit may add a widget.

Both kits do the same three steps: pick → upload → hold the descriptor. The one addition to the shared
markup convention is that **a control may carry a JSON payload**: a hidden input marked `data-json`
holds the descriptor and the collectors parse it instead of reading `value` as text. Everything else
applies unchanged — `data-name`/`data-type`, `data-error` for a refusal, per-entry scoping inside a
list, delegated behaviour so a control inside a cloned entry works — which is the payoff for stage 05
having settled it.

The rendered control carries `accept` (for the browser's own picker) and `data-max-size`, and after an
upload the page compares the descriptor's `type` against the item's `accept` — so a file that could
never be stored is refused in the page's own words from `translations/messages.*.yaml` (`page.file.*`:
pick, uploading, remove, download, too_large, rejected_type, failed). A filled item draws a chip:
name, size, a download link, and a remove control that clears the descriptor. Inside a collection,
`columns` may preview a file item as its name — the only part of a descriptor that reads as text.

## Configuration, and what runs where

```yaml
# config/packages/flysystem.yaml
flysystem:
    storages:
        forms.files:
            adapter: 'local'
            options:
                directory: '%env(resolve:FILES_DIR)%'
```

```
FILES_DIR=%kernel.project_dir%/var/storage/files   # one node; a shared volume or S3 for more than one
FILES_MAX_UPLOAD=10485760                          # the deployment ceiling, mirrored in docker/php.ini
FILES_PER_FORM=50                                  # per form, temporary and attached together
FILES_TEMPORARY_DAYS=7                             # what app:files:purge-temporary collects
```

- No new service in `compose.yaml` and none in CI: a directory needs no daemon, which is the whole
  point of choosing the local adapter for development. The test environment points `FILES_DIR` at
  `var/storage/files-test`, and cases need no cleanup between them because every form is a fresh uuid;
  `make storage-clean` empties it when a developer wants a clean slate.
- `docker/php.ini` (new, copied in the image) sets `upload_max_filesize` and `post_max_size` to at
  least `FILES_MAX_UPLOAD`; the default 2 MB would otherwise be the real limit and no definition would
  know.
- Production on S3: `composer require league/flysystem-async-aws-s3`, swap the adapter and options in
  that one file, and run `app:files:purge-temporary` on the same schedule as the expiry purge. No
  application code changes, and nothing to re-verify in ours — the sidecar is why.
- `make cron` targets are out of scope; both commands are ordinary console commands and a deployment
  schedules them next to each other.

## Order & acceptance

Each step ends with a green `make ci`; the steps that change what a definition derives end with
`make cache-clear` as part of the change.

0. `FileId`, `FileDescriptor`, `IncomingFile`, `FileStream`, the `FileStore` port,
   `FlysystemFileStore`, the in-memory fake for application tests, the bundle, the config, the env,
   `docker/php.ini`. Tests: a round trip through the real store (put → describe → open → count →
   forget); that a file missing either half — no sidecar, no bytes, or a size that disagrees — reads
   as absent; and that no name a client sends can escape its form's directory.
1. `FileField`, its rules, the meta-schema, `DataSchemaDeriver`, and the definition battery
   (`tests/Domain/Forms/Definition/Field/FileFieldTest.php`): which option combinations are allowed,
   what the item contributes to both contracts, and that every option survives storage.
2. `FileReferences` and gate 3, wired into `StagedValuesValidator`, plus the values battery
   (`tests/Infrastructure/Validation/Field/FileFieldValuesTest.php`), putting real files in the store
   so the accepted rows are genuinely accepted.
3. The upload endpoint and `UploadFormFile`: the budget, the three size failures, the `413` listener,
   the OpenAPI multipart body, integration tests for every guard. Its mirror in the same step:
   `DELETE …/files/{fileId}` and `DiscardFormFile` — on the locked row, refusing anything the stored
   values name.
4. The download endpoint and `ReadFormFile`: bytes, headers, streaming, and the two `404`s that look
   alike.
5. Housekeeping, all four collectors: `SaveFormData` deletes what its commit superseded (unit tests
   against the fake pin the *order* — the comparison inside the transaction, the deleting after it, and
   a store failure that does not fail the save); `DeleteForm` removes the row and *then* the bytes;
   `PurgeExpiredForms` stops being one bulk `DELETE` and works per form (`expiredIds`/`removeExpired`),
   the same way round; `PurgeTemporaryFiles` and `app:files:purge-temporary` arrive with
   `readForCleanup` on the repository, and both of the collectors that touch a live form take its row
   lock first. The command lists before it reads a row, counts what it collected per species, and
   treats a lone blob or a lone sidecar as a candidate like any other. Tests pin the rules that matter: nothing left in either place, a store failure leaves no
   form naming absent bytes, a file the values still name is never collected however old it is, and a
   delete that arrives while a save is in flight ends with either a stored reference and the file, or
   no reference and no file — never one without the other.
6. The presentation: `file` in both engines, `dropzone` in the richer one, the JSON payload
   convention, the page text, `columns` previews, and a browser battery per kit that attaches a real
   file (a fixture path inside the container), saves, reloads and follows the download link.
7. Documentation: `README.md` (the item, the two endpoints, the lifecycle, the error codes, the
   fileinfo trap, the ops notes), `CLAUDE.md` (the "why no file item" section becomes how it works,
   with the three exceptions it buys), `tests/_requests/08-files.http`, `make docs`, and this plan's
   own "what building it changed".

Acceptance, one sentence each: a definition can ask for a file; a page can attach one, save, come back
and download it; a confirmed form still hands it over; an expired form hands over nothing; a file the
page removes before saving is gone at once; a file a save supersedes is gone as soon as that save
commits; an upload nobody ever saved is gone within `FILES_TEMPORARY_DAYS`; and after
`app:forms:purge-expired` there is not one byte left in the store that belonged to the form.

## Risks, and what to check before writing much

- **`#[MapUploadedFile]` on 7.4** — that a violation arrives as the `ValidationFailedException` the
  existing listener already maps, and that `name: 'file'` behaves as expected. It decides what step 3's
  error path looks like.
- **`league/flysystem-bundle` and PHP 8.4 / Symfony 7.4** — one `composer require`, and whether the
  named storage autowires as `FilesystemOperator $formsFilesStorage`.
- **`listContents` on a directory that does not exist** — Flysystem's local adapter answers with an
  empty listing rather than throwing, but that is worth pinning in a test, because
  `app:files:purge-temporary` runs on an empty store on day one.
- **fileinfo's actual verdicts** for the types the owner cares about, before the README promises
  anything about `accept`.
- **Streaming a Flysystem handle into a `StreamedResponse`** without buffering the whole object.
- **Panther and a real file input**: the file has to exist inside the container the browser runs in, so
  the browser battery needs a fixture path, not a stream.

## Non-goals

Reference counting; a `files` table; presigned or public URLs; inline preview and thumbnailing; virus
scanning; resumable or chunked uploads (see the revisit trigger); per-item upload addressing; wildcards
in `accept`; a `multiple` option; downloading a form's files as an archive; and collecting a temporary
file no stored document ever named any sooner than the age threshold — from the server's side that is
indistinguishable from one another tab is about to save. Each is a rule or a store this stage
deliberately does not need, and every one is additive behind the port and the descriptor once somebody
actually asks.

## Two decisions that changed while planning

Worth recording, because both changed the shape of the work and not just a name.

**S3 first, then Flysystem.** The first draft chose S3-compatible storage outright (async-aws, MinIO in
compose and in CI, a bucket to create, a lifecycle rule to install) and argued *against* Flysystem on
the grounds that `FileStore` is already the abstraction. That argument was wrong in one respect the
owner put their finger on: the port stops the *rest of the codebase* from knowing where bytes live, but
it does nothing to spare us writing an adapter per backend — and betting the only adapter on S3 puts a
daemon, credentials and a bucket into every developer's machine and every CI run for a feature whose
tests need a directory. With Flysystem there is one adapter of ours, a directory in development, and a
configuration line in production.

**The promotion step disappeared with it.** The S3 draft moved bytes from `staging/` to `attached/`
when a save accepted them, because a bucket lifecycle rule can only expire a prefix — which forced a
server-side copy *inside the save transaction*, an idempotency puzzle, and a table of what each partial
failure leaves behind. Once cleanup is a command of ours rather than a store feature, none of that is
needed: the command can ask the values what is referenced, so "temporary" stops being a location and
goes back to being what it always was — a file no stored document names. The store write leaves the
save path entirely, and the one documented exception to "no outside-world call inside a transaction"
is gone with it. The state the owner asked for is unchanged; only the machinery that used to represent
it is.
