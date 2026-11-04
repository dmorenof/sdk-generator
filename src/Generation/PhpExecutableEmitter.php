<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

use Dmorenof\SdkGenerator\Contract\ApiContract;
use Dmorenof\SdkGenerator\Contract\SecuritySchemeDefinition;

/**
 * End-to-end HTTP SDK emitter.
 *
 * It adds the instance client and executable resource methods to the contract
 * emitter. No API-specific rules are coded here. Transport, hydration,
 * validation and security are inherited from the shared dmorenof/sdk-runtime
 * package; the generator is never required at runtime.
 */
final class PhpExecutableEmitter
{
    private ?PhpModelNameResolver $modelNames = null;
    /** @var list<SecuritySchemeDefinition> */
    private array $securitySchemes = [];
    /** @var array<string, array{type:?string,nullable:bool,schemaNullable:bool,noContentAllowed:bool}> */
    private array $operationSuccessTypes = [];
    /** @var array<string, array<string, string>> */
    private array $operationErrorTypes = [];
    public function __construct(
        private readonly string $namespace = 'Generated\Sdk',
        private readonly string $packageName = 'example/generated-sdk',
        /** @var array<string,string> Route prefix => generated class. */
        private readonly array $apiGroups = [],
    ) {}

    /** @return array<string, string> */
    public function emit(ApiContract $contract): array
    {
        $this->securitySchemes = $contract->securitySchemes;
        $contractEmitter = new PhpContractEmitter($this->namespace, $this->packageName);
        $files = $contractEmitter->emit($contract);
        $this->modelNames = new PhpModelNameResolver(
            $this->namespace,
            $contract->models,
            PhpContractEmitter::modelRolesFor($contract),
        );
        $this->operationSuccessTypes = $contractEmitter->operationSuccessTypes();
        $this->operationErrorTypes = $contractEmitter->operationErrorTypes();
        $requests = (new PhpOperationRequestEmitter($this->namespace, $this->modelNames))
            ->emitExecutable($contract);
        foreach ($requests['files'] as $path => $_source) {
            if (isset($files[$path])) {
                throw new \InvalidArgumentException("Operation Request conflicts with schema DTO: $path");
            }
        }
        unset(
            $files['src/Api/Operations.php'],
            $files['src/Api/OperationCatalog.php'],
        );
        foreach (array_keys($files) as $path) {
            if (str_starts_with($path, 'src/Api/')) {
                unset($files[$path]);
            }
        }
        $files = array_replace(
            $files,
            $requests['files'],
            (new PhpResourceEmitter(
                $this->namespace,
                $this->securitySchemes,
                $this->operationSuccessTypes,
                $this->operationErrorTypes,
                $this->modelNames,
                $this->apiGroups,
                $requests['types'],
            ))->emit($contract),
        );
        ksort($files, SORT_STRING);
        return $files;
    }
}
