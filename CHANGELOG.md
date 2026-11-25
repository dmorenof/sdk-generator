# Changelog

This project follows semantic versioning for tagged releases. The v0.1.0 release is an **early functional milestone**, not a claim of complete OpenAPI conformity.

## [Unreleased]

- **Fix: OpenAPI 3.2 documents can use `components.pathItems`.** The references to a named path item (and the `summary`/`description` beside a reference) were accepted for `3.1.x` only; a `3.2.x` document that used them was refused with «Expected a local named pathItems reference». Found by the new `DialectVariantsTest`, which generates every OpenAPI 3.1 fixture again as 3.2 (it must give the same SDK) and as 3.0 (the same, or a refusal for what only 3.1 has).
- **Repairs of a defective document live in one place.** Reading a contract used to decide in about twenty spots, with an `if` on the repair mode, whether to refuse a defect or mend it and note it. They now ask a single `RepairPolicy` (`src/Parsing/RepairPolicy.php`): strict, it refuses with the same message as before; repairing, it records where and what, which is what `--inspect` prints and `--strict` refuses. No behavior changes: the 15 SDKs published at the time regenerate byte for byte identical, and the 688 tests pass.
- Documentation brought up to date: `pendientes.md` (SendGrid grouped into one SDK, Stripe and uDA published, the `{url_path}` question answered) and `tests/README.md` (it still described a scaffold).

## [1.15.2] — 2026-11-25

- **Generated code is indented the way a reader expects.** The formatter ran `@PSR12` only, which leaves the arrays of class constants (`REQUIRED_FIELDS`, `OPERATION_FIELDS`, `VALIDATION`...) flush with the margin and a blank line before the closing brace of every class. It now also applies `array_indentation`, `no_extra_blank_lines` (inside braces) and `no_whitespace_in_blank_line`. Only whitespace changes; the 14 SDKs of this series were regenerated with it.

## [1.15.1] — 2026-11-25

- **Fix: the branch classes of two unions with the same class name no longer get a path that repeats the whole namespace.** 1.15.0 separated them by writing the full namespace into the hint, which produced paths of over 300 characters on Stripe's contract (`src/Enums/StripeSDK/Requests/StripeSDK/Requests/…`), too long for Windows. A later union with the same class name now takes a number after the name (`Variant1ValueRequest2`). Stripe's longest path went from over 300 to 152 characters; the output of other contracts is unchanged.

## [1.15.0] — 2026-11-25

