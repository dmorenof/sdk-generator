<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Parsing;

use Dmorenof\SdkGenerator\Contract\ModelDefinition;
use Dmorenof\SdkGenerator\Contract\RequestBodyDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaKind;
use Dmorenof\SdkGenerator\Diagnostics\JsonPointer;

/** Bounded form bodies share closed, flat schemas and primitive field validation. */
final class FormSupport
{
    public const MEDIA = 'application/x-www-form-urlencoded';
    public const MULTIPART = 'multipart/form-data';

    /** A single explicit media type, without silently selecting between alternatives. */
    public static function swaggerMedia(mixed $consumes): ?string
    {
        return in_array($consumes, [[self::MEDIA], [self::MULTIPART]], true) ? $consumes[0] : null;
    }

    /** Null media audits reusable declarations; each usage is checked with its effective consumes. */
    public static function swaggerField(array $field, ?string $media = self::MEDIA): bool
    {
        if (($field['in'] ?? null) !== 'formData' || !is_string($field['name'] ?? null) || $field['name'] === ''
            || (array_key_exists('required', $field) && !is_bool($field['required']))
            || array_intersect(array_keys($field), ['schema', 'content', 'style', 'explode', 'allowReserved']) !== []
            || (array_key_exists('allowEmptyValue', $field) && !is_bool($field['allowEmptyValue']))
            || (array_key_exists('collectionFormat', $field) && !is_string($field['collectionFormat']))) {
            return false;
        }
        foreach (['nullable', 'x-nullable'] as $flag) {
            if (array_key_exists($flag, $field) && !is_bool($field[$flag])) {
                return false;
            }
        }
        if ($media === self::MULTIPART && !self::name($field['name'])) {
            return false;
        }
        if (($field['type'] ?? null) === 'file') {
            return $media !== self::MEDIA && self::name($field['name'])
                && array_intersect(array_keys($field), ['format', 'items', 'enum', 'default', 'minimum', 'maximum',
                    'minLength', 'maxLength', 'pattern', 'minItems', 'maxItems', 'collectionFormat']) === [];
        }
        if (($field['type'] ?? null) === 'array') {
            return is_array($field['items'] ?? null) && self::rawScalar($field['items'])
                && ($media !== self::MULTIPART || ($field['items']['format'] ?? null) !== 'binary')
                && !($field['items']['nullable'] ?? $field['items']['x-nullable'] ?? false)
                && !is_array($field['items']['type'] ?? null) && empty($field['enum'])
                && in_array($field['collectionFormat'] ?? 'csv', $media === self::MULTIPART ? ['multi'] : ['csv', 'multi'], true);
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
        if (!in_array($body->mediaType, [self::MEDIA, self::MULTIPART], true)) {
            return null;
        }
        $schema = self::schema($body, $models);
        $multipart = $body->mediaType === self::MULTIPART;
        if ($schema === null || $schema->kind !== SchemaKind::Object || $schema->nullable
            || $schema->additionalProperties !== false || $schema->discriminator !== null) {
            return null;
        }
        $encoding = [];
        foreach ($body->content as $media) {
            if ($media->mediaType === $body->mediaType) {
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
            $binary = self::fileItem($field);
            if (($array && ($field->enumValues !== [] || $field->items === null || $field->items->nullable || !self::scalar($field->items)))
                || (!$array && !self::scalar($field)) || !self::encoding($encoding[$property->wireName] ?? [], $multipart)) {
                return null;
            }
            if ($multipart && (!self::name($property->wireName)
                || ($array && $field->items?->format === 'binary' && $binary === null)
                || ($binary === null && isset($encoding[$property->wireName]['contentType'])
                    && $encoding[$property->wireName]['contentType'] !== 'text/plain')
                || ($binary !== null && ($binary->enumValues !== [] || $binary->validation !== [] || $binary->pattern !== null)))) {
                return null;
            }
            if ($array) {
                $formats[$property->wireName] = ($encoding[$property->wireName]['explode'] ?? true) ? 'multi' : 'csv';
            }
        }
        return $formats;
    }

    /** @param list<ModelDefinition> $models */
    private static function schema(RequestBodyDefinition $body, array $models): ?SchemaDefinition
    {
        if ($body->schema->kind !== SchemaKind::Reference) {
            return $body->schema;
        }
        foreach ($models as $model) {
            if ($model->name === $body->schema->reference) {
                return $model->schema;
            }
        }
        return null;
    }

    /** @param list<ModelDefinition> $models @return array<string,array{contentType:?string,filename?:string}>|null */
    public static function parts(RequestBodyDefinition $body, array $models): ?array
    {
        if ($body->mediaType !== self::MULTIPART || self::formats($body, $models) === null) {
            return null;
        }
        $encoding = [];
        foreach ($body->content as $media) {
            if ($media->mediaType === self::MULTIPART) {
                $encoding = $media->encoding;
            }
        }
        $parts = [];
        foreach (self::schema($body, $models)->properties as $property) {
            $binary = self::fileItem($property->schema) !== null;
            $part = ['contentType' => $encoding[$property->wireName]['contentType'] ?? ($binary ? null : 'text/plain')];
            if ($binary) {
                $part['filename'] = basename($property->wireName) . '.bin';
            }
            $parts[$property->wireName] = $part;
        }
        return $parts;
    }

    public static function fileItem(SchemaDefinition $schema): ?SchemaDefinition
    {
        $item = $schema->kind === SchemaKind::Array ? $schema->items : $schema;
        return $item?->kind === SchemaKind::String && $item->format === 'binary' ? $item : null;
    }

    private static function name(string $name): bool
    {
        return $name !== '' && preg_match('/^[\x20-\x21\x23-\x5B\x5D-\x7E]+$/D', $name) === 1
            && basename($name) !== '' && !in_array(basename($name), ['.', '..'], true);
    }

    private static function scalar(SchemaDefinition $schema): bool
    {
        return in_array($schema->kind, [SchemaKind::String, SchemaKind::Integer, SchemaKind::Number, SchemaKind::Boolean], true)
            && ($schema->enumValues === [] || in_array($schema->kind, [SchemaKind::String, SchemaKind::Integer], true));
    }

    private static function encoding(mixed $encoding, bool $multipart = false): bool
    {
        return is_array($encoding) && array_diff(array_keys($encoding), $multipart ? ['contentType', 'style', 'explode', 'allowReserved'] : ['style', 'explode', 'allowReserved']) === []
            && (!array_key_exists('style', $encoding) || $encoding['style'] === 'form')
            && (!array_key_exists('explode', $encoding) || ($multipart ? $encoding['explode'] === true : is_bool($encoding['explode'])))
            && (!array_key_exists('contentType', $encoding) || (is_string($encoding['contentType'])
                && preg_match('~^[a-zA-Z0-9!#$&^_.+\-]+/[a-zA-Z0-9!#$&^_.+\-]+$~D', $encoding['contentType']) === 1))
            && (!array_key_exists('allowReserved', $encoding) || $encoding['allowReserved'] === false);
    }

    public static function rawSupported(array $definition, array $document, bool $multipart = false): bool
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
            if (!is_array($field) || !self::encoding($encoding[$name] ?? [], $multipart)) {
                return false;
            }
            $type = $field['type'] ?? null;
            $types = is_array($type) ? array_values(array_diff($type, ['null'])) : [$type];
            if (count($types) !== 1) {
                return false;
            }
            if ($types[0] === 'array') {
                if (array_key_exists('enum', $field) || !is_array($field['items'] ?? null)
                    || !self::rawScalar($field['items']) || ($field['items']['nullable'] ?? false)
                    || is_array($field['items']['type'] ?? null)) {
                    return false;
                }
            } elseif (!self::rawScalar($field)) {
                return false;
            }
            if ($multipart) {
                $item = $types[0] === 'array' ? $field['items'] : $field;
                $binary = ($item['type'] ?? null) === 'string' && ($item['format'] ?? null) === 'binary';
                if (!self::name((string) $name) || ($types[0] === 'array' && ($item['format'] ?? null) === 'binary' && !$binary)
                    || (!$binary && isset($encoding[$name]['contentType']) && $encoding[$name]['contentType'] !== 'text/plain')
                    || ($binary && array_intersect(array_keys($item), ['enum', 'minLength', 'maxLength', 'pattern']) !== [])) {
                    return false;
                }
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
