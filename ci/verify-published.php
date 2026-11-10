<?php
declare(strict_types=1);

function runPublished(array $command, ?string $directory = null): void
{
    $previous = getcwd();
    if ($directory !== null) {
        chdir($directory);
    }
    passthru(implode(' ', array_map('escapeshellarg', $command)), $status);
    if ($directory !== null) {
        chdir($previous);
    }
    if ($status !== 0) {
        throw new RuntimeException('Published package smoke command failed.');
    }
}
$tag = $argv[1] ?? throw new RuntimeException('Missing release tag.');
$expected = $argv[2] ?? throw new RuntimeException('Missing expected commit.');
$directory = sys_get_temp_dir() . '/generator-published-' . bin2hex(random_bytes(8));
mkdir($directory, 0700, true);
file_put_contents($directory . '/composer.json', json_encode([
    'name' => 'example/published-generator-smoke',
    'repositories' => [
        ['type' => 'vcs', 'url' => 'https://github.com/dmorenof/sdk-generator', 'no-api' => true],
        ['type' => 'vcs', 'url' => 'https://github.com/dmorenof/sdk-runtime', 'no-api' => true],
    ],
    'require' => ['dmorenof/sdk-generator' => ltrim($tag, 'v')],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $directory]);
require $directory . '/vendor/autoload.php';
if (\Composer\InstalledVersions::getReference('dmorenof/sdk-generator') !== $expected) {
    throw new RuntimeException('Installed generator does not match the immutable release commit.');
}
$package = $directory . '/vendor/dmorenof/sdk-generator';
$binary = $directory . '/vendor/bin/generate-sdk.php';
$accounts = $directory . '/accounts';
runPublished([PHP_BINARY, $binary, '--strict', '--resolve-local-references', $package . '/tests/fixtures/multifile-accounts/api.json', $accounts, 'Preview\\AccountsSdk', 'example/published-accounts']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $accounts]);
runPublished([PHP_BINARY, $package . '/tests/preview-smoke.php', 'accounts'], $accounts);
echo "PUBLISHED_LOCAL_REFERENCES_OK", PHP_EOL;
echo "PUBLISHED_PATH_ITEM_REFERENCES_OK", PHP_EOL;
echo "PUBLISHED_MULTIFILE_REFERENCES_OK", PHP_EOL;
$fileReport = (new \Dmorenof\SdkGenerator\Diagnostics\DocumentInspector())->inspectFile($package . '/tests/fixtures/multifile-accounts/api.json');
if (!$fileReport['strictlyAccepted'] || count($fileReport['sources'] ?? []) !== 8
    || $fileReport['sourceSha256'] !== hash_file('sha256', $package . '/tests/fixtures/multifile-accounts/api.json')) {
    throw new RuntimeException('Published generator did not fingerprint its local file graph.');
}
$servers = $directory . '/servers';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/operation-servers-path-items-openapi31.json', $servers, 'Example\\PublishedServers', 'example/published-servers']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $servers]);
file_put_contents($directory . '/server-consumer.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/servers/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator')
    || str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev')) {
    throw new RuntimeException('Generated SDK must run independently with a stable runtime.');
}
if (!(new \Example\PublishedServers\Configuration()) instanceof \Dmorenof\SdkRuntime\Configuration
    || (new ReflectionMethod(\Example\PublishedServers\Client::class, 'executeArguments'))->getDeclaringClass()->getName()
        !== \Dmorenof\SdkRuntime\ApiClient::class) {
    throw new RuntimeException('Published SDK must inherit shared configuration and execution.');
}
echo "PUBLISHED_INHERITED_EXECUTOR_OK", PHP_EOL;
$transport = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
    public array $urls = [];
    public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse
    {
        $this->urls[] = $url;
        return new \Dmorenof\SdkRuntime\Transport\HttpResponse(204, '');
    }
};
$client = \Example\PublishedServers\Client::connect('https://configured.test/v9', transport: $transport,
    serverOverrides: ['https://operation.example.test/v3' => 'https://staging.test/v8']);
