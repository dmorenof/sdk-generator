<?php
declare(strict_types=1);
namespace Dmorenof\SdkGenerator\Parsing;

use Dmorenof\SdkGenerator\Contract\ModelDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaKind;
use Dmorenof\SdkGenerator\Diagnostics\JsonPointer;

/** Shared alias lookup; recursive object models stop at their object schema. */
final class SchemaReferences {
    /** @param list<ModelDefinition> $models */
    public static function resolve(SchemaDefinition $schema, array $models): SchemaDefinition {
        return self::target($schema, $models)[0];
    }
    /** @param list<ModelDefinition> $models */
    public static function name(SchemaDefinition $schema, array $models): ?string {
        return self::target($schema, $models)[1];
    }
    /** @param list<ModelDefinition> $models
     * @return array{SchemaDefinition,?string}
     */
    private static function target(SchemaDefinition $schema, array $models): array {
        $seen = []; $name = null;
        while ($schema->kind === SchemaKind::Reference) {
            if (count($seen) >= 128) { throw new \InvalidArgumentException('Schema alias depth exceeds 128.'); }
            $name = $schema->reference;
            if ($name === null || isset($seen[$name])) { throw new \InvalidArgumentException('Cyclic or invalid schema alias.'); }
            $seen[$name] = true; $resolved = null;
            foreach ($models as $model) { if ($model->name === $name) { $resolved = $model->schema; break; } }
            if ($resolved === null) { throw new \InvalidArgumentException("Unknown schema alias $name."); }
            $schema = $resolved;
        }
        return [$schema, $name];
    }

    /** Decode the existing JSON Pointer implementation, retaining the model ID verbatim. */
    public static function localName(string $reference, string $dialect): string {
        $tokens = JsonPointer::tokens($reference);
        $prefix = $dialect === 'swagger2' ? ['definitions'] : ['components', 'schemas'];
        if (count($tokens) !== count($prefix) + 1 || array_slice($tokens, 0, -1) !== $prefix || end($tokens) === '') {
            throw new \InvalidArgumentException('Expected a local schema component reference.');
        }
        return $tokens[count($prefix)];
    }

    public static function raw(array $schema, array $document): array {
        $seen = [];
        while (isset($schema['$ref'])) {
            if (count($seen) >= 128) { throw new \InvalidArgumentException('Raw schema alias depth exceeds 128.'); }
            $reference = $schema['$ref'];
            if (!is_string($reference) || isset($seen[$reference])) { throw new \InvalidArgumentException('Cyclic or invalid raw schema alias.'); }
            $seen[$reference] = true; $schema = JsonPointer::resolve($document, $reference);
            if (!is_array($schema)) { throw new \InvalidArgumentException('Expected an object schema alias.'); }
        }
        return $schema;
    }
}
