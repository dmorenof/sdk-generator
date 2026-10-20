<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Contract;

final readonly class SchemaDefinition
{
    /**
     * @param list<PropertyDefinition> $properties
     * @param list<string|int|float|bool> $enumValues
     */
    public function __construct(
        public SchemaKind $kind,
        public bool $nullable = false,
        public ?string $format = null,
        public ?string $reference = null,
        public ?self $items = null,
        public array $properties = [],
        public array $enumValues = [],
        public bool|self|null $additionalProperties = null,
        /** @var array<string, int|float> OpenAPI primitive/array constraints */
        public array $validation = [],
    ) {}
}
