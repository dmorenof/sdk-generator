<?php

declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class PathStyleGenerationTest extends TestCase
{
    private static function document(): array
    {
        return json_decode(file_get_contents(__DIR__.'/fixtures/path-styles-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    private static function files(array $d): array
    {
        return(new PhpExecutableEmitter('Example\\PathSdk', 'example/path-styles'))->emit((new SpecificationNormalizer())->fromJson(json_encode($d, JSON_THROW_ON_ERROR), strict:true));
    }
    public static function setUpBeforeClass(): void
    {
        $files = self::files(self::document());
        foreach (['src/Client.php','src/Configuration.php','src/PathStylesAPISDK.php'] as $p) {
            eval(substr($files[$p], 5));
            unset($files[$p]);
        }
        foreach ($files as $p => $s) {
            if (str_ends_with($p, '.php')) {
                eval(substr($s, 5));
            }
        }
    }
    public function testVersionsYamlAndEveryDeclaredPathShape(): void
    {
        $d = self::document();
        $expected = self::files($d);
        self::assertSame($expected, (new PhpExecutableEmitter('Example\\PathSdk', 'example/path-styles'))->emit((new SpecificationNormalizer())->fromYaml(Yaml::dump($d, 40), strict:true)));
        $d['openapi'] = '3.0.3';
        self::assertSame($expected, self::files($d));
        foreach (['label','matrix'] as $style) {
            foreach ([null,false,true] as $explode) {
                foreach (['get','post'] as $v) {
                    foreach ([0,1,2] as $i) {
                        $d['paths']['/items/{code}/{ids}/{selector}'][$v]['parameters'][$i]['style'] = $style;
                        if ($explode === null) {
                            unset($d['paths']['/items/{code}/{ids}/{selector}'][$v]['parameters'][$i]['explode']);
                        } else {
                            $d['paths']['/items/{code}/{ids}/{selector}'][$v]['parameters'][$i]['explode'] = $explode;
                        }
                    }
                }
                $files = self::files($d);
                $source = $files['src/Resources/Items/ByCode/ByIds.php'];
                self::assertStringContainsString("'code' => '".$style."'", $source);
                self::assertStringContainsString($style.'-list'.($explode === true ? '-explode' : ''), $source);
                self::assertStringContainsString($style.'-object'.($explode === true ? '-explode' : ''), $source);
            }
        }
    }
    public function testCompositeIdentifiersKeepQueryHeaderAndJsonBodySeparate(): void
    {
        $http = new class () implements \Psr\Http\Client\ClientInterface {
            public array $calls = [];
            public function sendRequest(\Psr\Http\Message\RequestInterface $r): \Psr\Http\Message\ResponseInterface
            {
                $this->calls[] = $r;
                return new \GuzzleHttp\Psr7\Response(204);
            }
        };
        $config = new \Example\PathSdk\Configuration();
        $config->httpClient = $http;
        \Example\PathSdk\Client::configure($config);
        $path = new \Example\PathSdk\PathParams\Items\ByCode\ByIds\BySelector((object)['code' => 'x/y','ids' => ['a,b','ñ'],'selector' => (object)['code' => 'a/b','active' => false,'count' => 0]]);
        $get = new \Example\PathSdk\GetParams\Items\ByCode\ByIds\BySelector((object)['body' => 'query body','ids' => [2,3],'X-Trace' => 'trace']);
        \Example\PathSdk\Items\ByCode\ByIds::getBySelector($path, $get);
        $post = new \Example\PathSdk\PostParams\Items\ByCode\ByIds\BySelector((object)['queryBody' => 'query body','ids' => [2,3],'X-Trace' => 'trace','body' => (object)['name' => 'body name']]);
        $resource = \Example\PathSdk\Client::connect($config->baseUrl, httpClient:$http)->resource(\Example\PathSdk\Resources\Items\ByCode\ByIds::class);
        $resource->postBySelector($path, $post);
        foreach ($http->calls as $r) {
            self::assertSame('https://api.example.test/v1/items/.x%2Fy/;ids=a%2Cb;ids=%C3%B1/.active=false.code=a%2Fb.count=0?body=query%20body&ids=2&ids=3', (string)$r->getUri());
            self::assertSame('trace', $r->getHeaderLine('X-Trace'));
        }
        self::assertSame('{"name":"body name"}', (string)$http->calls[1]->getBody());
        $path->ids = [];
        try {
            $resource->postBySelector($path, $post);
            self::fail('Mutation accepted.');
        } catch (\UnexpectedValueException) {
            self::assertCount(2, $http->calls);
        }
    }
    public function testStylesRemainPathOnlyAndUndefinedShapesFail(): void
    {
        foreach (['header','query'] as $location) {
            foreach (['label', 'matrix'] as $style) {
                $swagger = ['swagger' => '2.0', 'info' => ['title' => 'Legacy', 'version' => '1'],
                    'host' => 'example.test', 'schemes' => ['https'], 'paths' => ['/items/{id}' => ['get' => [
                        'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'type' => 'string', 'style' => $style]],
                        'responses' => ['204' => ['description' => 'OK']]]]]];
                try {
                    (new SpecificationNormalizer())->fromJson(json_encode($swagger, JSON_THROW_ON_ERROR));
                    self::fail('Swagger style accepted.');
                } catch (\InvalidArgumentException $e) {
                    self::assertStringContainsString('style/explode', $e->getMessage());
                }
            }
            $d = self::document();
            $d['paths']['/items/{code}/{ids}/{selector}']['get']['parameters'][1]['in'] = $location;
            try {
                self::files($d);
                self::fail('Invalid location accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $d = self::document();
        $d['paths']['/items/{code}/{ids}/{selector}']['get']['parameters'][0]['style'] = 'unknown';
        try {
            self::files($d);
            self::fail('Unknown style accepted.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
    }
}
