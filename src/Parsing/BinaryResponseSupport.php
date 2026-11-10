<?php
declare(strict_types=1);
namespace Dmorenof\SdkGenerator\Parsing;

/** The bounded raw-response codec; JSON keeps selection priority. */
final class BinaryResponseSupport
{
    public const MEDIA = 'application/octet-stream';

    public static function selected(array $content, array $document = []): bool { return self::media($content, $document) !== null; }

    public static function media(array $content, array $document = []): ?string {
        $candidates = [];
        foreach ($content as $media => $definition) {
            if (is_string($media) && RawMediaSupport::json($media)) { return null; }
            if (!is_string($media) || !is_array($definition) || !RawMediaSupport::rawMedia($media)) { continue; }
            if (is_array($definition['schema'] ?? null) && isset($definition['schema']['$ref'])) {
                try { $definition['schema'] = SchemaReferences::raw($definition['schema'], $document); }
                catch (\InvalidArgumentException) { continue; }
            }
            if (self::supported($definition)) { $candidates[] = $media; }
        }
        if (in_array(self::MEDIA, $candidates, true)) { return self::MEDIA; }
        sort($candidates, SORT_STRING);
        return $candidates[0] ?? null;
    }

    /** Swagger file responses share the existing binary codec, with one explicit representation. */
    public static function swaggerSchema(array $schema, array $mediaTypes): ?array
    {
        if (count($mediaTypes) !== 1 || !is_string($mediaTypes[0] ?? null) || !RawMediaSupport::rawMedia($mediaTypes[0]) || ($schema['type'] ?? null) !== 'file' || array_key_exists('format', $schema)) {
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
