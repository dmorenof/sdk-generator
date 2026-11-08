<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Parsing;

use Dmorenof\SdkGenerator\Contract\ModelDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaKind;
use Dmorenof\SdkGenerator\Contract\ParameterDefinition;
use Dmorenof\SdkGenerator\Diagnostics\JsonPointer;

/** The defined, flat scalar subset of OpenAPI deepObject query parameters. */
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

    /** @param list<ModelDefinition> $models */
    public static function supported(ParameterDefinition $parameter, array $models): bool
    {
        if ($parameter->location !== 'query' || $parameter->serialization->style !== 'deepObject'
            || $parameter->serialization->explode !== true || $parameter->serialization->collectionFormat !== null
            || $parameter->content !== [] || !self::key($parameter->wireName)) {
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
            || ($schema->additionalProperties !== false && !($schema->additionalProperties instanceof SchemaDefinition))) {
            return false;
        }
        foreach ($schema->properties as $property) {
            if (!self::key($property->wireName) || !self::scalar($property->schema)) {
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
        if (isset($document['swagger']) || ($parameter['in'] ?? null) !== 'query'
            || ($parameter['style'] ?? null) !== 'deepObject' || ($parameter['explode'] ?? null) !== true
            || isset($parameter['content']) || isset($parameter['collectionFormat'])
            || !is_string($parameter['name'] ?? null) || !self::key($parameter['name'])) {
            return false;
        }
        $schema = $parameter['schema'] ?? null;
        if (is_array($schema) && isset($schema['$ref'])) {
            try {
                $schema = JsonPointer::resolve($document, $schema['$ref']);
            } catch (\InvalidArgumentException|\TypeError) {
                return false;
            }
        }
        if (!is_array($schema) || !in_array($schema['type'] ?? null, ['object', ['object', 'null'], ['null', 'object'], null], true)
            || isset($schema['discriminator'])
            || isset($schema['allOf']) || isset($schema['oneOf']) || isset($schema['anyOf'])) {
            return false;
        }
        foreach ($schema['properties'] ?? [] as $name => $property) {
            if (!self::key((string) $name) || !is_array($property) || !self::rawScalar($property)) {
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
            && (!isset($schema['enum']) || in_array($schema['type'], ['string', 'integer'], true));
    }

    private static function key(string $key): bool
    {
        return $key !== '' && strpbrk($key, '[]') === false;
    }
}
