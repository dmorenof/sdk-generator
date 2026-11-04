<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

use Dmorenof\SdkGenerator\Contract\ApiContract;
use Dmorenof\SdkGenerator\Contract\OperationDefinition;
use InvalidArgumentException;

/** Structural route grouping; no semantic inference from operationId or tags. */
final class PhpApiGroupingResolver
{
    /**
     * @param array<string,string> $overrides Absolute route prefix => API class name.
     * @return array<string,list<array{operation:OperationDefinition,method:string,prefix:string}>>
     */
    public static function resolve(ApiContract $contract, array $overrides = []): array
    {
        $rules = self::normalizeOverrides($overrides);
        $hasChildren = [];
        foreach ($contract->operations as $operation) {
            $segments = self::segments($operation->path);
            if (count($segments) > 1) {
                $hasChildren[strtolower($segments[0])] = true;
            }
        }
        $groups = [];
        foreach ($contract->operations as $operation) {
            $segments = self::segments($operation->path);
            [$prefix, $class] = self::groupFor($segments, $hasChildren, $rules);
            $groups[$class][] = [
                'operation' => $operation,
                'method' => self::methodName($operation, array_slice($segments, count($prefix))),
                'prefix' => '/' . implode('/', $prefix),
            ];
        }

        foreach ($groups as &$entries) {
            $counts = [];
            foreach ($entries as $entry) {
                $name = strtolower($entry['method']);
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
            $occupied = [];
            foreach ($entries as &$entry) {
                $name = $entry['method'];
                if ($counts[strtolower($name)] > 1) {
                    $operation = $entry['operation'];
                    $name .= ucfirst(substr(hash('sha256', $operation->method . ' ' . $operation->path), 0, 10));
                }
                $key = strtolower($name);
                if (isset($occupied[$key])) {
                    throw new InvalidArgumentException("Unresolvable grouped API method collision: $name");
                }
                $occupied[$key] = true;
                $entry['method'] = $name;
            }
            unset($entry);
        }
        unset($entries);
        uksort($groups, 'strnatcasecmp');
        return $groups;
    }

    /**
     * @param list<string> $segments
     * @param array<string,bool> $hasChildren
     * @param array<string,array{segments:list<string>,class:string}> $rules
     * @return array{0:list<string>,1:string}
     */
    private static function groupFor(array $segments, array $hasChildren, array $rules): array
    {
        foreach ($rules as $rule) {
            $length = count($rule['segments']);
            if ($length <= count($segments)
                && array_map('strtolower', array_slice($segments, 0, $length))
                    === array_map('strtolower', $rule['segments'])) {
                return [$rule['segments'], $rule['class']];
            }
        }
        $first = $segments[0] ?? '';
        $second = $segments[1] ?? '';
        $version = static fn (string $value): bool =>
            preg_match('/^v[0-9]+(?:[._-][0-9]+)*$/iD', $value) === 1;

        if ($first === '') {
            return [[], 'RootApi'];
        }
        if (strtolower($first) === 'api' && $version($second)) {
            return [array_slice($segments, 0, 2), 'Api' . self::pascal($second)];
        }
        if ($version($first) && $second !== '' && !str_starts_with($second, '{')) {
            return [array_slice($segments, 0, 2), self::apiClass($second) . self::pascal($first)];
        }
        if ($version($first)) {
            return [[$first], 'Api' . self::pascal($first)];
        }
        if (count($segments) === 1 && !isset($hasChildren[strtolower($first)])) {
            return [[], 'RootApi'];
        }
        if (str_starts_with($first, '{')) {
            return [[], 'RootApi'];
        }
        return [[$first], self::apiClass($first)];
    }

    private static function apiClass(string $segment): string
    {
        $name = self::pascal($segment);
        return preg_match('/Api$/iD', $name) === 1 ? $name : $name . 'Api';
    }

    private static function pascal(string $segment): string
    {
        return PhpResourceNameResolver::pascal($segment);
    }

    /** @param list<string> $segments Relative path, after the chosen group prefix. */
    private static function methodName(OperationDefinition $operation, array $segments): string
    {
        $suffix = '';
        foreach ($segments as $segment) {
            if (preg_match('/^\{([^{}]+)\}$/D', $segment, $match) === 1) {
                $suffix .= 'By' . self::pascal($match[1]);
            } else {
                $suffix .= self::pascal($segment);
            }
        }
        return PhpOperationNameResolver::resolve(strtolower($operation->method) . $suffix);
    }

    /** @return list<string> */
    private static function segments(string $path): array
    {
        return array_values(array_filter(explode('/', trim($path, '/')), static fn (string $s): bool => $s !== ''));
    }

    /**
     * @param array<string,string> $overrides
     * @return array<string,array{segments:list<string>,class:string}>
     */
    private static function normalizeOverrides(array $overrides): array
    {
        $rules = [];
        foreach ($overrides as $prefix => $class) {
            if (!is_string($prefix) || !str_starts_with($prefix, '/')
                || !is_string($class) || preg_match('/^[A-Z][A-Za-z0-9_]*$/D', $class) !== 1) {
                throw new InvalidArgumentException('Group overrides must map absolute route prefixes to valid PHP API class names.');
            }
            $segments = self::segments($prefix);
            if ($segments === [] || array_filter($segments, static fn (string $s): bool =>
                preg_match('/^\{[^{}]+\}$/D', $s) === 1) !== []) {
                throw new InvalidArgumentException("Group override prefixes must be literal: $prefix");
            }
            $key = strtolower(implode('/', $segments));
            if (isset($rules[$key])) {
                throw new InvalidArgumentException("Duplicate group prefix: $prefix");
            }
            $rules[$key] = ['segments' => $segments, 'class' => $class];
        }
        uasort($rules, static fn (array $a, array $b): int =>
            count($b['segments']) <=> count($a['segments']));
        return $rules;
    }
}
