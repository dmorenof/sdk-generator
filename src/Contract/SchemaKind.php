<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Contract;

enum SchemaKind: string
{
    case String = 'string';
    case Integer = 'integer';
    case Number = 'number';
    case Boolean = 'boolean';
    case Null = 'null';
    case Mixed = 'mixed';
    case Object = 'object';
    case Array = 'array';
    case Reference = 'reference';
    case AnyOf = 'anyOf';
    case OneOf = 'oneOf';
    case AllOf = 'allOf';
}
