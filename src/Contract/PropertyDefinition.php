<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Contract;

final readonly class PropertyDefinition
{
    public function __construct(
        public string $wireName,
        public SchemaDefinition $schema,
        public bool $required,
    ) {}
}
