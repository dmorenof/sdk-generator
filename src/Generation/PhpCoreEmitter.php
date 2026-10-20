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
    public function __construct(?object $json = null)
    {
        if ($json === null) {
            return;
        }

        $fields = get_object_vars($json);
        foreach ($fields as $name => $value) {
            if (!property_exists($this, $name)) {
                throw new \InvalidArgumentException("Unknown JSON property " . static::class . "::$name.");
            }
            $property = new \ReflectionProperty($this, $name);
            if (!$property->isPublic() || $property->isStatic()) {
                throw new \InvalidArgumentException("JSON property " . static::class . "::$name is not writable.");
            }
            $type = $property->getType();
            if (!$type instanceof \ReflectionNamedType) {
                throw new \InvalidArgumentException("Unsupported PHP property type at " . static::class . "::$name.");
            }
            $this->{$name} = self::convert($value, $type, static::class . "::$name");
        }

        // Schema-required fields are checked both when hydrating incoming JSON
        // and again before encoding a manually created outbound DTO.
        $this->assertRequiredFields();
    }

    private static function convert(mixed $value, \ReflectionNamedType $type, string $where): mixed
    {
        if ($value === null) {
            if (!$type->allowsNull()) {
                throw new \UnexpectedValueException("Unexpected null at $where.");
            }
            return null;
        }

        $kind = $type->getName();
        if ($type->isBuiltin()) {
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
                    if (!is_string($item) && !is_int($item)) {
                        throw new \UnexpectedValueException("Invalid enum value at $itemWhere.");
                    }
                    $item = $expectedType::from($item);
                } elseif (is_subclass_of($expectedType, self::class)) {
                    if (!is_object($item)) {
                        throw new \UnexpectedValueException("Expected object at $itemWhere.");
                    }
                    $item = new $expectedType($item);
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

    private function assertRequiredFields(): void
    {
        if (!defined(static::class . '::REQUIRED_FIELDS')) {
            return;
        }
        $defined = get_object_vars($this);
        foreach (constant(static::class . '::REQUIRED_FIELDS') as $name) {
            if (!array_key_exists($name, $defined)) {
                throw new \UnexpectedValueException("Required JSON property " . static::class . "::$name is absent.");
            }
        }
    }

    public function jsonSerialize(): object
    {
        $this->assertRequiredFields();
        return (object) get_object_vars($this);
    }

    public function __toString(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR);
    }
}
PHP,
            'src/Core/Request.php' => $header . <<<'PHP'
/** Base request behavior. API-specific request fields are generated separately. */
class Request extends DTO
{
}
PHP,
            'src/Core/Response.php' => $header . <<<'PHP'
/** Base response behavior; vendor-specific envelopes are NOT universal fields. */
class Response extends DTO
{
}
PHP,
            'src/Core/TypedArray.php' => $header . <<<'PHP'
/** Shared typed-collection behavior without duplicating TypedArray validation. */
class TypedArray extends \TypedArray\TypedArray implements \Stringable
{
    public function getExpectedType(): string
    {
        return $this->expected_type;
    }

    public function __toString(): string
    {
        return json_encode($this->getArrayCopy(), JSON_THROW_ON_ERROR);
    }
}
PHP,
        ];
    }
}
