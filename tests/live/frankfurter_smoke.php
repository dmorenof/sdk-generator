<?php
declare(strict_types=1);

/**
 * Real HTTP against Frankfurter (OpenAPI 3.1, free currency rates, no key), with an SDK generated from its
 * unmodified specification. Historical dates are fixed, so the answers do not drift.
 */
require __DIR__ . '/../../live-results/frankfurter-sdk/vendor/autoload.php';

use Dmorenof\SdkRuntime\ApiException;
use Example\Frankfurter\Client;
use Example\Frankfurter\Configuration;
use Example\Frankfurter\Enums\Base;
use Example\Frankfurter\GetParams\Latest as LatestParams;
use Example\Frankfurter\PathParams\StartDateEndDate as PeriodPath;
use Example\Frankfurter\Resources\Currencies;
use Example\Frankfurter\Resources\Latest;
use Example\Frankfurter\Resources\RootResource;
use Example\Frankfurter\Resources\StartDateEndDate;

function check(bool $condition, string $what): void
{
    if (!$condition) {
        throw new RuntimeException("Live check failed: $what");
    }
    echo "ok  $what\n";
}

$configuration = new Configuration();
$configuration->baseUrl = 'https://api.frankfurter.dev/v1';
$client = Client::connect($configuration);

$currencies = $client->resource(Currencies::class)->get();
check(count($currencies) > 20 && isset($currencies['EUR']), 'GET /currencies is a map of currency codes to names');

$params = new LatestParams();
$params->base = Base::USD;
$params->symbols = ['EUR', 'GBP'];
$latest = $client->resource(Latest::class)->get($params);
check($latest->base === Base::USD && count($latest->rates) === 2 && isset($latest->rates['EUR']) && isset($latest->rates['GBP']),
    'GET /latest?base=USD&symbols=EUR,GBP returns exactly the two rates asked for');

$historical = $client->resource(RootResource::class)->getByDate('2024-01-15');
check($historical->date === '2024-01-12' && $historical->base === Base::EUR && $historical->rates['USD'] > 1.0,
    'GET /{date} answers with the closest working day and the default base (EUR)');

$path = new PeriodPath();
$path->start_date = '2024-01-02';
$path->end_date = '2024-01-05';
$period = $client->resource(StartDateEndDate::class)->get($path);
check($period->start_date === '2024-01-02' && count($period->rates) === 4, 'GET /{start}..{end} returns one entry per working day');

try {
    $client->resource(RootResource::class)->getByDate('1900-01-01');
    check(false, 'a date before the data starts is refused');
} catch (ApiException $error) {
    check($error->getCode() === 404, 'GET of a date with no data is an ApiException 404');
}

try {
    $bad = new LatestParams();
    $bad->symbols = ['EUR', 'XXX'];
    $client->resource(Latest::class)->get($bad);
    check(false, 'an unknown currency is refused before sending');
} catch (UnexpectedValueException) {
    check(true, 'an unknown currency is refused before sending');
}

echo "Frankfurter live smoke passed.\n";
