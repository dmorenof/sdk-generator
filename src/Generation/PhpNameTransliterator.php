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
            $message = $context === 'schema segment'
                ? "Invalid PHP schema namespace or class segment: $source"
                : "Cannot derive PHP $context from: $source";
            throw new InvalidArgumentException($message);
        }
        $name = implode('', array_map('ucfirst', $words));
        if (preg_match('/^[A-Za-z_]/', $name) !== 1) {
            throw new InvalidArgumentException("PHP $context must begin with a Latin letter: $source");
        }
        return $name;
    }


    /** PHP property alias for a JSON wire key, with no translation. */
    public static function property(string $source): string
    {
        $latin = self::latin($source);
        $candidate = preg_replace_callback(
            '/[^A-Za-z0-9_]+([A-Za-z0-9_]?)/',
            static fn (array $match): string => strtoupper($match[1] ?? ''),
            $latin,
        );
        if (!is_string($candidate) || $candidate === '') {
            throw new InvalidArgumentException("Cannot derive PHP property name from: $source");
        }
        if (preg_match('/^[A-Za-z_]/', $candidate) !== 1) {
            $candidate = '_' . $candidate;
        }
        if (in_array(strtolower($candidate), [
            'abstract','and','array','as','break','callable','case','catch',
            'class','clone','const','continue','declare','default','die','do',
            'echo','else','elseif','empty','enddeclare','endfor','endforeach',
            'endif','endswitch','endwhile','enum','eval','exit','extends',
            'final','finally','fn','for','foreach','function','global',
            'goto','if','implements','include','include_once','instanceof',
            'insteadof','interface','isset','list','match','namespace','new',
            'or','print','private','protected','public','readonly','require',
            'require_once','return','static','switch','throw','trait','try',
            'unset','use','var','while','xor','yield','true','false','null',
            'int','float','string','bool','void','object','mixed','never',
            'self','parent',
        ], true)) {
            $candidate .= '_';
        }
        return $candidate;
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
