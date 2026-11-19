<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

/**
 * Everything the resource emitter needs from what the contract emitter already worked out: security schemes,
 * response and error types, the public arguments of each operation and the parameter types.
 */
final readonly class ResourceContext
{
    /**
     * @param array<string,array<string,mixed>> $successTypes Operation ID => success description.
     * @param array<string,array<string,?string>> $errorTypes Operation ID => status => error model.
     * @param array<string,array<int|string,array{type:?string,schemaNullable:bool,rules?:array<string,mixed>,mediaType?:string}>> $errorResponses
     * @param array<string,array{identifier:?array,payload:?array}> $requestTypes Operation ID => public arguments.
     * @param array<string,string> $groupOverrides
     * @param list<\Dmorenof\SdkGenerator\Contract\ModelDefinition> $formModels
     * @param list<\Dmorenof\SdkGenerator\Contract\SecuritySchemeDefinition> $securitySchemes
     */
    public function __construct(
        public array $securitySchemes,
        public array $successTypes,
        public array $errorTypes,
        public array $requestTypes = [],
        public array $errorResponses = [],
        public ParameterTypes $parameterTypes = new ParameterTypes(),
        public array $formModels = [],
        public array $groupOverrides = [],
    ) {}
}
