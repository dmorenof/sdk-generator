<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Contract;

final readonly class ResponseDefinition
{
    public function __construct(
        public string $status,
        public ?string $mediaType,
        public ?SchemaDefinition $schema,
    ) {}
}