$items = $client->resource(\Example\PublishedServers\Resources\Items::class);
$items->getById('a/b');
$items->postById('id');
$client->resource(\Example\PublishedServers\Resources\Root::class)->get();
if ($transport->urls !== ['https://staging.test/v8/items/a%2Fb', 'https://path.example.test/v2/items/id', 'https://configured.test/v9/root']) {
    throw new RuntimeException('Published SDK operation server routing failed.');
}
echo "PUBLISHED_SERVER_ROUTING_OK", PHP_EOL;
PHP);
runPublished([PHP_BINARY, $directory . '/server-consumer.php']);
runPublished([PHP_BINARY, $binary, '--inspect', $package . '/tests/fixtures/casafari-openapi31.json']);
$nested = json_decode(file_get_contents($package . '/tests/fixtures/accounts-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
$nested['components']['parameters']['Filter/~'] = [
    'name' => 'filter', 'in' => 'query', 'schema' => ['type' => 'string'], 'style' => 'deepObject',
];
$report = (new \Dmorenof\SdkGenerator\Diagnostics\DocumentInspector())->inspect(json_encode($nested, JSON_THROW_ON_ERROR));
$issues = array_values(array_filter($report['diagnostics'], static fn ($issue): bool => $issue->code === 'parameter_style'));
if ($report['strictlyAccepted'] || !$report['generatable']
    || ($issues[0]->pointer ?? null) !== '/components/parameters/Filter~1~0/style') {
    throw new RuntimeException('Published generator did not audit reusable parameter declarations.');
}
echo "PUBLISHED_NESTED_AUDIT_OK", PHP_EOL;
$headerDocument = json_decode(file_get_contents($package . '/tests/fixtures/accounts-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
$headerDocument['components']['headers']['Page'] = ['schema' => ['type' => 'integer']];
$headerDocument['paths']['/users/{id}']['get']['responses']['200']['headers']['X-Page'] = ['$ref' => '#/components/headers/Page'];
$headerContract = (new \Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer())->fromJson(json_encode($headerDocument, JSON_THROW_ON_ERROR));
if (($headerContract->operations[1]->responses[0]->headers['X-Page']['schema']['type'] ?? null) !== 'integer') {
    throw new RuntimeException('Published generator did not resolve header metadata.');
}
echo "PUBLISHED_HEADER_REFERENCES_OK", PHP_EOL;
$security = $directory . '/security';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/accounts-security-references-openapi31.json',
    $security, 'Example\\PublishedSecurity', 'example/published-security']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $security]);
file_put_contents($directory . '/security-consumer.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/security/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator')) {
    throw new RuntimeException('Security SDK must run independently.');
}
$transport = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
    public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse
    {
        if ($method !== 'GET' || $url !== 'https://api.example.test/v1/users/88'
            || ($headers['Authorization'] ?? null) !== 'Bearer alias-token'
            || ($headers['X-API-Key'] ?? null) !== 'alias-key') {
            throw new RuntimeException('Published security aliases did not preserve credential names or headers.');
        }
        return new \Dmorenof\SdkRuntime\Transport\HttpResponse(200, '{"id":88,"name":"Ada"}');
    }
};
$client = \Example\PublishedSecurity\Client::connect('https://api.example.test/v1', transport: $transport,
    securityCredentials: new \Dmorenof\SdkRuntime\Security\SecurityCredentials(['Bearer' => 'alias-token', 'HeaderKey' => 'alias-key']));
if ($client->resource(\Example\PublishedSecurity\Resources\Users::class)->getById(88)->id !== 88) {
    throw new RuntimeException('Published security SDK hydration failed.');
}
echo "PUBLISHED_SECURITY_REFERENCES_OK", PHP_EOL;
PHP);
runPublished([PHP_BINARY, $directory . '/security-consumer.php']);
$query = $directory . '/query';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/query-arrays-openapi31.json',
    $query, 'Example\\PublishedQuery', 'example/published-query']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $query]);
