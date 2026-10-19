<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Contract;

final readonly class ModelDefinition
{
    public function __construct(
        public string $name,
        public SchemaDefinition $schema,
    ) {}
}
