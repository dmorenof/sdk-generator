<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Contract;

final readonly class SecuritySchemeDefinition
{
    public function __construct(
        public string $name,
        public string $type,
        public ?string $scheme = null,
        public ?string $location = null,
        public ?string $parameterName = null,
    ) {}
}
