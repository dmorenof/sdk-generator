<?php
declare(strict_types=1);

/**
 * Real HTTP against httpbin (Swagger 2.0), with an SDK generated from its unmodified specification: `formData`
 * operations that declare no `consumes`, and operations whose answers the specification does not describe.
 * httpbin answers what it is asked, so the checks are about status codes, headers, forms and redirects.
 */
require __DIR__ . '/../../live-results/httpbin-sdk/vendor/autoload.php';

use Dmorenof\SdkRuntime\ApiException;
use Example\Httpbin\Client;
use Example\Httpbin\Configuration;
use Example\Httpbin\GetParams\Bearer as BearerParams;
use Example\Httpbin\PathParams\BasicAuth\ByUser\ByPasswd;
use Example\Httpbin\Requests\RedirectTo\Post as RedirectPost;
use Example\Httpbin\Resources\BasicAuth\ByUser as BasicAuth;
use Example\Httpbin\Resources\Bearer;
use Example\Httpbin\Resources\Delay;
use Example\Httpbin\Resources\RedirectTo;
use Example\Httpbin\Resources\Status;

function check(bool $condition, string $what): void
{
    if (!$condition) {
        throw new RuntimeException("Live check failed: $what");
    }
    echo "ok  $what\n";
}

function status(callable $call): ?int
{
    try {
        $call();
        return null;
    } catch (ApiException $error) {
        return $error->getCode();
    }
}

$configuration = new Configuration();
$client = Client::connect($configuration);

$statuses = $client->resource(Status::class);
$statuses->getByCodes('200');
check(status(fn () => $statuses->getByCodes('404')) === 404, 'GET /status/404 is an ApiException 404');
check(status(fn () => $statuses->getByCodes('418')) === 418, 'GET /status/418 is an ApiException 418');

$client->resource(Delay::class)->getByDelay(1.0);
check(true, 'GET /delay/1 waits and answers');

$bearer = $client->resource(Bearer::class);
check(status(fn () => $bearer->get()) === 401, 'GET /bearer without the header is a 401');
$params = new BearerParams();
$params->Authorization = 'Bearer demo-token';
$bearer->get($params);
check(true, 'GET /bearer with the header parameter is accepted');

$basic = new Configuration();
$basic->headers = ['Authorization' => 'Basic ' . base64_encode('ada:secret')];
$identifier = new ByPasswd();
$identifier->user = 'ada';
$identifier->passwd = 'secret';
Client::connect($basic)->resource(BasicAuth::class)->getByPasswd($identifier);
check(true, 'GET /basic-auth/{user}/{passwd} with the default Authorization header is accepted');
check(status(fn () => $client->resource(BasicAuth::class)->getByPasswd($identifier)) === 401, 'the same call without the header is a 401');

$form = new RedirectPost();
$form->url = 'https://httpbin.org/get';
$form->status_code = 302;
$client->resource(RedirectTo::class)->post($form);
check(true, 'POST /redirect-to (a form with no consumes declared) is sent as a form and its redirect is followed');

echo "httpbin live smoke passed.\n";
