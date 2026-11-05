<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Contract;

/** A declared representation, distinct from the representation selected for execution. */
final readonly class MediaTypeDefinition
{
    public function __construct(
        public string $mediaType,
        public ?SchemaDefinition $schema = null,
        /** @var array<string, mixed> */
        public array $encoding = [],
        /** @var array<string, mixed> */
        public array $annotations = [],
        /** Unsupported alternative schemas remain explicit, never executable canonical schemas. */
        public mixed $unparsedSchema = null,
    ) {}
}
