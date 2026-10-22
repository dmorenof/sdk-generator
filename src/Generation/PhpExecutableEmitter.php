<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

use Dmorenof\SdkGenerator\Contract\ApiContract;
use Dmorenof\SdkGenerator\Contract\SecuritySchemeDefinition;

/**
 * Experimental end-to-end HTTP SDK emitter.
 *
 * It adds a minimal injectable transport, JSON hydration, and executable
 * endpoint methods to the contract emitter. No API-specific rules are coded
 * here. Runtime behavior is inherited from the SDK's own shared Core classes;
 * the generator is never required at runtime.
 */
final class PhpExecutableEmitter
{
    private ?PhpModelNameResolver $modelNames = null;
    /** @var list<SecuritySchemeDefinition> */
    private array $securitySchemes = [];
    /** @var array<string, array{type:?string,nullable:bool,schemaNullable:bool,noContentAllowed:bool}> */
    private array $operationSuccessTypes = [];
    /** @var array<string, array<string, string>> */
    private array $operationErrorTypes = [];
    public function __construct(
        private readonly string $namespace = 'Generated\\Sdk',
        private readonly string $packageName = 'example/generated-sdk',
    ) {}

    /** @return array<string, string> */
    public function emit(ApiContract $contract): array
    {
        $this->securitySchemes = $contract->securitySchemes;
        $contractEmitter = new PhpContractEmitter($this->namespace, $this->packageName);
        $files = $contractEmitter->emit($contract);
        $this->modelNames = new PhpModelNameResolver(
            $this->namespace,
            $contract->models,
            PhpContractEmitter::modelRolesFor($contract),
        );
        $this->operationSuccessTypes = $contractEmitter->operationSuccessTypes();
        $this->operationErrorTypes = $contractEmitter->operationErrorTypes();
        unset(
            $files['src/Api/Operations.php'],
            $files['src/Api/OperationCatalog.php'],
            $files['src/Api/ApiClient.php'],
        );
        foreach (array_keys($files) as $path) {
            if (str_starts_with($path, 'src/Api/')) {
                unset($files[$path]);
            }
        }
        $ns = $this->namespace;
        $files['src/Transport/TransportInterface.php'] = $this->header($ns . '\\Transport')
            . "interface TransportInterface\n{\n"
            . "    /** @param array<string, string> \$headers */\n"
            . "    public function send(string \$method, string \$url, array \$headers, ?string \$body): HttpResponse;\n"
            . "}\n";
        $files['src/Transport/HttpResponse.php'] = $this->header($ns . '\\Transport')
            . "final readonly class HttpResponse\n{\n"
            . "    /** @param array<string, string> \$headers */\n"
            . "    public function __construct(public int \$status, public string \$body, public array \$headers = []) {}\n"
            . "}\n";
        // The default adapter is PSR-18-compatible and uses Guzzle 7 out of the box.
        // Build-time generator code is not loaded by the emitted package.
        $files = array_replace($files, (new PhpHttpCoreEmitter($ns))->emit());
        $files['src/Transport/Psr18Transport.php'] = $this->renderPsr18Transport();

        $manifest = json_decode($files['composer.json'], true, 512, JSON_THROW_ON_ERROR);
        $manifest['description'] = 'Standalone PHP SDK generated from an API contract, with typed endpoints and HTTP runtime.';
        $manifest['require']['guzzlehttp/guzzle'] = '^7.9';
        $manifest['require']['psr/http-client'] = '^1.0';
        ksort($manifest['require']);
        $files['composer.json'] = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
        $files['src/Support/JsonHydrator.php'] = $this->renderHydrator();
        $files = array_replace(
            $files,
            (new PhpResourceEmitter(
                $this->namespace,
                $this->securitySchemes,
                $this->operationSuccessTypes,
                $this->operationErrorTypes,
                $this->modelNames,
            ))->emit($contract),
        );
        ksort($files, SORT_STRING);
        return $files;
    }

    private function renderPsr18Transport(): string
    {
        $ns = $this->namespace;
        return $this->header($ns . '\\Transport')
            . "final class Psr18Transport extends \\" . $ns . "\\Core\\HttpClient\n{\n}\n";
    }

    private function header(string $namespace): string
    {
        return "<?php\n"
            . "declare(strict_types=1);\n\n"
            . "namespace $namespace;\n\n";
    }

    private function renderHydrator(): string
    {
        return $this->header($this->namespace . '\\Support')
            . "use \\{$this->namespace}\\Core\\DTO;\n\n"
            . <<<'PHP'
/** JSON decoding delegates every type conversion to the shared DTO runtime. */
final class JsonHydrator
{
    public static function decode(string $body, string $type, bool $nullable = false): mixed
    {
        return DTO::hydrateValue(
            json_decode($body, false, 512, JSON_THROW_ON_ERROR),
            $type,
            $nullable,
            'response',
        );
    }
}
PHP;
    }
}
