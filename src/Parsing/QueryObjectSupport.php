<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Parsing;

use Dmorenof\SdkGenerator\Contract\ModelDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaKind;
use Dmorenof\SdkGenerator\Contract\ParameterDefinition;
use Dmorenof\SdkGenerator\Contract\ParameterSerialization;
use Dmorenof\SdkGenerator\Diagnostics\JsonPointer;

/** Flat scalar OpenAPI parameter objects share schemas across deepObject/form/simple. */
final class QueryObjectSupport
{
    /** @param list<ModelDefinition> $models */
    public static function nullable(SchemaDefinition $schema, array $models): bool
    {
        if ($schema->kind === SchemaKind::Reference) {
            foreach ($models as $model) {
                if ($model->name === $schema->reference) {
                    return $model->schema->nullable;
                }
            }
        }
        return $schema->nullable;
    }

    public static function format(ParameterSerialization $options, string $location): ?string
    {
        if ($options->collectionFormat !== null || $options->collectionFormatDefault !== null) {
            return null;
        }
        if ($location === 'query') {
            if ($options->style === 'deepObject') {
                return $options->explode === true ? 'deepObject' : null;
            }
            return in_array($options->style, [null, 'form'], true)
                ? ($options->explode === false ? 'form-object' : 'form-object-explode') : null;
        }
        if ($location === 'path' && QueryArraySupport::pathFormat($options, 'object') !== null) {
            return QueryArraySupport::pathFormat($options, 'object');
        }
        return in_array($location, ['path', 'header'], true) && in_array($options->style, [null, 'simple'], true)
            ? ($options->explode === true ? 'object-explode' : 'object') : null;
    }

    /** @param list<ModelDefinition> $models */
    public static function supported(ParameterDefinition $parameter, array $models): bool
    {
        $format = self::format($parameter->serialization, $parameter->location);
        if ($format === null || $parameter->content !== []
            || ($parameter->location !== 'query' && $parameter->serialization->allowReserved === true) || $parameter->serialization->allowEmptyValue === true
            || !self::key($parameter->wireName, $format === 'deepObject')) {
            return false;
        }
        $schema = $parameter->schema;
        if ($schema->kind === SchemaKind::Reference) {
            $reference = $schema->reference;
            $schema = null;
            foreach ($models as $model) {
                if ($model->name === $reference) {
                    $schema = $model->schema;
                    break;
                }
            }
        }
        if ($schema === null || $schema->kind !== SchemaKind::Object || $schema->discriminator !== null
            || ($parameter->location !== 'query' && $schema->nullable) || $schema->enumValues !== []
            || ($schema->additionalProperties !== false && !($schema->additionalProperties instanceof SchemaDefinition))) {
            return false;
        }
        foreach ($schema->properties as $property) {
            if (!self::key($property->wireName, $format === 'deepObject') || !self::scalar($property->schema)) {
                return false;
            }
        }
        return !($schema->additionalProperties instanceof SchemaDefinition) || self::scalar($schema->additionalProperties);
    }

    private static function scalar(SchemaDefinition $schema): bool
    {
        return !$schema->nullable && in_array($schema->kind, [SchemaKind::String, SchemaKind::Integer, SchemaKind::Number, SchemaKind::Boolean], true)
            && ($schema->enumValues === [] || in_array($schema->kind, [SchemaKind::String, SchemaKind::Integer], true));
    }

    public static function rawSupported(array $parameter, array $document): bool
    {
        $location = $parameter['in'] ?? '';
        if (isset($document['swagger']) || !is_string($location)
            || isset($parameter['content']) || isset($parameter['collectionFormat'])
            || ($location !== 'query' && ($parameter['allowReserved'] ?? false) !== false) || ($parameter['allowEmptyValue'] ?? false) !== false
            || !is_string($parameter['name'] ?? null)
            || (isset($parameter['style']) && !is_string($parameter['style']))
            || (isset($parameter['explode']) && !is_bool($parameter['explode']))
            || self::format(new ParameterSerialization(style: $parameter['style'] ?? null, explode: $parameter['explode'] ?? null), $location) === null) {
            return false;
        }
        $deep = ($parameter['style'] ?? null) === 'deepObject';
        if (!self::key($parameter['name'], $deep)) { return false; }
        $schema = $parameter['schema'] ?? null;
        if (is_array($schema) && isset($schema['$ref'])) {
            try {
                $schema = JsonPointer::resolve($document, $schema['$ref']);
            } catch (\InvalidArgumentException|\TypeError) {
                return false;
            }
        }
        if (!is_array($schema) || array_key_exists('enum', $schema)
            || ($location !== 'query' && ((is_array($schema['type'] ?? null)) || ($schema['nullable'] ?? false) !== false))
            || !in_array($schema['type'] ?? null, ['object', ['object', 'null'], ['null', 'object'], null], true)
            || isset($schema['discriminator'])
            || isset($schema['allOf']) || isset($schema['oneOf']) || isset($schema['anyOf'])) {
            return false;
        }
        foreach ($schema['properties'] ?? [] as $name => $property) {
            if (!self::key((string) $name, $deep) || !is_array($property) || !self::rawScalar($property)) {
                return false;
            }
        }
        $additional = $schema['additionalProperties'] ?? null;
        return $additional === false || (is_array($additional) && self::rawScalar($additional));
    }

    private static function rawScalar(array $schema): bool
    {
        return in_array($schema['type'] ?? null, ['string', 'integer', 'number', 'boolean'], true)
            && !($schema['nullable'] ?? false)
            && (!array_key_exists('enum', $schema) || ($schema['enum'] !== [] && in_array($schema['type'], ['string', 'integer'], true)));
    }

    private static function key(string $key, bool $deep = true): bool
    {
        return $key !== '' && (!$deep || strpbrk($key, '[]') === false);
    }
}
