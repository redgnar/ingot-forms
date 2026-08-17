<!-- Generated from ../openapi.yaml by tools/build-docs.php — do not edit by hand; run `make docs`. -->

# ingot-forms API 0.2.0

Forms management backend. A form is a single fillable document: one immutable definition plus one data set with a required expire date. Lifecycle: empty → draft (repeatable lenient saves) → confirmed (strict validation, locked forever). Expired forms answer 410 everywhere. Every error response is an RFC 9457 `application/problem+json` document.

Machine-readable contract: [`openapi.yaml`](openapi.yaml) — this document with every
`$ref` inlined. Both halves of every exchange listed here (request *and* response) are
asserted against real HTTP traffic by `tests/Http/OpenApiComplianceTest.php`, so this
reference cannot drift from the implementation.

## Endpoints

| Method & path | Operation | Purpose | Responses |
|---|---|---|---|
| [`GET /api/forms`](#get-apiforms) | `listForms` | List non-expired forms | `200`, `422` |
| [`POST /api/forms`](#post-apiforms) | `createForm` | Create a form | `201`, `400`, `422` |
| [`GET /api/forms/{id}`](#get-apiformsid) | `getForm` | Read a form | `200`, `404`, `410` |
| [`DELETE /api/forms/{id}`](#delete-apiformsid) | `deleteForm` | Delete a form | `204`, `404`, `410` |
| [`GET /api/forms/{id}/schema`](#get-apiformsidschema) | `getFormDataSchema` | Read the values schema derived from the definition | `200`, `404`, `410`, `422` |
| [`GET /api/forms/{id}/data`](#get-apiformsiddata) | `getFormData` | Read the current values | `200`, `404`, `410` |
| [`PUT /api/forms/{id}/data`](#put-apiformsiddata) | `saveFormData` | Save draft values | `200`, `400`, `404`, `409`, `410`, `422` |
| [`POST /api/forms/{id}/confirm`](#post-apiformsidconfirm) | `confirmForm` | Confirm the stored values | `200`, `404`, `409`, `410`, `422` |

## Operations

### GET /api/forms

`operationId: listForms` — List non-expired forms

**Parameters**

| Name | In | Required | Type | Description |
|---|---|---|---|---|
| `limit` | query | no | `integer` (≥ 1, ≤ 200) | Page size. |
| `offset` | query | no | `integer` (≥ 0) | Rows to skip. |

**Responses**

| Status | Content type | Body | Description |
|---|---|---|---|
| `200` | `application/json` | [`FormList`](#formlist) | One page of forms, newest first. |
| `422` | `application/problem+json` | [`Problem`](#problem) | Paging outside the documented range — rejected, not clamped. |

### POST /api/forms

`operationId: createForm` — Create a form

The definition is immutable after creation — changing it means delete and recreate. Validation errors inside the definition are reported with JSON Pointers re-rooted under `/definition`.

**Request body** (`application/json`, required): [`CreateFormRequest`](#createformrequest)

**Responses**

| Status | Content type | Body | Description |
|---|---|---|---|
| `201` | `application/json` | [`FormEnvelope`](#formenvelope) | Form created; `Location` points at the new resource. |
| `400` | `application/problem+json` | [`Problem`](#problem) | The request body is not valid JSON. |
| `422` | `application/problem+json` | [`Problem`](#problem) | The request envelope or the definition is not valid. |

### GET /api/forms/{id}

`operationId: getForm` — Read a form

**Parameters**

| Name | In | Required | Type | Description |
|---|---|---|---|---|
| `id` | path | yes | `string` (`uuid`) | Form id (UUID, assigned at creation). |

**Responses**

| Status | Content type | Body | Description |
|---|---|---|---|
| `200` | `application/json` | [`FormEnvelope`](#formenvelope) | The full form envelope. |
| `404` | `application/problem+json` | [`Problem`](#problem) | No form with this id. |
| `410` | `application/problem+json` | [`Problem`](#problem) | The form has expired; its data is scheduled for physical deletion. |

### DELETE /api/forms/{id}

`operationId: deleteForm` — Delete a form

The "definition changed" path — delete the form and create a new one.

**Parameters**

| Name | In | Required | Type | Description |
|---|---|---|---|---|
| `id` | path | yes | `string` (`uuid`) | Form id (UUID, assigned at creation). |

**Responses**

| Status | Content type | Body | Description |
|---|---|---|---|
| `204` | — | empty | Form deleted. |
| `404` | `application/problem+json` | [`Problem`](#problem) | No form with this id. |
| `410` | `application/problem+json` | [`Problem`](#problem) | The form has expired; its data is scheduled for physical deletion. |

### GET /api/forms/{id}/schema

`operationId: getFormDataSchema` — Read the values schema derived from the definition

The JSON Schema 2020-12 document the server validates submitted values against — shippable to a frontend validator as-is. The draft variant drops `required` (and required-driven `minLength`) so partial progress validates.

**Parameters**

| Name | In | Required | Type | Description |
|---|---|---|---|---|
| `id` | path | yes | `string` (`uuid`) | Form id (UUID, assigned at creation). |
| `mode` | query | no | `any` (`strict` \| `draft`) | Which contract the returned schema enforces. |

**Responses**

| Status | Content type | Body | Description |
|---|---|---|---|
| `200` | `application/schema+json` | [`DataSchema`](#dataschema) | The derived values schema. |
| `404` | `application/problem+json` | [`Problem`](#problem) | No form with this id. |
| `410` | `application/problem+json` | [`Problem`](#problem) | The form has expired; its data is scheduled for physical deletion. |
| `422` | `application/problem+json` | [`Problem`](#problem) | Unknown schema mode. |

### GET /api/forms/{id}/data

`operationId: getFormData` — Read the current values

**Parameters**

| Name | In | Required | Type | Description |
|---|---|---|---|---|
| `id` | path | yes | `string` (`uuid`) | Form id (UUID, assigned at creation). |

**Responses**

| Status | Content type | Body | Description |
|---|---|---|---|
| `200` | `application/json` | [`FormValues`](#formvalues) | The stored values (draft or confirmed). |
| `404` | `application/problem+json` | [`Problem`](#problem) | Unknown form, or a form that has no data yet. |
| `410` | `application/problem+json` | [`Problem`](#problem) | The form has expired; its data is scheduled for physical deletion. |

### PUT /api/forms/{id}/data

`operationId: saveFormData` — Save draft values

Repeatable; overwrites the previous draft. Values are validated against the draft variant of the derived schema — types, enums, ranges and the closed property set are enforced, `required` is not.

**Parameters**

| Name | In | Required | Type | Description |
|---|---|---|---|---|
| `id` | path | yes | `string` (`uuid`) | Form id (UUID, assigned at creation). |

**Request body** (`application/json`, required): [`FormValues`](#formvalues)

**Responses**

| Status | Content type | Body | Description |
|---|---|---|---|
| `200` | `application/json` | [`FormEnvelope`](#formenvelope) | Draft stored. |
| `400` | `application/problem+json` | [`Problem`](#problem) | The request body is not valid JSON. |
| `404` | `application/problem+json` | [`Problem`](#problem) | No form with this id. |
| `409` | `application/problem+json` | [`Problem`](#problem) | The form is confirmed and locked. |
| `410` | `application/problem+json` | [`Problem`](#problem) | The form has expired; its data is scheduled for physical deletion. |
| `422` | `application/problem+json` | [`Problem`](#problem) | The draft breaks a value contract. |

### POST /api/forms/{id}/confirm

`operationId: confirmForm` — Confirm the stored values

Validates the stored data against the full strict schema and locks the form forever. A definition containing an unknown (plugin) field type cannot be confirmed — the server will not vouch for a value contract it does not know.

**Parameters**

| Name | In | Required | Type | Description |
|---|---|---|---|---|
| `id` | path | yes | `string` (`uuid`) | Form id (UUID, assigned at creation). |

**Responses**

| Status | Content type | Body | Description |
|---|---|---|---|
| `200` | `application/json` | [`FormEnvelope`](#formenvelope) | Form confirmed and locked. |
| `404` | `application/problem+json` | [`Problem`](#problem) | No form with this id. |
| `409` | `application/problem+json` | [`Problem`](#problem) | Nothing to confirm, or already confirmed. |
| `410` | `application/problem+json` | [`Problem`](#problem) | The form has expired; its data is scheduled for physical deletion. |
| `422` | `application/problem+json` | [`Problem`](#problem) | The stored data fails the strict contract. |

## Schemas

### CreateFormRequest

Generated from the request DTO `App\Http\Request\CreateFormRequest`, which is what the mapper enforces: `expireDate` is an RFC 3339 date-time that must also lie in the future (a rule no schema keyword covers, so the app reports it as `form.expire_date.past`), and `definition` is a form definition document whose own contract is the meta-schema at `src/Domain/Forms/form-definition.schema.json`.

| Property | Type | Required | Description |
|---|---|---|---|
| `expireDate` | `string` (`date-time`) | yes |  |
| `definition` | `object` | yes |  |

No other properties are allowed.

### FormDefinition

A form definition document. Its authoritative contract is the meta-schema at `src/Domain/Forms/form-definition.schema.json` (id, title, and 1–50 typed fields with unique names) — constraints are deliberately not duplicated here.

Type: `object`

### FormValues

Submitted values keyed by field name. The per-form contract is deliberately not part of this document — it is derived from the definition and served live by `GET /api/forms/{id}/schema`.

Type: `object`

### DataSchema

A JSON Schema 2020-12 document describing one form's values.

Type: `object`

### FormStatus

Derived, never stored — empty until first save, confirmed once locked.

Type: `string` (`empty` \| `draft` \| `confirmed`)

### FormEnvelope

The canonical JSON shape of a form returned by every endpoint.

| Property | Type | Required | Description |
|---|---|---|---|
| `id` | `string` (`uuid`) | yes |  |
| `title` | `string` | yes |  |
| `status` | [`FormStatus`](#formstatus) | yes |  |
| `expireDate` | `string` (`date-time`) | yes |  |
| `createdAt` | `string` (`date-time`) | yes |  |
| `definition` | [`FormDefinition`](#formdefinition) | yes |  |
| `data` | [`FormValues`](#formvalues) or `null` | yes |  |
| `dataSavedAt` | `string \| null` (`date-time`) | yes |  |
| `confirmedAt` | `string \| null` (`date-time`) | yes |  |

No other properties are allowed.

### FormListItem

| Property | Type | Required | Description |
|---|---|---|---|
| `id` | `string` (`uuid`) | yes |  |
| `title` | `string` | yes |  |
| `status` | [`FormStatus`](#formstatus) | yes |  |
| `expireDate` | `string` (`date-time`) | yes |  |
| `createdAt` | `string` (`date-time`) | yes |  |

No other properties are allowed.

### FormList

| Property | Type | Required | Description |
|---|---|---|---|
| `items` | `array` of [`FormListItem`](#formlistitem) | yes |  |
| `limit` | `integer` | yes |  |
| `offset` | `integer` | yes |  |

No other properties are allowed.

### ProblemError

One validation error, mapped 1:1 from an ingot ErrorReport entry.

| Property | Type | Required | Description |
|---|---|---|---|
| `pointer` | `string` | yes | RFC 6901 JSON Pointer into the offending document ("" is its root). |
| `code` | `string` | yes | Machine-readable code, e.g. `schema.required` or `form.field.duplicate-name`. |
| `message` | `string` | yes |  |
| `input` | `string \| number \| boolean` | no | The offending value — echoed only when it is a scalar. |

No other properties are allowed.

### Problem

RFC 9457 problem details. `type` is a URN `urn:problem:ingot-forms:<slug>`; validation problems carry the `errors` extension member.

| Property | Type | Required | Description |
|---|---|---|---|
| `type` | `string` (`uri`) | yes |  |
| `title` | `string` | yes |  |
| `status` | `integer` | yes |  |
| `detail` | `string` | no |  |
| `errors` | `array` of [`ProblemError`](#problemerror) | no |  |

No other properties are allowed.
