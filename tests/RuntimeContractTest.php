<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpResourceEmitter;
use Dmorenof\SdkRuntime\ApiClient;
use PHPUnit\Framework\TestCase;

final class RuntimeContractTest extends TestCase
{
    public function testEmitterOmitsExactlyTheDefaultsTheRuntimeInherits(): void
    {
        self::assertSame(ApiClient::OPERATION_DEFAULTS, PhpResourceEmitter::OPERATION_DEFAULTS);
    }
}
