<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Contract;

enum SchemaKind: string
{
    case String = 'string';
    case Integer = 'integer';
    case Number = 'number';
    case Boolean = 'boolean';
    case Object = 'object';
    case Array = 'array';
    case Reference = 'reference';
}
