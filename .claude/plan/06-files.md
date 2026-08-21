# 06 — files: object storage, an upload endpoint, and a `file` item

Until now every answer a form holds is JSON: one document, stored as the exact text that passed
validation, in one column. A file is the first answer that is **bytes**, and bytes do not fit in
that document. `CLAUDE.md` has said all along how this would arrive when it arrived — *"an upload
endpoint returning an id, with the item holding a reference: values stay JSON, the contract stays
the contract, and the weight lands where it belongs"* — and that is what this stage builds.

What the owner asked for, in their words: a service for uploading, a real store to keep the bytes
in, files that stay downloadable **for as long as the form is available**, downloads that always go
**through the form**, the configuration of a `file` item — and, said plainly in the second pass:
what a user uploads is **temporary**, and it has to *become* a file attached to the form for good,
reachable through the API.

## Decisions

1. **Values hold a descriptor, and the server authors it.** The value of a `file` item is a small
   object — `id`, `name`, `size`, `type` — and every one of those four is what the *server*
   measured at upload and returned. The client echoes it verbatim. This is the load-bearing
   decision: it is what makes `maxSize` and `accept` statable in the derived schema
   (`size.maximum`, `type.enum`), so a file item's rules are published and enforced where this
   codebase enforces rules, instead of "past the contract".
   *Rejected:* the value is a bare id string — then the schema says nothing but "a uuid", every
   rule about a file is invisible to clients, and every page that wants to write
   "invoice.pdf (2.1 MB)" has to ask a second endpoint.
2. **The bytes live in S3-compatible object storage.** One `FileStore` port, one adapter over
   `async-aws/simple-s3`, MinIO in dev/CI and any S3 (or a compatible service) in production. It
   is the one store that is shared between application instances, streams without holding a file
   in PHP's memory, deletes a whole form by prefix in one call, and has a lifecycle policy of its
   own — which is exactly what "temporary files" needs.
   *Rejected:* the local filesystem (not shared between instances, and a deploy or a container
   restart is enough to lose it) and the **database** — see below, it is the option this codebase
   has the strongest reason to refuse.
3. **Two prefixes, and a promotion between them.** `staging/{formId}/{fileId}` is what an upload
   writes; `attached/{formId}/{fileId}` is what a form holds for good. A staged object becomes an
   attached one the moment a *saved* document references it, and nothing else ever moves it. This
   is the answer to "temporary → permanent", and §"The lifecycle" is the whole of it.
4. **Our own upload endpoint, not tus.** One `POST` with a multipart body, streamed to the store.
   The comparison, and the trigger that would make us revisit it, are in §"Upload: our controller
   or tus".
5. **A file belongs to a form from the moment it is uploaded.** `POST /api/forms/{id}/files`, never
   `POST /api/files`. Ownership is not a column, it is the key: a file uploaded to form A cannot be
   named by form B, because nothing ever looks outside form B's own prefix.
6. **Only what the values reference can be downloaded.** `GET /api/forms/{id}/files/{fileId}`
   answers by walking the form's definition for its `file` items, reading those positions out of
   the values, and looking for that id. "Downloaded through the form" is then not a convention in
   the URL — the form's own document *is* the index of what may be fetched, and an uploaded file
   nobody saved is unreachable by construction. No presigned URLs, no public bucket, ever: a
   browser never speaks to the store.
7. **A file lives exactly as long as its form.** No reference counting. Every object sits under a
   form, every form has an `expire_date`, and the purge deletes the objects and then the row — in
   that order, so a failed delete leaves a row that says "come back". Staged objects also have a
   second, shorter end: a bucket lifecycle rule on `staging/`.
8. **Never delete bytes inside a form transaction, and never let a row reference bytes that are not
   there yet.** The two together decide the order of every step in §"The lifecycle".
9. **Many files is `collection` + `file`, not a `multiple` option.** Stage 05 already built the item
   that asks something repeatedly, with `min`/`max` counting entries and a pointer per entry. A
   second way to say "several" would be a second set of counting rules that can drift.
