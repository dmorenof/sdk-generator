<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Parsing;

use Dmorenof\SdkGenerator\Contract\ModelDefinition;
use Dmorenof\SdkGenerator\Contract\RequestBodyDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaKind;

/** Form encodings reuse parameter expansion and JSON multipart serialization. */
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
                && in_array($field['collectionFormat'] ?? 'csv', $media === self::MULTIPART ? ['multi'] : ['csv', 'multi', 'ssv', 'tsv', 'pipes'], true);
        }
        return !array_key_exists('collectionFormat', $field) && self::rawScalar($field)
            && is_string($field['type'] ?? null);
    }

    /**
     * @param list<ModelDefinition> $models
     * @return array<string,string>|null
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
            $field = SchemaReferences::resolve($property->schema, $models);
            $fieldEncoding = $encoding[$property->wireName] ?? [];
            $swaggerFormat = $fieldEncoding['collectionFormat'] ?? null;
            unset($fieldEncoding['collectionFormat']);
            if (!self::encoding($fieldEncoding, $multipart)) { return null; }
            if ($multipart && self::jsonPart($field, $fieldEncoding, $models)) {
                if (!self::name($property->wireName)) { return null; }
                continue;
            }
            $options = new \Dmorenof\SdkGenerator\Contract\ParameterSerialization(style: $fieldEncoding['style'] ?? 'form', explode: $fieldEncoding['explode'] ?? null);
            if (!$multipart && QueryObjectSupport::supported(new \Dmorenof\SdkGenerator\Contract\ParameterDefinition(
                $property->wireName, 'query', $field, $property->required, $options
            ), $models)) {
                $formats[$property->wireName] = QueryObjectSupport::format($options, 'query');
                continue;
            }
            $array = $field->kind === SchemaKind::Array;
            $binary = self::fileItem($field, $models);
            if ((!$array && ($fieldEncoding['style'] ?? 'form') !== 'form')
                || ($array && !in_array($fieldEncoding['style'] ?? 'form', ['form', 'spaceDelimited', 'pipeDelimited'], true))
                || ($swaggerFormat !== null && !in_array($swaggerFormat, ['csv', 'multi', 'ssv', 'tsv', 'pipes'], true))) { return null; }
            if (($array && ($field->enumValues !== [] || $field->items === null || $field->items->nullable || !self::scalar($field->items)))
                || (!$array && !self::scalar($field)) || !self::encoding($fieldEncoding, $multipart)) {
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
                $formats[$property->wireName] = $swaggerFormat ?? match ($fieldEncoding['style'] ?? 'form') {
                    'spaceDelimited' => 'ssv', 'pipeDelimited' => 'pipes',
                    default => ($fieldEncoding['explode'] ?? true) ? 'multi' : 'csv',
                };
            }
        }
        return $formats;
    }

    /** @param list<ModelDefinition> $models */
    private static function schema(RequestBodyDefinition $body, array $models): ?SchemaDefinition
    {
        try { return SchemaReferences::resolve($body->schema, $models); }
        catch (\InvalidArgumentException) { return null; }
    }

    /** @param list<ModelDefinition> $models @return array<string,array{contentType:?string,filename?:string,json?:bool}>|null */
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
            $fieldEncoding = $encoding[$property->wireName] ?? [];
            if (self::jsonPart($property->schema, $fieldEncoding, $models)) {
                $parts[$property->wireName] = ['contentType' => $fieldEncoding['contentType'] ?? 'application/json', 'json' => true];
                continue;
            }
            $binary = self::fileItem(SchemaReferences::resolve($property->schema, $models), $models) !== null;
            $part = ['contentType' => $encoding[$property->wireName]['contentType'] ?? ($binary ? null : 'text/plain')];
            if ($binary) {
                $part['filename'] = basename($property->wireName) . '.bin';
            }
            $parts[$property->wireName] = $part;
        }
        return $parts;
    }

    private static function jsonMedia(?string $media): bool
    {
        return $media !== null && preg_match('~^application/(?:[a-zA-Z0-9!#$&^_.+\\-]+\\+)?json$~D', $media) === 1;
    }

    private static function jsonPart(SchemaDefinition $schema, array $encoding, array $models): bool
    {
        $schema = SchemaReferences::resolve($schema, $models);
        $item = $schema->kind === SchemaKind::Array ? $schema->items : $schema;
        $item = $item === null ? null : SchemaReferences::resolve($item, $models);
        if (self::fileItem($schema, $models) !== null) { return false; }
        if (in_array($schema->kind, [SchemaKind::Array, SchemaKind::Object], true) && $schema->enumValues !== []) { return false; }
        if ($schema->kind === SchemaKind::AnyOf && array_key_exists('schema', $schema->validation)) { return self::jsonMedia($encoding['contentType'] ?? null); }
        $complex = $item !== null && in_array($item->kind, [SchemaKind::Object, SchemaKind::Array, SchemaKind::AllOf, SchemaKind::AnyOf, SchemaKind::OneOf], true);
        return self::jsonMedia($encoding['contentType'] ?? ($complex ? 'application/json' : null));
    }

    private static function rawJsonPart(array $schema, array $encoding, array $document): bool
    {
        if (isset($schema['$ref'])) {
            try { $schema = SchemaReferences::raw($schema, $document); }
            catch (\InvalidArgumentException|\TypeError) { return false; }
        }
        if (!is_array($schema)) { return false; }
        $type = $schema['type'] ?? '';
        $array = $type === 'array' || (is_array($type) && in_array('array', $type, true));
        $item = $array ? ($schema['items'] ?? []) : $schema;
        if (isset($item['$ref'])) {
            try { $item = SchemaReferences::raw($item, $document); }
            catch (\InvalidArgumentException|\TypeError) { return false; }
        }
        if (!is_array($item) || (($array || ($schema['type'] ?? '') === 'object') && array_key_exists('enum', $schema))) { return false; }
        if (($item['format'] ?? '') === 'binary') { return false; }
        $complex = isset($item['properties']) || array_key_exists('additionalProperties', $item) || isset($item['allOf']) || isset($item['oneOf']) || isset($item['anyOf'])
            || in_array($item['type'] ?? null, ['object', 'array'], true);
        return self::jsonMedia($encoding['contentType'] ?? ($complex ? 'application/json' : null));
    }

    public static function fileItem(SchemaDefinition $schema, array $models = []): ?SchemaDefinition
    {
        try {
            $schema = SchemaReferences::resolve($schema, $models);
            $item = $schema->kind === SchemaKind::Array ? $schema->items : $schema;
            $item = $item === null ? null : SchemaReferences::resolve($item, $models);
        } catch (\InvalidArgumentException) { return null; }
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
            && (!array_key_exists('style', $encoding) || in_array($encoding['style'], $multipart ? ['form'] : ['form', 'spaceDelimited', 'pipeDelimited', 'deepObject'], true))
            && (!array_key_exists('explode', $encoding) || ($multipart ? $encoding['explode'] === true : is_bool($encoding['explode'])))
            && (!in_array($encoding['style'] ?? 'form', ['spaceDelimited', 'pipeDelimited'], true) || ($encoding['explode'] ?? false) === false)
            && (($encoding['style'] ?? '') !== 'deepObject' || ($encoding['explode'] ?? false) === true)
            && (!array_key_exists('contentType', $encoding) || (is_string($encoding['contentType'])
                && preg_match('~^[a-zA-Z0-9!#$&^_.+\-]+/[a-zA-Z0-9!#$&^_.+\-]+$~D', $encoding['contentType']) === 1))
            && (!array_key_exists('allowReserved', $encoding) || $encoding['allowReserved'] === false);
    }

    public static function rawSupported(array $definition, array $document, bool $multipart = false): bool
    {
        $schema = $definition['schema'] ?? null;
        if (is_array($schema) && isset($schema['$ref'])) {
            try {
                $schema = SchemaReferences::raw($schema, $document);
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
            if ($multipart && self::rawJsonPart($field, $encoding[$name] ?? [], $document)) {
                if (!self::name((string) $name)) { return false; }
                continue;
            }
            if (!$multipart && QueryObjectSupport::rawSupported([
                'name' => (string) $name, 'in' => 'query', 'schema' => $field,
                'style' => $encoding[$name]['style'] ?? 'form', 'explode' => $encoding[$name]['explode'] ?? (($encoding[$name]['style'] ?? '') === 'deepObject' ? true : null),
            ], $document)) { continue; }
            if (isset($field['$ref'])) {
                try { $field = SchemaReferences::raw($field, $document); }
                catch (\InvalidArgumentException|\TypeError) { return false; }
            }
            if (is_array($field['items'] ?? null) && isset($field['items']['$ref'])) {
                try { $field['items'] = SchemaReferences::raw($field['items'], $document); }
                catch (\InvalidArgumentException|\TypeError) { return false; }
            }
            $type = $field['type'] ?? null;
            $types = is_array($type) ? array_values(array_diff($type, ['null'])) : [$type];
            if (count($types) !== 1) {
                return false;
            }
            if ($types[0] === 'array') {
                if (!in_array($encoding[$name]['style'] ?? 'form', ['form', 'spaceDelimited', 'pipeDelimited'], true)) { return false; }
                if (array_key_exists('enum', $field) || !is_array($field['items'] ?? null)
                    || !self::rawScalar($field['items']) || ($field['items']['nullable'] ?? false)
                    || is_array($field['items']['type'] ?? null)) {
                    return false;
                }
            } elseif (($encoding[$name]['style'] ?? 'form') !== 'form' || !self::rawScalar($field)) {
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
