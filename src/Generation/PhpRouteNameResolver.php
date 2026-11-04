<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

use Dmorenof\SdkGenerator\Contract\ApiContract;
use Dmorenof\SdkGenerator\Contract\OperationDefinition;
use InvalidArgumentException;

/**
 * Exact OpenAPI route -> PHP namespace mapping.
 *
 * /v1/invoices                   -> \Sdk\V1\Invoices::get()
 * /v1/invoices/{id}              -> \Sdk\V1\Invoices::getById($id)
 * /v1/invoices/{id}/lines        -> \Sdk\V1\Invoices\ById\Lines::get($id)
 * /v1/invoices/{id}/lines/{line} -> \Sdk\V1\Invoices\ById\Lines::getByLine($ids)
 *
 * Every literal URL segment becomes one PascalCase namespace/class segment.
 * Intermediate path placeholders become By{Placeholder} namespace segments;
 * a terminal placeholder becomes the By{Placeholder} method suffix.
 */
final class PhpRouteNameResolver
{
    /**
     * @return array{
     *   nodes: array<string,array{segments:list<string>,operations:list<OperationDefinition>}>,
     *   operationNodes: array<string,string>,
     *   operationMethods: array<string,string>,
     *   parameterSegments: array<string,list<string>>
     * }
     */
    public static function resolve(ApiContract $contract): array
    {
        $nodes = [];
        $operationNodes = [];
        $operationMethods = [];
        $parameterSegments = [];
        $usedClasses = [];
        foreach ($contract->operations as $operation) {
            $parts = self::segments($operation->path);
            $last = end($parts);
            $terminal = is_string($last) ? self::placeholder($last) : null;
            $classParts = $terminal === null ? $parts : array_slice($parts, 0, -1);
            $segments = array_map(static function (string $part): string {
                $placeholder = self::placeholder($part);
                return $placeholder !== null
                    ? 'By' . PhpResourceNameResolver::pascal($placeholder)
                    : PhpResourceNameResolver::pascal($part);
            }, $classParts);
            if ($segments === []) {
                $segments = ['RootResource'];
            }
            $method = strtolower($operation->method)
                . ($terminal === null ? '' : 'By' . PhpResourceNameResolver::pascal($terminal));
            $key = strtolower(implode('/', $segments));
            $className = implode('\\', $segments);
            if (isset($usedClasses[$key]) && $usedClasses[$key] !== $className) {
                throw new InvalidArgumentException("Case-insensitive route class collision: $className.");
            }
            $usedClasses[$key] = $className;
            $nodes[$key] ??= ['segments' => $segments, 'operations' => []];
            foreach ($nodes[$key]['operations'] as $existing) {
                if (strcasecmp(self::methodFor($existing), $method) === 0) {
                    throw new InvalidArgumentException(
                        "Route method collision at $className::$method for {$operation->path}."
                    );
                }
            }
            $nodes[$key]['operations'][] = $operation;
            $operationNodes[$operation->operationId] = $key;
            $operationMethods[$operation->operationId] = $method;
            $parameterSegments[$operation->operationId] = $terminal === null
                ? $segments
                : [...$segments, 'By' . PhpResourceNameResolver::pascal($terminal)];
        }
        ksort($nodes, SORT_STRING);
        return compact('nodes', 'operationNodes', 'operationMethods', 'parameterSegments');
    }

    private static function methodFor(OperationDefinition $operation): string
    {
        $parts = self::segments($operation->path);
        $last = end($parts);
        $terminal = is_string($last) ? self::placeholder($last) : null;
        return strtolower($operation->method)
            . ($terminal === null ? '' : 'By' . PhpResourceNameResolver::pascal($terminal));
    }

    /** @return list<string> */
    private static function segments(string $path): array
    {
        return array_values(array_filter(explode('/', trim($path, '/')), static fn (string $s): bool => $s !== ''));
    }

    private static function placeholder(string $part): ?string
    {
        return preg_match('/^\{([^{}]+)\}$/D', $part, $m) === 1 ? $m[1] : null;
    }
}