10. **`accept` and `maxSize` are both required on a file item.** A file item without them is a
    contract that says "any bytes, any size", which no deployment can honour and no client can
    check. Refusing that at definition time costs nothing.
11. **The upload knows nothing about which item it is for.** It takes bytes for a form, full stop.
    An item's `accept`/`maxSize` are published in the schema and enforced at the save gate, and the
    page compares the descriptor it just received against the item's own rules, so nobody learns at
    confirmation time. Addressing the item in the upload URL would need a second addressing scheme
    (an item inside a collection has a *definition* path, `lines/scan`, not a value path) to enforce
    a rule that is already enforced.
12. **The upload is the one endpoint whose body is not JSON; the download the one that does not
    answer with a document.** Stated as deliberate exceptions in `CLAUDE.md` rather than left to be
    discovered. Base64 in a JSON envelope was the alternative: +33% on the wire and the whole
    payload through the JSON parser, for nothing.

### Why not the database

It is the option to name explicitly, because it is the one that looks cheapest. `bytea` (or a
`BLOB`) would put megabytes into the row store this project keeps deliberately portable and
deliberately small: every backup, every replica and every WAL segment carries the bytes; a read is
all-or-nothing (no ranged reads, no streaming without large-object APIs, which are **not**
portable — `pg_largeobject` has no equivalent in the other platforms `FormRecord` is written to run
on); and Doctrine hands you the whole value as a PHP string, so a 10 MB download is 10 MB of
request memory. It also breaks the one rule persistence has here — *portable Doctrine types only* —
on its first day.

That said, the port is what makes this a preference rather than a lock-in: a `DoctrineFileStore`
writing chunks into a table is perfectly possible behind the same five methods if a deployment ever
has to run with nothing but a database. Nobody is forced into S3 by anything except the adapter
`services.yaml` names.

### Why not Flysystem

Because `FileStore` **is** the abstraction, and Flysystem would be a second one under it. We need
five operations, three of which (per-object user metadata, server-side copy, delete-by-prefix) are
either awkward or absent in Flysystem's portable surface — and the portability it sells is
portability between the very backends we just decided against. `async-aws/simple-s3` is three small
packages, streams uploads (switching to multipart on its own for large bodies) and exposes
`copyObject`/`headObject`/`listObjectsV2`/`deleteObjects` directly.

## The store

```
Application/Forms/Port/FileStore          five operations, every one of them scoped to a form
Application/Forms/File/IncomingFile       a temporary path + the name the client sent
Application/Forms/File/FileStream         an open handle + the descriptor a response needs
Infrastructure/Files/S3FileStore          async-aws; keys, metadata, sniffing, prefix deletes
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
    /** Writes an upload to staging and returns what the server measured while doing it. */
    public function stage(FormId $form, FileId $file, IncomingFile $upload): FileDescriptor;

    /** What the store knows about this form's file — attached, or still staged. Null if neither. */
    public function describe(FormId $form, FileId $file): ?FileDescriptor;

    /** Makes a staged file part of the form for good. Idempotent: already attached is success. */
    public function attach(FormId $form, FileId $file): void;

    /** Opens an attached file. @throws FileMissing when the object is not there */
    public function open(FormId $form, FileId $file): FileStream;

    /** How many files this form holds, staged and attached together — the upload budget. */
    public function countFor(FormId $form): int;

    /** Everything this form holds, both prefixes. Idempotent. */
    public function forget(FormId $form): void;

    /** Drops the staged copy of a file that is now attached. Best effort; never throws upwards. */
    public function discardStaged(FormId $form, FileId $file): void;
}
```

**Keys and metadata.** `staging/{formId}/{fileId}` and `attached/{formId}/{fileId}` — the prefix
comes *first* so a bucket lifecycle rule can match `staging/`, which is the whole reason staged
files need no cleanup job of ours. Per object: `ContentType` is what the server sniffed from the
bytes (`symfony/mime`, `ext-fileinfo`), `ContentLength` is what the store counted, and
`x-amz-meta-name` holds the sanitized client filename, `rawurlencode`d because user metadata must
be US-ASCII. `attach()` is `CopyObject` (server-side, the bytes never enter PHP) and metadata comes
across with it by default.

