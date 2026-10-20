<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

use InvalidArgumentException;

/** Derive an optional, readable SDK root namespace from OpenAPI info.title. */
final class PhpApiNameResolver
{
    public static function fromTitle(string $title): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', trim($title), -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            throw new InvalidArgumentException('Cannot derive PHP SDK name from OpenAPI info.title; supply an explicit namespace.');
        }
        $name = implode('', array_map(static fn(string $word): string => ucfirst(strtolower($word)), $words));
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
            throw new InvalidArgumentException(
                "Cannot derive valid PHP SDK name from OpenAPI info.title '$title'; supply an explicit namespace.",
            );
        }
        $reserved = ['class','interface','trait','enum','namespace','abstract','final','readonly',
            'string','int','float','bool','void','object','mixed','never','null','false','true'];
        if (in_array(strtolower($name), $reserved, true)) {
            throw new InvalidArgumentException(
                "Reserved PHP SDK name derived from OpenAPI info.title '$title'; supply an explicit namespace.",
            );
        }
        return $name;
    }
}
