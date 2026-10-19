# Primeras conclusiones medidas: SDK PHP frente a OpenAPI Generator

**Estado:** prueba exploratoria realizada y reproducida mediante GitHub Actions, 19 octubre 2026. [Ejecución verificada](https://github.com/dmorenof/sdk-generator/actions/runs/39321927932). [Datos brutos y código generado](https://github.com/dmorenof/sdk-generator/actions/runs/39321927932/artifacts/12220317031).

## Rendimiento durante ejecución — mismo HTTP simulado

Se usa OpenAPI Generator 7.26.0, salida PHP/Guzzle, sin plantillas modificadas; nuestra salida PSR-18/Guzzle procede del PR #11. Ambos SDKs reciben exactamente la misma especificación OpenAPI 3.0 de Accounts o Catalog. La respuesta JSON y un manejador Guzzle sin red son iguales. Cada ensayo crea su propio proceso PHP; 12 peticiones de calentamiento y 1000 peticiones GET con DTO, cinco ensayos por combinación, ejecución alternada. Medianas.

| API | SDK | µs/GET+DTO | Inicialización ms | Memoria PHP pico MiB | Memoria proceso pico MiB | Vendor instalado KiB | Paquetes de producción |
|---|---|---:|---:|---:|---:|---:|---:|
| Accounts | Nuestro | 27,25 | 3,92 | 2,00 | 52,40 | 1856 | 10 |
| Accounts | OpenAPI Generator | 32,89 | 4,29 | 4,00 | 54,45 | 1780 | 9 |
| Catalog | Nuestro | 28,54 | 3,90 | 2,00 | 52,46 | 1856 | 10 |
| Catalog | OpenAPI Generator | 33,61 | 4,30 | 4,00 | 54,45 | 1780 | 9 |

En esta pequeña carga, nuestro cliente fue alrededor de **17 % más rápido en Accounts** y **15 % en Catalog** para GET+DTO. La diferencia de memoria **del proceso** fue mucho menor, unos 2 MiB (~4 %); las cifras `memory_get_peak_usage(true)` usan bloques de memoria PHP y no equivalen a la memoria total del proceso. El árbol instalado de dependencias fue incluso algo **más grande** en nuestro SDK y contiene un paquete más. Evitar afirmar que nuestras dependencias son menores.

**Corrección de metodología:** una primera ejecución instaló `require-dev`, lo que hinchó artificialmente el vendor de OpenAPI Generator (incluía dependencias de pruebas). Los resultados anteriores quedaron descartados. Esta tabla emplea `composer install --no-dev` para ambos paquetes; los datos brutos corresponden a la ejecución final.

## Facilidad de integración — evidencia y límites

Para una operación GET simple, ambos SDKs ofrecen métodos tipados sin necesidad de definir un transporte HTTP. El nuestro utiliza `ApiClient::connect($server)->getUser(...)`; OpenAPI Generator crea `DefaultApi` con su configuración, cuyo host se puede definir por `Configuration::setHost()`. En ambas opciones, personalizar middlewares Guzzle significa construir o reutilizar un cliente `GuzzleHttp\Client` y pasarlo al SDK. **No hay todavía una prueba con desarrolladores que demuestre que uno resulte más intuitivo.**

El nuestro tiene DTOs con propiedades PHP tipadas y clases `TypedArray` para colecciones. OpenAPI Generator dispone de más cobertura del estándar y otras facilidades de autenticación, errores y compatibilidad. No se han comparado esas prestaciones de forma equivalente; conviene evaluar escenarios completos, no solo contar líneas de ejemplo.

## Límites de la afirmación de eficiencia

- Microbenchmark sin red, usando payloads JSON pequeños y operaciones GET con DTO. No mide latencia de servicios, streaming, POST, autenticación ni errores.
- No hay equivalencia de prestaciones completa. OpenAPI Generator puede ejecutar validaciones adicionales y admite casos que nuestro emisor aún rechaza.
- El runner es compartido y solo se realizaron cinco ensayos por combinación. Las cifras son orientativas, no una garantía estable.
- Las dependencias de ambos paquetes se instalaron sin `require-dev`. El árbol `vendor` mide tamaño en disco; las cifras de paquetes pueden variar con resoluciones futuras de Composer.
- La cifra de arranque no incluye el arranque del propio intérprete PHP: comienza al ejecutar la primera línea del script. La memoria de proceso sí incluye el intérprete.

## Próximos experimentos antes de concluir

1. Paridad de POST/JSON, códigos de error, middlewares y autenticación, con las mismas respuestas.
2. Un contrato público real y más grande para comprobar límites de cobertura y cómo escala el número de modelos.
3. Ejercicios de integración completos (instalación, credenciales, primer DTO, configuración del middleware), comparando código y decisiones necesarias con personas que no conocen ninguno de los dos proyectos.
4. Medición de operaciones repetidas con datos de distintos tamaños y condiciones de memoria/caché para confirmar o refutar esta primera ventaja.

El usuario no necesita instalar PHP: descargar el artefacto de GitHub Actions permite inspeccionar `REPORT.md`, `observations.csv`, cada medición individual, los logs, la configuración de dependencias y ambos SDKs generados.


## Auditoría adicional: reutilización, DTOs, tipo nativo y prevalidación

Esta revisión examina el código generado. No constituye todavía una prueba de casos negativos ejecutados con ambos SDKs y por tanto no acredita una nueva ventaja.

### OpenAPI Generator PHP: hay DTOs y reutilización, con límites

- Sí genera **modelos equivalentes a DTOs**: [Pet.php](https://github.com/OpenAPITools/openapi-generator/blob/master/samples/client/petstore/php/OpenAPIClient-php/lib/Model/Pet.php), 623 líneas en el ejemplo revisado. Proporciona getters/setters, PHPDoc, mapas de tipos/formats y métodos de validación de propiedades.
- [PetApi.php](https://github.com/OpenAPITools/openapi-generator/blob/master/samples/client/petstore/php/OpenAPIClient-php/lib/Api/PetApi.php) consta de 3559 líneas en el ejemplo. Cada operación expande varias variantes: addPet, addPetWithHttpInfo, addPetAsync, addPetAsyncWithHttpInfo y addPetRequest. Las variantes aportan funcionalidad, pero multiplican el código generado.
- **Sí reutiliza** [ObjectSerializer.php](https://github.com/OpenAPITools/openapi-generator/blob/master/samples/client/petstore/php/OpenAPIClient-php/lib/ObjectSerializer.php) y otros helpers (Configuration, HeaderSelector). Decir que no reutiliza nada sería inexacto.
- Pet::setName acepta un parámetro sin tipo PHP nativo y comprueba null, pero no que sea un string. PetApi::addPet admite un parámetro sin tipo nativo y verifica presencia, no instancia de Pet. Existen otros validadores de campo, pero no son equivalentes a control estricto de tipo en la frontera de llamada.

### Nuestro SDK: ventajas y defectos identificados

- Genera propiedades con tipos PHP nativos, enums, colecciones TypedArray y firmas tipadas de endpoint. Eso favorece la ayuda del IDE y la detección temprana de errores de tipos.
- Reutiliza clases de soporte para HTTP e hidratación. **Todavía repite** la construcción de rutas, query, serialización y comparación de status por operación, y duplica información que aparece en OperationCatalog. Conviene extraer un ejecutor compartido sin perder métodos públicos tipados.
- **Error de validación pendiente:** un DTO saliente puede omitir un campo obligatorio sin inicializar, porque jsonSerialize usa get_object_vars y solo incluye propiedades inicializadas. El control REQUIRED_FIELDS se aplica al hidratar respuestas, no antes de enviar.
- Faltan validaciones sistemáticas de rango, longitud, patrón, formatos, aliases y composición de esquemas cuando lo exija la especificación. Un tipo string nativo no equivale a un validador completo de OpenAPI.

### Pruebas comparativas que faltan

1. Contar líneas y ramas por operación distinguiendo helpers compartidos de duplicación.
2. Comprobar autocompletado y descubribilidad de modelos, tipos y colecciones.
3. Intentar enviar datos inválidos: tipo escalar, DTO de clase incorrecta, requerido ausente, enum inválido, colección de elemento incorrecto y restricciones explícitas del esquema.
4. Registrar si cada caso falla antes de invocar el transporte, falla al serializar o se transmite silenciosamente.
5. Repetir los benchmarks de CPU/memoria una vez añadida la validación necesaria: **omitir verificaciones no debe interpretarse como mejor rendimiento**.

No se ha ejecutado esta nueva batería de tests; estos son hallazgos de inspección fuente y criterios de aceptación.
