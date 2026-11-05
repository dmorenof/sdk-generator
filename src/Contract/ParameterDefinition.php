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
        public ParameterSerialization $serialization = new ParameterSerialization(),
        /** @var list<MediaTypeDefinition> */
        public array $content = [],
        /** @var array<string, mixed> */
        public array $annotations = [],
    ) {}
}