file_put_contents($directory . '/query-consumer.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/query/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator')
    || str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev')) {
    throw new RuntimeException('Query SDK must use only a stable independent runtime.');
}
$transport = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
    public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse
    {
        if ($url !== 'https://api.example.test/v1/items?codes=a%2Cb,x%20y&flags=true&flags=false&ids=1&ids=2') {
            throw new RuntimeException('Installed query list SDK produced the wrong URL.');
        }
        return new \Dmorenof\SdkRuntime\Transport\HttpResponse(204, '');
    }
};
$client = \Example\PublishedQuery\Client::connect('https://api.example.test/v1', transport: $transport);
$params = new \Example\PublishedQuery\GetParams\Items((object) ['ids' => [1, 2], 'codes' => ['a,b', 'x y'], 'flags' => [true, false]]);
$client->resource(\Example\PublishedQuery\Resources\Items::class)->get($params);
echo "PUBLISHED_QUERY_ARRAY_SDK_OK", PHP_EOL;
PHP);
runPublished([PHP_BINARY, $directory . '/query-consumer.php']);
$delimited = $directory . '/delimited';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/query-delimited-swagger2.json',
    $delimited, 'Example\\PublishedDelimited', 'example/published-delimited']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $delimited]);
file_put_contents($directory . '/delimited-consumer.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/delimited/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator')
    || str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev')) {
    throw new RuntimeException('Delimited SDK must run independently with a stable runtime.');
}
$transport = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
    public int $calls = 0;
    public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse
    {
        if ($url !== 'https://api.example.test/v1/items?codes=a%2Cb%7C%C3%B1%2B%26&ids=1%202') {
            throw new RuntimeException('Installed delimited SDK produced the wrong URL.');
        }
        ++$this->calls;
        return new \Dmorenof\SdkRuntime\Transport\HttpResponse(204, '');
    }
};
$client = \Example\PublishedDelimited\Client::connect('https://api.example.test/v1', transport: $transport);
$params = new \Example\PublishedDelimited\GetParams\Items((object) ['ids' => [1, 2], 'codes' => ['a,b', 'ñ+&']]);
$resource = $client->resource(\Example\PublishedDelimited\Resources\Items::class);
$resource->get($params);
$params->codes[] = 'a|b';
try {
    $resource->get($params);
    throw new RuntimeException('Installed SDK accepted an ambiguous delimiter.');
} catch (UnexpectedValueException $exception) {
    if ($transport->calls !== 1 || !str_contains($exception->getMessage(), 'list delimiter')) {
        throw new RuntimeException('Installed SDK mutation validation failed.', previous: $exception);
    }
}
echo "PUBLISHED_DELIMITED_QUERY_SDK_OK", PHP_EOL;
PHP);
runPublished([PHP_BINARY, $directory . '/delimited-consumer.php']);
$objects = $directory . '/objects';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/query-objects-openapi31.json',
    $objects, 'Example\\PublishedObjects', 'example/published-objects']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $objects]);
file_put_contents($directory . '/objects-consumer.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/objects/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator')
    || str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev')) {
    throw new RuntimeException('Object SDK must run independently with a stable runtime.');
}
$transport = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
    public array $calls = [];
    public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body');
        return new \Dmorenof\SdkRuntime\Transport\HttpResponse(204, '');
    }
};
$client = \Example\PublishedObjects\Client::connect('https://api.example.test/v1', transport: $transport);
$resource = $client->resource(\Example\PublishedObjects\Resources\Items::class);
$resource->get(new \Example\PublishedObjects\GetParams\Items((object) ['body' => (object) ['city-name' => 'Madrid']]));
$params = new \Example\PublishedObjects\PostParams\Items((object) [
    'queryBody' => (object) ['city-name' => 'Barcelona'],
    'filter' => (object) ['city-name' => 'Madrid'], 'body' => (object) ['filter' => 'json'],
]);
$resource->post($params);
if ($transport->calls[0]['url'] !== 'https://api.example.test/v1/items?body%5Bcity-name%5D=Madrid'
    || $transport->calls[0]['body'] !== null
    || $transport->calls[1]['url'] !== 'https://api.example.test/v1/items?body%5Bcity-name%5D=Barcelona&filter%5Bcity-name%5D=Madrid'
    || $transport->calls[1]['body'] !== '{"filter":"json"}'
    || $transport->calls[1]['headers']['Content-Type'] !== 'application/json') {
    throw new RuntimeException('Installed SDK confused query objects with request body.');
}
$params->filter->cityName = '';
try {
    $resource->post($params);
    throw new RuntimeException('Installed SDK accepted invalid query object mutation.');
} catch (UnexpectedValueException) {
    if (count($transport->calls) !== 2) {
        throw new RuntimeException('Invalid query mutation reached transport.');
    }
}
echo "PUBLISHED_DEEP_OBJECT_SDK_OK", PHP_EOL;
PHP);
runPublished([PHP_BINARY, $directory . '/objects-consumer.php']);
$forms = $directory . '/forms';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/forms-openapi31.json',
    $forms, 'Example\\PublishedForms', 'example/published-forms']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $forms]);
