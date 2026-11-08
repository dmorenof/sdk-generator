<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Parsing;

use Dmorenof\SdkGenerator\Contract\ModelDefinition;
use Dmorenof\SdkGenerator\Contract\RequestBodyDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaKind;
use Dmorenof\SdkGenerator\Diagnostics\JsonPointer;

/** A bounded URL-encoded form: closed object of scalars and primitive lists. */
final class FormSupport
{
    public const MEDIA = 'application/x-www-form-urlencoded';

    /** Supported Swagger Parameter Objects can be lowered to the same form body contract. */
    public static function swaggerField(array $field): bool
    {
        if (($field['in'] ?? null) !== 'formData' || !is_string($field['name'] ?? null) || $field['name'] === ''
            || (array_key_exists('required', $field) && !is_bool($field['required']))
            || array_intersect(array_keys($field), ['schema', 'content', 'style', 'explode', 'allowReserved']) !== []
            || ($field['allowEmptyValue'] ?? false) !== false
            || (array_key_exists('collectionFormat', $field) && !is_string($field['collectionFormat']))) {
            return false;
        }
        foreach (['nullable', 'x-nullable'] as $flag) {
            if (array_key_exists($flag, $field) && !is_bool($field[$flag])) {
                return false;
            }
        }
        if (($field['type'] ?? null) === 'array') {
            return is_array($field['items'] ?? null) && self::rawScalar($field['items'])
                && !($field['items']['nullable'] ?? $field['items']['x-nullable'] ?? false)
                && !is_array($field['items']['type'] ?? null) && empty($field['enum'])
                && in_array($field['collectionFormat'] ?? 'csv', ['csv', 'multi'], true);
        }
        return !array_key_exists('collectionFormat', $field) && self::rawScalar($field)
            && is_string($field['type'] ?? null);
    }

    /**
     * @param list<ModelDefinition> $models
     * @return array<string,'multi'|'csv'>|null
     */
    public static function formats(RequestBodyDefinition $body, array $models): ?array
    {
        if ($body->mediaType !== self::MEDIA) {
            return null;
        }
        $schema = $body->schema;
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
        if ($schema === null || $schema->kind !== SchemaKind::Object || $schema->nullable
            || $schema->additionalProperties !== false || $schema->discriminator !== null) {
            return null;
        }
        $encoding = [];
        foreach ($body->content as $media) {
            if ($media->mediaType === self::MEDIA) {
                $encoding = $media->encoding;
            }
        }
        $names = array_map(static fn ($property): string => $property->wireName, $schema->properties);
        if (array_diff(array_keys($encoding), $names) !== []) {
            return null;
        }
        $formats = [];
        foreach ($schema->properties as $property) {
            $field = $property->schema;
            $array = $field->kind === SchemaKind::Array;
            if (($array && ($field->enumValues !== [] || $field->items === null || $field->items->nullable || !self::scalar($field->items)))
                || (!$array && !self::scalar($field)) || !self::encoding($encoding[$property->wireName] ?? [])) {
                return null;
            }
            if ($array) {
                $formats[$property->wireName] = ($encoding[$property->wireName]['explode'] ?? true) ? 'multi' : 'csv';
            }
        }
        return $formats;
    }

    private static function scalar(SchemaDefinition $schema): bool
    {
        return in_array($schema->kind, [SchemaKind::String, SchemaKind::Integer, SchemaKind::Number, SchemaKind::Boolean], true)
            && ($schema->enumValues === [] || in_array($schema->kind, [SchemaKind::String, SchemaKind::Integer], true));
    }

    private static function encoding(mixed $encoding): bool
    {
        return is_array($encoding) && array_diff(array_keys($encoding), ['style', 'explode', 'allowReserved']) === []
            && (!array_key_exists('style', $encoding) || $encoding['style'] === 'form')
            && (!array_key_exists('explode', $encoding) || is_bool($encoding['explode']))
            && (!array_key_exists('allowReserved', $encoding) || $encoding['allowReserved'] === false);
    }

    public static function rawSupported(array $definition, array $document): bool
    {
        $schema = $definition['schema'] ?? null;
        if (is_array($schema) && isset($schema['$ref'])) {
            try {
                $schema = JsonPointer::resolve($document, $schema['$ref']);
            } catch (\InvalidArgumentException|\TypeError) {
                return false;
            }
        }
        if (!is_array($schema) || !in_array($schema['type'] ?? null, ['object', null], true)
            || ($schema['nullable'] ?? false) || ($schema['additionalProperties'] ?? null) !== false
            || isset($schema['allOf']) || isset($schema['anyOf']) || isset($schema['oneOf']) || isset($schema['discriminator'])) {
            return false;
        }
        $properties = $schema['properties'] ?? [];
        $encoding = $definition['encoding'] ?? [];
        if (!is_array($properties) || !is_array($encoding) || array_diff(array_keys($encoding), array_keys($properties)) !== []) {
            return false;
        }
        foreach ($properties as $name => $field) {
            if (!is_array($field) || !self::encoding($encoding[$name] ?? [])) {
                return false;
            }
            $type = $field['type'] ?? null;
            $types = is_array($type) ? array_values(array_diff($type, ['null'])) : [$type];
            if (count($types) !== 1) {
                return false;
            }
            if ($types[0] === 'array') {
                if (!empty($field['enum']) || !is_array($field['items'] ?? null)
                    || !self::rawScalar($field['items']) || ($field['items']['nullable'] ?? false)
                    || is_array($field['items']['type'] ?? null)) {
                    return false;
                }
            } elseif (!self::rawScalar($field)) {
                return false;
            }
        }
        return true;
    }

    private static function rawScalar(array $schema): bool
    {
        $type = $schema['type'] ?? null;
        $types = is_array($type) ? array_values(array_diff($type, ['null'])) : [$type];
        return count($types) === 1 && in_array($types[0], ['string', 'integer', 'number', 'boolean'], true)
            && (!isset($schema['enum']) || in_array($types[0], ['string', 'integer'], true))
            && !isset($schema['allOf']) && !isset($schema['anyOf']) && !isset($schema['oneOf']);
    }
}
