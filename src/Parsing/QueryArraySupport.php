<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Parsing;

use Dmorenof\SdkGenerator\Contract\ParameterSerialization;

/** Bounded query-array wire formats shared by compatibility audit and emission. */
final class QueryArraySupport
{
    public static function format(ParameterSerialization $options): ?string
    {
        if ($options->collectionFormatDefault === 'csv') {
            if ($options->style !== null || $options->explode !== null) {
                return null;
            }
            return match ($options->collectionFormat ?? 'csv') { 'csv' => 'csv', 'multi' => 'multi', default => null };
        }
        if ($options->collectionFormat !== null || !in_array($options->style, [null, 'form'], true)) {
            return null;
        }
        return $options->explode === false ? 'csv' : 'multi';
    }

    public static function rawSupported(array $parameter, bool $swagger): bool
    {
        if (($parameter['in'] ?? null) !== 'query' || isset($parameter['content'])) {
            return false;
        }
        $schema = $parameter['schema'] ?? $parameter;
        if (isset($schema['anyOf']) && is_array($schema['anyOf']) && count($schema['anyOf']) === 2) {
            $nonNull = array_values(array_filter($schema['anyOf'], static fn ($branch): bool => is_array($branch) && ($branch['type'] ?? null) !== 'null'));
            $schema = count($nonNull) === 1 ? $nonNull[0] : [];
        }
        $types = $schema['type'] ?? null;
        $array = $types === 'array' || (is_array($types) && count($types) === 2 && in_array('array', $types, true) && in_array('null', $types, true));
        $item = $schema['items'] ?? null;
        if (!$array || !empty($schema['enum']) || !is_array($item)
            || !in_array($item['type'] ?? null, ['string', 'integer', 'number', 'boolean'], true)
            || ($item['nullable'] ?? $item['x-nullable'] ?? false)
            || (!empty($item['enum']) && !in_array($item['type'], ['string', 'integer'], true))) {
            return false;
        }
        if ((isset($parameter['style']) && !is_string($parameter['style']))
            || (isset($parameter['explode']) && !is_bool($parameter['explode']))
            || (isset($parameter['collectionFormat']) && !is_string($parameter['collectionFormat']))) {
            return false;
        }
        return self::format(new ParameterSerialization(style: $parameter['style'] ?? null, explode: $parameter['explode'] ?? null,
            collectionFormat: $parameter['collectionFormat'] ?? null, collectionFormatDefault: $swagger ? 'csv' : null)) !== null;
    }
}