file_put_contents($directory . '/forms-consumer.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/forms/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator')
    || str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev')) {
    throw new RuntimeException('Form SDK must run independently with a stable runtime.');
}
$transport = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
    public array $calls = [];
    public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body');
        return new \Dmorenof\SdkRuntime\Transport\HttpResponse(204, '');
    }
};
$client = \Example\PublishedForms\Client::connect('https://api.example.test/v1', transport: $transport);
$items = $client->resource(\Example\PublishedForms\Resources\Items::class);
$items->get(new \Example\PublishedForms\GetParams\Items((object) ['name' => 'query name']));
$params = new \Example\PublishedForms\PostParams\Items((object) [
    'name' => 'query name', 'queryBody' => 'query-body', 'X-Trace' => 'trace',
    'body' => (object) ['name' => 'body name', 'codes' => ['a,b', 'x y'], 'ids' => [1, 2]],
]);
$items->post($params);
if ($transport->calls[0]['url'] !== 'https://api.example.test/v1/items?name=query%20name'
    || $transport->calls[0]['body'] !== null
    || $transport->calls[1]['url'] !== 'https://api.example.test/v1/items?body=query-body&name=query%20name'
    || $transport->calls[1]['body'] !== 'codes=a%2Cb,x+y&ids=1&ids=2&name=body+name'
    || $transport->calls[1]['headers']['Content-Type'] !== 'application/x-www-form-urlencoded'
    || $transport->calls[1]['headers']['X-Trace'] !== 'trace') {
    throw new RuntimeException('Published GetParams/PostParams form serialization failed.');
}
$params->body->name = '';
try {
    $items->post($params);
    throw new RuntimeException('Invalid form mutation was accepted.');
} catch (UnexpectedValueException) {
    if (count($transport->calls) !== 2) {
        throw new RuntimeException('Invalid form mutation reached transport.');
    }
}
echo "PUBLISHED_FORM_SDK_OK", PHP_EOL;
PHP);
runPublished([PHP_BINARY, $directory . '/forms-consumer.php']);
$swaggerForms = $directory . '/swagger-forms';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/forms-swagger2.json',
    $swaggerForms, 'Example\\PublishedSwaggerForms', 'example/published-swagger-forms']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $swaggerForms]);
