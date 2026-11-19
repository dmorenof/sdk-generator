<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use PHPUnit\Framework\TestCase;

final class Zz84ProbeTest extends TestCase
{
    public function testProbe(): void
    {
        if (PHP_VERSION_ID < 80400) { self::markTestSkipped('8.4 only'); }
        eval(<<<'PHP'
        namespace Probe84;
        class P {
            public ?int $a { set { $this->a = $value; } }
            public int $b { set { $this->b = $value; } }
            public ?int $c;
            public function vars(): array { return get_object_vars($this); }
        }
        PHP);
        $p = new \Probe84\P();
        $r = new \ReflectionProperty($p, 'a');
        $info = [
            'outside' => get_object_vars($p),
            'inside' => $p->vars(),
            'a_init' => $r->isInitialized($p),
            'b_init' => (new \ReflectionProperty($p, 'b'))->isInitialized($p),
            'c_init' => (new \ReflectionProperty($p, 'c'))->isInitialized($p),
            'cast' => (array) $p,
            'foreach' => iterator_to_array((function () use ($p) { foreach ($p as $k => $v) { yield $k => $v; } })()),
        ];
        self::fail('PROBE ' . json_encode($info, JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}