**Sniffing and sanitizing happen in the adapter**, because the adapter is what records them: one
place authors the four facts, and `describe()` reads back exactly what was written. The name is
`basename`d, stripped of control characters, capped at 255 bytes and replaced by `file` if nothing
survives — so the schema's `pattern` on `name` is always satisfiable by the descriptor we hand out.

## Upload: our controller or tus

**What tus would buy.** Resumable, chunked uploads (`ankitpokhrel/tus-php` server, `tus-js-client`
or Uppy in the browser): a dropped connection resumes at the offset instead of starting over.

**What it would cost, here specifically.** A second HTTP protocol surface next to the API — `POST`,
`PATCH`, `HEAD`, `OPTIONS` with `Tus-Resumable`, `Upload-Offset`, `Upload-Length` — that answers in
tus headers, not in `problem+json`, so "one error format" stops being true of this service. A
**third store** for partial uploads (its own cache backend, its own expiry job) next to the row
store and the object store, which is precisely the multiplication this plan is trying to avoid. A
front-end dependency vendored into `assets/vendor/` in a repository whose front-end rule is "no
build step, no package manager", where Uppy alone is larger than every asset committed so far. And
it does not remove the server limits: the assembled file still lands somewhere before it goes to the
store.

**What our controller costs.** One route, `#[MapUploadedFile]`, a stream into the store — and no
resumability: a 9 MB upload that dies at 8 MB starts again.

**Verdict: our own controller.** Forms in this system ask for invoices, scans and photos, and the
ceiling is a per-item `maxSize` an author writes down; at those sizes resumability is a cost with no
payer. Progress bars, which is what people usually want tus for, need nothing:
`XMLHttpRequest.upload.onprogress` reports them on a plain multipart `POST`, and that is what the
Bootstrap kit's `dropzone` will draw.

**When to revisit, and with what.** The trigger is a real case above roughly 50 MB, or uploads from
mobile networks. The answer then is most likely *not* tus but **presigned multipart straight to the
store**: `POST /api/forms/{id}/files/reserve` hands back a file id and a presigned URL, the browser
`PUT`s the parts, `POST /api/forms/{id}/files/{fileId}/complete` makes the server `headObject` the
result and author the descriptor exactly as it does today. The bytes never touch PHP, resumability
comes from the store's own multipart API, and — the point — *the reference the values hold does not
change*, so it is additive behind the same port. tus stays the answer only if the browser must
never speak to the store's origin **and** the uploads must be resumable.

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

Three failure modes deserve naming, because they do not all arrive the same way:

- **over `upload_max_filesize`** — the part arrives invalid (`UPLOAD_ERR_INI_SIZE`); the constraint
  turns it into a `422` with a pointer, like any violation;
- **over `post_max_size`** — PHP discards the *entire* body, so there is no part at all and the
  mapping would report "missing file". A small listener on this route compares `Content-Length` to
  the ini limit *before* mapping and answers `413` with a problem document, so the page can say
  something true;
- **a request that is not multipart at all** — `415`, the same answer the JSON endpoints give for
  the mirror-image mistake.

`UploadFormFile` reads the form and deliberately does **not** lock it: no column changes, and the
worst a race with a `confirm` can do is leave bytes nobody will ever reference — which is decision 7's
problem, already solved.

## The lifecycle: temporary, then attached

This is what the owner asked to see in detail. Four states, and only one transition anybody triggers.

```
(nothing)  --POST /files-->  staged  --PUT /data referencing it-->  attached  --purge/delete-->  gone
                               |                                        |
                               +-- lifecycle rule, FILES_STAGING_DAYS --+
```

**Staged.** What an upload wrote. It is *temporary in the store's own eyes*: the bucket has a
lifecycle rule on the `staging/` prefix, so an object nobody ever saved disappears on its own after
`FILES_STAGING_DAYS`. Nothing of ours runs to make that happen — no cron, no reference counting, no
"orphan sweep" — which is the whole reason the two prefixes exist. A staged file has **no download
route**: the page that just uploaded it holds the descriptor and can preview from the local `File`
object, and after a reload the page draws from the stored values, where a staged file by definition
does not appear.

