<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

/**
 * Emits one reusable, standalone OO Core per generated SDK.
 *
 * The generator is build-time only. Generated DTOs, requests, responses and
 * typed arrays inherit the behavior without a separate dmorenof runtime package.
 */
final readonly class PhpCoreEmitter
{
    public function __construct(private string $namespace) {}

    /** @return array<string, string> */
    public function emit(): array
    {
        $header = "<?php\n"
            . "declare(strict_types=1);\n\n"
            . "namespace {$this->namespace}\\Core;\n\n";

        return [
            'src/Core/DTO.php' => $header . <<<'PHP'
/**
 * Shared base for strongly typed model classes.
 * Models declare fields and REQUIRED_FIELDS; behavior is inherited once.
 */
class DTO implements \JsonSerializable, \Stringable
{
    /** @var array<string,array<string,int|float|string|list<string|int>>> */
    protected const array VALIDATION = [];
    /** @var \WeakMap<self, array<string, mixed>>|null */
    private static ?\WeakMap $additionalPropertyValues = null;

    public function __construct(?object $json = null)
    {
        if ($json === null) {
            return;
        }

        $fields = get_object_vars($json);
        foreach ($fields as $name => $value) {
            $propertyMap = defined(static::class . '::PROPERTY_MAP')
                ? constant(static::class . '::PROPERTY_MAP')
                : [];
            $propertyName = $propertyMap[$name] ?? $name;
            if (!property_exists($this, $propertyName)) {
                if (!defined(static::class . '::ADDITIONAL_PROPERTIES_TYPE')) {
                    throw new \InvalidArgumentException("Unknown JSON property " . static::class . "::$name.");
                }
                $type = constant(static::class . '::ADDITIONAL_PROPERTIES_TYPE');
                $nullable = constant(static::class . '::ADDITIONAL_PROPERTIES_NULLABLE');
                $converted = self::convertMapValue($value, $type, $nullable, static::class . "::$name");
                self::storeAdditionalProperty($this, $name, $converted);
                continue;
            }
            $property = new \ReflectionProperty($this, $propertyName);
            if (!$property->isPublic() || $property->isStatic()) {
                throw new \InvalidArgumentException("JSON property " . static::class . "::$propertyName is not writable.");
            }
            $type = $property->getType();
            if (!$type instanceof \ReflectionNamedType) {
                throw new \InvalidArgumentException("Unsupported PHP property type at " . static::class . "::$propertyName.");
            }
            $this->{$propertyName} = self::convert($value, $type, static::class . "::$name");
        }

        // Schema-required fields are checked both when hydrating incoming JSON
        // and again before encoding a manually created outbound DTO.
        if ($this instanceof Request) {
            $this->validateForRequest();
        } elseif ($this instanceof Response) {
            $this->validateForResponse();
        } else {
            $this->validate();
        }
    }

    private static function convert(mixed $value, \ReflectionNamedType $type, string $where): mixed
    {
        return self::convertByType(
            $value,
            $type->getName(),
            $type->allowsNull(),
            $type->isBuiltin(),
            $where,
        );
    }

    public static function convertMapValue(mixed $value, string $kind, bool $nullable, string $where): mixed
    {
        $builtin = in_array($kind, ['mixed', 'int', 'float', 'string', 'bool'], true);
        return self::convertByType($value, $kind, $nullable, $builtin, $where);
    }

    private static function convertByType(
        mixed $value,
        string $kind,
        bool $nullable,
        bool $builtin,
        string $where,
    ): mixed {
        if (!$builtin && is_subclass_of($kind, AnyOf::class)) {
            if ($value instanceof $kind) {
                return $value;
            }
            return $kind::fromValue($value);
        }

        if ($value === null) {
            if (!$nullable && $kind !== 'mixed') {
                throw new \UnexpectedValueException("Unexpected null at $where.");
            }
            return null;
        }

        if ($builtin) {
            if ($kind === 'mixed') {
                return $value;
            }
            // JSON integers are valid floating point values when expected.
            if ($kind === 'float' && is_int($value)) {
                return (float) $value;
            }
            $valid = match ($kind) {
                'int' => is_int($value),
                'float' => is_float($value),
                'string' => is_string($value),
                'bool' => is_bool($value),
                default => false,
            };
            if (!$valid) {
                throw new \UnexpectedValueException("Invalid scalar value at $where: expected $kind.");
            }
            return $value;
        }

        if (is_subclass_of($kind, Map::class)) {
            if ($value instanceof $kind) {
                return $value;
            }
            if (!is_object($value) && !is_array($value)) {
                throw new \UnexpectedValueException("Expected JSON object at $where.");
            }
            return new $kind($value);
        }

        if (enum_exists($kind)) {
            if ($value instanceof $kind) {
                return $value;
            }
            if (!is_string($value) && !is_int($value)) {
                throw new \UnexpectedValueException("Invalid enum value at $where.");
            }
            return $kind::from($value);
        }

        if (is_subclass_of($kind, TypedArray::class)) {
            if ($value instanceof $kind) {
                return $value;
            }
            // Compatible with Casafari's empty-object-as-empty-list behavior.
            if (is_object($value) && get_object_vars($value) === []) {
                $value = [];
            }
            if (!is_array($value) || !array_is_list($value)) {
                throw new \UnexpectedValueException("Expected a JSON array at $where.");
            }
            $result = new $kind();
            $expectedType = $result->getExpectedType();
            foreach ($value as $index => $item) {
                $itemWhere = $where . "[$index]";
                if (enum_exists($expectedType)) {
                    if (!$item instanceof $expectedType && !is_string($item) && !is_int($item)) {
                        throw new \UnexpectedValueException("Invalid enum value at $itemWhere.");
                    }
                    $item = $item instanceof $expectedType ? $item : $expectedType::from($item);
                } elseif (is_subclass_of($expectedType, self::class)) {
                    if (!is_object($item)) {
                        throw new \UnexpectedValueException("Expected object at $itemWhere.");
                    }
                    $item = $item instanceof $expectedType ? $item : new $expectedType($item);
                }
                // Element type validation belongs to the existing TypedArray library.
                $result->append($item);
            }
            return $result;
        }

        if (is_subclass_of($kind, self::class)) {
            if ($value instanceof $kind) {
                return $value;
            }
            if (!is_object($value)) {
                throw new \UnexpectedValueException("Expected JSON object at $where.");
            }
            return new $kind($value);
        }

        throw new \UnexpectedValueException("Unsupported nested PHP type $kind at $where.");
    }

    public static function validateMapConstraints(mixed $value, array $rules, string $where): void
    {
        foreach ($rules as $name => $limit) {
            self::assertConstraint('$value', $value, $name, $limit, $where);
        }
    }

    public function setAdditionalProperty(string $name, mixed $value): void
    {
        $propertyMap = defined(static::class . '::PROPERTY_MAP')
            ? constant(static::class . '::PROPERTY_MAP')
            : [];
        if (property_exists($this, $name) || array_key_exists($name, $propertyMap)) {
            throw new \InvalidArgumentException("Property " . static::class . "::$name is declared; assign it directly.");
        }
        if (!defined(static::class . '::ADDITIONAL_PROPERTIES_TYPE')) {
            throw new \InvalidArgumentException("Additional properties are not allowed by " . static::class . '.');
        }
        $type = constant(static::class . '::ADDITIONAL_PROPERTIES_TYPE');
        $nullable = constant(static::class . '::ADDITIONAL_PROPERTIES_NULLABLE');
        $converted = self::convertMapValue($value, $type, $nullable, static::class . "::$name");
        $rules = defined(static::class . '::ADDITIONAL_PROPERTIES_VALIDATION')
            ? constant(static::class . '::ADDITIONAL_PROPERTIES_VALIDATION')
            : [];
        self::validateMapConstraints($converted, $rules, static::class . "::$name");
        self::storeAdditionalProperty($this, $name, $converted);
    }

    public function getAdditionalProperty(string $name): mixed
    {
        return self::additionalPropertiesFor($this)[$name] ?? null;
    }

    /** @return array<string, mixed> */
    public function additionalProperties(): array
    {
        return self::additionalPropertiesFor($this);
    }

    private static function storeAdditionalProperty(self $dto, string $name, mixed $value): void
    {
        self::$additionalPropertyValues ??= new \WeakMap();
        $values = self::$additionalPropertyValues[$dto] ?? [];
        $values[$name] = $value;
        self::$additionalPropertyValues[$dto] = $values;
    }

    /** @return array<string, mixed> */
    private static function additionalPropertiesFor(self $dto): array
    {
        return self::$additionalPropertyValues[$dto] ?? [];
    }

    /** @return array<string, mixed> */
    private function fields(): array
    {
        return array_replace(get_object_vars($this), self::additionalPropertiesFor($this));
    }

    private function assertRequiredFields(bool $request = false, bool $response = false): void
    {
        if (!defined(static::class . '::REQUIRED_FIELDS')) {
            return;
        }
        $defined = $this->fields();
        $readOnly = $request && defined(static::class . '::READ_ONLY_FIELDS')
            ? constant(static::class . '::READ_ONLY_FIELDS')
            : [];
        $writeOnly = $response && defined(static::class . '::WRITE_ONLY_FIELDS')
            ? constant(static::class . '::WRITE_ONLY_FIELDS')
            : [];
        foreach (constant(static::class . '::REQUIRED_FIELDS') as $name) {
            if (in_array($name, $readOnly, true) || in_array($name, $writeOnly, true)) {
                continue;
            }
            if (!array_key_exists($name, $defined)) {
                throw new \UnexpectedValueException("Required JSON property " . static::class . "::$name is absent.");
            }
        }
    }

    /**
     * Run optional, declarative constraints and validate nested DTOs.
     * Undefined optional fields are skipped; a nullable field can be null.
     * Generated DTO classes declare rules, never duplicate implementations.
     */
    public function validate(): void
    {
        $this->validateWithMode();
    }

    public function validateForRequest(): void
    {
        $this->validateWithMode(true, false);
    }

    public function validateForResponse(): void
    {
        $this->validateWithMode(false, true);
    }

    private function validateWithMode(bool $request = false, bool $response = false): void
    {
        $this->assertRequiredFields($request, $response);
        $fields = $this->fields();
        $additionalRules = defined(static::class . '::ADDITIONAL_PROPERTIES_VALIDATION')
            ? constant(static::class . '::ADDITIONAL_PROPERTIES_VALIDATION')
            : [];
        if ($additionalRules !== []) {
            foreach (self::additionalPropertiesFor($this) as $name => $value) {
                self::validateMapConstraints($value, $additionalRules, static::class . "::$name");
            }
        }
        foreach (static::VALIDATION as $field => $rules) {
            if (!property_exists($this, $field)) {
                throw new \LogicException("Validation refers to an unknown field " . static::class . "::$field.");
            }
            if (!array_key_exists($field, $fields) || $fields[$field] === null) {
                continue;
            }
            if ($request && defined(static::class . '::READ_ONLY_FIELDS')
                && in_array($field, constant(static::class . '::READ_ONLY_FIELDS'), true)) {
                continue;
            }
            if ($response && defined(static::class . '::WRITE_ONLY_FIELDS')
                && in_array($field, constant(static::class . '::WRITE_ONLY_FIELDS'), true)) {
                continue;
            }
            foreach ($rules as $name => $limit) {
                self::assertConstraint($field, $fields[$field], $name, $limit);
            }
        }
        foreach ($fields as $value) {
            if ($value instanceof self) {
                if ($request) {
                    $value->validateForRequest();
                } elseif ($response) {
                    $value->validateForResponse();
                } else {
                    $value->validate();
                }
            } elseif ($value instanceof TypedArray) {
                foreach ($value as $item) {
                    if ($item instanceof self) {
                        if ($request) {
                            $item->validateForRequest();
                        } elseif ($response) {
                            $item->validateForResponse();
                        } else {
                            $item->validate();
                        }
                    }
                }
            } elseif ($value instanceof Map) {
                if ($request) {
                    $value->validateForRequest();
                } elseif ($response) {
                    $value->validateForResponse();
                } else {
                    $value->validate();
                }
            }
        }
    }

    private static function assertConstraint(
        string $field,
        mixed $value,
        string $rule,
        mixed $limit,
        ?string $location = null,
    ): void
    {
        $where = $location ?? static::class . "::$field";
        if ($rule === 'pattern') {
            if (!is_string($limit) || !is_string($value)) {
                throw new \UnexpectedValueException("Expected string for pattern at $where.");
            }
            $regex = '~' . str_replace('~', '\\~', $limit) . '~u';
            $matched = preg_match($regex, $value);
            if ($matched === false) {
                throw new \LogicException("Invalid pattern constraint for $where.");
            }
            $valid = $matched === 1;
        } elseif ($rule === 'enum') {
            if (!is_array($limit)) {
                throw new \LogicException("Invalid enum constraint for $where.");
            }
            $valid = in_array($value, $limit, true);
        } else {
            if (!is_int($limit) && !is_float($limit)) {
                throw new \LogicException("Invalid $rule constraint for $where.");
            }
            $valid = match ($rule) {
                'minLength', 'maxLength' => self::validateStringLength($value, $rule, $limit, $where),
                'minimum', 'maximum' => self::validateNumber($value, $rule, $limit, $where),
                'minItems', 'maxItems' => self::validateItemCount($value, $rule, $limit, $where),
                default => throw new \LogicException("Unknown validation rule $rule for $where."),
            };
        }
        if (!$valid) {
            $description = $rule === 'enum' ? 'allowed values ' . var_export($limit, true) : "limit $limit";
            throw new \UnexpectedValueException("Validation $rule failed at $where ($description).");
        }
    }

    private static function validateStringLength(mixed $value, string $rule, int|float $limit, string $where): bool
    {
        if (!is_string($value)) {
            throw new \UnexpectedValueException("Expected string for $rule at $where.");
        }
        // OpenAPI length counts Unicode code points, not UTF-8 bytes.
        $length = preg_match_all('/./us', $value);
        if ($length === false) {
            throw new \UnexpectedValueException("Invalid UTF-8 string at $where.");
        }
        return $rule === 'minLength' ? $length >= $limit : $length <= $limit;
    }

    private static function validateNumber(mixed $value, string $rule, int|float $limit, string $where): bool
    {
        if (!is_int($value) && !is_float($value)) {
            throw new \UnexpectedValueException("Expected number for $rule at $where.");
        }
        return $rule === 'minimum' ? $value >= $limit : $value <= $limit;
    }

    private static function validateItemCount(mixed $value, string $rule, int|float $limit, string $where): bool
    {
        if (!is_array($value) && !$value instanceof \Countable) {
            throw new \UnexpectedValueException("Expected array/collection for $rule at $where.");
        }
        $count = count($value);
        return $rule === 'minItems' ? $count >= $limit : $count <= $limit;
    }

    public function jsonSerialize(): object
    {
        $this->validate();
        return self::filterFields(
            $this->fields(),
            defined(static::class . '::WRITE_ONLY_FIELDS') ? constant(static::class . '::WRITE_ONLY_FIELDS') : [],
            'neutral',
        );
    }

    public function jsonSerializeForRequest(): object
    {
        $this->validateForRequest();
        $fields = $this->fields();
        $readOnly = defined(static::class . '::READ_ONLY_FIELDS')
            ? constant(static::class . '::READ_ONLY_FIELDS')
            : [];
        $fields = array_filter(
            $fields,
            static fn (mixed $value, string $name): bool => !in_array($name, $readOnly, true),
            ARRAY_FILTER_USE_BOTH,
        );
        return self::filterFields($fields, [], 'request');
    }

    public function jsonSerializeForResponse(): object
    {
        $this->validateForResponse();
        $writeOnly = defined(static::class . '::WRITE_ONLY_FIELDS')
            ? constant(static::class . '::WRITE_ONLY_FIELDS')
            : [];
        $fields = array_filter(
            $this->fields(),
            static fn (mixed $value, string $name): bool => !in_array($name, $writeOnly, true),
            ARRAY_FILTER_USE_BOTH,
        );
        return self::filterFields($fields, [], 'response');
    }

    /** @param array<string, mixed> $fields @param list<string> $excluded */
    private static function filterFields(array $fields, array $excluded, string $mode): object
    {
        $serialized = [];
        $wireNames = defined(static::class . '::WIRE_PROPERTY_MAP')
            ? constant(static::class . '::WIRE_PROPERTY_MAP')
            : [];
        foreach ($fields as $name => $value) {
            if (!in_array($name, $excluded, true)) {
                $wireName = $wireNames[$name] ?? $name;
                $serialized[$wireName] = self::serializeValueForMode($value, $mode);
            }
        }
        return (object) $serialized;
    }

    public static function serializeValueForMode(mixed $value, string $mode): mixed
    {
        if ($value instanceof self) {
            if ($mode === 'request') {
                return $value->jsonSerializeForRequest();
            }
            if ($mode === 'response') {
                return $value->jsonSerializeForResponse();
            }
            return $value->jsonSerialize();
        }
        if ($value instanceof TypedArray) {
            $value = $value->getArrayCopy();
        }
        if ($value instanceof Map) {
            return $value->serializeForMode($mode);
        }
        if (is_array($value)) {
            return array_map(
                static fn (mixed $item): mixed => self::serializeValueForMode($item, $mode),
                $value,
            );
        }
        return $value;
    }

    public function __toString(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR);
    }
}
PHP,
            'src/Core/AnyOf.php' => $header . <<<'PHP'
/**
 * Shared runtime for OpenAPI anyOf/oneOf generated wrappers.
 * Generated subclasses provide only their permitted PHP types.
 */
class AnyOf implements \JsonSerializable
{
    /** @var list<class-string|class-string-enum|'string'|'int'|'float'|'bool'|'null'> */
    protected const array TYPES = [];

    /** @param list<mixed> $matches */
    protected function __construct(
        private readonly mixed $original,
        private readonly array $matches,
    ) {}

    public static function fromValue(mixed $value): static
    {
        $original = $value instanceof \JsonSerializable ? $value->jsonSerialize() : $value;
        $matches = [];
        $failures = [];
        foreach (static::TYPES as $type) {
            try {
                $matches[] = self::hydrate($type, $value, static::class);
            } catch (\UnexpectedValueException|\InvalidArgumentException|\ValueError|\TypeError $exception) {
                $failures[] = $type . ': ' . $exception->getMessage();
            }
        }

        if ($matches === []) {
            try {
                static::assertMatchCount(0);
            } catch (\UnexpectedValueException $exception) {
                throw new \UnexpectedValueException(
                    $exception->getMessage() . ' ' . implode(' | ', $failures),
                    0,
                    $exception,
                );
            }
        }
        static::assertMatchCount(count($matches));
        return new static($original, $matches);
    }

    /** @return list<mixed> All successfully hydrated variants, in schema order. */
    public function matchedVariants(): array
    {
        return $this->matches;
    }

    protected static function assertMatchCount(int $count): void
    {
        if ($count < 1) {
            throw new \UnexpectedValueException('Expected at least one anyOf match for ' . static::class . '.');
        }
    }

    private static function hydrate(string $type, mixed $value, string $where): mixed
    {
        if ($type === 'null') {
            if ($value !== null) {
                throw new \UnexpectedValueException('Expected null.');
            }
            return null;
        }
        if ($type === 'string' || $type === 'int' || $type === 'float' || $type === 'bool') {
            $matches = match ($type) {
                'string' => is_string($value),
                'int' => is_int($value),
                'float' => is_float($value) || is_int($value),
                'bool' => is_bool($value),
            };
            if (!$matches) {
                throw new \UnexpectedValueException("Expected $type.");
            }
            return $type === 'float' ? (float) $value : $value;
        }

        if ($value instanceof $type) {
            if ($value instanceof DTO) {
                $value->validate();
            }
            return $value;
        }
        if (enum_exists($type)) {
            if (!is_string($value) && !is_int($value)) {
                throw new \UnexpectedValueException('Expected enum scalar.');
            }
            return $type::from($value);
        }
        if (is_subclass_of($type, self::class)) {
            return $type::fromValue($value);
        }
        if (is_subclass_of($type, TypedArray::class)) {
            if (!is_array($value) || !array_is_list($value)) {
                throw new \UnexpectedValueException('Expected JSON array.');
            }
            $result = new $type();
            $expectedType = $result->getExpectedType();
            foreach ($value as $index => $item) {
                $result->append(self::hydrateCollectionItem($expectedType, $item, "$where[$index]"));
            }
            return $result;
        }
        if (is_subclass_of($type, Map::class)) {
            if (!is_object($value) && !is_array($value)) {
                throw new \UnexpectedValueException('Expected JSON object.');
            }
            return new $type($value);
        }
        if (is_subclass_of($type, DTO::class)) {
            if (!is_object($value)) {
                throw new \UnexpectedValueException('Expected JSON object.');
            }
            return new $type($value);
        }

        throw new \LogicException("Unsupported anyOf variant type $type.");
    }

    private static function hydrateCollectionItem(string $type, mixed $value, string $where): mixed
    {
        $type = match ($type) {
            'integer' => 'int',
            'double' => 'float',
            'boolean' => 'bool',
            default => $type,
        };
        try {
            return self::hydrate($type, $value, $where);
        } catch (\UnexpectedValueException $exception) {
            throw new \UnexpectedValueException("$where: " . $exception->getMessage(), 0, $exception);
        }
    }

    public function jsonSerialize(): mixed
    {
        return $this->original;
    }
}
PHP,
            'src/Core/OneOf.php' => $header . <<<'PHP'
/** Shared exact-one-match semantics for generated OpenAPI oneOf wrappers. */
class OneOf extends AnyOf
{
    protected static function assertMatchCount(int $count): void
    {
        if ($count !== 1) {
            throw new \UnexpectedValueException(
                'Expected exactly one oneOf match for ' . static::class . "; found $count.",
            );
        }
    }
}
PHP,
            'src/Core/Request.php' => $header . <<<'PHP'
/** Base request behavior. API-specific request fields are generated separately. */
class Request extends DTO
{
    public function validate(): void
    {
        $this->validateForRequest();
    }

    public function jsonSerialize(): object
    {
        return $this->jsonSerializeForRequest();
    }
}
PHP,
            'src/Core/Response.php' => $header . <<<'PHP'
/** Base response behavior; vendor-specific envelopes are NOT universal fields. */
class Response extends DTO
{
    public function validate(): void
    {
        $this->validateForResponse();
    }

    public function jsonSerialize(): object
    {
        return $this->jsonSerializeForResponse();
    }
}
PHP,
            'src/Core/TypedArray.php' => $header . <<<'PHP'
/** Shared typed-collection behavior without duplicating TypedArray validation. */
class TypedArray extends \TypedArray\TypedArray implements \Stringable
{
    protected const array ITEM_VALIDATION = [];

    public function __construct(array $items = [])
    {
        foreach ($items as $item) {
            DTO::validateMapConstraints($item, static::ITEM_VALIDATION, static::class . ' item');
        }
        parent::__construct($items);
    }

    public function getExpectedType(): string
    {
        return $this->expected_type;
    }

    public function append(mixed $value): void
    {
        DTO::validateMapConstraints($value, static::ITEM_VALIDATION, static::class . ' item');
        parent::append($value);
    }

    public function offsetSet(mixed $key, mixed $value): void
    {
        DTO::validateMapConstraints($value, static::ITEM_VALIDATION, static::class . ' item');
        parent::offsetSet($key, $value);
    }

    public function __toString(): string
    {
        return json_encode($this->getArrayCopy(), JSON_THROW_ON_ERROR);
    }
}
PHP,
            'src/Core/Map.php' => $header . <<<'PHP'
/** Shared keyed JSON-object collection behavior for generated map wrappers. */
class Map implements \ArrayAccess, \Countable, \IteratorAggregate, \JsonSerializable
{
    protected const string VALUE_TYPE = 'mixed';
    protected const bool VALUE_NULLABLE = false;
    protected const array VALUE_VALIDATION = [];

    /** @var array<string, mixed> */
    private array $values = [];

    /** @param array<string, mixed>|object $values */
    public function __construct(array|object $values = [])
    {
        if (is_array($values) && $values !== [] && array_is_list($values)) {
            throw new \UnexpectedValueException('Expected an associative array or JSON object for a map.');
        }
        foreach (is_object($values) ? get_object_vars($values) : $values as $key => $value) {
            $this->offsetSet($key, $value);
        }
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($this->normalizeKey($offset), $this->values);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->values[$this->normalizeKey($offset)] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $key = $this->normalizeKey($offset);
        $where = static::class . '[' . $key . ']';
        $converted = DTO::convertMapValue($value, static::VALUE_TYPE, static::VALUE_NULLABLE, $where);
        DTO::validateMapConstraints($converted, static::VALUE_VALIDATION, $where);
        $this->values[$key] = $converted;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->values[$this->normalizeKey($offset)]);
    }

    public function count(): int
    {
        return count($this->values);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->values);
    }

    /** @return array<string, mixed> */
    public function getArrayCopy(): array
    {
        return $this->values;
    }

    public function validate(): void
    {
        $this->validateForMode('neutral');
    }

    public function validateForRequest(): void
    {
        $this->validateForMode('request');
    }

    public function validateForResponse(): void
    {
        $this->validateForMode('response');
    }

    private function validateForMode(string $mode): void
    {
        foreach ($this->values as $key => $value) {
            $where = static::class . '[' . $key . ']';
            DTO::validateMapConstraints($value, static::VALUE_VALIDATION, $where);
            $this->validateNestedValue($value, $mode);
        }
    }

    private function validateNestedValue(mixed $value, string $mode): void
    {
        if ($value instanceof DTO || $value instanceof self) {
            if ($mode === 'request') {
                $value->validateForRequest();
            } elseif ($mode === 'response') {
                $value->validateForResponse();
            } else {
                $value->validate();
            }
        } elseif ($value instanceof TypedArray) {
            foreach ($value as $item) {
                $this->validateNestedValue($item, $mode);
            }
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                $this->validateNestedValue($item, $mode);
            }
        }
    }

    private function normalizeKey(mixed $key): string
    {
        if (!is_string($key) && !is_int($key)) {
            throw new \InvalidArgumentException('Map keys must be strings or integers.');
        }
        return (string) $key;
    }

    public function jsonSerialize(): object
    {
        $this->validate();
        return $this->serializeForMode('neutral');
    }

    public function serializeForMode(string $mode): object
    {
        $values = [];
        foreach ($this->values as $key => $value) {
            $values[$key] = DTO::serializeValueForMode($value, $mode);
        }
        return (object) $values;
    }
}
PHP,
        ];
    }
}
