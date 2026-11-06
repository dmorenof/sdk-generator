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
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/accounts-references-openapi31.json', $accounts, 'Preview\\AccountsSdk', 'example/published-accounts']);
runPublished(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--working-dir=' . $accounts]);
runPublished([PHP_BINARY, $package . '/tests/preview-smoke.php', 'accounts'], $accounts);
echo "PUBLISHED_LOCAL_REFERENCES_OK", PHP_EOL;
$servers = $directory . '/servers';
runPublished([PHP_BINARY, $binary, '--strict', $package . '/tests/fixtures/operation-servers-openapi30.json', $servers, 'Example\\PublishedServers', 'example/published-servers']);
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
echo "PUBLISHED_GENERATOR_INSTALL_OK ", $tag, " ", $expected, PHP_EOL;
