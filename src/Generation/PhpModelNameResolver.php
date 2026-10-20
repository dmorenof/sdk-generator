<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

use Dmorenof\SdkGenerator\Contract\ModelDefinition;
use InvalidArgumentException;

/**
 * Map schema identifiers to PHP files without modifying their hierarchy.
 *
 * Casafari.Crm.Api.Dtos.Agency.AgencyDto
 *   -> src/Casafari/Crm/Api/Dtos/Agency/AgencyDto.php
 *   -> \Vendor\Sdk\Casafari\Crm\Api\Dtos\Agency\AgencyDto
 *
 * The namespace of the SDK is a separate concern: it is explicitly configured
 * or derived from OpenAPI/Swagger info.title, NEVER inferred from model paths.
 *
 * Undotted schemas retain the historical src/Models/ layout.
 */
final readonly class PhpModelNameResolver
{
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/D';
    private const RESERVED = [
        'abstract','and','array','as','break','callable','case','catch',
        'class','clone','const','continue','declare','default','die','do',
        'echo','else','elseif','empty','enddeclare','endfor','endforeach',
        'endif','endswitch','endwhile','enum','eval','exit','extends','final',
        'finally','fn','for','foreach','function','global','goto','if',
        'implements','include','include_once','instanceof','insteadof',
        'interface','isset','list','match','namespace','new','or','print',
        'private','protected','public','readonly','require','require_once',
        'return','static','switch','throw','trait','try','unset','use','var',
        'while','xor','yield','true','false','null','int','float','string',
        'bool','void','object','mixed','never','self','parent',
    ];

    /** @var array<string, string> */
    private array $paths;
    /** @var array<string, string> */
    private array $types;

    /** @param list<ModelDefinition> $models */
    public function __construct(private string $namespace, array $models)
    {
        $paths = [];
        $types = [];
        $occupied = [];
        foreach ($models as $model) {
            $name = $model->name;
            [$path, $type] = $this->resolve($name, 'Models');
            // On case-insensitive filesystems, path collisions must also fail.
            $key = strtolower($path);
            if (isset($occupied[$key])) {
                throw new InvalidArgumentException(
                    "Generated PHP schema name collision: '$name' and '{$occupied[$key]}' both map to $path.",
                );
            }
            $occupied[$key] = $name;
            $paths[$name] = $path;
            $types[$name] = $type;
        }
        $this->paths = $paths;
        $this->types = $types;
    }

    public function path(string $schemaName): string
    {
        return $this->paths[$schemaName]
            ?? throw new InvalidArgumentException("Unknown model reference: $schemaName.");
    }

    public function fqcn(string $schemaName): string
    {
        return $this->types[$schemaName]
            ?? throw new InvalidArgumentException("Unknown model reference: $schemaName.");
    }

    /**
     * Keep the full source hierarchy for derived collections and enums too.
     *
     * @return array{path:string,type:string,namespace:string,class:string}
     */
    public function derived(string $hint, string $category): array
    {
        if (!in_array($category, ['Enums', 'Collections'], true)) {
            throw new InvalidArgumentException("Unsupported derived type category: $category");
        }
        [$path, $type] = $this->resolve($hint, $category);
        $index = strrpos($type, '\\');
        return [
            'path' => $path,
            'type' => $type,
            'namespace' => substr($type, 0, $index),
            'class' => substr($type, $index + 1),
        ];
    }

    /** @return array{string,string} */
    private function resolve(string $name, string $category): array
    {
        if (!str_contains($name, '.')) {
            $this->checkSegment($name);
            return [
                'src/' . $category . '/' . $name . '.php',
                '\\' . $this->namespace . '\\' . $category . '\\' . $name,
            ];
        }

        $segments = explode('.', $name);
        foreach ($segments as $segment) {
            $this->checkSegment($segment);
        }
        $path = implode('/', $segments);
        $namespace = implode('\\', $segments);
        if ($category === 'Models') {
            return ['src/' . $path . '.php', '\\' . $this->namespace . '\\' . $namespace];
        }
        return [
            'src/' . $category . '/' . $path . '.php',
            '\\' . $this->namespace . '\\' . $category . '\\' . $namespace,
        ];
    }

    private function checkSegment(string $segment): void
    {
        if (preg_match(self::IDENTIFIER, $segment) !== 1
            || in_array(strtolower($segment), self::RESERVED, true)) {
            throw new InvalidArgumentException("Invalid PHP schema namespace or class segment: $segment");
        }
    }
}