file_put_contents($directory . '/swagger-forms-consumer.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/swagger-forms/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator')
    || str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev')) {
    throw new RuntimeException('Swagger form SDK must run independently with a stable runtime.');
}
$transport = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
    public array $calls = [];
    public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body');
        return new \Dmorenof\SdkRuntime\Transport\HttpResponse(204, '');
    }
};
$client = \Example\PublishedSwaggerForms\Client::connect('https://api.example.test/v1', transport: $transport);
$items = $client->resource(\Example\PublishedSwaggerForms\Resources\Items::class);
$items->get(new \Example\PublishedSwaggerForms\GetParams\Items((object) ['name' => 'query name']));
$params = new \Example\PublishedSwaggerForms\PostParams\Items((object) [
    'name' => 'query name', 'queryBody' => 'query-body', 'X-Trace' => 'trace',
    'body' => (object) ['name' => 'body name', 'codes' => ['a,b', 'x y'], 'ids' => [1, 2]],
]);
$items->post($params);
if ($transport->calls[0]['url'] !== 'https://api.example.test/v1/items?name=query%20name'
    || $transport->calls[0]['body'] !== null
    || $transport->calls[1]['url'] !== 'https://api.example.test/v1/items?body=query-body&name=query%20name'
    || $transport->calls[1]['body'] !== 'codes=a%2Cb,x+y&ids=1&ids=2&name=body+name'
    || $transport->calls[1]['headers']['Content-Type'] !== 'application/x-www-form-urlencoded'
    || $transport->calls[1]['headers']['X-Trace'] !== 'trace') {
    throw new RuntimeException('Published Swagger formData serialization or CSV default failed.');
}
$params->body->name = '';
try {
    $items->post($params);
    throw new RuntimeException('Invalid Swagger form mutation was accepted.');
} catch (UnexpectedValueException) {
    if (count($transport->calls) !== 2) {
        throw new RuntimeException('Invalid Swagger form mutation reached transport.');
    }
}
echo "PUBLISHED_SWAGGER_FORM_SDK_OK", PHP_EOL;
PHP);
runPublished([PHP_BINARY, $directory . '/swagger-forms-consumer.php']);
$multipart = $directory . '/multipart';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/multipart-openapi31.json',
    $multipart, 'Example\\MultipartSdk', 'example/published-multipart']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $multipart]);
runPublished([PHP_BINARY, $package . '/tests/multipart-smoke.php', $multipart]);
$swaggerMultipart = $directory . '/swagger-multipart';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/multipart-swagger2.json',
    $swaggerMultipart, 'Example\\MultipartSdk', 'example/published-swagger-multipart']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $swaggerMultipart]);
runPublished([PHP_BINARY, $package . '/tests/multipart-smoke.php', $swaggerMultipart, '', 'application/octet-stream']);
echo "PUBLISHED_SWAGGER_MULTIPART_SDK_OK", PHP_EOL;
runPublished([PHP_BINARY, '-d', 'memory_limit=16M', $package . '/tests/streaming-smoke.php', $multipart]);
runPublished([PHP_BINARY, '-d', 'memory_limit=16M', $package . '/tests/streaming-smoke.php', $swaggerMultipart]);
$fileLists = $directory . '/file-lists';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/multipart-file-lists-openapi31.json',
    $fileLists, 'Example\\MultipartSdk', 'example/published-file-lists']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $fileLists]);
runPublished([PHP_BINARY, '-d', 'memory_limit=16M', $package . '/tests/streaming-smoke.php', $fileLists, '', 'list']);
$downloads = $directory . '/downloads';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/downloads-openapi31.json',
    $downloads, 'Example\\DownloadSdk', 'example/published-downloads']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $downloads]);
runPublished([PHP_BINARY, '-d', 'memory_limit=16M', $package . '/tests/download-smoke.php', $downloads]);
$swaggerDownloads = $directory . '/swagger-downloads';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/downloads-swagger2.json',
    $swaggerDownloads, 'Example\\DownloadSdk', 'example/published-swagger-downloads']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $swaggerDownloads]);
runPublished([PHP_BINARY, '-d', 'memory_limit=16M', $package . '/tests/download-smoke.php', $swaggerDownloads]);
echo "PUBLISHED_SWAGGER_DOWNLOAD_SDK_OK", PHP_EOL;
$statusResponses = $directory . '/status-responses';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/status-responses-openapi31.json',
    $statusResponses, 'Example\\StatusSdk', 'example/published-status-responses']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $statusResponses]);
