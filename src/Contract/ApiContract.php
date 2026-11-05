<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Contract;

final readonly class ApiContract
{
    /**
     * @param list<string> $servers
     * @param list<ModelDefinition> $models
     * @param list<OperationDefinition> $operations
     * @param list<SecuritySchemeDefinition> $securitySchemes
     * @param list<array<string, list<string>>> $securityRequirements
     */
    public function __construct(
        public string $title,
        public array $servers,
        public array $models,
        public array $operations,
        public array $securitySchemes = [],
        public array $securityRequirements = [],
    ) {}
}
