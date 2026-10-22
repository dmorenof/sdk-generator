<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

use InvalidArgumentException;

final class PhpOperationNameResolver
{
    private const RESERVED = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch',
        'class', 'clone', 'const', 'continue', 'declare', 'default', 'die',
        'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor',
        'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit',
        'extends', 'final', 'finally', 'fn', 'for', 'foreach', 'function',
        'global', 'goto', 'if', 'implements', 'include', 'include_once',
        'instanceof', 'insteadof', 'interface', 'isset', 'list', 'match',
        'namespace', 'new', 'or', 'print', 'private', 'protected', 'public',
        'readonly', 'require', 'require_once', 'return', 'static', 'switch',
        'throw', 'trait', 'try', 'unset', 'use', 'var', 'while', 'xor',
        'yield', 'true', 'false', 'null', 'int', 'float', 'string', 'bool',
        'void', 'object', 'mixed', 'never', 'self', 'parent',
    ];

    public static function resolve(string $operationId): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $operationId) === 1
            && !in_array(strtolower($operationId), self::RESERVED, true)) {
            return $operationId;
        }

        $words = preg_split('/[^A-Za-z0-9]+/', $operationId, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            throw new InvalidArgumentException("Cannot derive a PHP method name from operationId: $operationId");
        }
        $name = lcfirst(implode('', array_map('ucfirst', $words)));
        if (preg_match('/^[A-Za-z_]/', $name) !== 1) {
            $name = 'operation' . ucfirst($name);
        }
        if (in_array(strtolower($name), self::RESERVED, true)) {
            $name .= 'Operation';
        }
        return $name;
    }
}
