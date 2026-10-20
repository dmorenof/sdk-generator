<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

use Dmorenof\SdkGenerator\Contract\ApiContract;
use Dmorenof\SdkGenerator\Contract\OperationDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaKind;
use InvalidArgumentException;

/**
 * One strongly typed Request object per operation with URL/header parameters.
 * A body-only operation reuses its referenced generated model (Core\Request).
 */
final readonly class PhpOperationRequestEmitter
{
    public function __construct(
        private string $namespace,
        private ?PhpModelNameResolver $modelNames = null,
    ) {}

    /** @return array<string, string> */
    public function emit(ApiContract $contract): array
    {
        $files = [];
        foreach ($contract->operations as $operation) {
            if ($operation->parameters === []) {
                continue;
            }
            $name = self::name($operation);
            $path = "src/Requests/$name.php";
            if (isset($files[$path])) {
                throw new InvalidArgumentException("Duplicate generated Request class: $name");
            }
            $fields = [];
            $required = [];
            $seen = [];
            foreach ($operation->parameters as $parameter) {
                $field = $parameter->wireName;
                $this->checkName($field);
                if (isset($seen[strtolower($field)])) {
                    throw new InvalidArgumentException("Duplicate Request parameter $field for $name");
                }
                $seen[strtolower($field)] = true;
                $type = match ($parameter->schema->kind) {
                    SchemaKind::String => 'string',
                    SchemaKind::Integer => 'int',
                    SchemaKind::Number => 'float',
                    SchemaKind::Boolean => 'bool',
                    default => throw new InvalidArgumentException("Unsupported Request parameter $field in $name"),
                };
                if ($parameter->schema->nullable || $parameter->schema->enumValues !== []) {
                    throw new InvalidArgumentException("Unsupported nullable/enum Request parameter $field in $name");
                }
                $fields[] = '    public ' . ($parameter->required ? $type : '?' . $type) . ' $' . $field . ';';
                if ($parameter->required) {
                    $required[] = $field;
                }
            }
            if ($operation->requestBody !== null) {
                $body = $operation->requestBody;
                if ($body->schema->kind !== SchemaKind::Reference || $body->schema->nullable
                    || $body->schema->reference === null) {
                    throw new InvalidArgumentException("Unsupported mixed Request body in $name");
                }
                if (isset($seen['body'])) {
                    throw new InvalidArgumentException("Request body conflicts with parameter in $name");
                }
                // Full dotted Swagger model refs are resolved by the shared registry.
                $type = ($this->modelNames ?? new PhpModelNameResolver($this->namespace, $contract->models))
                    ->fqcn($body->schema->reference);
                $fields[] = '    public ' . ($body->required ? $type : '?' . $type) . ' $body;';
                if ($body->required) {
                    $required[] = 'body';
                }
            }
            $header = "<?php\n"
                . "declare(strict_types=1);\n\n"
                . "namespace {$this->namespace}\\Requests;\n\n";
            $source = $header . 'final class ' . $name . ' extends \\' . $this->namespace
                . "\\Core\\Request\n{\n"
                . '    public const array REQUIRED_FIELDS = ' . var_export($required, true) . ";\n\n"
                . implode("\n", $fields) . "\n}\n";
            $files[$path] = $source;
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    public static function name(OperationDefinition $operation): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $operation->operationId) !== 1) {
            throw new InvalidArgumentException("Invalid operation identifier: $operation->operationId");
        }
        return ucfirst($operation->operationId) . 'Request';
    }

    public function fqcn(OperationDefinition $operation): string
    {
        return '\\' . $this->namespace . '\\Requests\\' . self::name($operation);
    }

    private function checkName(string $name): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
            throw new InvalidArgumentException("Invalid Request property name: $name");
        }
    }
}
