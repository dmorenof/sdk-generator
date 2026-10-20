<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

use Dmorenof\SdkGenerator\Contract\ModelDefinition;
use InvalidArgumentException;

/**
 * Map Swagger names into PSR-4 namespaces.
 *
 * If every schema has a shared dotted API prefix, remove that prefix from
 * generated class paths: the SDK root namespace already represents the API.
 *
 * Example (SDK namespace CasafariCrmApi):
 *   Casafari.Crm.Api.Dtos.Agency.AgencyDto
 *      -> src/Dtos/Agency/AgencyDto.php
 *      -> \CasafariCrmApi\Dtos\Agency\AgencyDto
 *
 * A final common domain category (Dtos/Models/etc) is preserved.
 * Undotted schemas retain the existing Models/ layout.
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
    /** @var list<string> */
    private array $commonApiPrefix;

    /** @param list<ModelDefinition> $models */
    public function __construct(private string $namespace, array $models)
    {
        $this->commonApiPrefix = $this->inferCommonApiPrefix($models);
        $paths = [];
        $types = [];
        $occupied = [];
        foreach ($models as $model) {
            $raw = $model->name;
            [$path, $type] = $this->resolve($raw, 'Models');
            $key = strtolower($path);
            if (isset($occupied[$key])) {
                throw new InvalidArgumentException(
                    "Generated PHP schema name collision: '$raw' and '{$occupied[$key]}' both map to $path.",
                );
            }
            $occupied[$key] = $raw;
            $paths[$raw] = $path;
            $types[$raw] = $type;
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
     * For generated enums and collections, retain the declaring model's
     * subnamespace, but place the type under Enums/ or Collections/.
     *
     * @return array{path:string, type:string, namespace:string, class:string}
     */
    public function derived(string $hint, string $kind): array
    {
        if (!in_array($kind, ['Enums', 'Collections'], true)) {
            throw new InvalidArgumentException("Unsupported derived type category: $kind");
        }
        [$path, $type] = $this->resolve($hint, $kind);
        $class = substr($type, strrpos($type, '\\') + 1);
        $namespace = substr($type, 0, strrpos($type, '\\'));
        return compact('path', 'type', 'namespace', 'class');
    }

    /** @return array{string, string} */
    private function resolve(string $name, string $category): array
    {
        if (!str_contains($name, '.')) {
            $this->checkSegment($name);
            return ['src/' . $category . '/' . $name . '.php',
                '\\' . $this->namespace . '\\' . $category . '\\' . $name];
        }

        $segments = explode('.', $name);
        if ($this->commonApiPrefix !== []
            && array_slice($segments, 0, count($this->commonApiPrefix)) === $this->commonApiPrefix) {
            $segments = array_slice($segments, count($this->commonApiPrefix));
        }
        if ($segments === []) {
            throw new InvalidArgumentException("Empty PHP class name from '$name'.");
        }
        foreach ($segments as $segment) {
            $this->checkSegment($segment);
        }
        $relative = implode('/', $segments);
        $namespace = implode('\\', $segments);
        // Dotted model definitions live directly under the SDK root; helper
        // enums/collections retain their hierarchy under their type category.
        if ($category === 'Models') {
            return ['src/' . $relative . '.php', '\\' . $this->namespace . '\\' . $namespace];
        }
        return ['src/' . $category . '/' . $relative . '.php',
            '\\' . $this->namespace . '\\' . $category . '\\' . $namespace];
    }

    /**
     * Only collapse when ALL model definitions share a dotted prefix.
     * Avoid swallowing meaningful type groups (Dtos, Models, etc), even if
     * they are present in every definition. Keep at least the class segment.
     *
     * @param list<ModelDefinition> $models
     * @return list<string>
     */
    private function inferCommonApiPrefix(array $models): array
    {
        if ($models === []) {
            return [];
        }
        $prefix = null;
        foreach ($models as $model) {
            $segments = explode('.', $model->name);
            if (count($segments) < 2) {
                // A mixed flat/hierarchical API has no single reliable root.
                return [];
            }
            if ($prefix === null) {
                $prefix = $segments;
                continue;
            }
            $common = 0;
            $limit = min(count($segments), count($prefix));
            while ($common < $limit && $prefix[$common] === $segments[$common]) {
                ++$common;
            }
            $prefix = array_slice($prefix, 0, $common);
            if ($prefix === []) {
                return [];
            }
        }
        // A model itself is not an API namespace.
        $shortest = min(array_map(static fn (ModelDefinition $m): int => count(explode('.', $m->name)), $models));
        $prefix = array_slice($prefix ?? [], 0, $shortest - 1);
        $domainCategories = [
            'dto', 'dtos', 'model', 'models', 'entity', 'entities',
            'request', 'requests', 'response', 'responses',
            'collection', 'collections', 'enum', 'enums',
        ];
        while ($prefix !== [] && in_array(strtolower($prefix[count($prefix) - 1]), $domainCategories, true)) {
            array_pop($prefix);
        }
        return $prefix;
    }

    /** For reporting only; the explicitly configured PHP root namespace remains authoritative. */
    public function inferredApiName(): ?string
    {
        return $this->commonApiPrefix === [] ? null : implode('', $this->commonApiPrefix);
    }

    private function checkSegment(string $segment): void
    {
        if (preg_match(self::IDENTIFIER, $segment) !== 1
            || in_array(strtolower($segment), self::RESERVED, true)) {
            throw new InvalidArgumentException("Invalid PHP schema namespace or class segment: $segment");
        }
    }
}
