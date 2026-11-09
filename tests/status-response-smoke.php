<?php
declare(strict_types=1);
require ($argv[1] ?? throw new RuntimeException('Missing SDK directory.')) . '/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator')
    || str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev')) {
    throw new RuntimeException('Status SDK must run independently with a stable runtime.');
}
$cases = [
    'found' => [200, '{"id":7,"name":"Ada"}'],
    'created' => [201, '{"id":7,"name":"Ada","ticket":"created"}'],
    'queued' => [202, '{"message":"queued"}'], 'nullable' => [202, 'null'], 'empty' => [204, ''],
    'empty200' => [200, ''], 'null200' => [200, 'null'], 'wrong201' => [201, '{"name":"Ada"}'],
    'invalid201' => [201, '{"name":"Ada","ticket":""}'], 'bad202' => [202, '{}'],
    'problem' => [400, '{"message":"problem"}'], 'failure' => [500, 'raw error'],
];
$http = empty($argv[2]) ? new class($cases) implements \Psr\Http\Client\ClientInterface {
    public function __construct(private array $cases) {}
    public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        parse_str($request->getUri()->getQuery(), $query);
        if (($query['body'] ?? null) !== 'query body' || $request->getHeaderLine('X-Trace') !== 'trace'
            || $request->getHeaderLine('Accept') !== 'application/json'
            || ($request->getMethod() === 'POST' && ((string) $request->getBody() !== '{"name":"export"}'
                || $request->getHeaderLine('Content-Type') !== 'application/json'))) {
            throw new RuntimeException('Request contract changed while selecting responses.');
        }
        [$status, $body] = $this->cases[$query['scenario']];
        return new \GuzzleHttp\Psr7\Response($status, ['Content-Type' => 'application/json'], $body);
    }
} : null;
$resource = \Example\StatusSdk\Client::connect(empty($argv[2]) ? 'https://api.example.test/v1' : $argv[2], httpClient: $http)
    ->resource(\Example\StatusSdk\Resources\Users::class);
foreach (['GET', 'POST'] as $verb) {
    foreach ($cases as $scenario => [$status, $body]) {
        try {
            $values = (object) ['scenario' => $scenario, 'X-Trace' => 'trace'];
            if ($verb === 'GET') {
                $values->body = 'query body';
                $result = $resource->get(new \Example\StatusSdk\GetParams\Users($values));
            } else {
                $values->queryBody = 'query body'; $values->body = (object) ['name' => 'export'];
                $result = $resource->post(new \Example\StatusSdk\PostParams\Users($values));
            }
        } catch (\Dmorenof\SdkRuntime\ApiException $e) {
            if ($status < 400 || $e->response->status !== $status || $e->response->body !== $body
                || ($status === 400 && !$e->error instanceof \Example\StatusSdk\DTOs\Problem)) {
                throw new RuntimeException('Wrong error response behavior.', previous: $e);
            }
            continue;
        } catch (\UnexpectedValueException|\InvalidArgumentException|\JsonException|\TypeError $e) {
            if (!in_array($scenario, ['empty200', 'null200', 'wrong201', 'invalid201', 'bad202'], true)) { throw $e; }
            continue;
        }
        $expected = match ($scenario) {
            'found' => \Example\StatusSdk\DTOs\User::class, 'created' => \Example\StatusSdk\DTOs\CreatedUser::class,
            'queued' => $verb === 'GET' ? \Example\StatusSdk\Responses\Users\GetStatus202::class : \Example\StatusSdk\Responses\Users\PostStatus202::class,
            'nullable', 'empty' => null,
            default => throw new RuntimeException('Invalid response accepted: ' . $scenario),
        };
        if (($result === null ? null : $result::class) !== $expected) { throw new RuntimeException('Status selected the wrong class.'); }
        if ($scenario === 'created' && (!$result instanceof \Example\StatusSdk\DTOs\User || $result->id !== 7
            || $result->ticket !== 'created')) { throw new RuntimeException('Inherited response fields lost.'); }
    }
}
echo 'PUBLISHED_STATUS_RESPONSE_SDK_OK', PHP_EOL;
