<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Contract;

final readonly class OperationDefinition
{
    /**
     * @param list<ParameterDefinition> $parameters
     * @param list<ResponseDefinition> $responses
     */
    public function __construct(
        public string $operationId,
        public string $method,
        public string $path,
        public array $parameters,
        public ?RequestBodyDefinition $requestBody,
        public array $responses,
    ) {}
}
