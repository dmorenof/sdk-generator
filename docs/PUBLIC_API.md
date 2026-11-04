# Grouped static API — 0.2.0 development

The preferred public API groups operations by **literal structural route
prefixes**, independently of provider tags or operationId quality:

~~~php
use Casafari\GeneratedSdk\ApiV1;
use Casafari\GeneratedSdk\AlertsApi;
use Casafari\GeneratedSdk\Client;
use Casafari\GeneratedSdk\Configuration;

$config = new Configuration();
$config->baseUrl = 'https://api.casafari.com';
// Supply credentials for the security schemes the relevant operation requires.
Client::configure($config);

// The generated method names follow the route: GET /api/v1/references/types
$types = ApiV1::getReferencesTypes();
~~~

Casafari's 46 endpoints are grouped into \`ApiV1\`, \`ApiV2\`,
\`AlertsApi\`, \`MarketAnalyticsApi\`, \`PropertySourcingApiV1\`,
\`SellerLeadsApiV1\` and \`RootApi\` (for isolated top-level routes).

Naming rules:
- \`/api/v1/...\` => \`ApiV1\`, \`/api/v2/...\` => \`ApiV2\`.
- \`/v1/seller-leads/...\` => \`SellerLeadsApiV1\`.
- \`/user/{num}\` => \`UserApi::getByNum(int $num)\`; never invent \`id\`.
- Isolated one-segment endpoints => \`RootApi::postLogin()\`, etc.
- Every method has a comment with its original method/path and operationId.
- Colliding method names are deterministically disambiguated with route hashes.
- Case-insensitive identifier collisions are checked.

To customize group boundaries without editing the OpenAPI:
~~~php
$files = (new PhpExecutableEmitter(
    'Casafari\\GeneratedSdk',
    'preview/casafari-generated-sdk',
    ['/api/v1/references' => 'ReferencesApi'],
))->emit($contract);
~~~
The longest configured literal prefix wins. The routing and HTTP paths remain
unchanged. The default is deterministic and does not infer service domains.

\`Client::configure(Configuration)\` still owns the active connection, and
independent instance clients remain supported for concurrent workloads.
The previous single \`Api\`, \`StaticApi\` and instance resource facades remain
during the 0.2.0 migration; they are not the new recommended entry point.

**Planned simplification:** unify Requests/Responses/DTOs and remove legacy
duplicate facades once migration coverage is complete. For primitive typed
collections, prefer existing \`typedarray/typedarray\` classes; if functionality
is missing, evaluate a separately tested/versioned improvement to
\`dmorenof/TypedArray\` before generating equivalent helper code.

**Lifecycle:** Static state is per PHP-FPM request; it is not isolated across
concurrent fibers/coroutines in persistent workers. Use instance clients there.
