# 11 — making this installable more than one way

The service was written for one installation: the root of a host, with a gateway in front. That
was right until the owner said it should be something other people install too, at which point
"the root of a host" stopped being a fact about deployments and became **a claim the code was
making in four places** — three of which nothing would have caught.

This is the record of what was claimed, what replaced it, and what was deliberately left
un-configurable.

## What was found, by measuring rather than reading

The page was drawn behind a gateway asserting `X-Forwarded-Prefix: /svc` and every address on it
was compared with what it should have been:

| Where | What it said | Would it move? |
|---|---|---|
| `path('web_form_view')` in both templates | `/forms/{id}` | yes — the router already knew |
| `importmap()` / `asset()` (bootstrap kit) | `/assets/…` | yes, **once the header was trusted** |
| `<script src="/js/core-html-form.js">` | written into the template | no |
| `fetch('/api/forms/${id}/…')` — 8 places in two kits | written into the JavaScript | no |
| `sprintf('/api/forms/%s/files', …)` in `PresentedNodes` | written into PHP | no |

Two corrections to what was believed before the measurement, both worth recording because both
were stated confidently:

1. **AssetMapper does follow a forwarded prefix.** An earlier read said it could not. It does —
   the asset package generates from the request's base URL — and the earlier test only failed
   because `x-forwarded-prefix` is not in Symfony's default `trusted_headers`. One line of
   configuration, not a limitation.
2. **A trusted prefix does not help a gateway that passes the whole path through.** Symfony
   computes `pathInfo` from the *real* base URL, so `/svc/forms/{id}` with the header set is a
   404. That variant needs the routes to be declared under the prefix, which is a different
   mechanism — see below.

## What it landed as

**One idea: the service is told where it stands, and everything else is generated.**

- **`X-Forwarded-Prefix`, trusted like `X-Forms-Identity`** — runtime, nothing configured, for a
  gateway that answers on a path and strips it. It is in `trusted_headers` now, and it counts only
  from an address in `FORMS_TRUSTED_PROXIES`, for exactly the reason the identity header does: it
  changes what this service says about itself, so a client must not be able to say it.
- **`FORMS_BASE_PATH`** — for a gateway that passes the path through, read in `App\Kernel::build()`
  and used as the prefix both route resources are declared under. It is **build-time**, because
  routes are compiled when the container is; that is stated wherever it appears rather than
  hidden, and `app:routes:groups` prints the base path it is actually serving under so a
  deployment diffs reality instead of trusting a variable.
- **`FORMS_ASSETS_PREFIX`** → `asset_mapper.public_prefix`. One value moves every static file,
  including where `asset-map:compile` writes them, and an absolute URL puts them on a CDN or in a
  bucket (which then needs CORS — a module fetched cross-origin without it is refused by the
  browser with a perfectly good `200`).
- **`FormApi`** — the one place that names the four routes a page needs (`api_form_data`,
  `api_form_confirm`, `api_form_files`, `api_form_history`, plus `api_form_file` for a download),
  handed to a kit as data: `data-form-api-value` in the richer kit, `data-api` in the plain one.
  Two of the four are bases: `{files}/{fileId}` and `{history}/{seq}` are composed by the page,
  because the page is what learns a file's id from the upload it just made and a revision's number
  from the list it just read — but what it composes onto is the router's answer.
- **The plain kit's module moved** from `public/js/core-html-form.js` to
  `assets/pages/core-html-form.js`, asked for with `asset()`. It was the only file outside
  AssetMapper and the only address written into a template; now it follows the prefix like
  everything else and gets a digest and `immutable` into the bargain. "No machinery" was never
  about where the file is served from — it is still one hand-written module importing nothing.
- **`RouteGroup::of($path, $base)`** takes the base off before asking its four questions, and
  `RouteGroup::under()` says whether an address is this service's at all. `RouteGroupsTest`
  asserts the two gateway properties over the real route collection *and* under a base path.

## What was deliberately not made configurable

- **The four audience prefixes.** A base path already makes several of these services unique
  behind one host, so four more knobs would buy nothing while turning a gateway rule into a guess
  and `RouteGroup` into a description of one deployment. One base, four fixed prefixes.
- **Rewriting paths inside the application.** If the service knows its base there is nothing to
  rewrite; if it does not, no rewrite repairs the addresses already in the HTML it sent.
- **Pages and API on different origins.** It buys nothing a path rule cannot do, and costs CORS,
  cookie configuration, and the page's standing as an ordinary client of the API next to it.

## Settled after the fact: the last outbound call

The polyfill AssetMapper puts on a page for browsers without import maps was coming from
`ga.jspm.io` — not by choice but by omission: with no entry in `importmap.php`, AssetMapper falls
back to a hardcoded CDN URL (`ImportMapRenderer::DEFAULT_ES_MODULE_SHIMS_POLYFILL_URL`). For a
service other people install that is the wrong default twice over: an installation with no egress
cannot fetch it, and a CSP that does not name that host refuses it. `importmap:require
es-module-shims` settles it — 48 KB committed under `assets/vendor/`, served under
`FORMS_ASSETS_PREFIX`, nothing importing it — and the only `http://` left on a rendered page is
the SVG namespace. Note that `importmap:require` **rewrites the file and drops its comments**; the
one explaining the per-skin entrypoints had to be put back.

## What is still open

- **A changed `FORMS_BASE_PATH` with a warm cache is silently stale.** The env var is not a
  tracked resource, so nothing invalidates the container. Documented, printed by
  `app:routes:groups`, and not solved.
- **The gateway itself is still not here**, which [09](09-access.md) said and this changes not at
  all. Being installable in more ways is not being safe to expose in any of them.

## What proves it

`ViewFormActionTest` draws both kits behind `X-Forwarded-Prefix: /svc` and asserts that every
address on the page carries it — the page, the module, and the four endpoints — and that none of
the unprefixed ones survives. That is the assertion that matters, because the failure this whole
change prevents is a page that **draws perfectly and cannot save**: half-moved is worse than not
moved, since nothing about it looks wrong until somebody presses a button.
