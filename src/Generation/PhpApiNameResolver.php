<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

use InvalidArgumentException;

/** Derive an optional, readable SDK root namespace from OpenAPI info.title. */
final class PhpApiNameResolver
{
    public static function fromTitle(string $title): string
    {
        $name = PhpNameTransliterator::pascal($title, 'SDK namespace');
        if (in_array(strtolower($name), ['class','interface','trait','enum','namespace','abstract','final',
            'readonly','string','int','float','bool','void','object','mixed','never','null','false','true'], true)) {
            throw new InvalidArgumentException("Reserved PHP SDK namespace from title: $title");
        }
        return $name;
    }

    public static function sdkClassFromTitle(string $title): string
    {
        $name = self::fromTitle($title);
        return str_ends_with(strtoupper($name), 'SDK') ? $name : $name . 'SDK';
    }
}
