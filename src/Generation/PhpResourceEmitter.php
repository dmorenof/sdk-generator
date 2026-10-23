<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

use Dmorenof\SdkGenerator\Contract\ApiContract;
use Dmorenof\SdkGenerator\Contract\OperationDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaKind;
use InvalidArgumentException;

/** Emits instance clients with inherited execution and typed resource methods. */
final readonly class PhpResourceEmitter
{
    /** Must equal the runtime's ApiClient::OPERATION_DEFAULTS; a test enforces it. */
    public const OPERATION_DEFAULTS = [
        'security' => [],
        'success' => [200],
        'errors' => [],
        'responseType' => null,
        'schemaNullable' => false,
        'noContentAllowed' => false,
        'validation' => [],
    ];

    /**
     * @param array<string,array{type:?string,nullable:bool,schemaNullable:bool,noContentAllowed:bool}> $successTypes
     * @param array<string,array<string,string>> $errorTypes
     */
    public function __construct(
        private string $namespace,
        private array $securitySchemes,
        private array $successTypes,
        private array $errorTypes,
        private PhpModelNameResolver $modelNames,
    ) {}

    /** @return array<string,string> */
    public function emit(ApiContract $contract): array
    {
        $resolved = PhpResourceNameResolver::resolve($contract);
        $files = ['src/Client.php' => $this->renderConnector()];
        foreach ($resolved['nodes'] as $node) {
            if (strtolower($node['segments'][0]) === 'api' && count($node['segments']) > 1) {
                array_shift($node['segments']);
            }
            $path = 'src/Api/' . implode('/', array_slice($node['segments'], 0, -1))
                . (count($node['segments']) > 1 ? '/' : '')
                . end($node['segments']) . '.php';
            $files[$path] = $this->renderResource($node, $resolved['operationMethods']);
        }
        return $files;
    }

    private function renderConnector(): string
    {
        $schemes = [];
        foreach ($this->securitySchemes as $scheme) {
            $schemes[$scheme->name] = [
                'type' => $scheme->type,
                'scheme' => $scheme->scheme,
                'location' => $scheme->location,
                'parameterName' => $scheme->parameterName,
            ];
        }
        return $this->header($this->namespace)
            . 'class Client extends \\' . PhpContractEmitter::RUNTIME_NAMESPACE . "\\ApiClient\n{\n"
            . '    protected const array SECURITY_SCHEMES = ' . PhpLiteral::export($schemes) . ";\n"
            . "}\n";
    }

    /**
     * @param array{segments:list<string>,path:string,operations:list<OperationDefinition>} $node
     * @param array<string,string> $operationMethods
     */
    private function renderResource(array $node, array $operationMethods): string
    {
        $segments = $node['segments'];
        $class = end($segments);
        $parentSegments = array_slice($segments, 0, -1);
        $namespace = $this->namespace . '\\Api'
            . ($parentSegments === [] ? '' : '\\' . implode('\\', $parentSegments));
        $source = $this->header($namespace)
            . "final class $class extends \\{$this->namespace}\\Client\n{\n";
        foreach ($node['operations'] as $operation) {
            $method = $operationMethods[$operation->operationId]
                ?? throw new InvalidArgumentException("Missing resource method for $operation->operationId.");
            $source .= $this->renderOperation($operation, $method);
        }
        return $source . "}\n";
    }

    private function renderOperation(OperationDefinition $operation, string $method): string
    {
        $parameters = [];
        foreach ($operation->parameters as $parameter) {
            $resolved = PhpParameterTypeResolver::resolve(
                $parameter->schema,
                $operation->operationId . '.' . $parameter->wireName,
            );
            $name = self::variableName($parameter->wireName);
            $parameters[] = [
                'wireName' => $parameter->wireName,
                'name' => $name,
                'location' => $parameter->location,
                'type' => $resolved['type'],
                'nullable' => !$parameter->required || $resolved['nullable'],
                'required' => $parameter->required,
                'validation' => $parameter->schema->validation
                    + ($resolved['enumValues'] !== [] ? ['enum' => $resolved['enumValues']] : []),
            ];
        }
        if ($operation->requestBody !== null) {
            $body = $operation->requestBody;
            if ($body->schema->kind !== SchemaKind::Reference || $body->schema->nullable) {
                throw new InvalidArgumentException("Unsupported request body at $operation->operationId");
            }
            $parameters[] = [
                'wireName' => 'body',
                'name' => 'body',
                'location' => 'body',
                'type' => $this->modelNames->fqcn($body->schema->reference
                    ?? throw new InvalidArgumentException("Missing request body reference at $operation->operationId")),
                'nullable' => !$body->required,
                'required' => $body->required,
                'validation' => [],
            ];
        }
        usort($parameters, static fn (array $a, array $b): int => (int) $b['required'] <=> (int) $a['required']);

        $argumentList = [];
        $seenArguments = [];
        foreach ($parameters as $parameter) {
            $name = $parameter['name'];
            if (isset($seenArguments[strtolower($name)])) {
                throw new InvalidArgumentException("Duplicate parameter $name at $operation->operationId");
            }
            $seenArguments[strtolower($name)] = true;
            $argumentList[] = ($parameter['nullable'] ? '?' : '') . $parameter['type'] . ' $' . $name
                . ($parameter['required'] ? '' : ' = null');
        }

        $success = $this->successTypes[$operation->operationId]
            ?? throw new InvalidArgumentException("Missing success response metadata at $operation->operationId");
        $returnType = $success['type'] ?? 'void';
        if ($success['nullable'] && $success['type'] !== null) {
            $returnType = '?' . $returnType;
        }
        $pathParameters = [];
        $queryParameters = [];
        $headerParameters = [];
        $parameterValidations = [];
        $body = 'null';
        foreach ($parameters as $parameter) {
            $name = '$' . $parameter['name'];
            if ($parameter['validation'] !== []) {
                $parameterValidations[$parameter['wireName']] = $parameter['validation'];
            }
            if ($parameter['location'] === 'path') {
                $pathParameters[$parameter['wireName']] = $name;
            } elseif ($parameter['location'] === 'query') {
                $queryParameters[$parameter['wireName']] = $name;
            } elseif ($parameter['location'] === 'header') {
                $headerParameters[$parameter['wireName']] = $name;
            } elseif ($parameter['location'] === 'body') {
                $body = $name;
            }
        }
        $pathParameters = self::formatParameterMap($pathParameters);
        $queryParameters = self::formatParameterMap($queryParameters);
        $headerParameters = self::formatParameterMap($headerParameters);
        $successStatuses = array_values(array_map(
            static fn ($response): int => (int) $response->status,
            array_filter(
                $operation->responses,
                static fn ($response): bool => preg_match('/^2[0-9]{2}$/', $response->status) === 1,
            ),
        ));
        $metadata = [
            'method' => strtoupper($operation->method),
            'path' => $operation->path,
            'security' => $operation->securityRequirements,
            'success' => $successStatuses,
            'errors' => $this->errorTypes[$operation->operationId] ?? [],
            'responseType' => $success['type'],
            'schemaNullable' => $success['schemaNullable'],
            'noContentAllowed' => $success['noContentAllowed'],
            'validation' => $parameterValidations,
        ];
        foreach (self::OPERATION_DEFAULTS as $key => $default) {
            if ($metadata[$key] === $default) {
                unset($metadata[$key]);
            }
        }
        $constant = strtoupper(preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $method));
        $source = '    private const array ' . $constant . ' = ' . PhpLiteral::export($metadata, 1) . ";\n\n";
        $arguments = ['self::' . $constant];
        if ($pathParameters !== '[]') { $arguments[] = 'pathParameters: ' . $pathParameters; }
        if ($queryParameters !== '[]') { $arguments[] = 'queryParameters: ' . $queryParameters; }
        if ($headerParameters !== '[]') { $arguments[] = 'headerParameters: ' . $headerParameters; }
        if ($body !== 'null') { $arguments[] = 'body: ' . $body; }
        $call = '$this->executeOperation(' . implode(', ', $arguments) . ')';
        $source .= "    public function $method(" . implode(', ', $argumentList) . "): $returnType\n    {\n";
        $source .= $success['type'] === null
            ? "        $call;\n        return;\n"
            : "        return $call;\n";
        return $source . "    }\n\n";
    }

    /** @param array<string,string> $parameters */
    private static function formatParameterMap(array $parameters): string
    {
        $entries = [];
        foreach ($parameters as $wireName => $variable) {
            $entries[] = var_export($wireName, true) . ' => ' . $variable;
        }
        return '[' . implode(', ', $entries) . ']';
    }

    private function header(string $namespace): string
    {
        return "<?php\n"
            . "declare(strict_types=1);\n\n"
            . "namespace $namespace;\n\n";
    }

    private static function variableName(string $wireName): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $wireName) === 1) {
            return $wireName;
        }
        $name = lcfirst(PhpResourceNameResolver::pascal($wireName));
        return $name;
    }
}
