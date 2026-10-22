<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

use Dmorenof\SdkGenerator\Contract\ApiContract;
use Dmorenof\SdkGenerator\Contract\OperationDefinition;
use InvalidArgumentException;

/** Resolves one static API class for each distinct OpenAPI route template. */
final class PhpResourceNameResolver
{
    /**
     * @return array{
     *   nodes:array<string,array{segments:list<string>,path:string,operations:list<OperationDefinition>}>,
     *   operationNodes:array<string,string>,
     *   operationMethods:array<string,string>
     * }
     */
    public static function resolve(ApiContract $contract): array
    {
        $nodes = [];
        $operationNodes = [];
        $operationMethods = [];
        $classPaths = [];
        foreach ($contract->operations as $operation) {
            $key = strtolower($operation->path);
            $segments = self::resourceSegments($operation->path);
            $classPath = implode('\\', $segments);
            $classKey = strtolower($classPath);
            if (isset($classPaths[$classKey]) && $classPaths[$classKey] !== $key) {
                throw new InvalidArgumentException(
                    "Distinct API routes '{$classPaths[$classKey]}' and '{$operation->path}' map to $classPath.",
                );
            }
            $classPaths[$classKey] = $operation->path;
            $nodes[$key] ??= [
                'segments' => $segments,
                'path' => $operation->path,
                'operations' => [],
            ];
            foreach ($nodes[$key]['operations'] as $existing) {
                if (strtolower($existing->method) === strtolower($operation->method)) {
                    throw new InvalidArgumentException(
                        "Duplicate HTTP method {$operation->method} for route {$operation->path}.",
                    );
                }
            }
            $nodes[$key]['operations'][] = $operation;
            $operationNodes[$operation->operationId] = $key;
            $operationMethods[$operation->operationId] = self::operationMethod($operation);
        }

        return [
            'nodes' => $nodes,
            'operationNodes' => $operationNodes,
            'operationMethods' => $operationMethods,
        ];
    }

    /** @return list<string> namespace segments followed by the endpoint class */
    private static function resourceSegments(string $path): array
    {
        $parts = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $part): bool => $part !== ''));
        $lastStaticIndex = null;
        $static = [];
        foreach ($parts as $index => $part) {
            if (preg_match('/^\{([^{}]+)\}$/D', $part) === 1) {
                $static[$index] = null;
                continue;
            }
            $static[$index] = self::pascal($part);
            $lastStaticIndex = $index;
        }
        if ($lastStaticIndex === null) {
            return ['RootResource'];
        }

        $prefix = [];
        foreach (array_slice($parts, 0, $lastStaticIndex) as $part) {
            if (preg_match('/^\{([^{}]+)\}$/D', $part, $match) === 1) {
                $prefix[] = 'By' . self::pascal($match[1]);
            } else {
                $prefix[] = self::pascal($part);
            }
        }
        $resource = $static[$lastStaticIndex];
        $isItemRoute = isset($parts[$lastStaticIndex + 1])
            && preg_match('/^\{([^{}]+)\}$/D', $parts[$lastStaticIndex + 1]) === 1;
        if ($prefix === [] && $isItemRoute) {
            $prefix[] = $resource;
        }
        $class = $isItemRoute ? self::singular($resource) : $resource;
        if ($isItemRoute && $class === $resource
            && preg_match('/^\{([^{}]+)\}$/D', $parts[$lastStaticIndex + 1], $match) === 1) {
            $class .= 'By' . self::pascal($match[1]);
        }
        for ($index = $lastStaticIndex + 1; $index < count($parts); ++$index) {
            if (preg_match('/^\{([^{}]+)\}$/D', $parts[$index], $match) === 1 && $index !== $lastStaticIndex + 1) {
                $class .= 'By' . self::pascal($match[1]);
            }
        }

        return [...$prefix, $class];
    }

    private static function operationMethod(OperationDefinition $operation): string
    {
        $parameters = self::pathParameters($operation);
        $method = strtolower($operation->method);
        if ($parameters === []) {
            return $method;
        }
        $suffixes = [];
        foreach ($parameters as $parameter) {
            $name = self::pascal($parameter);
            $resource = self::staticSegments($operation->path);
            $leafResource = $resource === [] ? '' : self::singular((string) end($resource));
            if ($leafResource !== '' && str_starts_with(strtolower($name), strtolower($leafResource))) {
                $name = substr($name, strlen($leafResource));
            }
            $suffixes[] = $name !== '' ? $name : 'Id';
        }
        return $method . 'By' . implode('And', $suffixes);
    }

    private static function singular(string $name): string
    {
        if (preg_match('/(sses|shes|ches|xes|zes)$/i', $name) === 1) {
            return substr($name, 0, -2);
        }
        if (preg_match('/ies$/i', $name) === 1) {
            return substr($name, 0, -3) . 'y';
        }
        if (str_ends_with(strtolower($name), 's')
            && !preg_match('/(ss|us|is)$/i', $name)) {
            return substr($name, 0, -1);
        }
        return $name;
    }

    /** @return list<string> */
    public static function staticSegments(string $path): array
    {
        $segments = [];
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '' || preg_match('/^\{[^{}]+\}$/D', $segment) === 1) {
                continue;
            }
            $segments[] = self::pascal($segment);
        }
        return $segments;
    }

    public static function pascal(string $value): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            throw new InvalidArgumentException("Cannot derive a PHP resource name from: $value");
        }
        $name = implode('', array_map('ucfirst', $words));
        if (preg_match('/^[A-Za-z_]/', $name) !== 1) {
            $name = 'Resource' . $name;
        }
        if (in_array(strtolower($name), [
            'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch',
            'class', 'clone', 'const', 'continue', 'declare', 'default', 'die', 'do',
            'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach',
            'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends',
            'final', 'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto',
            'if', 'implements', 'include', 'include_once', 'instanceof', 'insteadof',
            'interface', 'isset', 'list', 'match', 'namespace', 'new', 'or', 'print',
            'private', 'protected', 'public', 'readonly', 'require', 'require_once',
            'return', 'static', 'switch', 'throw', 'trait', 'try', 'unset', 'use',
            'var', 'while', 'xor', 'yield',
        ], true)) {
            $name .= 'Resource';
        }
        return $name;
    }

    /** @return list<string> */
    public static function pathParameters(OperationDefinition $operation): array
    {
        preg_match_all('/\{([^{}]+)\}/', $operation->path, $matches);
        return $matches[1] ?? [];
    }
}
