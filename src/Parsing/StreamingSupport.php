<?php
declare(strict_types=1);
namespace Dmorenof\SdkGenerator\Parsing;

/** Recognizes response media the runtime delivers lazily as a \Generator (one item per line, record or event). */
final class StreamingSupport {
    public const ENCODINGS = [
        'application/jsonl' => 'jsonl',
        'application/x-ndjson' => 'jsonl',
        'application/json-seq' => 'json-seq',
        'text/event-stream' => 'sse',
    ];

    /** The runtime encoding for a media type (parameters ignored), or null when it is not streamed. */
    public static function encoding(?string $media): ?string {
        return $media === null ? null : (self::ENCODINGS[strtolower(trim(explode(';', $media, 2)[0]))] ?? null);
    }

    /**
     * The streamed representation of a response: the only streaming media type of its content and no JSON alternative.
     * The item schema is `itemSchema` (OpenAPI 3.2) or, as most documents write it, `schema`; Server-Sent Events may have none.
     *
     * @param array<string, mixed> $content
     * @return array{mediaType: string, schema: ?array<string, mixed>}|null
     */
    public static function selected(array $content): ?array {
        $streamed = [];
        foreach ($content as $media => $definition) {
            if (!is_string($media)) { continue; }
            if (RawMediaSupport::json($media)) { return null; }
            if (self::encoding($media) !== null) { $streamed[$media] = $definition; }
        }
        if (count($streamed) !== 1) { return null; }
        $media = array_key_first($streamed);
        $definition = $streamed[$media];
        if (!is_array($definition)) { return null; }
        $schema = $definition['itemSchema'] ?? $definition['schema'] ?? null;
        if ($schema !== null && !is_array($schema)) { return null; }
        return ['mediaType' => $media, 'schema' => $schema];
    }
}
