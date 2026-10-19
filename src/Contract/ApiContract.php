<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Contract;

final readonly class ApiContract
{
    /**
     * @param list<string> $servers
     * @param list<ModelDefinition> $models
     * @param list<OperationDefinition> $operations
     */
    public function __construct(
        public string $title,
        public array $servers,
        public array $models,
        public array $operations,
    ) {}
}
