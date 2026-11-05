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
        if (!str_starts_with($reference, '#/')) {
            throw new \InvalidArgumentException('Only document-local JSON Pointers can be inspected.');
        }
        $node = $document;
        foreach (explode('/', substr(rawurldecode($reference), 2)) as $token) {
            if (preg_match('/~(?![01])/', $token)) {
                throw new \InvalidArgumentException('Invalid JSON Pointer escape.');
            }
            $token = str_replace(['~1', '~0'], ['/', '~'], $token);
            if (!is_array($node) || !array_key_exists($token, $node)) {
                throw new \InvalidArgumentException('Unresolved local reference.');
            }
            $node = $node[$token];
        }
        return $node;
    }
}