**Attached.** Referenced by a document that was accepted and stored. Reachable through
`GET /api/forms/{id}/files/{fileId}` for as long as the form is, confirmed or not.

**The transition happens inside `SaveFormData`**, in this order:

```php
$this->transactions->run(function () use ($id, $values): void {
    $form = $this->forms->getForUpdate($id);
    $form->saveDraft($values, $this->valuesValidator);   // gates 1-3; gate 3 accepts staged or attached

    foreach ($this->references->in($form) as $file) {
        $this->files->attach($id, $file->id);            // CopyObject; idempotent
    }

    $this->forms->save($form);                           // the row starts pointing at them
});

foreach ($promoted as $file) {
    $this->files->discardStaged($id, $file->id);         // after the commit, best effort
}
```

**The invariant this order buys**: *an attached object exists before any stored document references
it, and a staged object is never the last copy of anything a form's values name.* That is what makes
the lifecycle rule safe — a referenced file has already left `staging/` before the rule could ever
look at it.

It also puts one S3 call inside a database transaction, which is the only outside-world call inside
one anywhere in this codebase, so it is a deliberate exception and here is the reasoning. The row is
locked for the state check either way; a server-side copy is milliseconds and moves no bytes through
us; and the alternative — promote after the commit, and let the download fall back to `staging/` —
turns "attached" into a maybe, and worse, leaves a window in which a promotion that silently failed
is a *deleted file* seven days later. Failing a save loudly is better than losing bytes quietly.

What each failure leaves behind, deliberately:

| what fails | what the client sees | what is left |
|---|---|---|
| gate 3 (`unknown` / `mismatch`) | `422` with a pointer at the member | the staged object, until the lifecycle rule |
| `attach()` | `500`, nothing stored | the staged object, until the lifecycle rule |
| the commit | `500`, nothing stored | an attached object nothing references; dies with the form |
| `discardStaged()` | `204`, all fine | a staged duplicate, until the lifecycle rule |

**A reference that disappears** from a later draft (somebody replaced the file) leaves its attached
object in place: unreachable, because only referenced ids can be downloaded, and already promised an
end date. Deleting it on the spot would be a delete inside a save — decision 8 — and would make a
mistaken intermediate save unrecoverable.

**A form born with data cannot reference a file**, and nothing had to be written to make that true:
there is no form to have uploaded to yet, so gate 3 finds nothing and refuses. The plan says it here
so nobody discovers it as a bug.

## Download

`GET /api/forms/{id}/files/{fileId}` — `ReadFormFile` reads the form (`410` when expired), asks
`FileReferences` whether the stored values name this id (`404` when they do not, indistinguishable
from a file that never existed — deliberately), then streams the object:

- `Content-Type` from the recorded type, `Content-Length` from the recorded size;
- `Content-Disposition: attachment; filename*=UTF-8''…` with the recorded name;
- `X-Content-Type-Options: nosniff`, and always an attachment, never inline — bytes a stranger
  uploaded, served from this application's own origin, are an XSS vector the moment a browser renders
  them;
- a `StreamedResponse` over the store's handle, so a 10 MB file is 10 MB through a socket and not
  10 MB of PHP memory.

An attached object that is missing is a broken invariant, not a client mistake: `404` to the caller
and an error in the log, loudly, rather than a shrug.

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
**rules of its own**, not a widget: what the bytes may be and how many of them there may be are
things no other item can say.

- **No wildcards** (`image/*`) in the first cut: the derived schema states `accept` as an `enum`,
  which is exactly what a fixed list is, and a wildcard would have to become a `pattern` — two ways
  of saying one thing. Additive later if anybody asks.
