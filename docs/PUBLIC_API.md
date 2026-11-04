# SDK root and route-grouped APIs — 0.2.0 development

Each generated SDK has an abstract class named from **the exact OpenAPI
\`info.title\`**, transliterated to Latin ASCII and converted to PascalCase.
No semantic words are discarded. Append \`SDK\` unless already present.

| OpenAPI info.title | Generated root |
| --- | --- |
| Facturas Guays | FacturasGuaysSDK |
| Casafari API | CasafariAPISDK |
| Stripe API | StripeAPISDK |
| Example Service | ExampleServiceSDK |

Every operation group extends that root:
~~~php
use Casafari\GeneratedSdk\CasafariAPISDK;
use Casafari\GeneratedSdk\ApiV1;
use Casafari\GeneratedSdk\AlertsApi;
use Casafari\GeneratedSdk\Configuration;

$config = new Configuration();
$config->baseUrl = 'https://api.casafari.com';
// Assign typed security credentials for the OpenAPI schemes in use.
CasafariAPISDK::configure($config);

$types = ApiV1::getReferencesTypes();
~~~

The abstract root delegates configuration and connection lookup to the
generated \`Client\`; it never duplicates HTTP, hydration or transport code.
\`Client::configure($config)\` remains supported. The connection belongs to
the generated Client, so all groups in a SDK share one connection.
Independent instance clients are still available for concurrent workloads.

## Route-group naming

- \`/api/facturas\` => \`Api::getFacturas()\` (or postFacturas).
- \`/revenue/\` => \`RevenueApi::get()\`.
- \`/api/v1/references/types\` => \`ApiV1::getReferencesTypes()\`.
- \`/user/{num}\` => \`UserApi::getByNum(int $num)\`; no invented \`id\`.
- \`/v1/seller-leads/{lead_id}\` => \`SellerLeadsApiV1::getByLeadId(...)\`.
- Isolated top-level routes are their own groups, e.g. \`LoginApi\`,
  \`RefreshTokenApi\`. Root path \`/\` uses \`RootApi\`.
- Every operation records original HTTP method, route and operationId in its
  source comment; wire names are untouched.
- Collisions are checked case-insensitively. Distinct route group prefixes
  that normalize to the same class are rejected; colliding method names
  are deterministically disambiguated with a route hash.

Group boundaries can be overridden without changing the contract:
~~~php
$files = (new PhpExecutableEmitter(
    'Casafari\\GeneratedSdk',
    'preview/casafari-generated-sdk',
    ['/api/v1/references' => 'ReferencesApi'],
))->emit($contract);
~~~

## Unicode and wire compatibility

The generator depends on PHP \`ext-intl\` and ICU's
\`Any-Latin; Latin-ASCII\` transliteration. The common naming policy applies
to generated PHP class, API method, model, property, enum and parameter names.
Transliteration is not translation. The original OpenAPI names always remain
the keys used for requests/responses and path substitution.
Unsupported Unicode characters that cannot be transliterated to Latin ASCII
cause explicit errors rather than silently disappearing.

ICU data/version can affect spelling; pin the build environment for fully
reproducible SDK builds. The generated SDK has no \`ext-intl\` dependency.

## Transitional state

The old **flat all-operations** \`Api\` facade is no longer generated. \`Api\`
now represents only the structural group for unversioned \`/api/...\` paths.
Instance resources and \`StaticApi\` are temporarily retained for migration;
unifying schema-model categories, error and security ergonomics, typed filters
and TypedArray reuse are separate follow-up stages.

The static connection is shared within one PHP execution scope; it is not
fiber-/coroutine-local in persistent servers. Use instance clients for
concurrent independent connections.
