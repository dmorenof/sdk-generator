<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Parsing;

/** Keep JSON literal identity while exposing specification objects as PHP maps. */
final class SpecificationData
{
    public static function json(string $source): mixed
    {
        return self::maps(json_decode($source, false, 512, JSON_THROW_ON_ERROR));
    }

    public static function yaml(string $source): mixed
    {
        $value = \Symfony\Component\Yaml\Yaml::parse($source,
            \Symfony\Component\Yaml\Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | \Symfony\Component\Yaml\Yaml::PARSE_OBJECT_FOR_MAP);
        return self::maps($value);
    }

    private static function maps(mixed $value, string $context = 'object'): mixed
    {
        if ($value instanceof \stdClass) {
            $result = [];
            foreach (get_object_vars($value) as $key => $child) {
                if (str_starts_with($context, 'map:')) {
                    $result[$key] = self::maps($child, substr($context, 4));
                    continue;
                }
                if (in_array($key, ['const', 'enum'], true)) { $result[$key] = $child; continue; }
                $next = in_array($key, ['properties', 'schemas', 'definitions', '$defs', 'patternProperties', 'dependentSchemas', 'dependentRequired', 'paths', 'parameters', 'requestBodies', 'responses', 'headers', 'links', 'callbacks', 'pathItems', 'securitySchemes', 'examples', 'encoding'], true) ? 'map:object' : 'object';
                $result[$key] = self::maps($child, $next);
            }
            return $result;
        }
        if (is_array($value)) { return array_map(static fn ($child) => self::maps($child, str_starts_with($context, 'map:') ? substr($context, 4) : $context), $value); }
        return $value;
    }
}
