<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

use Dmorenof\SdkGenerator\Contract\SchemaDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaKind;
use InvalidArgumentException;

/** Resolve primitive HTTP parameters, including a nullable primitive anyOf. */
final class PhpParameterTypeResolver
{
    /** @return array{type:string,nullable:bool,enumValues:list<string|int|float|bool>} */
    public static function resolve(SchemaDefinition $schema, string $where): array
    {
        $enumValues = $schema->enumValues;
        if ($schema->kind === SchemaKind::AnyOf) {
            $variants = $schema->branches;
            $nonNull = array_values(array_filter(
                $variants,
                static fn (SchemaDefinition $branch): bool => $branch->kind !== SchemaKind::Null,
            ));
            $hasNull = count($variants) !== count($nonNull);
            if (!$hasNull || count($variants) !== 2 || count($nonNull) !== 1) {
                throw new InvalidArgumentException("Unsupported HTTP parameter schema at $where.");
            }
            $schema = $nonNull[0];
            $enumValues = $schema->enumValues;
            $nullable = true;
        } else {
            $nullable = $schema->nullable;
        }

        $type = match ($schema->kind) {
            SchemaKind::String => 'string',
            SchemaKind::Integer => 'int',
            SchemaKind::Number => 'float',
            SchemaKind::Boolean => 'bool',
            default => throw new InvalidArgumentException("Unsupported HTTP parameter schema at $where."),
        };

        if ($enumValues !== []) {
            if (!in_array($schema->kind, [SchemaKind::String, SchemaKind::Integer], true)) {
                throw new InvalidArgumentException("Unsupported HTTP parameter enum type at $where.");
            }
            foreach ($enumValues as $value) {
                if (($schema->kind === SchemaKind::String && !is_string($value))
                    || ($schema->kind === SchemaKind::Integer && !is_int($value))) {
                    throw new InvalidArgumentException("Invalid HTTP parameter enum value at $where.");
                }
            }
        }

        return ['type' => $type, 'nullable' => $nullable, 'enumValues' => $enumValues];
    }
}
