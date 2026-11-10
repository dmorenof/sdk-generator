<?php
declare(strict_types=1);
namespace Dmorenof\SdkGenerator\Parsing;

/** Recognize bounded raw representations; serialization stays in Guzzle/PSR. */
final class RawMediaSupport {
    public const TEXT = 'text/plain';
    public static function supported(array $definition, string $media, array $document, bool $request): bool {
        if (!is_array($definition['schema'] ?? null) || !empty($definition['encoding'])) { return false; }
        try { $schema = SchemaReferences::raw($definition['schema'], $document); } catch (\InvalidArgumentException) { return false; }
        if ($request && $media === BinaryResponseSupport::MEDIA) { return BinaryResponseSupport::supported(['schema' => $schema]); }
        return $media === self::TEXT && ($schema['type'] ?? null) === 'string' && ($schema['format'] ?? null) !== 'binary'
            && ($schema['nullable'] ?? false) === false && ($schema['x-nullable'] ?? false) === false;
    }
    public static function selected(array $content, array $document, bool $request): ?array {
        foreach (array_keys($content) as $media) {
            if (is_string($media) && preg_match('~^application/(?:[a-z0-9!#$&^_.+\-]+\+)?json(?:\s*;|$)~i', $media)) { return null; }
        }
        $raw = [];
        foreach ($content as $media => $definition) {
            if (is_string($media) && is_array($definition) && self::supported($definition, $media, $document, $request)) { $raw[$media] = $definition['schema']; }
        }
        if (count($raw) !== 1) { return null; }
        return ['mediaType' => array_key_first($raw), 'schema' => reset($raw)];
    }
}
