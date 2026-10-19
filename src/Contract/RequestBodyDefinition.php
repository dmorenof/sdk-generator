<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Contract;

final readonly class RequestBodyDefinition
{
    public function __construct(
        public string $mediaType,
        public SchemaDefinition $schema,
        public bool $required,
    ) {}
}
