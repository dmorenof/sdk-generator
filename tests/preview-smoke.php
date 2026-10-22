<?php
declare(strict_types=1);

require getcwd() . '/vendor/autoload.php';

function checkPreview(bool $actual, string $message): void
{
    if (!$actual) {
        throw new RuntimeException($message);
    }
}

$api = $argv[1] ?? '';
if ($api === 'accounts') {
    $transport = new class implements \Preview\AccountsSdk\Transport\TransportInterface {
        public array $calls = [];

        public function send(string $method, string $url, array $headers, ?string $body): \Preview\AccountsSdk\Transport\HttpResponse
        {
            $this->calls[] = compact('method', 'url', 'headers', 'body');
            return new \Preview\AccountsSdk\Transport\HttpResponse(
                $method === 'GET' ? 200 : 201,
                json_encode([
                    'id' => 27, 'name' => 'Example user', 'role' => 'admin',
                    'profile' => ['city' => 'Salamanca', 'visits' => 4],
                    'labels' => ['verified', 'preview'],
                ], JSON_THROW_ON_ERROR),
            );
        }
    };
    $client = \Preview\AccountsSdk\Client::connect('https://api.example.test/v1', transport: $transport);
    $user = $client->resource(\Preview\AccountsSdk\Api\Users\User::class)->getById(27, true);
    checkPreview($user instanceof \Preview\AccountsSdk\Responses\User, 'Accounts DTO type mismatch');
    checkPreview($user->role === \Preview\AccountsSdk\Enums\User\RoleEnum::Admin, 'Accounts enum mismatch');
    checkPreview($user->profile->visits === 4, 'Accounts nested model mismatch');
    checkPreview($user->labels->getArrayCopy() === ['verified', 'preview'], 'Accounts collection mismatch');
    checkPreview($transport->calls[0]['url'] === 'https://api.example.test/v1/users/27?verbose=true', 'Accounts URL mismatch');

    $body = new \Preview\AccountsSdk\Requests\NewUser();
    $body->name = 'A user';
    $client->resource(\Preview\AccountsSdk\Api\Users::class)->post($body);
    checkPreview($transport->calls[1]['method'] === 'POST', 'Accounts POST mismatch');
    checkPreview(
        json_decode((string)$transport->calls[1]['body'], true, 512, JSON_THROW_ON_ERROR) === ['name' => 'A user'],
        'Accounts POST JSON mismatch',
    );
} elseif ($api === 'catalog') {
    $transport = new class implements \Preview\CatalogSdk\Transport\TransportInterface {
        public array $calls = [];

        public function send(string $method, string $url, array $headers, ?string $body): \Preview\CatalogSdk\Transport\HttpResponse
        {
            $this->calls[] = compact('method', 'url', 'headers', 'body');
            return new \Preview\CatalogSdk\Transport\HttpResponse(
                $method === 'GET' ? 200 : 201,
                json_encode([
                    'sku' => 'BOOK-09', 'title' => 'Example book', 'price' => 19.75,
                    'genre' => 'fiction', 'stock' => 8, 'available' => true,
                    'warehouse' => ['region' => 'Castilla', 'temperature' => 18.5],
                    'tags' => ['demo', 'typed'],
                ], JSON_THROW_ON_ERROR),
            );
        }
    };
    $client = \Preview\CatalogSdk\Client::connect('https://catalog.example.test/api/v2', transport: $transport);
    $book = $client->resource(\Preview\CatalogSdk\Api\Books\Book::class)->getBySku('BOOK-09', false);
    checkPreview($book instanceof \Preview\CatalogSdk\Responses\Book, 'Catalog DTO type mismatch');
    checkPreview($book->price === 19.75, 'Catalog float mismatch');
    checkPreview($book->genre === \Preview\CatalogSdk\Enums\Book\GenreEnum::Fiction, 'Catalog enum mismatch');
    checkPreview($book->warehouse->region === 'Castilla', 'Catalog nested DTO mismatch');
    checkPreview($book->tags->getArrayCopy() === ['demo', 'typed'], 'Catalog collection mismatch');
    checkPreview($transport->calls[0]['url'] === 'https://catalog.example.test/api/v2/books/BOOK-09?expand=false', 'Catalog URL mismatch');

    $body = new \Preview\CatalogSdk\Requests\BookDraft();
    $body->title = 'A new book';
    $body->price = 12.5;
    $client->resource(\Preview\CatalogSdk\Api\Books::class)->post($body);
    checkPreview($transport->calls[1]['method'] === 'POST', 'Catalog POST mismatch');
    checkPreview(
        count(json_decode((string)$transport->calls[1]['body'], true, 512, JSON_THROW_ON_ERROR)) === 2
            && (json_decode((string)$transport->calls[1]['body'], true, 512, JSON_THROW_ON_ERROR)['title'] ?? null) === 'A new book'
            && (json_decode((string)$transport->calls[1]['body'], true, 512, JSON_THROW_ON_ERROR)['price'] ?? null) === 12.5,
        'Catalog POST JSON value mismatch',
    );
} else {
    throw new InvalidArgumentException('Expected accounts or catalog fixture.');
}
// Prove the factory works inside the independently Composer-installed SDK,
// not only within the generator's own PHPUnit/autoloader environment.
if ($api === 'accounts') {
    $payload = json_encode([
        'id' => 27, 'name' => 'Standalone',
        'role' => 'viewer',
        'profile' => ['city' => 'Madrid', 'visits' => 1],
        'labels' => ['installed'],
    ], JSON_THROW_ON_ERROR);
    $events = [];
    $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([
        new \GuzzleHttp\Psr7\Response(200, [], $payload),
    ]));
    $stack->push(\GuzzleHttp\Middleware::mapRequest(
        function (\Psr\Http\Message\RequestInterface $request) use (&$events): \Psr\Http\Message\RequestInterface {
            $events[] = 'before';
            return $request->withHeader('X-Preview', 'yes');
        }
    ));
    $stack->push(\GuzzleHttp\Middleware::mapResponse(
        function (\Psr\Http\Message\ResponseInterface $response) use (&$events): \Psr\Http\Message\ResponseInterface {
            $events[] = 'after';
            return $response;
        }
    ));

    $client = \Preview\AccountsSdk\Client::connect(
        'https://api.example.test/v1',
        new \GuzzleHttp\Client(['handler' => $stack]),
        ['Authorization' => 'Bearer example'],
    );
    $result = $client->resource(\Preview\AccountsSdk\Api\Users\User::class)->getById(27);
    checkPreview($result->name === 'Standalone', 'Ready-to-use Accounts DTO mismatch');
    checkPreview($events === ['before', 'after'], 'Guzzle middleware chain did not run');
} else {
    $payload = json_encode([
        'sku' => 'BOOK-09', 'title' => 'Ready',
        'price' => 19.75,
        'genre' => 'fiction',
        'warehouse' => ['region' => 'Madrid'],
        'tags' => ['installed'],
    ], JSON_THROW_ON_ERROR);
    $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([
        new \GuzzleHttp\Psr7\Response(200, [], $payload),
    ]));
    $client = \Preview\CatalogSdk\Client::connect(
        'https://catalog.example.test/api/v2',
        new \GuzzleHttp\Client(['handler' => $stack]),
    );
    $result = $client->resource(\Preview\CatalogSdk\Api\Books\Book::class)->getBySku('BOOK-09');
    checkPreview($result->title === 'Ready', 'Ready-to-use Catalog DTO mismatch');
}
echo "READY_PSR18_CONSUMER_OK: $api\n";
echo "PREVIEW_CONSUMER_OK: $api GET/POST, typed DTOs, enums and collections\n";
