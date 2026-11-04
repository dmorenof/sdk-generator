<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

use InvalidArgumentException;

/**
 * ICU transliteration for generated PHP symbols only.
 * Wire names, paths, operationIds, enum values and source references stay intact.
 */
final class PhpNameTransliterator
{
    public static function latin(string $source): string
    {
        if (!function_exists('transliterator_transliterate')) {
            throw new \LogicException('The sdk-generator requires ext-intl for Unicode transliteration.');
        }
        $text = transliterator_transliterate('Any-Latin; Latin-ASCII', $source);
        if (!is_string($text) || preg_match('/[^\x00-\x7F]/', $text) === 1) {
            throw new InvalidArgumentException("Cannot transliterate PHP identifier to Latin ASCII: $source");
        }
        return $text;
    }

    /** Preserve existing case, including API/HTTP/SDK acronyms. */
    public static function pascal(string $source, string $context = 'identifier'): string
    {
        $latin = self::latin($source);
        $words = preg_split('/[^A-Za-z0-9]+/', $latin, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            throw new InvalidArgumentException("Cannot derive PHP $context from: $source");
        }
        $name = implode('', array_map('ucfirst', $words));
        if (preg_match('/^[A-Za-z_]/', $name) !== 1) {
            throw new InvalidArgumentException("PHP $context must begin with a Latin letter: $source");
        }
        return $name;
    }

    /** Keep existing PHP-compatible spelling for wire property and parameter aliases. */
    public static function camel(string $source, string $context = 'identifier'): string
    {
        $latin = self::latin($source);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $latin) === 1) {
            return $latin;
        }
        return lcfirst(self::pascal($source, $context));
    }
}
