# Guía rápida — SDK Generator 0.1.0

Generador experimental de SDKs PHP **8.3+** a partir de un subconjunto de especificaciones **JSON** Swagger 2.0 y OpenAPI 3.0/3.1. No admite todavía toda la especificación.

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
use Example\AccountsSdk\Api\ApiClient;

$api = ApiClient::connect('https://api.example.com/v1');
$user = $api->getUser(88);
echo $user->name;
~~~

La especificación de demostración es **sintética**: esa URL es ilustrativa y no se contacta durante las pruebas.

### Cliente HTTP opcional

~~~php
$api = ApiClient::connect(
    'https://api.example.com/v1',
    $customPsr18Client,
    ['Authorization' => 'Bearer ' . $token]
);
~~~

Se puede inyectar Guzzle con middlewares o cualquier implementación PSR-18 compatible. Todavía **no generamos autenticación** a partir de securitySchemes.

## Sin instalar PHP

Entra en [Actions: Downloadable SDK previews](https://github.com/dmorenof/sdk-generator/actions/workflows/sdk-preview.yml) y descarga los ZIP de Accounts/Catalog. La CI genera, instala y prueba los dos paquetes con peticiones HTTP simuladas.

## Límites importantes

Solo subconjunto JSON y endpoints/modelos admitidos; no YAML, composiciones completas de esquema ni autenticación automática. Hay tipos PHP nativos, pero **faltan verificaciones completas de campos requeridos y restricciones antes de enviar DTOs**. Consulta [SUPPORTED_SCOPE.md](SUPPORTED_SCOPE.md) y el [README principal](../README.md).

Esta versión no exige modificar Casafari SDK ni su interfaz de middlewares.
