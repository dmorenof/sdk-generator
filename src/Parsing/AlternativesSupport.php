<?php
declare(strict_types=1);
namespace Dmorenof\SdkGenerator\Parsing;

/** Several decodable representations of one success status (JSON, text, binary), chosen at runtime by Content-Type. */
final class AlternativesSupport {
    /**
     * Every representation, in declaration order, when there are at least two and all of them are decodable; otherwise null
     * and the response keeps its single selected representation.
     *
     * @param array<string, mixed> $content
     * @param array<string, mixed> $document
     * @return list<array{mediaType: string, encoding: 'json'|'text'|'binary', schema: ?array<string, mixed>}>|null
     */
    public static function select(array $content, array $document): ?array {
        $alternatives = [];
        foreach ($content as $media => $definition) {
            if (!is_string($media) || !is_array($definition) || !is_array($definition['schema'] ?? null) || !empty($definition['encoding'])
                || StreamingSupport::encoding($media) !== null || FormSupport::MEDIA === self::base($media) || FormSupport::MULTIPART === self::base($media)
                || preg_match("@^[!#$%&'*+.^_`|~a-z0-9-]+/[!#$%&'*+.^_`|~a-z0-9-]+(?:\\s*;[^\\r\\n]*)?$@iD", $media) !== 1) {
                return null;
            }
            try { $schema = SchemaReferences::raw($definition['schema'], $document); } catch (\InvalidArgumentException) { return null; }
            if (RawMediaSupport::json($media)) { $alternatives[] = ['mediaType' => $media, 'encoding' => 'json', 'schema' => $definition['schema']]; continue; }
            if (BinaryResponseSupport::supported(['schema' => $schema])) { $alternatives[] = ['mediaType' => $media, 'encoding' => 'binary', 'schema' => null]; continue; }
            if (($schema['type'] ?? null) === 'string' && ($schema['format'] ?? null) !== 'binary'
                && ($schema['nullable'] ?? false) === false && ($schema['x-nullable'] ?? false) === false) {
                $alternatives[] = ['mediaType' => $media, 'encoding' => 'text', 'schema' => $definition['schema']];
                continue;
            }
            return null;
        }
        return count($alternatives) >= 2 ? $alternatives : null;
    }

    private static function base(string $media): string {
        return strtolower(trim(explode(';', $media, 2)[0]));
    }
}
