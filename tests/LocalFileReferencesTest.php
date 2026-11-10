<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Acceptance\OpenApiAcceptanceAnalyzer;
use Dmorenof\SdkGenerator\Diagnostics\CompatibilityException;
use Dmorenof\SdkGenerator\Diagnostics\DocumentInspector;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\FileReferenceException;
use Dmorenof\SdkGenerator\Parsing\LocalFileBundler;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class LocalFileReferencesTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sdk-files-' . bin2hex(random_bytes(8));
        $this->copyTree(__DIR__ . '/fixtures/multifile-accounts', $this->directory);
    }

    private function copyTree(string $from, string $to): void
    {
        mkdir($to, 0700, true);
        foreach (new \FilesystemIterator($from) as $entry) {
            $entry->isDir() ? $this->copyTree($entry->getPathname(), "$to/" . $entry->getFilename())
                : copy($entry->getPathname(), "$to/" . $entry->getFilename());
        }
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    private function remove(string $path): void
    {
        foreach (new \FilesystemIterator($path) as $entry) {
            $entry->isDir() && !$entry->isLink() ? $this->remove($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }

    private function edit(string $file, callable $change): void
    {
        $path = "$this->directory/$file";
        $document = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        file_put_contents($path, json_encode($change($document), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    public function testMultifileInputEmitsTheSameContractAndSdkAsConsolidatedInput(): void
    {
        $normalizer = new SpecificationNormalizer();
        $inline = $normalizer->fromJson(file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json'), strict: true);
        $files = $normalizer->fromFile("$this->directory/api.json", strict: true);
        self::assertEquals($inline, $files);
        $emitter = new PhpExecutableEmitter('Example\\Multifile', 'example/multifile');
        self::assertSame($emitter->emit($inline), $emitter->emit($files));
        $acceptance = (new OpenApiAcceptanceAnalyzer())->analyzeFile("$this->directory/api.json");
        self::assertTrue($acceptance['accepted']);
        self::assertSame(2, $acceptance['operationCount']);
    }

    public function testYamlFilesAndUriEncodedFilenamesResolveRelativeToTheirOwnDocument(): void
    {
        $profile = json_decode(file_get_contents("$this->directory/models/Profile.json"), true, 512, JSON_THROW_ON_ERROR);
        file_put_contents("$this->directory/models/Profile info.yaml", Yaml::dump($profile, 20));
        $this->edit('api.json', static function ($doc) {
            $doc['components']['schemas']['Profile']['$ref'] = './models/Profile%20info.yaml';
            return $doc;
        });
        $this->edit('models/User.json', static function ($doc) {
            $doc['properties']['profile']['$ref'] = './Profile%20info.yaml';
            return $doc;
        });
        $root = json_decode(file_get_contents("$this->directory/api.json"), true, 512, JSON_THROW_ON_ERROR);
        file_put_contents("$this->directory/api.yaml", Yaml::dump($root, 30));
        self::assertTrue((new DocumentInspector())->inspectFile("$this->directory/api.yaml")['strictlyAccepted']);
    }

    public function testGraphsAreCachedAndSuccessfulReportsFingerprintEverySource(): void
    {
        $bundle = (new LocalFileBundler())->bundle("$this->directory/api.json");
        self::assertCount(8, $bundle->sources);
        foreach ($bundle->sources as $file => $hash) {
            self::assertSame(hash_file('sha256', $file), $hash);
        }
        $report = (new DocumentInspector())->inspectBundle($bundle);
        self::assertSame(hash_file('sha256', "$this->directory/api.json"), $report['sourceSha256']);
        self::assertSame(hash('sha256', json_encode($bundle->document, JSON_THROW_ON_ERROR)), $report['bundleSha256']);
        $this->edit('models/User.json', static fn ($doc) => $doc + ['not' => []]);
        self::assertTrue((new DocumentInspector())->inspectBundle($bundle)['strictlyAccepted']);
        self::assertFalse((new DocumentInspector())->inspectFile("$this->directory/api.json")['strictlyAccepted']);
    }

    public function testSchemaCyclesAcrossDocumentsRetainSymbolicIdentity(): void
    {
        $this->edit('models/Profile.json', static function ($doc) {
            $doc['properties']['owner'] = ['$ref' => '../api.json#/components/schemas/User'];
            return $doc;
        });
        $contract = (new SpecificationNormalizer())->fromFile("$this->directory/api.json", strict: true);
        self::assertCount(3, $contract->models);
        self::assertTrue((new DocumentInspector())->inspectFile("$this->directory/api.json")['strictlyAccepted']);
    }

    public function testDifferentFilesWithTheSameSchemaNameHaveDeterministicDistinctNames(): void
    {
        foreach (['left', 'right'] as $name) {
            file_put_contents("$this->directory/models/$name.json", json_encode(['User' => ['type' => 'object', 'properties' => [$name => ['type' => 'string']]]], JSON_THROW_ON_ERROR));
        }
        $this->edit('models/User.json', static function ($doc) {
            foreach (['left', 'right'] as $name) {
                $doc['properties'][$name] = ['$ref' => "./$name.json#/User"];
            }
            return $doc;
        });
        $bundle = (new LocalFileBundler())->bundle("$this->directory/api.json");
        $user = $bundle->document['components']['schemas']['User'];
        self::assertNotSame($user['properties']['left']['$ref'], $user['properties']['right']['$ref']);
        $other = "$this->directory/relocated";
        $this->copyTree(__DIR__ . '/fixtures/multifile-accounts', $other);
        copy("$this->directory/models/User.json", "$other/models/User.json");
        copy("$this->directory/models/left.json", "$other/models/left.json");
        copy("$this->directory/models/right.json", "$other/models/right.json");
        self::assertSame($bundle->document, (new LocalFileBundler())->bundle("$other/api.json")->document);
    }

    public function testDiagnosticsAndStrictErrorsPointToTheOriginalExternalSchema(): void
    {
        $this->edit('models/User.json', static function ($doc) {
            $doc['properties']['id']['not'] = ['type' => 'null'];
            return $doc;
        });
        $report = (new DocumentInspector())->inspectFile("$this->directory/api.json");
        $issue = array_values(array_filter($report['diagnostics'], static fn ($issue): bool => $issue->code === 'unsupported_schema_keyword'))[0];
        self::assertSame("$this->directory/models/User.json", $issue->source);
        self::assertSame('/properties/id/not', $issue->pointer);
        try {
            (new SpecificationNormalizer())->fromFile("$this->directory/api.json", strict: true);
            self::fail('Unsupported schema accepted.');
        } catch (CompatibilityException $exception) {
            self::assertSame($issue->source, $exception->diagnostics[0]->source);
        }
    }

    public function testMissingFilesAndFragmentsHaveTheirOriginalReferenceChain(): void
    {
        $this->edit('models/User.json', static function ($doc) {
            $doc['properties']['profile']['$ref'] = './missing.json#/Profile';
            return $doc;
        });
        $report = (new DocumentInspector())->inspectFile("$this->directory/api.json");
        $issue = $report['diagnostics'][0];
        self::assertSame('unresolved_reference', $issue->code);
        self::assertSame("$this->directory/models/User.json", $issue->source);
        self::assertSame('/properties/profile/$ref', $issue->pointer);
        self::assertSame(['./models/User.json', './missing.json#/Profile'], $issue->referenceChain);
        self::assertFalse($report['strictlyAccepted']);
    }

    public function testNetworkStreamsAbsolutePathsAndInvalidUrisAreNeverOpened(): void
    {
        foreach (['https://never-fetch.invalid/api.json', 'php://filter/resource=api.json', 'file:///tmp/api.json',
            '/tmp/api.json', '//never-fetch.invalid/api.json', './bad%00.json', './bad.json?x=1', './bad%zz.json'] as $reference) {
            $this->edit('api.json', static function ($doc) use ($reference) {
                $doc['components']['schemas']['User']['$ref'] = $reference;
                return $doc;
            });
            self::assertFalse((new DocumentInspector())->inspectFile("$this->directory/api.json")['strictlyAccepted']);
        }
    }

    public function testParentTraversalAndSymlinksStayInsideTheConfiguredRoot(): void
    {
        mkdir("$this->directory/restricted");
        copy("$this->directory/api.json", "$this->directory/restricted/api.json");
        $root = json_decode(file_get_contents("$this->directory/restricted/api.json"), true, 512, JSON_THROW_ON_ERROR);
        $root['components'] = ['schemas' => ['User' => ['$ref' => '../models/User.json']]];
        $root['paths'] = [];
        file_put_contents("$this->directory/restricted/api.json", json_encode($root, JSON_THROW_ON_ERROR));
        $inspector = new DocumentInspector();
        self::assertSame('reference_access', $inspector->inspectFile("$this->directory/restricted/api.json")['diagnostics'][0]->code);
        self::assertSame('reference_root', $inspector->inspectFile("$this->directory/restricted/api.json", '')['diagnostics'][0]->code);
        self::assertTrue($inspector->inspectFile("$this->directory/restricted/api.json", $this->directory)['strictlyAccepted']);
        symlink("$this->directory/models/User.json", "$this->directory/restricted/User.json");
        $root['components']['schemas']['User']['$ref'] = './User.json';
        file_put_contents("$this->directory/restricted/api.json", json_encode($root, JSON_THROW_ON_ERROR));
        self::assertSame('reference_access', $inspector->inspectFile("$this->directory/restricted/api.json")['diagnostics'][0]->code);
    }

    public function testFileCountAndByteBudgetsFailBeforeUnboundedLoading(): void
    {
        foreach ([new LocalFileBundler(maxFileBytes: 100), new LocalFileBundler(maxDocuments: 2),
            new LocalFileBundler(maxTotalBytes: filesize("$this->directory/api.json") + 1)] as $loader) {
            try {
                $loader->bundle("$this->directory/api.json");
                self::fail('Reference limit ignored.');
            } catch (FileReferenceException $exception) {
                self::assertSame('reference_limit', $exception->diagnostic->code);
            }
        }
    }

    public function testUnsupportedResourceScopesAndMixedDialectsAreRejected(): void
    {
        foreach ([['$id' => 'https://never-fetch.invalid/base/'], ['$schema' => 'https://json-schema.org/draft/2020-12/schema'],
            ['openapi' => '3.0.3']] as $extra) {
            $this->edit('models/User.json', static fn ($doc) => ['type' => 'object'] + $extra);
            $issue = (new DocumentInspector())->inspectFile("$this->directory/api.json")['diagnostics'][0];
            self::assertContains($issue->code, ['unsupported_reference_scope', 'unsupported_reference_dialect']);
        }
    }

    public function testExampleAndLinkValuesAreNotTreatedAsReferences(): void
    {
        $this->edit('models/User.json', static function ($doc) {
            $doc['example'] = ['$ref' => '../not-read.json'];
            return $doc;
        });
        $report = (new DocumentInspector())->inspectFile("$this->directory/api.json");
        self::assertTrue($report['strictlyAccepted']);
        self::assertCount(8, $report['sources']);
    }

    public function testHttpAliasCyclesAcrossFilesAreRejectedWithMappedLocations(): void
    {
        $this->edit('http/responses.json', static function ($doc) {
            $doc['User/~'] = ['$ref' => './responses.json#/ReadAlias'];
            return $doc;
        });
        $report = (new DocumentInspector())->inspectFile("$this->directory/api.json");
        self::assertFalse($report['strictlyAccepted']);
        $issues = array_values(array_filter($report['diagnostics'], static fn ($issue): bool => $issue->code === 'reference_cycle'));
        self::assertNotEmpty($issues);
        self::assertTrue(str_starts_with($issues[0]->source, $this->directory));
        self::assertTrue((bool) array_filter($issues[0]->referenceChain, fn ($reference): bool => str_contains($reference, 'responses.json#')));
    }

    public function testDefaultStringInspectionRemainsOfflineAndCliFileResolutionIsExplicit(): void
    {
        $file = "$this->directory/api.json";
        $inspector = new DocumentInspector();
        self::assertFalse($inspector->inspect(file_get_contents($file))['strictlyAccepted']);
        $command = [PHP_BINARY, dirname(__DIR__) . '/bin/generate-sdk.php', '--inspect', '--resolve-local-references', $file];
        exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
        self::assertTrue(json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR)['strictlyAccepted']);
        self::assertDirectoryDoesNotExist("$this->directory/output");
    }

    public function testHttpDiagnosticsKeepExternalPointersOperationsAndQualifiedChains(): void
    {
        $this->edit('http/parameters.json', static function ($doc) {
            $doc['ID/~']['style'] = 'deepObject';
            return $doc;
        });
        $report = (new OpenApiAcceptanceAnalyzer())->analyzeFile("$this->directory/api.json");
        $issues = array_values(array_filter($report['compatibility']['diagnostics'], static fn ($issue): bool =>
            $issue->code === 'parameter_style' && $issue->operationId === 'getUser'));
        self::assertSame("$this->directory/http/parameters.json", $issues[0]->source);
        self::assertSame('/ID~1~0/style', $issues[0]->pointer);
        self::assertTrue((bool) array_filter($issues[0]->referenceChain, static fn ($reference): bool => str_contains($reference, 'parameters.json#')));
    }

    public function testAncestorSchemaIdsAndMissingFragmentsAreActionableFailures(): void
    {
        file_put_contents("$this->directory/models/fragment.json", json_encode(['$id' => 'https://never-fetch.invalid/', 'Nested' => ['type' => 'object']], JSON_THROW_ON_ERROR));
        $this->edit('api.json', static function ($doc) {
            $doc['components']['schemas']['User']['$ref'] = './models/fragment.json#/Nested';
            return $doc;
        });
        self::assertSame('unsupported_reference_scope', (new DocumentInspector())->inspectFile("$this->directory/api.json")['diagnostics'][0]->code);
        $this->edit('api.json', static function ($doc) {
            $doc['components']['schemas']['User']['$ref'] = './models/Profile.json#/Missing';
            return $doc;
        });
        self::assertSame('unresolved_reference', (new DocumentInspector())->inspectFile("$this->directory/api.json")['diagnostics'][0]->code);
    }

    public function testAmbiguousPublicAliasesAndRootSchemaSiblingRulesAreExplicitLimits(): void
    {
        $this->edit('api.json', static function ($doc) {
            $doc['components']['schemas']['User']['description'] = 'Not silently discarded';
            return $doc;
        });
        self::assertSame('unsupported_reference_siblings', (new DocumentInspector())->inspectFile("$this->directory/api.json")['diagnostics'][0]->code);
        $this->edit('api.json', static function ($doc) {
            unset($doc['components']['schemas']['User']['description']);
            $doc['components']['schemas']['OtherUser'] = $doc['components']['schemas']['User'];
            return $doc;
        });
        self::assertSame('ambiguous_schema_alias', (new DocumentInspector())->inspectFile("$this->directory/api.json")['diagnostics'][0]->code);
    }

    public function testMalformedFilesAndNonObjectTargetsNeverGenerateOutput(): void
    {
        $this->edit('api.json', static function ($doc) {
            $doc['components']['schemas']['User']['$ref'] = './models/Profile.json#/properties/city/type';
            return $doc;
        });
        self::assertSame('invalid_reference_target', (new DocumentInspector())->inspectFile("$this->directory/api.json")['diagnostics'][0]->code);
        file_put_contents("$this->directory/models/Profile.json", '{');
        self::assertSame('invalid_reference_document', (new DocumentInspector())->inspectFile("$this->directory/api.json")['diagnostics'][0]->code);
    }

    public function testExternalPetMappingsPreserveInheritanceAndOriginalCompositionSemantics(): void
    {
        $document = json_decode(file_get_contents(__DIR__ . '/fixtures/pets-discriminator-openapi30.json'), true, 512, JSON_THROW_ON_ERROR);
        $inline = (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true);
        $convert = function ($node) use (&$convert) {
            if (!is_array($node)) {
                return $node;
            }
            foreach ($node as $key => $value) {
                if ($key === '$ref' && str_starts_with($value, '#/components/schemas/')) {
                    $node[$key] = './' . substr($value, strlen('#/components/schemas/')) . '.json';
                } else {
                    $node[$key] = $convert($value);
                }
            }
            foreach ($node['discriminator']['mapping'] ?? [] as $tag => $target) {
                $name = str_starts_with($target, '#/components/schemas/') ? substr($target, strlen('#/components/schemas/')) : $target;
                $node['discriminator']['mapping'][$tag] = $name . '.json';
            }
            return $node;
        };
        foreach ($document['components']['schemas'] as $name => $schema) {
            file_put_contents("$this->directory/models/$name.json", json_encode($convert($schema), JSON_THROW_ON_ERROR));
            $document['components']['schemas'][$name] = ['$ref' => "./models/$name.json"];
        }
        file_put_contents("$this->directory/pets.json", json_encode($document, JSON_THROW_ON_ERROR));
        $files = (new SpecificationNormalizer())->fromFile("$this->directory/pets.json", strict: true);
        self::assertEquals($inline, $files);
        $emitter = new PhpExecutableEmitter('Example\\FilePets', 'example/file-pets');
        self::assertSame($emitter->emit($inline), $emitter->emit($files));
    }

    public function testImplicitMultidocumentDiscriminatorsAreRejectedBeforeRenamingTags(): void
    {
        $this->edit('models/User.json', static function ($doc) {
            $doc['discriminator'] = ['propertyName' => 'kind'];
            return $doc;
        });
        self::assertSame('unsupported_external_discriminator', (new DocumentInspector())->inspectFile("$this->directory/api.json")['diagnostics'][0]->code);
    }

    public function testSwaggerAndOpenApi30SchemaFilesUseTheExistingModelContract(): void
    {
        foreach (['accounts-swagger2.json', 'accounts-openapi30.json'] as $fixture) {
            $document = json_decode(file_get_contents(__DIR__ . '/fixtures/' . $fixture), true, 512, JSON_THROW_ON_ERROR);
            $normalizer = new SpecificationNormalizer();
            $inline = $normalizer->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true);
            if (isset($document['swagger'])) {
                foreach ($document['definitions'] as $name => $schema) {
                    file_put_contents("$this->directory/models/$name.json", str_replace('#/definitions/', './', json_encode($schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
                    // References in the schema files must resolve within that file's namespace.
                    $text = file_get_contents("$this->directory/models/$name.json");
                    foreach (array_keys($document['definitions']) as $target) {
                        $text = str_replace('"./' . $target . '"', '"./' . $target . '.json"', $text);
                    }
                    file_put_contents("$this->directory/models/$name.json", $text);
                    $document['definitions'][$name] = ['$ref' => "./models/$name.json"];
                }
            } else {
                foreach ($document['components']['schemas'] as $name => $schema) {
                    $text = str_replace('#/components/schemas/', './', json_encode($schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                    foreach (array_keys($document['components']['schemas']) as $target) {
                        $text = str_replace('"./' . $target . '"', '"./' . $target . '.json"', $text);
                    }
                    file_put_contents("$this->directory/models/$name.json", $text);
                    $document['components']['schemas'][$name] = ['$ref' => "./models/$name.json"];
                }
            }
            file_put_contents("$this->directory/dialect.json", json_encode($document, JSON_THROW_ON_ERROR));
            self::assertEquals($inline, $normalizer->fromFile("$this->directory/dialect.json", strict: true));
        }
    }

    public function testAcceptanceCliPinsTheRootHashAndReportsTheDependencyHashes(): void
    {
        $file = "$this->directory/api.json";
        $command = [PHP_BINARY, dirname(__DIR__) . '/bin/check-openapi-acceptance.php', $file,
            '--resolve-local-references', '--sha256', hash_file('sha256', $file)];
        exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>/dev/null', $output, $status);
        self::assertSame(0, $status);
        self::assertCount(8, json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR)['sources']);
        $command[count($command) - 1] = str_repeat('0', 64);
        exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>/dev/null', $ignored, $status);
        self::assertSame(3, $status);
    }

    public function testCompleteExternalApiHttpNamespacesAreExplicitlyRejected(): void
    {
        file_put_contents("$this->directory/http/another-api.json", json_encode([
            'openapi' => '3.1.0', 'info' => ['title' => 'Other', 'version' => '1'],
            'servers' => [['url' => 'https://other.example.test']],
            'paths' => ['/users' => ['get' => ['responses' => ['204' => ['description' => 'Done']]]]],
        ], JSON_THROW_ON_ERROR));
        $this->edit('api.json', static function ($doc) {
            $doc['paths']['/users'] = ['$ref' => './http/another-api.json#/paths/~1users'];
            return $doc;
        });
        self::assertSame('unsupported_external_document_context', (new DocumentInspector())->inspectFile("$this->directory/api.json")['diagnostics'][0]->code);
    }

    public function testFailedStrictCliGenerationLeavesDestinationAbsent(): void
    {
        $this->edit('models/User.json', static function ($doc) {
            $doc['properties']['profile']['$ref'] = './missing.json';
            return $doc;
        });
        $command = [PHP_BINARY, dirname(__DIR__) . '/bin/generate-sdk.php', '--strict', '--resolve-local-references',
            "$this->directory/api.json", "$this->directory/output", 'example/no-output'];
        exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $lines, $status);
        self::assertSame(1, $status);
        self::assertDirectoryDoesNotExist("$this->directory/output");
        self::assertStringContainsString('models/User.json#/properties/profile/$ref', implode("\n", $lines));
    }

    public function testPureSchemaAliasesKeepThePublicModelAndRejectAliasCycles(): void
    {
        copy("$this->directory/models/User.json", "$this->directory/models/ActualUser.json");
        file_put_contents("$this->directory/models/User.json", '{"$ref":"./ActualUser.json"}');
        $normalizer = new SpecificationNormalizer();
        self::assertEquals($normalizer->fromJson(file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json'), strict: true),
            $normalizer->fromFile("$this->directory/api.json", strict: true));
        file_put_contents("$this->directory/models/ActualUser.json", '{"$ref":"./User.json"}');
        self::assertSame('reference_cycle', (new DocumentInspector())->inspectFile("$this->directory/api.json")['diagnostics'][0]->code);
    }

    public function testPinnedRootIsCheckedBeforeLoadingAnyDependencies(): void
    {
        $this->edit('api.json', static function ($doc) {
            $doc['components']['schemas']['User']['$ref'] = './missing.json';
            return $doc;
        });
        try {
            (new LocalFileBundler())->bundle("$this->directory/api.json", expectedRootHash: str_repeat('0', 64));
            self::fail('Hash pin ignored.');
        } catch (FileReferenceException $exception) {
            self::assertSame('source_hash_mismatch', $exception->diagnostic->code);
        }
    }
}
