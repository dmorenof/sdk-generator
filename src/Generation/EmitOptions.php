<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

/** What the caller chose about the shape of the generated code. */
final readonly class EmitOptions
{
    /**
     * @param bool $propertyHooks Generate PHP 8.4 `set` hooks: a property with rules checks each value when it is
     *        assigned or hydrated, and the model is not walked again to check them. The SDK then requires PHP 8.4.
     */
    public function __construct(public bool $propertyHooks = false) {}
}
