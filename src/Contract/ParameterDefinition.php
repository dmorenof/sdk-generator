<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Contract;

final readonly class ParameterDefinition
{
    public function __construct(
        public string $wireName,
        public string $location,
        public SchemaDefinition $schema,
        public bool $required,
    ) {}
}
