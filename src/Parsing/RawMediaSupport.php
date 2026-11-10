<?php
declare(strict_types=1);
namespace Dmorenof\SdkGenerator\Parsing;

/** Recognize bounded raw representations; serialization stays in Guzzle/PSR. */
final class RawMediaSupport {
    public const TEXT = 'text/plain';
    public static function json(string $media): bool {
        return preg_match('~^application/(?:[a-z0-9!#$&^_.+\-]+\+)?json(?:\s*;[^\r\n]*)?$~iD', trim($media)) === 1;
    }
    public static function rawMedia(string $media): bool {
        $base = strtolower(trim(explode(';', $media, 2)[0]));
        return !self::json($media) && !str_contains($base, '*') && !in_array($base, [FormSupport::MEDIA, FormSupport::MULTIPART], true)
            && preg_match("@^[!#$%&'*+.^_`|~a-z0-9-]+/[!#$%&'*+.^_`|~a-z0-9-]+(?:\\s*;[^\\r\\n]*)?$@iD", $media) === 1;
    }
    public static function supported(array $definition, string $media, array $document, bool $request): bool {
        if (!is_array($definition['schema'] ?? null) || !empty($definition['encoding'])) { return false; }
        try { $schema = SchemaReferences::raw($definition['schema'], $document); } catch (\InvalidArgumentException) { return false; }
        if (!self::rawMedia($media)) { return false; }
        if (($schema['format'] ?? null) === 'binary') { return $request && BinaryResponseSupport::supported(['schema' => $schema]); }
        return ($schema['type'] ?? null) === 'string' && ($schema['format'] ?? null) !== 'binary'
            && ($schema['nullable'] ?? false) === false && ($schema['x-nullable'] ?? false) === false;
    }
    public static function selected(array $content, array $document, bool $request): ?array {
        foreach (array_keys($content) as $media) {
            if (is_string($media) && self::json($media)) { return null; }
        }
        $raw = [];
        foreach ($content as $media => $definition) {
            if (is_string($media) && is_array($definition) && self::supported($definition, $media, $document, $request)) { $raw[$media] = $definition['schema']; }
        }
        if (count($raw) !== 1) { return null; }
        return ['mediaType' => array_key_first($raw), 'schema' => reset($raw)];
    }
}
