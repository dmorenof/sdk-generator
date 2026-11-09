<?php

declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Parsing;

use Dmorenof\SdkGenerator\Contract\ParameterSerialization;

/** Primitive parameter-list formats shared by audit and emission, including simple path/header CSV. */
final class QueryArraySupport
{
    public static function pathFormat(ParameterSerialization $options, string $kind): ?string
    {
        if ($options->collectionFormat !== null || $options->collectionFormatDefault !== null
            || !in_array($options->style, ['label', 'matrix'], true)) {
            return null;
        }
        return $options->style . ($kind === 'scalar' ? '' : '-' . $kind . ($options->explode === true ? '-explode' : ''));
    }

    public static function format(ParameterSerialization $options, string $location = 'query'): ?string
    {
        if ($options->collectionFormatDefault === 'csv') {
            if ($options->style !== null || $options->explode !== null) {
                return null;
            }
            if ($location !== 'query') {
                return in_array($location, ['path', 'header'], true) && in_array($options->collectionFormat, [null, 'csv'], true) ? 'csv' : null;
            }
            return match ($options->collectionFormat ?? 'csv') {
                'csv' => 'csv', 'multi' => 'multi', 'ssv' => 'ssv', 'tsv' => 'tsv', 'pipes' => 'pipes', default => null,
            };
        }
        if ($options->collectionFormat !== null) {
            return null;
        }
        if ($location === 'path' && self::pathFormat($options, 'list') !== null) {
            return self::pathFormat($options, 'list');
        }
        if ($location !== 'query') {
            return in_array($location, ['path', 'header'], true) && in_array($options->style, [null, 'simple'], true) ? 'csv' : null;
        }
        return match ($options->style) {
            null, 'form' => $options->explode === false ? 'csv' : 'multi',
            'spaceDelimited' => $options->explode === true ? null : 'ssv',
            'pipeDelimited' => $options->explode === true ? null : 'pipes',
            default => null,
        };
    }

    public static function rawSupported(array $parameter, bool $swagger): bool
    {
        $location = $parameter['in'] ?? null;
        if (!in_array($location, ['query', 'path', 'header'], true) || isset($parameter['content'])) {
            return false;
        }
        $schema = $parameter['schema'] ?? $parameter;
        if ($location !== 'query' && (($schema['type'] ?? null) !== 'array'
            || ($schema['nullable'] ?? $schema['x-nullable'] ?? false) !== false)) {
            return false;
        }
        if (isset($schema['anyOf']) && is_array($schema['anyOf']) && count($schema['anyOf']) === 2) {
            $nonNull = array_values(array_filter($schema['anyOf'], static fn ($branch): bool => is_array($branch) && ($branch['type'] ?? null) !== 'null'));
            $schema = count($nonNull) === 1 ? $nonNull[0] : [];
        }
        $types = $schema['type'] ?? null;
        $array = $types === 'array' || (is_array($types) && count($types) === 2 && in_array('array', $types, true) && in_array('null', $types, true));
        $item = $schema['items'] ?? null;
        if (!$array || array_key_exists('enum', $schema) || !is_array($item)
            || !in_array($item['type'] ?? null, ['string', 'integer', 'number', 'boolean'], true)
            || ($item['nullable'] ?? $item['x-nullable'] ?? false)
            || (array_key_exists('enum', $item) && $item['enum'] === [])
            || (!empty($item['enum']) && !in_array($item['type'], ['string', 'integer'], true))) {
            return false;
        }
        if ((isset($parameter['style']) && !is_string($parameter['style']))
            || (isset($parameter['explode']) && !is_bool($parameter['explode']))
            || (isset($parameter['collectionFormat']) && !is_string($parameter['collectionFormat']))) {
            return false;
        }
        return self::format(new ParameterSerialization(
            style: $parameter['style'] ?? null,
            explode: $parameter['explode'] ?? null,
            collectionFormat: $parameter['collectionFormat'] ?? null,
            collectionFormatDefault: $swagger ? 'csv' : null
        ), $location) !== null;
    }
}
