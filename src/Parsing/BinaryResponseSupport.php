<?php
declare(strict_types=1);
namespace Dmorenof\SdkGenerator\Parsing;

/** The bounded raw-response codec; JSON keeps selection priority. */
final class BinaryResponseSupport
{
    public const MEDIA = 'application/octet-stream';

    public static function selected(array $content): bool
    {
        foreach (array_keys($content) as $media) {
            if (is_string($media) && preg_match('~^application/(?:[a-z0-9!#$&^_.+\-]+\+)?json(?:\s*;|$)~i', $media)) {
                return false;
            }
        }
        return isset($content[self::MEDIA]) && is_array($content[self::MEDIA]) && self::supported($content[self::MEDIA]);
    }

    /** Swagger file responses share the existing binary codec, with one explicit representation. */
    public static function swaggerSchema(array $schema, array $mediaTypes): ?array
    {
        if ($mediaTypes !== [self::MEDIA] || ($schema['type'] ?? null) !== 'file' || array_key_exists('format', $schema)) {
            return null;
        }
        $schema['type'] = 'string';
        $schema['format'] = 'binary';
        return self::supported(['schema' => $schema]) ? $schema : null;
    }

    public static function supported(array $definition): bool
    {
        $schema = $definition['schema'] ?? null;
        if (!is_array($schema) || ($schema['type'] ?? null) !== 'string' || ($schema['format'] ?? null) !== 'binary'
            || !empty($definition['encoding']) || ($schema['nullable'] ?? false) !== false
            || ($schema['x-nullable'] ?? false) !== false) {
            return false;
        }
        foreach (array_keys($schema) as $key) {
            if (!is_string($key) || (!in_array($key, ['type', 'format', 'nullable', 'x-nullable', 'title', 'description', 'example', 'deprecated', 'externalDocs'], true)
                && !str_starts_with($key, 'x-'))) {
                return false;
            }
        }
        return true;
    }
}
