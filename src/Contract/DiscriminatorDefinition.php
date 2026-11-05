<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Contract;

final readonly class DiscriminatorDefinition
{
    /** @param array<string, string> $mapping Discriminator values to local schema names. */
    public function __construct(public string $propertyName, public array $mapping = []) {}
}
