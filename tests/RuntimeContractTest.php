<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpResourceEmitter;
use Dmorenof\SdkRuntime\Operation;
use PHPUnit\Framework\TestCase;

final class RuntimeContractTest extends TestCase
{
    public function testEmitterOmitsExactlyTheDefaultsTheRuntimeInherits(): void
    {
        self::assertSame(Operation::DEFAULTS, PhpResourceEmitter::OPERATION_DEFAULTS);
    }
}
