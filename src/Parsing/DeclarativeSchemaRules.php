<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Parsing;

/** JSON Schema assertions which refine a PHP type without introducing a wrapper. */
final class DeclarativeSchemaRules
{
    public const KEYWORDS = ['not', 'if', 'then', 'else', 'contains', 'minContains', 'maxContains',
        'dependentRequired', 'dependentSchemas', 'propertyNames', 'prefixItems', 'patternProperties', 'unevaluatedProperties', 'unevaluatedItems'];

    /** @return array{array,string}|null */
    public static function extract(array $raw, bool $modern, string $where): ?array
    {
        $rules = array_intersect_key($raw, array_flip(self::KEYWORDS));
        if ($rules === []) { return null; }
        if (!$modern) { throw new \InvalidArgumentException("JSON Schema assertion requires OpenAPI 3.1 at $where."); }
        if (array_intersect(array_keys($raw), ['$ref', 'allOf', 'oneOf', 'anyOf']) !== []) {
            throw new \InvalidArgumentException("Assertion siblings on references/compositions require contextual evaluation at $where.");
        }
        $structural = array_diff_key($raw, $rules);
        if (array_intersect(array_keys($rules), ['patternProperties', 'unevaluatedProperties', 'unevaluatedItems']) !== []) {
            // Evaluated-property/item bookkeeping must see the complete sibling schema.
            // Raw additional values remain unconverted; Opis decides which patterns apply.
            $guard = self::encode($raw, $where);
            if (array_key_exists('patternProperties', $rules) || array_key_exists('unevaluatedProperties', $rules)) {
                if (($raw['type'] ?? null) !== 'object') {
                    throw new \InvalidArgumentException("Contextual property assertions currently require explicit object type at $where.");
                }
                $structural['additionalProperties'] = true;
            }
            if (array_key_exists('unevaluatedItems', $rules) || array_key_exists('prefixItems', $rules)) {
                if (($raw['type'] ?? null) !== 'array') {
                    throw new \InvalidArgumentException("Contextual item assertions currently require explicit array type at $where.");
                }
                $structural['items'] = true;
            }
            return [$structural, $guard];
        }
        if (($raw['type'] ?? null) === 'array' && !array_key_exists('items', $structural)) { $structural['items'] = true; }
        $guard = [];
        foreach ($rules as $keyword => $value) {
            if (in_array($keyword, ['minContains', 'maxContains'], true)) {
                if (!is_int($value) || $value < 0) { self::fail($where . '.' . $keyword); }
                $guard[$keyword] = $value;
            } elseif ($keyword === 'dependentRequired') {
                if (!is_array($value)) { self::fail($where . '.' . $keyword); }
                foreach ($value as $dependencies) {
                    if (!is_array($dependencies) || !array_is_list($dependencies)
                        || count(array_filter($dependencies, 'is_string')) !== count($dependencies)
                        || count(array_unique($dependencies)) !== count($dependencies)) { self::fail($where . '.' . $keyword); }
                }
                $guard[$keyword] = (object) $value;
            } elseif ($keyword === 'dependentSchemas') {
                if (!is_array($value)) { self::fail($where . '.' . $keyword); }
                $children = [];
                foreach ($value as $name => $child) { $children[$name] = self::schema($child, "$where.$keyword.$name"); }
                $guard[$keyword] = (object) $children;
            } elseif ($keyword === 'prefixItems') {
                if (!is_array($value) || !array_is_list($value) || $value === []) { self::fail($where . '.' . $keyword); }
                $guard[$keyword] = array_map(static fn ($child) => self::schema($child, "$where.$keyword"), $value);
                // Tuple positions have their own schemas. PHP's array preserves every value;
                // Opis checks the prefix and the tail together, before JSON leaves the SDK.
                if (array_key_exists('items', $raw)) { $guard['items'] = self::schema($raw['items'], "$where.items"); }
                $structural['items'] = true;
            } else {
                $guard[$keyword] = self::schema($value, "$where.$keyword");
            }
        }
        $json = json_encode((object) $guard, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        try { \Dmorenof\SdkRuntime\Constraints::compileSchema($json); }
        catch (\Throwable $error) { throw new \InvalidArgumentException("Invalid declarative schema assertion at $where: " . $error->getMessage(), 0, $error); }
        return [$structural, $json];
    }

    public static function conjoin(?string $left, string $right): string
    {
        if ($left === null) { return $right; }
        return json_encode(['allOf' => [json_decode($left, false, 512, JSON_THROW_ON_ERROR), json_decode($right, false, 512, JSON_THROW_ON_ERROR)]], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** Encode schema-position maps as JSON objects, delegating semantics to Opis. */
    public static function encode(array $raw, string $where): string
    {
        $json = json_encode(self::schema($raw, $where), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        try { \Dmorenof\SdkRuntime\Constraints::compileSchema($json); }
        catch (\Throwable $error) { throw new \InvalidArgumentException("Invalid schema at $where: " . $error->getMessage(), 0, $error); }
        return $json;
    }

    /** Compile self-contained assertion schemas; never resolve data as references. */
    private static function schema(mixed $raw, string $where): bool|\stdClass
    {
        if (is_bool($raw)) { return $raw; }
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) { self::fail($where); }
        $result = [];
        foreach ($raw as $key => $value) {
            // Scoped references and directional assertions need their own context. Reject
            // rather than change their meaning or fetch a resource during validation.
            if (in_array($key, ['$ref', '$id', '$anchor', '$dynamicRef', '$dynamicAnchor', 'readOnly', 'writeOnly', 'discriminator'], true)) {
                throw new \InvalidArgumentException("Context-dependent assertion '$key' is unsupported at $where.");
            }
            if (in_array($key, ['properties', 'patternProperties', 'dependentSchemas', '$defs'], true)) {
                if (!is_array($value)) { self::fail("$where.$key"); }
                $children = [];
                foreach ($value as $name => $child) { $children[$name] = self::schema($child, "$where.$key.$name"); }
                $result[$key] = (object) $children;
            } elseif (in_array($key, ['not', 'if', 'then', 'else', 'items', 'contains', 'additionalProperties', 'propertyNames', 'unevaluatedProperties', 'unevaluatedItems'], true)) {
                $result[$key] = self::schema($value, "$where.$key");
            } elseif (in_array($key, ['allOf', 'oneOf', 'anyOf', 'prefixItems'], true)) {
                if (!is_array($value) || !array_is_list($value) || $value === []) { self::fail("$where.$key"); }
                $result[$key] = array_map(static fn ($child) => self::schema($child, "$where.$key"), $value);
            } elseif ($key === 'dependentRequired') {
                $compiled = self::extract([$key => $value], true, $where);
                $result[$key] = json_decode($compiled[1], false, 512, JSON_THROW_ON_ERROR)->dependentRequired;
            } elseif ($key === 'const' || $key === 'enum') {
                if ($key === 'enum' && (!is_array($value) || !array_is_list($value) || $value === [])) { self::fail("$where.$key"); }
                $result[$key] = $value;
            } elseif (in_array($key, ['type', 'required', 'minLength', 'maxLength', 'minItems', 'maxItems', 'minProperties', 'maxProperties', 'minContains', 'maxContains', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'uniqueItems', 'pattern', 'format'], true)) {
                $result[$key] = $value;
            } elseif (!in_array($key, ['title', 'description', 'default', 'examples', 'deprecated', '$comment'], true) && !str_starts_with($key, 'x-')) {
                throw new \InvalidArgumentException("Unsupported assertion keyword '$key' at $where.");
            }
        }
        return (object) $result;
    }

    private static function fail(string $where): never
    {
        throw new \InvalidArgumentException("Invalid declarative schema assertion at $where.");
    }
}
