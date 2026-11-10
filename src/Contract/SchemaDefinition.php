<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Contract;

final readonly class SchemaDefinition
{
    /**
     * @param list<PropertyDefinition> $properties
     * @param list<string|int|float|bool> $enumValues
     * @param list<self> $branches
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
        /** @var array<string, mixed> Normalized declarative schema constraints */
        public array $validation = [],
        public array $branches = [],
        public ?string $pattern = null,
        public bool $readOnly = false,
        public mixed $default = null,
        public bool $hasDefault = false,
        public bool $writeOnly = false,
        public ?string $title = null,
        public ?string $description = null,
        public ?DiscriminatorDefinition $discriminator = null,
        /** @var array<string, mixed> Documentation and vendor metadata; never executable. */
        public array $annotations = [],
    ) {}
}