- **A placeholder that is the rest of the path keeps its slashes.** When repairing a path placeholder with no declaration whose name ends in `path` (`{url_path}`, `{full_path}`: Starlette's `path` converter, which uDA uses), the parameter is declared with reserved expansion and the operation lists it in `pathAllowReserved` (runtime 1.13.0). uDA's SDK now sends `/indicators/period/2024%20Q1` instead of `/indicators%2Fperiod…`.
- **An enum that lists a value twice is one value** (Infobip's `CARD` twice made PHP refuse the enum class), and the repair is reported. **A model whose discriminator mapping cannot be honored, an `allOf` branch that is not an object, an extension that also declares `additionalProperties`, and two unrelated declarations of one `allOf` property** no longer stop the generation of a repaired document; each is listed in the report.
- **Schema shapes of real contracts (Mailjet, Infobip, GoCardless, Marketo, NeutrinoAPI), read exactly as OpenAPI defines them:** an object with its own `properties` and an `allOf` is the conjunction of both; `{$ref, required: [...]}` is the model extended by an object that requires those fields; a reference into the inside of a model (`#/components/schemas/Query/properties/Id`) is that schema in place; a `oneOf` of scalars of different JSON types is a plain union (a parameter `limit: integer|string` is `int|string`); `type: object` beside an `allOf` or `oneOf` of objects, `type: string` beside an `allOf` of one string reference and a bare `allOf` of one non-object reference are that reference; an `allOf` of one schema and annotation-only branches is that schema; `properties` beside a `oneOf` whose every branch declares them add nothing; a base model that lists its subtypes in `oneOf` is the base of the hierarchy; limits that do not apply to a type are ignored by JSON Schema; a property redeclared in an `allOf` is the intersection (the narrower model, the limited scalar, both inline objects merged; documentation never makes two declarations differ); a schema name that starts with a digit gets an `N` (Infobip prefixes models with a hash).
- **More defects are repaired, and reported (`repaired_defect`)** in the same mode as above: a `*/*` answer with a schema is JSON; an `application/octet-stream` answer with a model schema is binary; a Swagger `in: body` model under a form `consumes` is JSON; a multipart body that is one binary string is an object with a `file` part; a free-form object in the query without style is `name[key]=value`; an array without `items` is a list of anything; `type: null` beside an `allOf` of one reference is the nullable reference; a discriminator that cannot be honored (its mapping targets models that do not extend the base) is left out; two paths that are the same URL with other placeholder names are merged; two schemas whose class names differ only in case take a number; two unrelated declarations of an `allOf` property keep the extension's. `--strict` refuses all of them.
- Published definitions generated as they are: **Mailjet** (OpenAPI 3.1, 738 files), **GoCardless** (OpenAPI 3.0, 971), **NeutrinoAPI** (122), **Marketo** (Lead Database, Asset, Identity and User Management, Swagger 2.0, 1,625), **Infobip** (the whole platform, 8,064 files), **Floorfy** (reconstructed from its documentation, 171). PHPStan at the maximum level reports no error on any of them.
- **`contentEncoding`, `contentMediaType` and `contentSchema` are annotations; `webhooks` is reported, not refused.** In JSON Schema 2020-12 the content vocabulary only describes how a string encodes data (Redsys's `Ds_MerchantParameters` is base64 of JSON): the property stays a string, sent and read as it is, with a `content_not_executed` warning. A document's `webhooks` (requests the API sends to you) have no operation in a client SDK: a `webhooks_not_executed` warning. Redsys's reconstructed contract (4 operations, JSON answers also declared as `text/plain`) generates, passes PHPStan at the maximum level and reads a JSON answer sent as `text/plain`.
- **OpenAPI 3.2 documents are read as 3.1.** Trustpilot publishes `openapi: 3.2.0`; the version was refused. 3.2 only adds to 3.1, so the document is read as 3.1 (what 3.2 adds is not executed).
- **`type: object` beside an `allOf` of objects is the plain `allOf`** (Trustpilot's `PrivateReview`), and **an extension that declares again an inline object property of its base** (`consumer` with more fields) is one object with the fields of both; a field both declare differently is still a conflict ("Conflicting allOf property").
- Trustpilot's Business Units and Reviews definitions and the Catastro OVC services (16 operations, three servers) generate and pass PHPStan at the maximum level.
- **Defective documents are repaired, not refused, and the report says so.** The generator (CLI without `--strict`, and `DocumentInspector`) mends what it can without guessing: a path placeholder with no declaration becomes a required string, a path parameter without a placeholder is dropped, a path parameter that is not required is made required, and keywords beside `oneOf`/`anyOf` (a `type: string` next to a list of objects) are ignored. Each repair is a `repaired_defect` diagnostic of severity `error`: the SDK is generated, but `--strict` and `strictlyAccepted` refuse the document. `new SpecificationNormalizer(repair: true)` is the library switch; by default it still throws. uDA's published specification (24 undeclared placeholders) and Sendbird's (three defects) now generate as published, with no copy corrected by hand.
- **Nested form data and query parameters (`style: deepObject, explode: true`) at any depth.** Stripe writes its request bodies this way (`collection_options[fields]`, `custom_fields[0][key]`, `created[gt]`, `expand[0]`): objects inside objects, lists of objects, lists of strings and unions of those, in `application/x-www-form-urlencoded` bodies, in the query and, for a field that is not a file, in `multipart/form-data` as text fields. Before, only one level of scalars was executable. The operation declares the format `nested`; the runtime writes it. **Needs `sdk-runtime` 1.13.0**: an SDK generated with this and an older runtime sends the wrong bytes. An object that says `additionalProperties: true` is still refused (its fields cannot be named); one that says nothing is built from its declared fields.
- **Two unions of the same class name in different namespaces no longer share a class for their branches** ("Generated class collision at src/Requests/Variant1ValueRequest.php", found on Stripe).
- **A path parameter that two operations of one route declare with different limits is one class without those limits.** Stripe writes `maxLength: 5000` on one verb and nothing on another for the same `{transaction}`; the generator refused the whole document ("Conflicting path parameter definitions"). The shared class keeps type, format and enum and drops length, range and pattern, so it never refuses a value an operation accepts; any other difference is still a conflict.
- Stripe's published specification (OpenAPI 3.0, 611 urlencoded bodies, 84 structured query parameters): from 1,292 errors to **supported, strictly accepted**; 13,564 files, none with a syntax error. A generated SDK sends `account=acct_1&collection_options[items][0][key]=a&expand[0]=x` as expected (checked against a recording server), and PHPStan at level max is clean on it.
- **A `type` beside a `$ref` that repeats the referenced schema's own type is the bare reference.** OpenAPI 3.1 documents write `{$ref: Status, type: string}`; SendGrid's 46 specifications do it 167 times, always with the type of the target, and every one of them was refused with "Reference siblings are not supported". It is accepted now (through any alias chain, in 3.0 and 3.1), and the SDK generated is identical to the one for the bare reference. A different `type`, a type set, a `type` beside a reference that has none, and any other sibling (`format`, `nullable`, `enum`) are still refused. Measured with `--inspect` on SendGrid's specifications as published: 13 supported before, 35 supported and 2 partial after.
- **A schema named like a PHP reserved word gets a class name that PHP accepts.** `Default` and `List` were refused ("Invalid PHP schema namespace or class segment") and stopped the whole document. The last segment now takes a suffix: the singular of its category folder at the top level (`Enums\Default` becomes `Enums\DefaultEnum`, `DTOs\List` becomes `DTOs\ListDTO`) and the singular of the folder that holds it below that (`Enums\Assets\Default` becomes `Enums\Assets\DefaultAsset`; the singular is the one the resource names already use). The same document always gives the same names (no number, no hash), a name that lands on another schema is still an explicit collision, and every rename is a `renamed_reserved_class` warning at the schema. An alias of a native type has no class and is not renamed; a composition wrapper is already prefixed; a folder that is a reserved word is still refused. Only documents that failed before are affected: no existing SDK changes its class names.
- SendGrid's 46 specifications as published: 13 generated before this release, 46 with it (43 supported, 3 partial because of warnings; 2,242 generated files, none with a syntax error). DeepL and, with its three defects corrected, Sendbird generate too.
- **Contracts as published, found with `--inspect` on SendGrid's 46 specifications, DeepL and Sendbird** (each refused before; each fixed on a minimal document first):
  - **`nullable`, `summary` and a repeated `format` beside a `$ref`.** `nullable: true` makes the property nullable; `summary` (the OpenAPI 3.1 Reference Object) is documentation; a `format` that repeats the referenced schema's, or a width hint (`float`, `int64`) beside a number, asserts nothing new. Any other sibling is still refused.
  - **An OpenAPI 3.1 keyword that JSON Schema 2020-12 does not define** (`example-1`) is an annotation: a `uninterpreted_keyword` warning, not an error. A keyword that means something and is not executed (`contentEncoding`, `$defs`, `$id`...) is still refused, and so is any unknown keyword before 3.1.
  - **An ECMA-262 pattern is translated for PCRE**: `\u0080` becomes `\x{0080}` (an escaped backslash before the letter `u` is left alone).
  - **A JSON media type with no `schema` is any JSON value** (OpenAPI: `schema` is optional).
  - **A reference inside `patternProperties`** (and the other contextual assertions) is written in place in that assertion only, with a loop or a missing target refused; the generated types do not change.
  - **`type: object` beside a `oneOf`/`anyOf` whose every branch is an object** is the bare union. A `type` that one branch contradicts (Sendbird's push template) is still refused.
  - **A union of one reference that may be null is the nullable reference.** `{anyOf: [{$ref: X}], nullable: true}` (or `oneOf`) is how OpenAPI 3.0 writes "X, or null": Stripe writes it 426 times, and each one used to be refused or, without the `nullable`, got a wrapper class of its own. It is read as `{$ref: X, nullable: true}`; a union of several branches that may be null is a nullable union. Generated output of Casafari and of SendGrid's specifications is byte for byte the same as before.
  - **A discriminator tag written as a reference to a string enum** (an enum of one value) is a string tag.
  - **A one-branch `allOf`, or one whose other branches only annotate, over a schema that is not an object** is the reference with that description (DeepL). Over an object it is still inheritance.
  - **An identical enum of a parameter on one route is one class**, as identical error bodies are: `GET` and `POST` on the same route made two copies with different names and the route's one file of path parameters conflicted (Sendbird). A different enum on the same route is still an explicit conflict.
  - **A form or multipart body that says nothing about `additionalProperties` is built from its declared fields**, in `--inspect` and in generation; one that declares itself open (`true` or a schema) is still refused.
- **`--skip-unsupported` also leaves out an operation whose form body the generator cannot build.** A `multipart/form-data` or URL-encoded body that is not an explicitly closed, flat object (Sendbird's bot message sends nested objects in an open schema) passed the normalizer and then aborted the emitter, so the option did not do what it says: it now lists the operation as skipped and generates the rest. Without the option nothing changes: the emitter still refuses the document.

## [1.14.0] — 2026-11-23

Follows sdk-runtime 1.12.0: **a call only serializes, and an answer is trusted** (decision D25 of the runtime).

- **The parameter classes of an operation get `set` hooks.** `GetParams`, `PostParams`, `PathParams`, ... check the rules of a parameter (a latitude of 91) where the value is assigned, as a model does, since the call no longer checks them. A native array cannot have a hook, so a list parameter has none and its rules are not checked at all.
- Behavior change for generated SDKs (with the runtime): a request is not validated when sent, and an answer is not validated when read. A required field missing from an answer is simply not set; a value outside a rule is returned. Only PHP's own `TypeError` and an enum's `ValueError` refuse a value, wrapped in `InvalidResponseException`.
- **Identical error bodies are one class.** Operations that declare the very same inline error body for the same status share `Responses/Errors/Status401Response` (a second, different shared body of that status is `Status401Variant2Response`), instead of one near-identical class per operation. A body only one operation declares, or that differs even by a description, keeps its operation's name. The real Casafari contract went from 79 error classes (17 distinct shapes) to 7, and from 1,001 generated files to 928. **The class names of the shared bodies change**: code that caught `Responses\Errors\Api\V1\...\PostStatus401Response` now catches `Responses\Errors\Status401Response`; `ApiException::$error` holds an instance of the shared class.
- **Found generating from real public APIs** (PokeAPI, weather.gov, Wikimedia, met.no), each of which was refused before:
  - A reference written as a one-branch `allOf` or as `oneOf: [{$ref}, {type: null}]` beside `readOnly` / `writeOnly` keeps the referenced type (the 3.1 idiom for a nullable or read-only property).
  - `required` written beside an `allOf` is read as the composition's own.
  - **A `oneOf` or `anyOf` of string enums is one enum** with every value (disjoint values for `oneOf`), which also lets a list of such values be a query parameter.
  - A model name that would split into a PHP reserved word (`PointString` beside `Point` → `Point\String`) stays whole.
  - A list of a subtype refines the list of its type in an `allOf` (a collection of alerts over a collection of features): the model stands alone, since PHP cannot narrow a property by inheritance.
  - **Responses that are not JSON are returned as bytes**: a media range (`image/*`), an XML or other media with no schema, and a Swagger `produces` list of several file types with no schema on the success response. A response that offers two JSON representations (`geo+json`, `ld+json`) beside an XML twin is chosen by `Content-Type`; two that differ only in a parameter are still a conflict.
  - Swagger 2.0 `produces` with several media types and a JSON error schema no longer refuses the operation.
- **New options.** `--skip-unsupported` leaves out, and lists on stderr, the operations the generator cannot execute instead of refusing the whole API. `--server=https://host` gives the server of a document that does not name one (Swagger without `host`, OpenAPI without `servers`).
- Needs sdk-runtime `^1.12.0` (the SDKs now require it).
- The documentation and the tests that fixed "an invalid request does not reach HTTP" and "an invalid answer is refused" now fix the new behavior.

## [1.13.0] — 2026-11-20

Found with httpbin (Swagger 2.0), whose unmodified specification now generates strictly and runs against the real server.

- **`formData` parameters on an operation that declares no `consumes`** (httpbin's `/redirect-to`): the form is URL-encoded, or multipart when a field is a file. A form with another declared media is still refused.
- `Live public APIs` also runs httpbin: status codes, a header parameter, the default `Authorization` header, a form with no `consumes`, and its redirect.

## [1.12.0] — 2026-11-20

Found with the Swagger Petstore (Swagger 2.0), whose unmodified specification now generates strictly and runs against the real server.

- **`consumes` / `produces` that start with JSON and list others** (`application/json`, `application/xml`): the SDK speaks JSON and the XML twin is not emitted, as in OpenAPI 3.x. A list that does not start with JSON is still refused.
- **An operation that lists only failures** (`addPet` declares just a 405) succeeds with any 2XX and returns nothing. An operation with no response at all is still refused.
- **A Swagger `in: body` parameter that is a list of models** is no longer reported as an unsupported structured parameter: it was always a request body, and the SDK takes a typed collection.
- `Live public APIs` also runs the Swagger Petstore (Swagger 2.0).

## [1.11.0] — 2026-11-20

Found by generating SDKs from the unmodified specifications of public APIs (Frankfurter, OpenAPI 3.1) and calling the real servers (see the `Live public APIs` workflow).

- **A default beside a one-branch `allOf` reference** (`{allOf: [{$ref: X}], default: ...}`, the way OpenAPI 3.0 puts an annotation beside a reference, and what many 3.1 documents still do) is read as the referenced schema with that default. A reference with any other assertion beside it is still refused.
- **`propertyNames` that reference a schema** (a map keyed by an enum of currency codes) are accepted: the referenced schema is written in place, as a key-name assertion on the map. A referenced schema that itself references others is refused.
- The `Live public APIs` workflow generates SDKs from the Swagger Petstore (OpenAPI 3.0) and Frankfurter (OpenAPI 3.1) specifications, checks them at PHPStan max and calls the real servers. Manual and weekly; it never gates a merge, and can run on the runtime of a branch.
- Needs sdk-runtime `^1.11.1` (the SDKs now require it): Frankfurter's `multipleOf: 0.00001` on rates such as 392.4 was refused by earlier runtimes.

## [1.10.0] — 2026-11-20

- **OpenID Connect discovery.** The client of an SDK whose contract declares an `openIdConnect` scheme carries the scheme's `openIdConnectUrl` in its metadata, and offers `Client::openIdDiscovery($scheme)` (sdk-runtime 1.9), which fetches the provider's configuration document and gives the endpoints for the OAuth2 flows. Other schemes carry no URL. Needs sdk-runtime `^1.9.0` (the SDKs now require it).

## [1.9.0] — 2026-11-19

- **`mutualTLS` security schemes (OpenAPI 3.1).** The generator accepts a `mutualTLS` scheme, emits it in the client's `SECURITY_SCHEMES`, and the audit no longer reports it as unsupported. The credential is a `ClientCertificate` (sdk-runtime 1.7): the default Guzzle transport presents it in the TLS handshake of the operations that require the scheme. In OpenAPI 3.0 and Swagger 2.0, which have no such scheme, it is still rejected; a requirement of a `mutualTLS` scheme cannot list scopes. Needs sdk-runtime `^1.7.0` (the SDKs now require it).

## [1.8.0] — 2026-11-19

- **A list of plain models is built inline.** A property whose value is a list of plain nested models gets an `assignMember()` arm that checks every item is an object and builds a model from each with `Hydrator::models()`; anything else takes the general path, unchanged. Needs sdk-runtime `^1.6.0` (the SDKs now require it).

## [1.7.0] — 2026-11-19

- **Generated models convert the common JSON members inline.** A model gets an `assignMember()` override with a `match` that sets scalar, enum, plain-nested-model and list-of-scalars properties directly when the JSON value already has the declared type, and hands every other member to the general path, so what is accepted, converted or refused does not change. Needs sdk-runtime `^1.5.0` (the SDKs now require it). About 9% less time per call on PHP 8.4 in the runtime's `Audit speed` workflow.

## [1.6.0] — 2026-11-19

- **Generated models validate where a value enters them.** Each property that has rules gets a PHP 8.4 `set` hook that checks the value when it is assigned or hydrated; nothing is walked later. Generated SDKs require PHP `^8.4` and `dmorenof/sdk-runtime` `^1.4.2` (the generator itself needs PHP 8.4 too).
- The range of `int32` is always asserted (PHP has no 32-bit integer); other formats still need `--validate-formats`.
- Internal: `PhpResourceEmitter` (12 constructor arguments) and `PhpOperationRequestEmitter` (6) now take at most 4: the parameter types travel in a `ParameterTypes` value object and what the resource emitter needs from the contract emitter in a `ResourceContext`. The generated output of all 52 fixture SDKs was byte-identical before and after.

## [1.5.0] — 2026-11-18

Generated SDKs require `dmorenof/sdk-runtime` `^1.2.0`.

- **Enum parameters.** A path, query, header or cookie parameter whose schema lists its values (strings or integers) is a generated enum, like a model field: `getByState(StateEnum::Open)`, `$params->sort = SortEnum::Desc`. The request carries the backing value (the runtime 1.2 accepts enum cases in parameters) and the enum replaces the validation rule that repeated the list. Lists of enum values (array parameters) stay lists of strings with a rule; a parameter whose schema is a nullable `anyOf` keeps its previous typing.
- Existing SDKs are not affected until regenerated; regenerating changes the argument types of operations that have enum parameters.

## [1.4.0] — 2026-11-18

Generated models are smaller and more often enums. Output changes (regenerate to get them); the runtime requirement is unchanged.

- A field whose values PHP can hold as a backed enum is an enum in more cases: an enum whose values include `null` becomes a nullable enum (`?SortByEnum`), and an enum with no declared type is an enum when all its values are strings, or all integers. Lists PHP cannot hold (fractional numbers, booleans, mixed kinds) stay plain types with a JSON Schema guard.
- Enum case names are always valid and unique: an empty value is `Empty`, a value starting with a digit gets a `Value` prefix, a value with no usable characters is `Value<n>`; before, such a value made generation fail.
- An enum-typed field no longer repeats its values as a validation rule: the type already refuses anything else. (In the Casafari CRM SDK: 23 rules fewer.)
- A model with no required fields no longer declares an empty `REQUIRED_FIELDS`; the runtime treats a missing one as empty. A subclass keeps its own, because an empty list overrides what its parent requires. Same for parameter objects. (In the Casafari CRM SDK: 83 files fewer carry it, and the SDK is 10% fewer lines.)
- Not yet: HTTP parameters (path, query, header) whose schema is an enum are still a plain string or integer with a validation rule; making them enums needs the runtime to accept enum values in parameters.

## [1.3.1] — 2026-11-18

Found by regenerating the contract of the hand-written casafari-sdk (Swagger 2.0, 14 operations, 82 schemas), which the generator used to refuse:

- A `$ref` property with `readOnly` or `writeOnly` beside it (Swashbuckle's way of marking a model-typed property) is accepted, and the property is read-only or write-only. Other siblings of a `$ref` are still refused.
- A Swagger 2 `consumes` or `produces` that lists several names for JSON (`application/json` and `text/json`, as ASP.NET tooling emits) is one JSON representation. A mix of JSON and other media types is still refused.
- The contract is now a test fixture: it must generate strictly into 14 resources.

## [1.3.0] — 2026-11-18

- Add `oauth2` and `openIdConnect` security schemes. They are validated (flows and their endpoints, `openIdConnectUrl`, Swagger 2 `flow`) and emitted like the other schemes; the runtime applies a token or a token provider as a Bearer token. Operations may now list scopes for these schemes (they stay in the contract metadata); scopes on `apiKey` or `http` schemes are still refused. The auditor no longer reports these schemes as unsupported. Endpoints and scopes are not generated into the SDK: build the provider with `OAuth2ClientCredentials` or `OAuth2AuthorizationCode`.
- Removed unreferenced code found by a sweep of `src/` (the class `PhpApiGroupingResolver` and five unused methods). No behavior change.

## [1.2.0] — 2026-11-18

`dmorenof/sdk-runtime` and this generator are on Packagist. Generated SDKs no longer declare a `repositories` entry for the runtime: `composer require` resolves it from Packagist. The generator's own `composer.json` lost that entry too. Existing SDKs generated by 1.1.x keep working; regenerate to drop the entry.

## [1.1.0] — 2026-11-18

Built on sdk-runtime 1.1.0 (generated SDKs require `^1.1.0`). Generated calls go through `self::operation(...)`, which the runtime builds once per class and route.

- Generated code targets the redesigned runtime: methods call `executeArguments(operation, path:, parameters:, body:)` and `executeBody(operation, body, path:, parameters:)`, an optional body that the caller omits goes through `executeArguments()`, and `Client::configure()` is `self::connect($configuration)`. The constraint on `dmorenof/sdk-runtime` moves to the matching 1.x release when it is published.

## [1.0.0] — 2026-11-11

First stable release, built on sdk-runtime 1.0.0 (generated SDKs require `^1.0.0`). It accumulates the former 0.2.0 development line, including typed response headers, dynamic and relative servers, streaming responses, representation alternatives and opt-in `--validate-formats`. The generated Casafari SDK is code-identical to the previous generator apart from PHPDoc.


- Emit declared response headers (`responses[*].headers`, OpenAPI and Swagger 2) as runtime `responseHeaders` metadata so `lastResponse()->headers->get()` converts and checks them by schema; `Content-Type` is ignored and the `response_headers` diagnostic now only flags headers that cannot be typed (object, nested array, type union, `content`, non-representable `collectionFormat`).
- Emit dynamic servers: operations (or path items) that declare several servers or server variables get runtime `servers` metadata (`useServer()`, `withServerVariables()`, enum-checked), and root declarations of that kind add `Client::SERVERS` so `Client::serverUrl('…', [...])` builds the base URL. A single plain server keeps the previous `serverUrl`/base URL behavior.
- Emit streaming responses: a single 2xx response whose only media type is `application/jsonl`, `application/x-ndjson`, `application/json-seq` or `text/event-stream` (item schema from `itemSchema` or `schema`; none needed for events) returns a lazy `\Generator` with `responseEncoding` `jsonl`/`json-seq`/`sse`. **Behavior change:** `text/event-stream` responses with a string schema were previously returned as text. Error statuses are never streamed; a JSON alternative or several success statuses keep the buffered value.
- Add opt-in `--validate-formats` (`new SpecificationNormalizer(validateFormats: true)`): string and integer schemas with a `format` (date-time, uuid, email, int64, ...) get a runtime `format` rule, so models, parameters and responses assert it. `binary`, `password`, `float` and `double` stay annotations; without the option nothing changes.
- Emit response representation alternatives: a single 2xx status that declares two or more decodable media types (JSON, text, binary incl. wildcards like `image/*`) gets runtime `alternatives`; the `Content-Type` picks the codec, `Accept` lists all of them and the return type is the union. **Behavior change:** JSON no longer silently wins over text/binary in the same status (return types widen). If any media type is undecodable (e.g. XML with an object schema) or is a stream/form, the previous single-JSON choice stays.
- Support relative servers (`/v2`, `v2`, `../v2`, templated like `/{env}`): operation and path servers are resolved by the runtime against the configured base URL; a relative root server leaves `Configuration::$baseUrl` empty for the application to supply. Relative servers with whitespace, query or fragment, and absolute non-HTTP(S) URLs, are still rejected.
- Add self-contained OpenAPI 3.1 assertion guards using the runtime's Opis compiler: negation, conditionals, contains/counts, dependent constraints, property names and tuple prefixes. Preserve original JSON when constructing DTOs and recheck mutable outbound values.
- Preserve schema documentation/vendor annotations and emit escaped PHPDoc for models, fields and operations, including deprecation and format annotations.
- Resolve named local Example/Link Object aliases and import fragment-file references without traversing example values or executing link expressions.
- Extend self-contained object/array type sets to native stdClass/array unions; retain complete schema guards for pattern/unevaluated assertions. Preserve complex const/enum JSON literal identity through JSON/YAML and fragment-file loading, reusing Opis JSON equality.
- Execute Swagger operation-specific HTTP(S) schemes through existing shared server routing and validate host/port/basePath without inventing missing document-origin defaults.
- Apply shared HTTP body eligibility to HEAD and 1xx/204/205/304, retain declared representation metadata, correct binary/range nullable signatures and reject TRACE request bodies.
- Represent newly supported self-contained OAS 3.1 non-object allOf and composition siblings using native PHP kinds plus the complete declarative schema. Preserve oneOf exclusivity and existing composition class APIs. Contextual references/directional guards remain explicit limits.
- Runtime coordination remains pending. No tests, analysis or release have run for this accumulated development, at the user's request. See pendientes.md for precise limits and outstanding work.

## [0.40.0] — 2026-11-10

- Expand OpenAPI server defaults and validate string defaults/enums/placeholders independently at root, Path Item and operation scope. Preserve original metadata and preference order; reuse PHP strtr, existing runtime URL policy and Guzzle URI equivalence. Existing baseUrl/serverOverrides configuration stays unchanged.
- Reject malformed path templates, missing/extra or optional path declarations, non-boolean required flags, query/fragment/control bytes and equivalent templated hierarchies. Preserve valid repeated/embedded templates and operation parameter overrides.
- Treat Paths Object extensions as annotation data through normalization, acceptance isolation and local file bundling, without interpreting their $ref/get-shaped data. Named Path Item components starting x- stay supported.
- Reuse stable runtime ^0.31.0 without runtime changes. Verify 471 tests / 3,770 assertions, GET/POST HTTP routing and configuration snapshots, and a 42nd independently installed SDK at PHPStan maximum. Relative URLs, named variable-binding APIs and dynamic server selection retain explicit limits in docs/SERVER_ROUTES.md.

## [0.39.0] — 2026-11-10

- Retain concrete MIME types with existing JSON, opaque string and PSR binary codecs. CSV/XML string bodies/responses retain bytes and constraints; PNG/PDF/SVG and other bounded binary media retain streams and binary aliases. Swagger file responses support a single explicit concrete produces MIME.
- Preserve vendor/problem +json in Accept/Content-Type while retaining JSON hydration. Distinguish codec from MIME through stable runtime ^0.31.0 metadata, including mixed success statuses and general error payloads; retain legacy SDK inference.
- Reuse existing Guzzle/PSR transport with no model parser or wrapper. Verify actual 32 MiB image upload and PDF download under PHP 16 MiB, independent installation and a 41st SDK at PHPStan maximum.
- Wildcards, incompatible representation negotiation, structured XML/CSV models, charset transcoding and binary/error-streaming limits remain explicit. See docs/MIME_MEDIA.md.

## [0.38.0] — 2026-11-10

- Emit declared error response descriptors for JSON primitive/null values, arrays/collections, maps, enums, compositions and DTOs, plus text/plain and bounded binary errors. Preserve exact/range/default selection and explicit bodyless overrides.
- Reuse stable runtime ^0.30.0 and existing hydration/validation. ApiException payload exposes general values while its DTO-only error property and legacy constructor/metadata remain compatible. Invalid decoding preserves raw HTTP status/body and the decoding cause.
- Include error media in Accept, retain direction/constraints/mutation checks and verify real HTTP including malformed JSON, invalid schemas, raw bytes and fallback precedence. Add a 40th installed SDK at PHPStan maximum and published-package smoke.
- Binary errors retain buffered HttpResponse bytes; arbitrary media, error streaming and representation negotiation remain pending. See docs/ERROR_PAYLOADS.md.

## [0.37.0] — 2026-11-10

- Resolve local object, enum, map and composition alias chains to canonical PHP types, extending existing scalar/array alias support. Emit no alias wrapper classes and prevent aliases from distorting canonical naming prefixes.
- Reuse shared chain resolution in forms/object parameters, recursive model properties, allOf inheritance and discriminator mappings. Retain original composition branches: equivalent aliases still invalidate oneOf exclusivity. Reject missing targets, pure cycles and excessive chain depth.
- Reuse the existing JSON Pointer decoder for schema references and discriminator targets, including URI fragment escapes and ~0/~1. Preserve source identities and wire names; remote/dynamic scopes and $ref validation siblings remain pending.
- Keep stable runtime ^0.29.0 without new runtime code. Verify recursive HTTP hydration, enum/query/map aliases, mutation checks, discriminators and a 39th independently installed SDK at PHPStan maximum. See docs/SCHEMA_ALIASES.md.

## [0.36.0] — 2026-11-10

- Add raw text/plain request/response strings and application/octet-stream body strings/PSR-7 streams. Preserve constraints, aliases, optional omission versus empty text and path/query/header/body separation in the existing operation DTOs.
- Select text, JSON, binary PSR responses and bodyless success codecs by existing status precedence, retaining response validation and explicit Accept media. JSON selection priority and bounded raw-schema diagnostics remain explicit.
- Reuse stable runtime ^0.29.0, Guzzle/PSR stream identity and transport rewinding. Verify 32 MiB binary upload under PHP 16 MiB, installed-package HTTP smoke and a 38th SDK at PHPStan maximum. Existing protected executor signatures remain compatible.
- Non-seekable inputs, binary constraints, arbitrary raw media and negotiation among incompatible representations remain pending. See docs/RAW_MEDIA.md.

## [0.35.0] — 2026-11-10

- Accept JSON root request bodies as primitive values, null, native arrays, existing typed collections, maps and DTOs. Resolve named array schemas/alias chains through the existing collection representation; retain supported JSON/+json media types.
- Preserve omission versus explicit JSON null across resources, static facades and combined operation parameter DTOs. Enforce required/nullability, root and nested constraints, mutation checks and readOnly request filtering before HTTP; keep query parameters named body separate.
- Reuse stable runtime ^0.28.0, existing argument mapping, nested validation and Serializer. Existing protected DTO executor signatures stay compatible. Add actual HTTP source/published smoke and a 37th installed SDK at PHPStan maximum.
- Text/binary request bodies, request representation negotiation, general nullable collection items and remaining schema compositions stay explicit pending work. See docs/REQUEST_BODIES.md.

## [0.34.0] — 2026-11-10

- Emit native primitive unions for OpenAPI 3.1 type sets and preserve boolean schemas in models, properties, array items and JSON responses. Validate original kinds and mutations through stable runtime ^0.27.0 and existing Opis guards.
- Resolve scalar alias chains across model fields, responses and supported parameter/form/content shapes without scalar DTO wrappers. Accept integral JSON numbers for PHP integers and integer-backed enums; preserve primitive union kinds.
- Reuse the canonical reserved-name check for route-derived resource classes. Reject required HTTP parameters that would otherwise vanish as null, while retaining optional omission and nullable JSON properties.
- Verify JSON/YAML, real HTTP, independent package installation and 36 generated SDKs at PHPStan maximum. Object/array type-set alternatives, general object compositions, named array aliases and primitive root request bodies remain pending. See docs/NATIVE_SCHEMAS.md.

## [0.33.0] — 2026-11-10

- Deliver a JSON Schema rules block: numeric exclusiveMinimum/exclusiveMaximum/multipleOf, object property counts, array uniqueItems and OpenAPI 3.1 scalar/null const. Normalize Swagger/OpenAPI 3.0 boolean exclusive bounds to the same runtime rules as OpenAPI 3.1 numeric bounds.
- Reuse maintained opis/json-schema through stable runtime ^0.26.1 for JSON numeric/object equality and rule evaluation. Emit model/map/collection metadata without new wrappers; preserve existing shared collection names and intersect object-count bounds across supported allOf inheritance.
- Enforce root scalar/array response constraints as well as DTO fields, maps, collections and parameter items. Constrained native enum cases reflect their valid intersection. Recheck mutations, including inherited ArrayObject exchangeArray.
- Add an independently installed SDK at PHPStan maximum (35 total) and actual HTTP validation in source and immutable-package smoke. Verify Unicode code-point counting already present. Complex const literals, arbitrary type unions/boolean schemas and remaining schema vocabularies stay explicit pending work.

## [0.32.0] — 2026-11-10

- Deliver one HTTP form block: Swagger URL-encoded csv/multi/ssv/tsv/pipes lists; OpenAPI spaceDelimited/pipeDelimited lists and flat DTO/Map form/exploded/deepObject fields; multipart JSON models, maps, nested values, repeated complex items and explicit JSON scalar parts.
- Reuse parameter expansion, request-direction model serialization and Guzzle MultipartStream through published stable runtime ^0.25.0. Preserve query/body separation, native form encoding, readOnly/JSON names and binary streams without new wrappers or executor APIs.
- Verify JSON/YAML, OpenAPI 3.0/3.1, Swagger aliases, constraints/mutations, delimiter/collision errors and real HTTP. Add two independently installed SDKs (34 total) at PHPStan maximum and a shared source/published installation smoke.
- Root open forms, undefined nested URL-encoded structures, multipart per-part headers/explode:false and multiple request representations remain outside the supported subset. Update older limits and completed GetParams/PostParams review in pendientes.md.

## [0.31.0] — 2026-11-10

- Deliver HTTP response selection as one block: OpenAPI uppercase 1XX–5XX selectors, exact/range/default precedence, default-only successes and JSON/binary/bodyless success mixtures. Swagger keeps exact codes/default and rejects ranges.
- Reuse operation maps and stable runtime ^0.24.0; emit native return unions and retain PSR-7 binary streams without a response wrapper. Preserve binary-only SDK behavior and existing executor APIs.
- Exact bodyless/untyped errors block broader fallback hydration. DTO errors retain ApiException raw status/headers/body when decoding fails. Default success applies only to unmatched 2xx; non-2xx remain errors.
- Add the 32nd independently installed SDK at PHPStan maximum and nine real HTTP scenarios to source and published-package smoke. No binary error codec, same-status MIME negotiation, typed response headers or general HEAD/205 policy is added.

## [0.30.0] — 2026-11-10

- Execute Swagger 2.0 collectionFormat ssv/tsv/pipes for primitive arrays in path and header parameters, including reusable aliases and JSON/YAML input. Emit existing declarative metadata and reuse stable runtime ^0.23.0; no new DTO wrappers or executor API.
- Preserve per-item URI encoding, raw header separators, native list validation and GET/POST query-body separation. Reject delimiter-bearing data, unsupported multi in these locations and outer header whitespace that HTTP would trim.
- Add the 31st independently installed SDK at PHPStan maximum and actual GET/POST HTTP smoke in source and published-package verification. OpenAPI collectionFormat, structured/nullable items and additional Swagger formData formats remain unsupported.

## [0.29.1] — 2026-11-10

- Declare sdk-runtime ^0.22.1 as a production dependency: parameter-content cookie validation uses its shared CookieHeader class when the generator is installed without development dependencies. Include both VCS repositories in the independent installation smoke, since Composer only reads root repositories.
- v0.29.0 remains immutable; its publication smoke identified this packaging defect.

## [0.29.0] — 2026-11-09

- Add OpenAPI single-representation parameter content: application/json in path/query/header and text/plain in path/query/header/cookie. Reuse native scalar types, DTOs, TypedArrays, Maps and shared request-direction Serializer; emit declarative operation metadata without new executor signatures or transport layers.
- Serialize once before path/query encoding; retain raw header/plain-cookie bytes, readOnly filtering, logical constraints, numeric map keys, JSON empty containers and query body/payload separation. Reject conflicting schema/style options, multiple/unsupported media and unsupported root shapes.
- Require stable runtime ^0.22.1, including the correction that sparse typed collections serialize as JSON arrays at root and nested positions. Add the 30th independently installed SDK with maximum PHPStan and actual GET/POST/scalar-path/sparse-mutation HTTP in source and immutable-package smoke. Document limits in docs/PARAMETER_CONTENT.md and pendientes.md.

## [0.28.0] — 2026-11-09

- Accept boolean allowEmptyValue on supported OpenAPI/Swagger query parameters and Swagger formData fields. Reuse existing DTO validation and serializers with a documented schema-authoritative policy: explicit empty strings remain name=, optional absent/null values are omitted and required/constraints/types are preserved.
- Keep native types, false/zero, empty collection/item behavior, GetParams/PostParams and query body/form/JSON separation unchanged. No new runtime metadata, wrappers or transport logic; stable ^0.21.0 remains sufficient.
- Verify JSON/YAML, OpenAPI 3.0/3.1, Swagger reusable declarations, supported object/multipart generation and mutation failures. Add independent OpenAPI and Swagger HTTP smokes to source and immutable-package verification (29 SDK analyses). Invalid-context and boolean-option diagnostics remain explicit; unsupported-style gate tests retain their coverage.

## [0.27.0] — 2026-11-09

- Support OpenAPI query allowReserved with declarative per-parameter metadata and stable runtime ^0.21.0. Reuse the existing serializer for primitive scalars/lists and flat DTO/Map form/deepObject objects; default/false behavior remains unchanged.
- Preserve legal reserved characters and percent triples in values while normally encoding names and security credentials. Reject raw query syntax and ambiguous structural delimiters according to documented OpenAPI application pre-encoding responsibilities.
- Verify JSON/YAML, OpenAPI 3.0/3.1, local references, GetParams/PostParams, query body/JSON/cookie/header independence, credential precedence and mutations. Add the 27th independently installed SDK with maximum PHPStan and actual GET/POST HTTP in source and published-package smoke.
- Keep unsupported-context diagnostics for Swagger, other parameter locations, content and advanced form-body encoding. Inspection gate tests now use the still-unsupported allowEmptyValue flag. See docs/RESERVED_QUERY.md.

## [0.26.1] — 2026-11-09

- Reject repeated (location, name) identities within Path Item and operation parameter lists instead of silently overwriting them, including resolved aliases and duplicates masked by an operation override. Keep valid cross-level complete replacement and inheritance unchanged.
- Report duplicate_parameter at the repeated usage with source pointers and reference chains; diagnose non-list parameter declarations. Reusable component maps may still contain alternative declarations with one identity.
- Verify JSON/YAML, Swagger 2.0 and OpenAPI 3.0/3.1, inherited empty lists, effective types/styles/constraints, GetParams/PostParams and mutation validation. Add independent real GET/POST HTTP and published duplicate-rejection smoke; stable runtime ^0.20.0 remains sufficient. See docs/PARAMETER_OVERRIDES.md.

## [0.26.0] — 2026-11-09

- Support OpenAPI scalar form cookie parameters through existing GetParams/PostParams DTOs, constraints and shared runtime execution. Preserve query body, headers and JSON payload separation without changing executor signatures.
- Add API key cookie authentication using existing security alternatives/AND selection. Stable runtime ^0.20.0 combines configured, operation and security cookies in one RFC6265 header, preserving raw authentication values and rejecting conflicts/invalid bytes before transport.
- Verify JSON/YAML, OpenAPI 3.0/3.1, reusable parameters/security aliases, defaults, mutations and actual HTTP GET/POST from an independently installed SDK. Add the 25th SDK analysis and immutable-package HTTP smoke.
- Reject structured/nullable/content cookie schemas and Swagger cookies explicitly. Document OpenAPI Appendix D delimiter incompatibilities; no cookie jar, persistence or Set-Cookie parsing is added. See docs/COOKIES.md.

## [0.25.0] — 2026-11-09

- Add OpenAPI label/matrix path scalars, primitive lists and flat typed DTO/Map objects with both explode modes and defaults. Reuse parameter support and shared runtime serialization; preserve GetParams/PostParams, query/header/JSON body separation and existing public signatures.
- Require stable runtime ^0.19.0, preserving empty-label dot segments over default Guzzle/cURL transport. Verify RFC 6570 reference examples, percent encoding, numeric object keys, empty values, invalid shapes and mutations.
- Add a 24th independently installed SDK with maximum PHPStan and immutable-package smoke; verify exact real HTTP GET/POST targets. Nested/null/unconstrained shapes, cookies and general URI templates remain outside scope. See docs/PATH_STYLES.md.

## Unreleased

No changes yet.

## [0.24.0] — 2026-11-09

- Complete flat OpenAPI form query and simple path/header object parameter families, including default/explicit styles and both explode modes. Reuse referenced/inline DTOs, typed Maps and the shared validation/directional field extractor; keep deepObject output and legacy emitter constructors/accessors compatible.
- Track parameter types by operation/location/name so equal wire names cannot mix path/header/query schemas. Preserve PathParams and GetParams/PostParams body separation, required fields, aliases, enums, readOnly filtering, false/zero and mutation validation. Require published stable runtime ^0.18.0 without new transport, wrapper or hydrator.
- Reject query expansion collisions, ambiguous header delimiters/controls and unsupported schemas/options. Verify JSON/YAML, both object expansion forms, references, maps, optional/empty behavior, mutations, twenty-three installed SDKs and real GET/POST request bytes. Update pendientes.md and docs/OBJECT_PARAMETERS.md; cookies, label/matrix, untyped maps, nesting and content remain outside this delivery.

## [0.23.0] — 2026-11-09

- Add OpenAPI simple primitive path/header arrays and Swagger CSV equivalents using the existing parameter list resolver, guard and declarative validation. Generate native array PathParams for single or composite list paths; preserve scalar signatures and the inherited two-argument executor API.
- Require stable runtime ^0.17.1 for native path item encoding and raw simple header lists, including numeric wire-name keys. Keep query/header/body locations independent, including duplicated path/query names and query named body. Reject ambiguous header commas/controls/outer whitespace, unsupported list/schema formats and ignored enum constraints; validate required nonnullable path declarations against placeholders.
- Verify JSON/YAML, dialect/default/explode equivalence, local aliases, bounds/items/mutations, both transport paths, twenty-two installed SDKs and real OpenAPI/Swagger GET/POST bytes. Reuse independent package smoke for CI/publication; update current scope and pendientes.md without claiming object/label/matrix/cookie support.

## [0.22.0] — 2026-11-09

- Emit native PHP return unions and status-specific JSON response metadata for multiple explicit successes, including reference/inline models, inheritance, nullable schemas and bodyless statuses. Give multiple inline responses distinct status names; preserve existing single-body names and deduplicate native types.
- Reuse published stable runtime ^0.16.0 to select the existing JsonHydrator by actual status without probing schemas. A bodyless 204 no longer permits an invalid empty/null 200; older SDKs retain legacy metadata behavior. Preserve typed/raw errors, GetParams/PostParams, request DTOs, serialization, directional fields and mutation validation.
- Verify equivalent Swagger/OpenAPI JSON/YAML, twenty independently installed SDKs with maximum PHPStan, and 24 real Guzzle GET/POST response scenarios. Extend immutable-publication installation with the status consumer; update pendientes.md, current version summaries and docs/RESPONSES.md. Mixed JSON/binary codecs, ranges and general property unions remain outside this change.

## [0.21.0] — 2026-11-09

- Normalize Swagger 2.0 successful type:file responses with one explicit application/octet-stream produces into the existing binary PSR contract. Resolve root/operation produces and response aliases per use, preserving compatible statuses and empty responses. Generate the same SDK as equivalent OpenAPI; reuse published stable runtime ^0.15.0 unchanged.
- Verify JSON/YAML, GET/POST query/header separation and JSON export DTOs, native bytes/multi-value headers, replay, mutation validation and raw ApiException errors. Diagnose unsupported MIME/produces alternatives, file constraints/nullability/error schemas and typed JSON errors incompatible with shared octet-stream produces.
- Reuse the installed binary download smoke for a nineteenth independent SDK, maximum PHPStan and immutable publication verification. Real Guzzle/cURL repeats four 32 MiB GET/POST downloads under PHP memory_limit=16M with an 8 MiB PHP peak and full hashes/headers. Update pendientes.md and docs/DOWNLOADS.md without claiming general streaming guarantees.

## [0.20.0] — 2026-11-08

- Emit native PSR-7 ResponseInterface returns for bounded OpenAPI 3.0/3.1 application/octet-stream string/binary successes. Preserve JSON representation priority, local response aliases, compatible binary status codes, empty responses, raw headers and original bytes without download DTOs or per-SDK transport logic.
- Require published stable runtime ^0.15.0. Preserve GET/POST query/header separation, JSON export DTOs, typed JSON errors, mutation validation and legacy custom transports. Keep unsupported binary constraints/MIME/error schemas, mixed success codecs, declared header schemas and Swagger diagnosed.
- Add JSON/YAML and behavior coverage, an eighteenth independently installed SDK with PHPStan maximum, and CI/immutable-publication download smokes. Real default Guzzle/cURL downloads verified 32 MiB files under PHP memory_limit=16M with an 8 MiB PHP peak; document disk-backed buffering and client/middleware/error limits in docs/DOWNLOADS.md.

## [0.19.0] — 2026-11-08

- Generate native list<string|Psr\Http\Message\UploadedFileInterface> properties for OpenAPI multipart binary arrays, with nullable outer fields, minItems/maxItems and binary item validation. Scope the representation to multipart body models; JSON-only collection types remain unchanged and inherited native fields retain item validation. Require published stable runtime ^0.14.0.
- Reuse the existing multipart encoder/PSR-18 stream for repeated exact wire names, per-item filenames/MIME, mixed string/PSR files and independent cursors. Preserve order, optional/null/empty behavior, query/header/body separation, mutations and explicit JSON rejection of upload objects.
- Reject array-level enum declarations in bounded form bodies, including empty enum, rather than dropping their semantics. Keep invalid Swagger 2.0 file-array forms diagnosed; only valid OpenAPI binary array schemas gain support. Reject nullable/nested/unsupported constrained items and unsupported encoding.
- Verify JSON/YAML, OpenAPI 3.0/3.1, named/inline bodies, JSON-only compatibility and inheritance. Seventeen installed SDKs pass PHPStan maximum. Runtime and an independent SDK each send two 16 MiB files twice over real cloud-local HTTP under PHP memory_limit=16M with a 4 MiB PHP peak; an independent MIME parser verifies both files. Extend CI and immutable-package installation with the file-list memory smoke.

## [0.18.0] — 2026-11-08

- Require published stable runtime ^0.13.0 for streaming multipart through the existing PSR-18 adapter. Generated classes inherit the behavior; no new per-SDK streaming implementation, transport interface or upload wrapper. Legacy custom transports continue receiving strings.
- Preserve query/header/body separation, file metadata, validation and repeat sends. The adapter rewinds seekable bodies after middleware; file parts with a shared PSR stream cursor fail before truncating a later part.
- Extend CI and immutable-release verification with independent OpenAPI/Swagger SDK consumers sending two 32 MiB bodies under PHP memory_limit=16M. Verify MultipartStream at the PSR-18 boundary and run real cloud-local HTTP against an independent MIME parser. Sixteen installed SDKs pass PHPStan maximum.
- Update pendientes.md and measured limits: streaming works for file-backed seekable uploads through the tested PSR-18/Guzzle path; strings, legacy transports, client/middleware configuration and response bodies may still use buffering. Non-seekable/file-list/nested uploads and streaming downloads remain pending.

## [0.17.0] — 2026-11-08

- Widen native binary DTO properties to string|Psr\Http\Message\UploadedFileInterface (plus null when nullable), retaining string inputs and the existing operation/transport signatures. Reuse Guzzle UploadedFile and PSR streams rather than introducing upload wrappers or factories. Require published stable runtime ^0.12.0.
- Accept uploaded filenames and MIME types for OpenAPI/Swagger multipart bodies. Omitted binary encoding.contentType permits the uploaded MIME or application/octet-stream fallback; explicitly declared MIME types remain enforced. Strings preserve fixed field-derived filenames and fallback types.
- Verify constructor hydration, required/nullable fields, original bytes, request direction, seekable stream replay, metadata/value mutations and MIME conflicts. JSON serialization rejects uploaded objects explicitly. Extend both independent installed multipart SDK smokes; verify PSR uploads through PSR-18 and real cloud-local HTTP with an independent MIME parser. Sixteen installed SDKs pass PHPStan max.
- Document readable/seekable input requirements and remaining buffering: accepting a PSR input stream does not implement streaming HTTP or bounded-memory uploads. General native schema unions, file lists and advanced encoding remain pending.

## [0.16.0] — 2026-11-08

- Normalize Swagger 2.0 multipart/form-data formData and type:file parameters into the existing closed request-body contract, using native string binary properties and the unchanged stable runtime ^0.11.1. No new transport, serializer or upload class.
- Select one explicit consumes representation with operation precedence; validate reusable declarations separately from each effective usage. Files require multipart; multipart lists require explicit collectionFormat: multi. Reject default/explicit CSV, file constraints/options that cannot be preserved, file lists, media alternatives and body/formData conflicts.
- Verify byte-identical SDK output for equivalent Swagger/OpenAPI contracts, JSON/YAML, aliases, path/operation overrides, required/optional/empty/null files, GetParams/PostParams query/header/body separation and mutations. Sixteen independently installed SDKs pass PHPStan max; real cloud-local HTTP is decoded with an independent MIME parser. Extend immutable release verification by reusing the installed multipart SDK smoke.
- Update pendientes.md and supported limits: bodies are still buffered with fixed field-derived .bin filenames; streaming, configurable filenames and advanced multipart encoding remain pending.

## [0.15.0] — 2026-11-08

- Add bounded OpenAPI 3.0/3.1 multipart/form-data bodies with text scalar fields, repeated primitive lists and binary string file properties. Reuse form schema checks, DTOs and the shared Guzzle MultipartStream runtime; no upload wrapper or MIME encoder is generated. Require stable runtime ^0.11.1.
- Declare static part MIME types and schema-field-derived .bin filenames; support binary encoding.contentType. Preserve JSON then URL-encoded precedence, inline/local referenced bodies, GetParams/PostParams separation, direction rules, empty/absent/null policies and mutation validation.
- Verify exact binary bytes and boundary matching through PSR-18 and real cloud-local HTTP with an independent MIME parser, fifteen installed SDKs at PHPStan max and an independent installed multipart SDK during release verification.
- Document buffering through the compatible string transport interface and remaining streaming/custom filename/encoding/Swagger multipart limits. Unsupported shapes and binary Unicode constraints fail explicitly.

## [0.14.0] — 2026-11-08

- Stop emitting generic operationFields/executeArguments methods in every SDK Client; inherit the shared implementation from stable runtime ^0.10.1. Generated Configuration inherits shared connection options and supplies its API-specific baseUrl.
- Keep the public parameter DTOs, signatures, per-SDK connection isolation, middleware and JSON/form/query semantics. Accounts Client+Configuration decreases from 158 to 50 generated lines; generic behavior exists once in the runtime.
- Reuse Guzzle PSR-7 query assembly and native query/form percent-encoding through the shared runtime. Preserve the OpenAPI-specific style adaptation that generic indexed query/form_params options cannot replace.
- Record the code review, measured reduction, remaining contract adaptation and user reuse/inheritance rules in docs/ARCHITECTURE_REVIEW.md and AGENTS.md. Verify inherited runtime declarations alongside all existing generated-SDK behavior and installation smoke.

## [0.13.0] — 2026-11-08

- Normalize Swagger 2.0 formData parameters into the existing URL-encoded request-body contract. Aggregate scalar fields and primitive lists, required flags, constraints, descriptions and nullable extensions; reuse the stable runtime ^0.9.0 without changing it.
- Preserve Swagger's default CSV list format and explicit csv/multi options by lowering them to form/explode encoding. Resolve reusable parameter aliases and apply operation-over-path overrides before building the body; any required form field makes the aggregate body required.
- Keep GetParams query-only when consumes is inherited without form fields. PostParams separates query, headers and the typed form body, including coincident names and a query named body. Optional/body-only forms retain the existing absent/empty/null rules.
- Audit effective referenced parameters before media checks. Require one explicit URL-encoded consumes representation, with operation-over-root precedence; reject files, multipart, unsupported formats/options and body+formData conflicts with source diagnostics.
- Verify byte-identical SDK output for equivalent Swagger/OpenAPI forms, JSON/YAML, PSR-18 and real cloud-local HTTP requests, mutations, 14 SDKs at PHPStan max and an independent installed Swagger form SDK in release verification. Update the remaining HTTP/forms limits.

## [0.12.0] — 2026-11-08

- Generate OpenAPI 3.0/3.1 application/x-www-form-urlencoded request bodies for closed object schemas with scalar fields and primitive lists. Reuse named body DTOs, generate inline bodies and preserve local requestBody references. JSON retains precedence when both representations are declared; per-call media negotiation remains pending.
- Execute per-field form encoding with default/explicit form style and explode true (repeated keys) or false (CSV); require stable runtime ^0.9.0. Encode spaces as +, literal plus/tilde/percent as data, preserve backed enums and JSON names, apply request-direction fields and revalidate mutations.
- Verify GetParams remains query-only and PostParams separates query, headers and form body even for coincident names and a query named body. Distinguish absent body, present empty form, null fields, empty strings and lists. Body-only operations reuse the original DTO.
- Extend strict inspection to supported form schemas/encoding and reject unsupported options, nested fields, unconstrained objects, nullable list items, multipart and files. Swagger formData and other form styles remain pending.
- Validate 13 SDKs at PHPStan max, exact PSR-18 URL/header/body bytes, a real HTTP POST to a cloud-local server and a separately installed form SDK in immutable-release verification. Update the HTTP/forms roadmap and actual limits.

## [0.11.0] — 2026-11-08

- Generate typed query objects for OpenAPI `style: deepObject`, `explode: true`: inline or local referenced flat scalar object schemas with explicit `additionalProperties: false` or a supported scalar value schema. Reuse named DTOs and existing typed Maps; reject undefined nested/null properties, unconstrained extras and other object styles.
- Require published runtime `^0.8.0`. Serialize JSON wire names and backed enum values, omit readOnly properties, include writeOnly properties and revalidate object/map mutations before transport. Encode brackets and values once; reject ambiguous names and query-key collisions.
- Exercise GetParams and PostParams with GET and POST, including simultaneous query objects and JSON body. Add explicit operation-body metadata so a query parameter named `body` is not sent as JSON, and reserve the actual `body` constructor field when the query DTO uses `queryBody`.
- Verify JSON/YAML and OpenAPI 3.0/3.1, reusable parameters, static and instance APIs, exact PSR-18 URLs/body bytes, required fields, enums, validation and invalid mutations. Analyse twelve installed SDKs at PHPStan max and extend immutable-release installation smoke with GET/POST query-body separation.
- HTTP forms, multipart, nested deepObject data and other pending HTTP serialization remain outside this release; record the partial getParameters/postParameters review and its remaining form work in pendientes.md.

## [0.10.0] — 2026-11-08

- Execute primitive query lists using OpenAPI `spaceDelimited` and `pipeDelimited`, with omitted or false `explode`. Reject undefined `explode: true` combinations. Support Swagger query `collectionFormat: ssv`, `tsv` and `pipes` alongside existing CSV/multi.
- Require stable runtime `^0.7.0`. Use shared serialization with RFC3986-compatible `%20`, `%09` and `%7C` delimiters, while encoding item data once. Reject data containing the format's own delimiter before transport, including mutations, because percent-decoding makes such values ambiguous.
- Verify equivalent OpenAPI 3.0/3.1 and Swagger output, JSON/YAML, reusable parameters, exact URLs through PSR-18, omission/empty-item behavior and invalid values. Analyse eleven generated SDKs at PHPStan max and exercise an independently installed delimited SDK in release verification.
- Preserve scalar, repeated-key and CSV behavior. Objects, other parameter locations, content parameters, reserved/empty-value flags and the remaining styles remain pending; update `pendientes.md` and the supported scope accordingly.

## [0.9.0] — 2026-11-07

- Emit native PHP list properties for query arrays of non-null primitive items. Validate list shape, item types, supported item constraints and array bounds during construction and after mutations, before transport.
- Execute OpenAPI `style: form` with repeated keys (`explode: true`, the default) or CSV (`explode: false`). Execute Swagger 2.0 `collectionFormat: multi` and `csv`, preserving its different implicit CSV default. Encode individual values without confusing embedded commas with CSV separators.
- Require published stable runtime `^0.6.0`; serialization remains shared by all generated operations. Omit null/absent parameters and empty lists; preserve empty string items and scalar behavior, including API-key precedence.
- Verify exact URLs with fake transports and PSR-18, JSON/YAML input, OpenAPI/Swagger output equivalence, invalid values and mutations. Analyse ten generated SDKs at PHPStan max and verify a separately installed query SDK in the publication smoke.
- Other array locations, objects, nullable/nested items, content parameters and additional styles/flags remain unsupported and diagnosed. See `docs/QUERY_ARRAYS.md`; P0 serialization remains partial.

## [0.8.0] — 2026-11-06

- Resolve local OpenAPI 3.0/3.1 `components/securitySchemes` Reference Objects through the shared resolver, including alias chains and existing annotation/cycle/missing-target rules.
- Keep the declaring security-scheme name as the public credential key. Root/operation inheritance, empty overrides and AND/OR requirement selection remain unchanged; base-definition credentials do not replace credentials required under an alias.
- Audit the resolved authentication definition at its actual source pointer with the complete reference chain. Unsupported OAuth, scopes and cookie keys remain unsupported and cannot be enabled by sibling overrides.
- Verify Bearer/header-key AND, query-key OR fallback, Basic auth, missing credentials, split credential names, JSON/YAML and inline/reference SDK equivalence. Add a ninth PHPStan-max generated SDK and independently install/execute an alias-authenticated SDK in release smoke.
- Keep runtime `^0.5.0`. Swagger 2.0 security-definition references and file/network security-scheme imports are not added by this local-reference release.

## [0.7.0] — 2026-11-06

- Add opt-in local JSON/YAML file graphs through `--resolve-local-references`, `--reference-root=...`, `SpecificationNormalizer::fromFile()`, `DocumentInspector::inspectFile()` and acceptance file APIs. String-based APIs retain their in-memory behavior; HTTP(S), absolute paths and URI streams are not fetched.
- Bundle supported external schema and HTTP fragments into the existing canonical pipeline. Preserve public root schema names, collapse pure schema aliases, keep recursive schema identity and assign deterministic names to colliding imported definitions. Equivalent split/consolidated Accounts and explicitly mapped Pet models emit identical SDK code.
- Cache canonical source files and fingerprint the original root, each dependency and the materialized bundle. Preserve original file/JSON Pointer diagnostics and qualify cross-file reference chains. Acceptance root hash pins are checked before reading dependencies.
- Confine real paths and symlinks to a configurable root; bound source counts/bytes, decoded payloads, graph depth and alias chains. Reject unsupported resource scopes, mixed dialects and schema alias siblings rather than interpreting them silently.
- Keep initial limits explicit: multi-document discriminators require explicit mapping; HTTP fragments from another complete API document require future server/security namespace handling; external Path Items require OAS 3.1. Existing schema/HTTP subset limits remain. Runtime stays stable `^0.5.0`.
- Verify an eight-file SDK through independent Composer installation, mock HTTP, PHPStan max and the installed published CLI. Update the roadmap with the completed local file slice and remaining general-reference work.

## [0.6.0] — 2026-11-06

- Resolve local Path Item references to `#/paths/...` in Swagger/OpenAPI and named `components/pathItems` in OpenAPI 3.1. Generate and count their inherited operations, parameters and servers; retain existing operation ID collision checks.
- Treat Path Item `$ref` as a fixed field with independent siblings. Combine disjoint fields and reject overlapping definitions explicitly, including overlapping annotations; apply neither Reference Object sibling ignoring nor implicit operation/parameter overrides to this undefined case.
- Preserve per-field pointers and alias chains through mixed local/inherited operations and isolated acceptance. Report unresolved path counts instead of accepting unresolved routes as an empty SDK; bound alias and recursive HTTP-metadata graphs.
- Resolve local OpenAPI Header Object references in response and media-encoding metadata. Header exposure/typing in the runtime and media encoding execution remain pending and continue to fail strict acceptance when applicable. Invalid references in these newly normalized positions fail visibly.
- Verify inline/referenced contract and SDK equivalence, JSON/YAML, HTTP mock execution and installed-package checks. Release smoke now generates Accounts and server-routing SDKs from Path Item references and verifies resolved header metadata. Keep stable runtime `^0.5.0`; external documents, arbitrary target locations and general JSON Schema reference rules remain pending.

## [0.5.0] — 2026-11-06

- Resolve document-local named OpenAPI `components/parameters`, `requestBodies` and `responses`, including alias chains, path/operation parameter overrides and escaped/URI-encoded pointer tokens. Resolve Swagger 2.0 reusable parameters (including body parameters) and responses.
- Share resolution between normalization and inspection; preserve declaration pointers, operation identity and complete alias chains. Strict normalization reports unresolved/invalid/cyclic references before parsing the executable contract. Alias chains are bounded to 128 hops; recursive schema identity is preserved.
- Apply OpenAPI 3.0/Swagger Reference Object sibling rules and OpenAPI 3.1 description/summary overrides without replacing target validation or required fields. These rules apply to the supported HTTP Reference Objects, not arbitrary JSON Schema `$ref` siblings.
- Keep referenced schema dependencies during isolated operation acceptance and distinguish literal example/link payloads from schema references. Verify equivalent inline/referenced SDK output, JSON/YAML input, HTTP execution and a clean installed-package smoke; analyse a sixth generated SDK at PHPStan max.
- Keep stable runtime `^0.5.0`. Headers, Path Items, external documents and full JSON Schema/dialect reference resolution remain pending; supported references do not add new HTTP serializers or media types.

## [0.4.1] — 2026-11-06

- Close inspection gaps in reusable OpenAPI parameters, request bodies, responses, headers, links and Path Items, plus Swagger reusable parameters/responses and header schemas. Strict mode now rejects detected unsupported options in declarations even when they are not used by an operation.
- Audit nested media encoding headers and callback operations; localize unsupported security schemes, scopes and undefined requirements. Preserve escaped source pointers, operation identity and bounded local reference chains.
- Keep literal examples and link expression/value payloads separate from schemas and references. Inspection remains offline; these diagnostics do not implement general reference resolution, incoming callbacks, OAuth or additional HTTP serializers.
- Verify the nested audit from a clean installation of the published generator. Continue development on the existing `release/0.2.0` branch rather than creating feature branches.

## [0.4.0] — 2026-11-05

- Execute literal absolute HTTP(S) operation servers with operation > path > root precedence in the shared runtime. Preserve configured root URLs, declared server path prefixes and encoded parameters; support per-connection `serverOverrides` through instance clients and static configuration.
- All 46 pinned Casafari operations pass strict acceptance; seven HTTP mock regressions verify the actual generated methods route to platform.casafari.com with their declared authentication.
- Require stable runtime `^0.5.0`, replacing the temporary branch dependency. Relative/template servers, variable expansion and dynamic server selection remain pending.
- Add in-memory `--inspect` / `--dry-run` compatibility reporting and an opt-in `--strict` generation gate before output is created.
- Audit previously ignored HTTP options with deterministic source/operation JSON Pointer diagnostics and local reference chains. Compatibility generation now prints diagnostics instead of silently ignoring known unsupported options.
- Preserve explicit parameter serialization options, content representations/encoding, effective server metadata, response headers/links and annotations in the intermediate contract. Keep server preference order and the declared Swagger JSON media type.
- Apply the strict HTTP audit to acceptance reports (`compatibility_failed`) and expose structured strict normalization errors. Operation isolation retains Path Item metadata and discriminator subtype closures instead of hiding server overrides or removing valid subtypes.
- The completed P0 contract/diagnostic and routing features are published in this version; unsupported serializers, relative/template servers, full reference resolution and transport formats remain pending. See `docs/CONTRACT_INSPECTION.md`.

## [0.3.0] — 2026-11-05

- Fix the Composer-installed generator binary to use the consumer autoloader supplied by Composer, while retaining checkout execution.
- Generate native PHP inheritance for supported `allOf` models with a single referenced object parent and compatible inline object extensions. Keep inherited property types (including enums), required fields, validation, JSON names and directional metadata across multiple levels. Only classes used as parents lose `final`.
- Reject object `allOf` extensions that introduce properties forbidden by a branch's `additionalProperties: false` instead of silently flattening away that restriction.
- Parse local explicit/implicit discriminator mappings and emit shared runtime metadata for base-model and composition hydration. Scalar enum intersections refine inherited fields without redeclaring their PHP type.
- Evaluate original composition schemas before hydrating; discriminator selection cannot hide `oneOf` ambiguity. Preserve independent JSON snapshots and serialize/revalidate a selected variant after mutation.
- Require stable `sdk-runtime` `^0.4.0`, replacing the temporary development branch. This release is coordinated with runtime v0.4.0 through runtime PR #6 and generator PR #19.
- Native composition unions, `not`, `const`, arbitrary inherited-property refinements and general JSON Schema remain pending; see `pendientes.md` and `docs/SUPPORTED_SCOPE.md`.

## [0.2.0] — 2026-11-05

### Consolidation

- Require stable `dmorenof/sdk-runtime` ^0.3.1, replacing the temporary feature branch.
- Use a single DTO base class for generated data; keep operation-specific inline `Requests` and `Responses` separated by HTTP verb and route, preserving reusable named `DTOs`.
- Generate and verify PHP sources in PSR-12 via build-time PHP-CS-Fixer.
- Accept multiple advertised media types when compatible JSON representations share a schema.
- Verify the entire public FakeRESTApi OpenAPI specification can generate a Composer package and execute GET and POST calls against the live synthetic test service.
- Generate and validate 46 Casafari operations offline, without credentials; production migration remains separate.

### Changed

- Generated SDKs no longer embed their own `Core`, `Transport` and `Support` classes (about 1,300 lines each). They depend on the shared `dmorenof/sdk-runtime` package, which owns hydration, validation, security, PSR-18 transport and operation execution; the generated `Client` only declares its `SECURITY_SCHEMES`.
- Reconstruct schema hierarchy from `__` identifiers, retain numeric variants, and preserve original reference identity instead of replacing single-use components with field-derived names. Reject naming collisions explicitly.
- Replace the global generated `CasafariSDK` connection and static operations with independent instance clients, inherited construction, and explicit resource sharing through `Client::resource()`.
- Reuse equivalent collection classes; simplify finite enum `anyOf` unions; give generated ranges and inline HTTP errors readable names.
- Share type conversion across DTOs, maps, union branches and root responses; fix union items inside DTO collections.
- Cache reflection types by class/field, serialize after one recursive validation, and emit endpoint configuration as class constants. Add isolation, round-trip, mutation and CPU/memory regression probes.

### Added

- Bounded `allOf`, `anyOf`, and `oneOf` support: compatible object intersections, generated composition wrappers, and shared runtime matching.
- Preserve schema defaults as metadata, support type-less values as `mixed`, validate PCRE-compatible patterns, and filter `readOnly`/`writeOnly` properties by request/response direction.
- Generate HTTP Basic/Bearer and header/query API-key authentication from OpenAPI schemes, preserving inherited/overridden security requirements and their AND/OR semantics.
- Preserve supported `$ref` title/description annotations and accept single JSON-suffix media types such as `application/problem+json`.
- Support inline string/integer enums on primitive HTTP parameters and validate request values against their declared members.
- Generate operation-derived types for inline object/array success responses and inline object errors; support one body-bearing success response alongside no-content success statuses.

### Limitations

- Composition discriminators, external references, and unsupported branch/sibling forms remain rejected.

## [0.1.0] — 2026-10-20

### Added

- YAML input support for Swagger 2.0 and OpenAPI 3.0/3.1 through Symfony YAML, using the existing normalization and emission pipeline.
- One typed canonical contract model and equivalent synthetic Swagger 2.0, OpenAPI 3.0 and OpenAPI 3.1 normalization fixtures.
- Deterministic PHP emission of models/DTOs, native typed fields, backed enums, TypedArray-backed collections and executable typed operation methods.
- Standalone generated Composer packages, build-time CLI and generated JSON hydration.
- PSR-18 bridge with Guzzle HTTP default and an optional injected client/headers; ordinary API calls do not require manually configuring the HTTP transport.
- GitHub Actions CI for PHP 8.3, 8.4 and 8.5, preview packages downloadable without local PHP, and comparisons with OpenAPI Generator using equivalent synthetic inputs and fake HTTP.

### Known limitations

- A **subset** of JSON/YAML Swagger/OpenAPI only; no complete security schema or general composition/parameter/media-type support.
- Outbound required-field and schema-constraint validation is not complete; generated typed PHP does not replace full input validation.
- Synthetic fixtures and offline HTTP only: no production API compatibility claim or general performance superiority claim.
- Shared sdk-runtime prototype is not a released dependency of generated packages.

See docs/SUPPORTED_SCOPE.md and README.md before production evaluation.
