<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests\Support;

use Dmorenof\SdkRuntime\Configuration;
use Dmorenof\SdkRuntime\Security\SecurityCredentials;
use Dmorenof\SdkRuntime\Transport\HttpMiddlewareInterface;
use Dmorenof\SdkRuntime\Transport\RedirectPolicy;
use Dmorenof\SdkRuntime\Transport\RetryPolicy;
use Dmorenof\SdkRuntime\Transport\TransportInterface;
use Psr\Http\Client\ClientInterface;

/** Test helper: a Configuration from flat arguments, so each test names only what it varies. */
final class Cfg
{
    /**
     * @param array<string, string> $headers
     * @param array<string, string> $serverOverrides
     * @param list<HttpMiddlewareInterface> $middleware
     * @param array<string, string> $serverVariables
     */
    public static function make(
        string $baseUrl,
        ?ClientInterface $httpClient = null,
        array $headers = [],
        ?SecurityCredentials $securityCredentials = null,
        ?TransportInterface $transport = null,
        array $serverOverrides = [],
        array $middleware = [],
        ?RetryPolicy $retryPolicy = null,
        ?RedirectPolicy $redirectPolicy = null,
        int|string|null $server = null,
        array $serverVariables = [],
    ): Configuration {
        $configuration = new Configuration();
        $configuration->baseUrl = $baseUrl;
        $configuration->httpClient = $httpClient;
        $configuration->headers = $headers;
        $configuration->securityCredentials = $securityCredentials;
        $configuration->transport = $transport;
        $configuration->serverOverrides = $serverOverrides;
        $configuration->middleware = $middleware;
        $configuration->retryPolicy = $retryPolicy;
        $configuration->redirectPolicy = $redirectPolicy;
        $configuration->server = $server;
        $configuration->serverVariables = $serverVariables;
        return $configuration;
    }
}
