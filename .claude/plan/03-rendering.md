# 03 — rendering a form here

**Status: proposed.** Nothing implemented. Depends on `02-presentation.md`, which gives a form
a presentation document naming the engine it is written for.

This is where the service stops being only an API: besides answering with documents, it can
answer with a **form somebody can look at**. The API stays exactly as it is; rendering is a
second way in, over the same use cases.

## The shape of it

```
GET /forms/{id}/view            text/html — the form, drawn by the engine its presentation names
```

Note the path: **not** under `/api`. Everything under `/api` speaks JSON and answers errors as
`application/problem+json`, and that rule is worth keeping unbroken. A page for a human is a
different contract, so it gets a different root, and the OpenAPI document — which describes the
API — does not describe it.

Placement follows the architecture rather than bending it: `src/UserInterface/Web/` with its own
actions and Twig templates, calling `ReadForm` and `ReadFormPresentation` exactly as the JSON
actions do. The domain gains nothing and learns nothing. A renderer is one more adapter.

```
src/UserInterface/Web/Action/ViewFormAction      one endpoint, one __invoke
src/UserInterface/Web/Renderer/                  FormRenderer port + CoreHtmlRenderer
templates/forms/core-html/                       the templates that engine draws with
```

`EngineCatalogue` (domain, from plan 02) says which widgets an engine *declares*; the renderers
here say how those widgets *look*. A renderer that cannot be found for the engine a document
names is a `409` with its own problem — the document is fine, this deployment just cannot draw
it.

## What this forces us to decide, and how

**A locale has to be chosen.** The API deliberately never resolves one: it serves the document
whole and the client picks. A renderer cannot dodge it — it emits text. So `?locale=pl` is
explicit, falling back to the document's `defaultLocale`, and `Accept-Language` is **not** read:
a page whose content depends on a header nobody can see in the URL is a page that caches wrong
and reproduces badly. The API's rule is untouched, because this is not the API.

**There is no authentication in this service.** Today that is defensible: it answers JSON to
whatever is in front of it, and what is in front of it is expected to be another service. A
rendered page invites a browser, and a browser invites people — so `GET /forms/{id}/view` makes
a form and its stored answers readable by anyone who can reach the port and guess nothing (the
id is a UUIDv7, which is unguessable but also not a secret). **This must be decided before the
endpoint exists**, and the honest options are: keep it behind the same network boundary as the
API and document that loudly, or add authentication first. Adding it "later" is how services end
up serving other people's form data.

**Submitting is not part of this.** The page renders what the form holds; it does not save.
Saving from a browser means either accepting form-encoded bodies (which the API refuses on
purpose) or JavaScript that PUTs JSON — plus CSRF, plus a session, plus an answer to the
authentication question above. A read-only view is useful on its own (preview, print, support
looking at what somebody sent), and it is a much smaller thing to get right.

## What it draws

For each section, in order, its items in order; for each item, the control its widget names,
labelled by the resolved code, filled with the value the form holds if it has one, and marked
read-only when the form is confirmed. Nothing else: no theme, no colour, one small stylesheet so
it is legible rather than pretty.

Values come from `ReadForm`, so what is drawn is exactly what the API would answer with — there
is no second source of truth about what a form contains.

## Tests

- The rendered document contains a control per shown item, of the kind the widget names, in the
  order the presentation gives. Assertions on structure (a DOM crawl), never on markup strings:
  a template that changes its classes should not fail a test about behaviour.
- Text comes from the resolved locale, with the fallback exercised: a code missing from `pl`
  falls back to the default and does not print the code itself.
- A confirmed form draws read-only controls; a draft draws editable ones with the stored values
  in them.
- An engine no renderer here knows: `409`, not a blank page.
- Expired form: `410`, as everywhere.
- These live in the integration suite, and deliberately **not** in the OpenAPI compliance test:
  that test is about the published API contract, and this endpoint is not part of it.

## Non-goals

- **Themes, styling, layout systems.** One stylesheet, legible, done.
- **A JavaScript application.** If a client wants interactivity it has the API; that is what the
  API is for.
- **Submitting from the page**, until authentication is decided (see above).
- **Server-side rendering of the whole lifecycle** — no confirm button, no wizard navigation.
- **PDF or print pipelines.** A page a browser can print is not the same as a document
  generator, and pretending otherwise invites a renderer nobody can test.

## Order & acceptance

Each step ends with a green `make ci`.

1. **Decide the access question** — this one is not code. Either the endpoint is documented as
   living behind the same boundary as the API, or authentication comes first.
2. **The port and the built-in engine.** `FormRenderer`, `CoreHtmlRenderer`, templates for the
   widgets `core-html` declares, unit-testable through the port with a presentation and values
   handed in.
3. **The endpoint.** `ViewFormAction` over `ReadForm` + `ReadFormPresentation`, locale
   resolution, the `409` for an engine nobody can draw, the `410` for an expired form.
4. **Documents.** README gains a short section saying the service can also draw a form, what
   that endpoint is not (not the API, not authenticated by itself), and how a locale is chosen.
