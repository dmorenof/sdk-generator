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
        $seen = [];
        while ($schema->kind === SchemaKind::Reference) {
            $name = $schema->reference;
            if ($name === null || isset($seen[$name])) { throw new \InvalidArgumentException('Cyclic or invalid schema alias.'); }
            $seen[$name] = true; $resolved = null;
            foreach ($models as $model) { if ($model->name === $name) { $resolved = $model->schema; break; } }
            if ($resolved === null) { throw new \InvalidArgumentException("Unknown schema alias $name."); }
            $schema = $resolved;
        }
        return $schema;
    }

    public static function raw(array $schema, array $document): array {
        $seen = [];
        while (isset($schema['$ref'])) {
            $reference = $schema['$ref'];
            if (!is_string($reference) || isset($seen[$reference])) { throw new \InvalidArgumentException('Cyclic or invalid raw schema alias.'); }
            $seen[$reference] = true; $schema = JsonPointer::resolve($document, $reference);
            if (!is_array($schema)) { throw new \InvalidArgumentException('Expected an object schema alias.'); }
        }
        return $schema;
    }
}