runPublished([PHP_BINARY, $package . '/tests/status-response-smoke.php', $statusResponses]);
foreach (['simple-parameters-openapi31.json', 'simple-parameters-swagger2.json'] as $simpleFixture) {
    $simpleDirectory = $directory . '/' . pathinfo($simpleFixture, PATHINFO_FILENAME);
    runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/' . $simpleFixture,
        $simpleDirectory, 'Example\\SimpleSdk', 'example/published-simple-parameters']);
    runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $simpleDirectory]);
    runPublished([PHP_BINARY, $package . '/tests/simple-parameters-smoke.php', $simpleDirectory]);
}
$objectParameters = $directory . '/object-parameters';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/object-parameters-openapi31.json',
    $objectParameters, 'Example\\ObjectSdk', 'example/published-object-parameters']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $objectParameters]);
runPublished([PHP_BINARY, $package . '/tests/object-parameters-smoke.php', $objectParameters]);
$pathStyles = $directory . '/path-styles';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/path-styles-openapi31.json',
    $pathStyles, 'Example\\PathSdk', 'example/published-path-styles']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $pathStyles]);
runPublished([PHP_BINARY, $package . '/tests/path-styles-smoke.php', $pathStyles]);
$cookies = $directory . '/cookies';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/cookies-openapi31.json',
    $cookies, 'Example\\CookieSdk', 'example/published-cookies']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $cookies]);
runPublished([PHP_BINARY, $package . '/tests/cookies-smoke.php', $cookies]);
$parameterOverrides = $directory . '/parameter-overrides';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/parameter-overrides-openapi31.json',
    $parameterOverrides, 'Example\\OverrideSdk', 'example/published-parameter-overrides']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $parameterOverrides]);
runPublished([PHP_BINARY, $package . '/tests/parameter-overrides-smoke.php', $parameterOverrides]);
$duplicates = json_decode(file_get_contents($package . '/tests/fixtures/parameter-overrides-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
$duplicates['paths']['/items/{id}']['parameters'][] = $duplicates['paths']['/items/{id}']['parameters'][0];
$duplicateJson = json_encode($duplicates, JSON_THROW_ON_ERROR);
$duplicateReport = (new \Dmorenof\SdkGenerator\Diagnostics\DocumentInspector())->inspect($duplicateJson);
$duplicateIssues = array_values(array_filter($duplicateReport['diagnostics'], static fn ($issue): bool => $issue->code === 'duplicate_parameter'));
if ($duplicateReport['strictlyAccepted'] || $duplicateReport['generatable']
    || ($duplicateIssues[0]->pointer ?? null) !== '/paths/~1items~1{id}/parameters/5') {
    throw new RuntimeException('Published generator did not diagnose masked duplicate parameters.');
}
try {
    (new \Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer())->fromJson($duplicateJson);
    throw new RuntimeException('Published generator silently overwrote duplicate parameters.');
} catch (InvalidArgumentException) {
}
echo "PUBLISHED_DUPLICATE_PARAMETER_REJECTION_OK", PHP_EOL;
$reservedQuery = $directory . '/reserved-query';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/reserved-query-openapi31.json',
    $reservedQuery, 'Example\\ReservedSdk', 'example/published-reserved-query']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $reservedQuery]);
runPublished([PHP_BINARY, $package . '/tests/reserved-query-smoke.php', $reservedQuery]);
foreach (['empty-values-openapi30.json', 'empty-values-swagger2.json'] as $index => $fixture) {
    $emptyValues = $directory . '/empty-values-' . $index;
    runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/' . $fixture,
        $emptyValues, 'Example\\EmptySdk', 'example/published-empty-values-' . $index]);
    runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $emptyValues]);
    runPublished([PHP_BINARY, $package . '/tests/empty-values-smoke.php', $emptyValues]);
}
$parameterContent = $directory . '/parameter-content';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/parameter-content-openapi31.json',
    $parameterContent, 'Example\\ContentSdk', 'example/published-parameter-content']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $parameterContent]);
runPublished([PHP_BINARY, $package . '/tests/parameter-content-smoke.php', $parameterContent]);
$delimitedDirectory = $directory . '/delimited-parameters';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/delimited-parameters-swagger2.json',
    $delimitedDirectory, 'Example\\DelimitedSdk', 'example/published-delimited-parameters']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $delimitedDirectory]);