- `maxSize` **is the contract**, so a deployment's own limits (`upload_max_filesize`,
  `post_max_size`, `FILES_MAX_UPLOAD`) must be at least the largest `maxSize` any definition on it
  uses. An ops note, not a definition rule: a rule that reads deployment configuration would make a
  stored definition unreadable (`FormUnreadable`) the day somebody lowers a limit, which is the
  worst possible place to learn about it.
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
person may leave a question for later. Nobody types a descriptor — it arrives whole from one
response — so a half descriptor is a client error, in the same category as `maxLength`. The question
a person can still leave for later is the file itself, and that is the item's own `required`,
behaving exactly like every other item's.

## Three gates now, and the honest cost

1. the derived schema — shape, size, media type. Cached per form and mode, unchanged.
2. the Symfony form — nothing to add for a file item (`RawValueType`, the way a collection is taken
   as it came), because everything a file's value *is* has been said in the schema.
3. **`ReferencedFilesExist`** — for every `file` position in the definition, if the values name a
   descriptor there, the store must hold that id **under this form** (staged or attached) and its
   recorded facts must equal the claim. Findings: `form.file.unknown` at `/invoice/id`,
   `form.file.mismatch` at the member that differs (`/invoice/size`). Last in the order because it
   is the only gate that talks to another store: nothing pays for it until the document is otherwise
   perfect.

Gate 3 is **the one place in this codebase where the server is stricter than its published
contract**, and that needs saying out loud. No schema can state "this id exists" — a question about
the world, the same category as "this form has expired", which the contract has never covered
either. Two things keep it from being a trap: a client that echoes the upload response verbatim can
never trip it, and it lives in its own class rather than inside the form stage, so
`testTheFormNeverRefusesWhatThePublishedSchemaAccepts` stays literally true and keeps protecting
what it was written to protect.

## Drawing it

`core-html` gains `file`; `bootstrap` gains `file` and `dropzone` — a different way of *asking*
(drag it in, with a progress bar from `upload.onprogress`), not a restyling of the plain control,
which is the standing test for whether a kit may add a widget.

Both kits do the same three steps: pick → upload → hold the descriptor. The one addition to the
shared markup convention is that **a control may carry a JSON payload**: a hidden input marked
`data-json` holds the descriptor and the collectors parse it instead of reading `value` as text.
Everything else applies unchanged — `data-name`/`data-type`, `data-error` for a refusal, per-entry
scoping inside a list, delegated behaviour so a control inside a cloned entry works — which is the
payoff for stage 05 having settled it.

