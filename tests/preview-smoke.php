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
    $client = new \Preview\AccountsSdk\Api\ApiClient($transport, 'https://api.example.test/v1');
    $user = $client->getUser(27, true);
    checkPreview($user instanceof \Preview\AccountsSdk\Models\User, 'Accounts DTO type mismatch');
    checkPreview($user->role === \Preview\AccountsSdk\Enums\UserRoleEnum::Admin, 'Accounts enum mismatch');
    checkPreview($user->profile->visits === 4, 'Accounts nested model mismatch');
    checkPreview($user->labels->getArrayCopy() === ['verified', 'preview'], 'Accounts collection mismatch');
    checkPreview($transport->calls[0]['url'] === 'https://api.example.test/v1/users/27?verbose=true', 'Accounts URL mismatch');

    $body = new \Preview\AccountsSdk\Models\NewUser();
    $body->name = 'A user';
    $client->createUser($body);
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
    $client = new \Preview\CatalogSdk\Api\ApiClient($transport, 'https://catalog.example.test/api/v2');
    $book = $client->findBook('BOOK-09', false);
    checkPreview($book instanceof \Preview\CatalogSdk\Models\Book, 'Catalog DTO type mismatch');
    checkPreview($book->price === 19.75, 'Catalog float mismatch');
    checkPreview($book->genre === \Preview\CatalogSdk\Enums\BookGenreEnum::Fiction, 'Catalog enum mismatch');
    checkPreview($book->warehouse->region === 'Castilla', 'Catalog nested DTO mismatch');
    checkPreview($book->tags->getArrayCopy() === ['demo', 'typed'], 'Catalog collection mismatch');
    checkPreview($transport->calls[0]['url'] === 'https://catalog.example.test/api/v2/books/BOOK-09?expand=false', 'Catalog URL mismatch');

    $body = new \Preview\CatalogSdk\Models\BookDraft();
    $body->title = 'A new book';
    $body->price = 12.5;
    $client->addBook($body);
    checkPreview($transport->calls[1]['method'] === 'POST', 'Catalog POST mismatch');
    checkPreview(
        json_decode((string)$transport->calls[1]['body'], true, 512, JSON_THROW_ON_ERROR) === ['title' => 'A new book', 'price' => 12.5],
        'Catalog POST JSON mismatch',
    );
} else {
    throw new InvalidArgumentException('Expected accounts or catalog fixture.');
}
echo "PREVIEW_CONSUMER_OK: $api GET/POST, typed DTOs, enums and collections\n";
