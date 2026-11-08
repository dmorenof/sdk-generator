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
    'repositories' => [['type' => 'vcs', 'url' => 'https://github.com/dmorenof/sdk-generator', 'no-api' => true]],
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
    'name' => 'filter', 'in' => 'query', 'schema' => ['type' => 'string'], 'allowReserved' => true,
];
$report = (new \Dmorenof\SdkGenerator\Diagnostics\DocumentInspector())->inspect(json_encode($nested, JSON_THROW_ON_ERROR));
$issues = array_values(array_filter($report['diagnostics'], static fn ($issue): bool => $issue->code === 'parameter_allowReserved'));
if ($report['strictlyAccepted'] || !$report['generatable']
    || ($issues[0]->pointer ?? null) !== '/components/parameters/Filter~1~0/allowReserved') {
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
echo "PUBLISHED_GENERATOR_INSTALL_OK ", $tag, " ", $expected, PHP_EOL;