The rendered control carries `accept` (for the browser's own picker) and `data-max-size`, and after
an upload the page compares the descriptor's `type` against the item's `accept` — so a file that
could never be stored is refused in the page's own words from `translations/messages.*.yaml`
(`page.file.*`: pick, uploading, remove, download, too_large, rejected_type, failed). A filled item
draws a chip: name, size, a download link, and a remove control that clears the descriptor. Inside a
collection, `columns` may preview a file item as its name — the only part of a descriptor that reads
as text.

## Configuration, and what runs where

```
S3_ENDPOINT=http://minio:9000      # empty in production for real AWS
S3_REGION=us-east-1
S3_BUCKET=ingot-forms
S3_KEY / S3_SECRET                 # MinIO's dev credentials in .env; real ones in .env.local or a secret store
FILES_MAX_UPLOAD=10485760          # the deployment ceiling, mirrored in docker/php.ini
FILES_PER_FORM=50                  # staged + attached, per form
FILES_STAGING_DAYS=7               # the bucket's own lifecycle rule
```

- `compose.yaml` gains a **minio** service (with `pathStyleEndpoint: true` on our side) and a
  volume; the CI workflow gains the same service next to postgres.
- `docker/php.ini` (new, copied in the image) sets `upload_max_filesize` and `post_max_size` to at
  least `FILES_MAX_UPLOAD`; the default 2 MB would otherwise be the real limit and no definition
  would know.
- `bin/console app:storage:init` creates the bucket if it is missing and installs the `staging/`
  lifecycle rule — idempotent, run by `make storage`, and called from `make setup`. A deploy runs it
  too, for the same reason it runs migrations.
- Integration tests need no cleanup between cases: every form is a fresh uuid, so every test writes
  under a prefix of its own. `make storage-clean` empties the dev bucket when a developer wants it.

## Order & acceptance

Each step ends with a green `make ci`; the steps that change what a definition derives end with
`make cache-clear` as part of the change.

0. `FileId`, `FileDescriptor`, `IncomingFile`, `FileStream`, the `FileStore` port, `S3FileStore`,
   the in-memory fake for application tests, the env/config/compose/php.ini/`app:storage:init`
   groundwork. Tests: a round trip through the real store against MinIO (stage → describe → attach →
   open → forget), and that no name a client sends can escape its prefix.
1. `FileField`, its rules, the meta-schema, `DataSchemaDeriver`, and the definition battery
   (`tests/Domain/Forms/Definition/Field/FileFieldTest.php`): which option combinations are allowed,
   what the item contributes to both contracts, and that every option survives storage.
2. `FileReferences` and gate 3, wired into `StagedValuesValidator`, plus the values battery
   (`tests/Infrastructure/Validation/Field/FileFieldValuesTest.php`), staging real files through the
   store so the accepted rows are genuinely accepted.
3. The upload endpoint and `UploadFormFile`: budget, the three size failure modes, the `413`
   listener, the OpenAPI multipart body, integration tests for every guard.
4. Promotion: `SaveFormData` attaches what the stored document references and discards the staged
   copies after the commit. Unit tests against the fake pin the *order* — that nothing is attached
   before the values are accepted, and that the row is never written before the copy exists.
5. The download endpoint and `ReadFormFile`: bytes, headers, streaming, and the two `404`s that look
   alike.
6. Deletion: `DeleteForm` forgets the bytes before removing the row, and `PurgeExpiredForms` stops
   being one bulk `DELETE` — it asks the repository for expired ids in batches
   (`expiredIds`/`removeExpired`) and, per form, deletes objects first and the row second, so a
   half-finished run is a resumable run. Tests: nothing left in either place, and a store failure
   leaves the row.
7. The presentation: `file` in both engines, `dropzone` in the richer one, the JSON payload
   convention, the page text, `columns` previews, and a browser battery per kit that attaches a real
   file (a fixture path inside the container), saves, reloads and follows the download link.
8. Documentation: `README.md` (the item, the two endpoints, the lifecycle, the error codes, the
   fileinfo trap, the ops notes), `CLAUDE.md` (the "why no file item" section becomes how it works,
   with the three exceptions it buys), `tests/_requests/08-files.http`, `make docs`, and this plan's
   own "what building it changed".

Acceptance, one sentence each: a definition can ask for a file; a page can attach one, save, come
back and download it; a confirmed form still hands it over; an expired form hands over nothing; an
upload nobody saves disappears on its own; and after `app:forms:purge-expired` there is not one
object left in the bucket that belonged to the form.

## Risks, and what to check before writing much

- **`#[MapUploadedFile]` on 7.4** — that a violation arrives as the `ValidationFailedException` the
  existing listener already maps, and that `name: 'file'` behaves as expected. It decides what step 3's
  error path looks like.
- **MinIO's lifecycle support** for a prefix rule, and whether `app:storage:init` can install it
  through async-aws (`PutBucketLifecycleConfiguration`) on both MinIO and AWS.
- **fileinfo's actual verdicts** for the types the owner cares about, before the README promises
  anything about `accept`.
- **async-aws streaming into a `StreamedResponse`** — that the result stream can be handed over
  without buffering the whole object.
- **Panther and a real file input**: the file has to exist inside the container the browser runs in,
  so the browser battery needs a fixture path, not a stream.

## Non-goals

Reference counting and orphan sweeps of our own; a `files` table; presigned or public URLs; inline
preview and thumbnailing; virus scanning; resumable or chunked uploads (see the revisit trigger);
per-item upload addressing; wildcards in `accept`; a `multiple` option; downloading a form's files as
an archive; deleting a de-referenced file before its form expires. Each is a rule or a store this
stage deliberately does not need, and every one is additive behind the port and the descriptor once
somebody actually asks.