runPublished([PHP_BINARY, $package . '/tests/delimited-parameters-smoke.php', $delimitedDirectory]);
$selectionDirectory = $directory . '/response-selection';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/response-selection-openapi31.json',
    $selectionDirectory, 'Example\\SelectionSdk', 'example/published-response-selection']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $selectionDirectory]);
runPublished([PHP_BINARY, $package . '/tests/response-selection-smoke.php', $selectionDirectory]);
$formBlockDirectories = [];
foreach (['openapi31' => 'FormBlockSdk', 'swagger2' => 'SwaggerFormBlockSdk'] as $dialect => $namespace) {
    $formBlockDirectory = $directory . '/form-block-' . $dialect;
    runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/form-block-' . $dialect . '.json',
        $formBlockDirectory, 'Example\\' . $namespace, 'example/published-form-block-' . $dialect]);
    runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $formBlockDirectory]);
    $formBlockDirectories[] = $formBlockDirectory;
}
runPublished([PHP_BINARY, $package . '/tests/form-block-smoke.php', ...$formBlockDirectories]);
$schemaDirectory = $directory . '/schema-rules';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/schema-rules-openapi31.json',
    $schemaDirectory, 'Example\\SchemaRulesSdk', 'example/published-schema-rules']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $schemaDirectory]);
runPublished([PHP_BINARY, $package . '/tests/schema-rules-smoke.php', $schemaDirectory]);
$nativeDirectory = $directory . '/native-schemas';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/native-schemas-openapi31.json',
    $nativeDirectory, 'Example\\NativeSdk', 'example/published-native-schemas']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $nativeDirectory]);
runPublished([PHP_BINARY, $package . '/tests/native-schemas-smoke.php', $nativeDirectory]);
$bodyDirectory = $directory . '/request-bodies';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/request-bodies-openapi31.json',
    $bodyDirectory, 'Example\\BodySdk', 'example/published-request-bodies']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $bodyDirectory]);
runPublished([PHP_BINARY, $package . '/tests/request-bodies-smoke.php', $bodyDirectory]);
$rawDirectory = $directory . '/raw-media';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/raw-media-openapi31.json',
    $rawDirectory, 'Example\\RawSdk', 'example/published-raw-media']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $rawDirectory]);
runPublished([PHP_BINARY, '-d', 'memory_limit=16M', $package . '/tests/raw-media-smoke.php', $rawDirectory]);
$aliasDirectory = $directory . '/schema-aliases';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/schema-aliases-openapi31.json',
    $aliasDirectory, 'Example\\AliasSdk', 'example/published-schema-aliases']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $aliasDirectory]);
runPublished([PHP_BINARY, $package . '/tests/schema-aliases-smoke.php', $aliasDirectory]);
$errorDirectory = $directory . '/error-payloads';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/error-payloads-openapi31.json',
    $errorDirectory, 'Example\\ErrorPayloadSdk', 'example/published-error-payloads']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $errorDirectory]);
runPublished([PHP_BINARY, $package . '/tests/error-payloads-smoke.php', $errorDirectory]);
$mimeDirectory = $directory . '/mime-media';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/mime-media-openapi31.json',
    $mimeDirectory, 'Example\\MimeMediaSdk', 'example/published-mime-media']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $mimeDirectory]);
runPublished([PHP_BINARY, '-d', 'memory_limit=16M', $package . '/tests/mime-media-smoke.php', $mimeDirectory]);
$serverRoutesDirectory = $directory . '/server-routes';
runPublished([PHP_BINARY, $binary, '--strict', '--resolve-local-references', $package . '/tests/fixtures/server-routes-openapi31.json',
    $serverRoutesDirectory, 'Example\\ServerRoutesSdk', 'example/published-server-routes']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $serverRoutesDirectory]);
runPublished([PHP_BINARY, $package . '/tests/server-routes-smoke.php', $serverRoutesDirectory]);
echo "PUBLISHED_GENERATOR_INSTALL_OK ", $tag, " ", $expected, PHP_EOL;
