<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Diagnostics;

final readonly class Diagnostic implements \JsonSerializable
{
    /** @param list<string> $referenceChain */
    public function __construct(
        public string $code,
        public string $severity,
        public string $pointer,
        public string $message,
        public string $source,
        public ?string $operationId = null,
        public array $referenceChain = [],
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
