# 03 — rendering a form here

**Status: implemented 2026-08-19**, in the four steps below, each its own commit with a green
`make ci`. The two questions the first draft left open were answered before any code:

- **(decided) Access**: the view lives behind the same network boundary as the API. Nothing new
  is exposed by it — this service has no authentication at all, so whoever can reach the port
  can already `GET /api/forms/{id}/data`. The page is a more convenient way to see the same
  thing, and README says so as a deployment condition rather than pretending otherwise.
- **(decided) Scope**: the page shows a form **and sends it back**, by calling this service's own
  JSON API from the browser. No second write path, no form-encoded bodies, and no second place
  that knows how a value is typed.

Depends on `02-presentation.md`: a form carries the document that says how it is drawn, and the
engine it names is an object that says what it can draw.

## The shape of it

```
GET /forms/{id}?locale=pl        text/html — the form, drawn by the kit its presentation names
```

Not under `/api`: everything there speaks JSON and answers errors as `application/problem+json`,
and that rule is worth keeping unbroken. A page for a person is a different contract, so it gets
a different root and stays out of the OpenAPI document.

```
src/UserInterface/Web/Action/ViewFormAction     one endpoint, one __invoke
src/UserInterface/Web/Renderer/FormRenderer     the port: which engine it draws, and how
src/UserInterface/Web/Renderer/CoreHtmlRenderer + templates/forms/core-html/
```

A renderer declares the engine id it draws; a registry finds one by the id the document names.
No renderer for that engine is `409` with its own problem — the document is fine, this
deployment simply cannot draw it.

**A new dependency**: `symfony/twig-bundle`. Rendering HTML by string concatenation is how
escaping bugs are written, and this is the one place the service produces markup.

## What the page does

Draws the presentation as it stands: containers as `fieldset`, decorations as headings and
paragraphs, each value with the control its widget names, labelled by the resolved code, filled
with what the form holds. A confirmed form draws every control read-only and offers no buttons.

**Submitting is JavaScript calling the same API a frontend would.** A small module in the page
— no build step, no framework, no package — collects the controls into a values document typed
by what each item is, then:

- `PUT /api/forms/{id}/data` to save a draft,
- `POST /api/forms/{id}/confirm` to lock it.

A refusal comes back as `application/problem+json`, and its `errors[].pointer` names the item
(`/email`), so the page can put each message beside the control it belongs to. That is the point
of publishing the contract: the page is a client of it, with no privileged path of its own.

Without JavaScript the page still renders and is readable; it simply cannot submit. That is the
cost of having one write path rather than two.

**CSRF is not part of this**, and the reason is worth writing down: there is no session and no
cookie, so a request from another site has nothing to borrow — and whoever can reach the port
can call the API directly anyway. When authentication arrives, this question comes back with it.

## Locale

`?locale=pl`, falling back to the document's `defaultLocale`, and to the code itself when no
catalogue carries it — visible, diagnosable, and honest about what is missing. `Accept-Language`
is deliberately **not** read: a page whose content depends on a header nobody can see in the URL
caches wrong and reproduces badly. The API's rule that it never resolves a locale is untouched,
because this is not the API.

## Tests

- The rendered document has a control per shown item, of the kind the widget names, in the order
  the presentation gives — asserted by crawling the DOM, never by matching markup, so a template
  that changes its classes does not fail a test about behaviour.
- Text comes from the resolved locale, with the fallback exercised: a code missing from `pl`
  falls back to the default rather than printing the code.
- A confirmed form draws read-only controls and no buttons; a draft draws editable ones holding
  the stored values.
- An engine no renderer here knows: `409`, not a blank page. A form with no presentation at all:
  `404`. Expired: `410`.
- These live in the integration suite and deliberately **not** in the OpenAPI compliance test:
  that test is about the published API, and this endpoint is not part of it.

## Non-goals

- **Themes and styling.** One small stylesheet, legible, done.
- **A JavaScript application.** The module in the page collects values and shows errors; it does
  not route, template or manage state.
- **A no-JavaScript write path**, which would mean form-encoded bodies the API refuses and a
  conversion layer repeating what the definition already says about types.
- **PDF or print pipelines.**

## Order & acceptance — as built

1. **The renderer, without an endpoint** (`99f212c`). Twig, the port and its registry,
   `CoreHtmlRenderer` and the templates, exercised by handing it a form directly.
2. **The endpoint** (`1aa377d`). `ViewFormAction` over `ReadForm`, `409` for a kit nothing here
   draws, `404` with no presentation, `410` for an expired form.
3. **Submitting, proved in a browser** (`9bac5c2`). Panther drives headless Chromium as a
   `browser` suite: what is typed reaches the API in the contract's types, a refusal lands beside
   the control its pointer names, and confirming returns a read-only page.
4. **Documents** (this step).

## What building it changed

- **The locale is the framework's, not ours.** The plan proposed `?locale=` and explicitly
  refused `Accept-Language`; the owner asked for Symfony's own mechanism, so `_locale` in the
  path wins, the header decides otherwise, `default_locale` has the last word, and the response
  varies on the header. A browser asking for `pl-PL` arrives as `pl_PL`, so resolution walks the
  locale, then the language without its region, then the document's default, then the code.
- **`problem+json` stayed in the API.** The page was throwing `ProblemException`, which answers a
  browser with a document it is no client of. Web routes carry `_errors: html`, the API's
  listener steps aside, and an `ErrorPageListener` answers with a page.
- **`Http` became `Api`.** Once a second adapter spoke HTTP, the name told nobody anything:
  `src/UserInterface/{Api,Web,Cli}`.
- **Browser tests rather than a JavaScript toolchain.** The choice was between asserting the
  page's contract server-side, unit-testing the module under jsdom, or driving a real browser.
  The last one is what proves the loop, and it is what the owner asked for — with the cost being
  Chromium in the image and a slower suite.
