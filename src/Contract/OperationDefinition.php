<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Contract;

final readonly class OperationDefinition
{
    /**
     * @param list<ParameterDefinition> $parameters
     * @param list<ResponseDefinition> $responses
     * @param list<array<string, list<string>>> $securityRequirements
     */
    public function __construct(
        public string $operationId,
        public string $method,
        public string $path,
        public array $parameters,
        public ?RequestBodyDefinition $requestBody,
        public array $responses,
        public array $securityRequirements = [],
        /** @var list<string> Applicable servers in preference order. */
        public array $servers = [],
        /** @var list<array<string, mixed>> */
        public array $serverDefinitions = [],
        public string $serverSource = 'root',
        /** @var array<string, mixed> */
        public array $annotations = [],
    ) {}
}
