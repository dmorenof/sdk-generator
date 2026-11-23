<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Parsing;

/**
 * A `oneOf` or `anyOf` of string enums is one string enum with every value: `RegionCode = LandRegionCode | MarineRegionCode`
 * accepts exactly the values of both. Written as one enum it is a type the generator, a parameter and a PHP enum
 * can all use, where the union is only a composition of references.
 *
 * Only named schemas (`components.schemas`, `definitions`) whose branches are references to, or inline, string enums
 * are rewritten; a `oneOf` whose enums overlap is left alone, because there the keyword means something else.
 */
final class EnumUnions
{
    /**
     * @param array<mixed> $document
     * @return array<mixed>
     */
    public static function collapse(array $document): array
    {
        $where = isset($document['swagger']) ? ['definitions'] : ['components', 'schemas'];
        $schemas = $document;
        foreach ($where as $key) {
            $schemas = $schemas[$key] ?? null;
            if (!is_array($schemas)) {
                return $document;
            }
        }
        // A union can be made of unions, so repeat until a pass changes nothing.
        do {
            $changed = false;
            foreach ($schemas as $name => $schema) {
                $values = self::values($schema, $schemas);
                if ($values !== null && !isset($schema['enum'])) {
                    $schemas[$name] = array_filter(['type' => 'string', 'enum' => $values, 'title' => $schema['title'] ?? null, 'description' => $schema['description'] ?? null], static fn ($v): bool => $v !== null);
                    $changed = true;
                }
            }
        } while ($changed);
        $target = &$document;
        foreach ($where as $key) {
            $target = &$target[$key];
        }
        $target = $schemas;
        return $document;
    }

    /**
     * The values of a union of string enums, or null when the schema is anything else.
     *
     * @param array<mixed> $schemas
     * @return list<string>|null
     */
    private static function values(mixed $schema, array $schemas): ?array
    {
        if (!is_array($schema)) {
            return null;
        }
        $keyword = isset($schema['oneOf']) ? 'oneOf' : (isset($schema['anyOf']) ? 'anyOf' : null);
        if ($keyword === null || !is_array($schema[$keyword]) || count($schema[$keyword]) < 2
            || array_diff(array_keys($schema), [$keyword, 'title', 'description']) !== []) {
            return null;
        }
        $all = [];
        foreach ($schema[$keyword] as $branch) {
            if (is_array($branch) && is_string($branch['$ref'] ?? null) && count($branch) === 1) {
                $name = preg_match('~^#/(?:components/schemas|definitions)/([^/]+)$~', $branch['$ref'], $match) === 1 ? $match[1] : null;
                $branch = $name === null ? null : ($schemas[$name] ?? null);
            }
            if (!is_array($branch) || ($branch['type'] ?? null) !== 'string' || !is_array($branch['enum'] ?? null) || $branch['enum'] === []
                || array_diff(array_keys($branch), ['type', 'enum', 'title', 'description']) !== []
                || array_filter($branch['enum'], static fn ($value): bool => !is_string($value)) !== []) {
                return null;
            }
            $all[] = $branch['enum'];
        }
        $merged = array_merge(...$all);
        // With oneOf a value in two enums matches two branches and is refused; as one enum it would be accepted.
        if ($keyword === 'oneOf' && count($merged) !== count(array_unique($merged))) {
            return null;
        }
        return array_values(array_unique($merged));
    }
}
