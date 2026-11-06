<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Diagnostics;

final class JsonPointer
{
    public static function append(string $pointer, string|int $token): string
    {
        return $pointer . '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $token);
    }

    public static function resolve(array $document, string $reference): mixed
    {
        $node = $document;
        foreach (self::tokens($reference) as $token) {
            if (!is_array($node) || !array_key_exists($token, $node)) {
                throw new \InvalidArgumentException('Unresolved local reference.');
            }
            $node = $node[$token];
        }
        return $node;
    }

    /** @return list<string> */
    public static function tokens(string $reference): array
    {
        if (!str_starts_with($reference, '#') || preg_match('/%(?![0-9a-f]{2})/i', $reference)) {
            throw new \InvalidArgumentException('Expected a valid document-local JSON Pointer fragment.');
        }
        $pointer = rawurldecode(substr($reference, 1));
        if ($pointer === '') {
            return [];
        }
        if (!str_starts_with($pointer, '/') || preg_match('//u', $pointer) !== 1) {
            throw new \InvalidArgumentException('Expected a UTF-8 JSON Pointer fragment.');
        }
        $tokens = [];
        foreach (explode('/', substr($pointer, 1)) as $token) {
            if (preg_match('/~(?![01])/', $token)) {
                throw new \InvalidArgumentException('Invalid JSON Pointer escape.');
            }
            $tokens[] = str_replace(['~1', '~0'], ['/', '~'], $token);
        }
        return $tokens;
    }
}
