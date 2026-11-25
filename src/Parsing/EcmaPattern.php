<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Parsing;

/** JSON Schema patterns are ECMA-262 regular expressions; the SDK runs them with PCRE. */
final class EcmaPattern
{
    /**
     * A UTF-16 code unit is written `\u0080` in ECMA-262 and `\x{0080}` in PCRE (with the `u` flag the SDK uses). An escaped
     * backslash before the letter `u` is a literal backslash and is left as it is.
     */
    public static function toPcre(string $pattern): string
    {
        return preg_replace_callback(
            '/\\\\\\\\|\\\\u([0-9A-Fa-f]{4})/',
            static fn (array $match): string => isset($match[1]) ? '\\x{' . $match[1] . '}' : $match[0],
            $pattern,
        ) ?? $pattern;
    }
}
