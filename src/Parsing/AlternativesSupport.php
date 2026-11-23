<?php
declare(strict_types=1);
namespace Dmorenof\SdkGenerator\Parsing;

/** Several decodable representations of one success status (JSON, text, binary), chosen at runtime by Content-Type. */
final class AlternativesSupport {
    /**
     * The decodable representations, in declaration order, when there are at least two; otherwise null and the response
     * keeps its single selected representation. One this SDK cannot decode (the XML twin of a JSON schema) is left out, as it
     * is when there is only one JSON representation.
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
                continue;
            }
            try { $schema = SchemaReferences::raw($definition['schema'], $document); } catch (\InvalidArgumentException) { continue; }
            if (RawMediaSupport::json($media)) { $alternatives[] = ['mediaType' => $media, 'encoding' => 'json', 'schema' => $definition['schema']]; continue; }
            if (BinaryResponseSupport::supported(['schema' => $schema])) { $alternatives[] = ['mediaType' => $media, 'encoding' => 'binary', 'schema' => null]; continue; }
            if (($schema['type'] ?? null) === 'string' && ($schema['format'] ?? null) !== 'binary'
                && ($schema['nullable'] ?? false) === false && ($schema['x-nullable'] ?? false) === false) {
                $alternatives[] = ['mediaType' => $media, 'encoding' => 'text', 'schema' => $definition['schema']];
                continue;
            }
            continue;
        }
        // Chosen by Content-Type at run time, so two of them that differ only in a parameter (`application/json; v=1` and
        // `application/json`) cannot be told apart: that stays refused, as a conflict.
        $bases = array_map(static fn (array $alternative): string => self::base($alternative['mediaType']), $alternatives);
        return count($alternatives) >= 2 && count($bases) === count(array_unique($bases)) ? $alternatives : null;
    }

    private static function base(string $media): string {
        return strtolower(trim(explode(';', $media, 2)[0]));
    }
}
