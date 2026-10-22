# Guía rápida — SDK Generator 0.1.0

Generador experimental de SDKs PHP **8.3+** a partir de un subconjunto de especificaciones **JSON o YAML** Swagger 2.0 y OpenAPI 3.0/3.1. No admite todavía toda la especificación.

## Generar un SDK

Desde el repositorio, con PHP y Composer disponibles:

~~~bash
composer install
php bin/generate-sdk.php tests/fixtures/accounts-openapi31.json build/accounts-sdk 'Example\AccountsSdk' example/accounts-sdk
cd build/accounts-sdk
composer install
~~~

Se crea un paquete Composer **independiente del generador** con DTOs, enums, colecciones tipadas, métodos de API y Guzzle por defecto.

~~~php
use Example\AccountsSdk\Client;
use Example\AccountsSdk\Api\Users\User;
use Example\AccountsSdk\Api\Users;
use Example\AccountsSdk\Requests\NewUser;

$client = Client::connect('https://api.example.com/v1');
$user = $client->resource(User::class)->getById(88);
echo $user->name;

$nuevo = new NewUser();
$nuevo->name = 'Grace';
$creado = $client->resource(Users::class)->post($nuevo);
~~~

La especificación de demostración es **sintética**: esa URL es ilustrativa y no se contacta durante las pruebas.

### Cliente HTTP opcional

~~~php
$client = Client::connect(
    'https://api.example.com/v1',
    $customPsr18Client,
    ['Authorization' => 'Bearer ' . $token]
);
~~~

Se puede inyectar Guzzle con middlewares o cualquier implementación PSR-18 compatible. Las operaciones son métodos de instancia: cada cliente conserva su conexión y credenciales. También puedes crear directamente una clase de ruta con `new User($server)` o compartir la configuración de un cliente mediante `$client->resource(User::class)`. Las operaciones protegidas aplican los `securitySchemes` de OpenAPI; los flujos para obtener tokens OAuth2/OpenID Connect no están implementados.

Las clases de rutas se generan bajo `Api`, siguiendo la jerarquía de cada ruta, y extienden `Client` para reutilizar la construcción de peticiones y el transporte común. Los errores HTTP identifican el método y la ruta (por ejemplo, `GET /users/{id}`), no el `operationId` arbitrario de OpenAPI. Los esquemas usados solo para enviar datos se generan bajo `Requests`; los de respuesta, bajo `Responses`; los compartidos o neutrales permanecen en `DTOs`. Los enums y colecciones de dominio se organizan según su esquema. Las colecciones equivalentes comparten clase; las de strings y enteros sin restricciones usan `StringCollection` e `IntegerCollection`. Los componentes objeto generados (`__schema__`) que solo usa un campo se integran en el DTO de ese campo; los compartidos conservan su tipo, y las uniones simples con `null` se expresan como propiedades nullable de PHP.

## Sin instalar PHP

Entra en [Actions: Downloadable SDK previews](https://github.com/dmorenof/sdk-generator/actions/workflows/sdk-preview.yml) y descarga los ZIP de Accounts/Catalog. La CI genera, instala y prueba los dos paquetes con peticiones HTTP simuladas.

## Límites importantes

El CLI detecta YAML por extensión `.yaml` o `.yml`; JSON sigue siendo compatible. `allOf`, `anyOf` y `oneOf` están soportados en un subconjunto acotado, no como implementación general de JSON Schema. La autenticación no se genera automáticamente. Consulta [SUPPORTED_SCOPE.md](SUPPORTED_SCOPE.md) para los límites y [el README principal](../README.md) para la visión general.

Los DTOs sí se instancian normalmente; las clases de rutas no.
