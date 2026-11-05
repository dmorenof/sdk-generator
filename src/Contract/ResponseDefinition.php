<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Contract;

final readonly class ResponseDefinition
{
    public function __construct(
        public string $status,
        public ?string $mediaType,
        public ?SchemaDefinition $schema,
        /** @var list<MediaTypeDefinition> All declared representations. */
        public array $content = [],
        /** @var array<string, array<string, mixed>> Preserved until header normalization/execution is implemented. */
        public array $headers = [],
        /** @var array<string, mixed> */
        public array $links = [],
        /** @var array<string, mixed> */
        public array $annotations = [],
    ) {}
}
