# Revisión de reutilización y simplicidad — 2026-11-08

La revisión cubre la preparación HTTP del generador v0.13.0/runtime v0.9.0:
query strings, parámetros POST, URL-encoded forms y payloads DTO. El criterio
expreso del usuario es reutilizar código y herencia y no reinventar capacidades
existentes. La [guía de Gestycontrol](https://gestycontrol.com/servicios/inteligencia-artificial/)
aporta el marco de revisión, arquitectura y responsabilidad del resultado;
la regla técnica de reutilización procede de la instrucción del usuario.

## Hallazgos y cambios

| Zona | Hallazgo comprobado | Resultado en generador v0.14.0 / runtime v0.10.1 |
| --- | --- | --- |
| Client generado | Cada SDK copiaba el mapeo operationFields y el ejecutor executeArguments, aunque no dependen de la API. | Ambos viven una vez en ApiClient del runtime y se heredan. |
| Configuration generada | Repetía clientes, headers, credenciales, transporte, middleware y overrides en cada SDK. | Hereda los campos de Configuration del runtime; solo declara el baseUrl específico. |
| Construcción de query | El adaptador ensamblaba manualmente pares y claves repetidas. | Guzzle PSR-7 Query::build ensambla los parámetros; PHP aplica el percent-encoding nativo. |
| Formularios | Convertía el query terminado mediante sustituciones de strings para cambiar el encoding. | Selecciona directamente RFC1738 mediante urlencode y reutiliza el mismo constructor. |
| Payloads DTO | Los modelos nombrados y body-only ya reutilizaban DTO y su validación común. | Conservan esa herencia; los DTOs mixtos declaran los campos y metadatos propios de query/header/body. |
| Transporte | Psr18Transport ya creaba mensajes Guzzle PSR-7 y enviaba mediante ClientInterface PSR-18. | Mantiene la inyección y compatibilidad de transportes/middleware. No hay otro cliente de red propio. |

El SDK Accounts emitido pasa de **134 a 40 líneas en Client** y de **24 a 10 en
Configuration**: **158 → 50**, una reducción de 108 líneas (aproximadamente 68%)
en ese bloque generado. La ejecución y los campos comunes se mantienen una vez
en el runtime, en lugar de copiarse por cada SDK.

## Qué debe seguir haciendo el adaptador

Guzzle query/form_params emplea http_build_query para los arrays PHP. Por ejemplo:

```text
http_build_query(['ids' => [1, 2], 'enabled' => false])
ids%5B0%5D=1&ids%5B1%5D=2&enabled=0
```

El contrato soportado puede exigir `ids=1&ids=2&enabled=false`, CSV, delimitadores
o deepObject. Query::build admite las claves repetidas. La capa de adaptación
conserva la elección por esquema, los booleanos, la omisión de null, las comas
que pertenecen a los datos y las validaciones de formas no definidas. Sustituir
todo por form_params o query sin esa adaptación cambiaría los bytes del contrato.
No se añade una librería de plantillas URI, fábrica ni capa de transporte nueva.

La salida DTO de petición aplica JSON names, readOnly/writeOnly, required,
constraints y validación tras mutaciones. Guzzle recibe los bytes ya preparados;
no interpreta schemas OpenAPI. Los DTOs declarativos siguen siendo necesarios
para esa API tipada y heredan el comportamiento común.

## Compatibilidad y límites

- Se mantienen los nombres públicos GetParams/PostParams, los argumentos de los
  métodos, los cuerpos JSON/urlencoded y la inyección PSR-18/TransportInterface.
- La conexión estática permanece por SDK para conservar el aislamiento entre
  configuraciones. Los recursos comparten explícitamente transporte y credenciales.
- Los SDKs anteriores pueden conservar sus overrides del ejecutor. Los nuevos
  requieren runtime estable ^0.10.1. La inferencia de tipos se comprobó también
  con el PHPStan mínimo, sin supresiones ni movimiento de tags existentes.
- No se fusionan modelos/enums de dominios distintos por tener la misma forma.
  Las composiciones y sus validaciones siguen en su alcance declarado.
- Multipart, archivos, estilos aún no implementados y demás hoja de ruta siguen
  pendientes; esta revisión simplifica infraestructura existente.

## Validación

Se conserva la suite de contratos y de requests exactos: GET/POST, query +
headers + JSON/form, nombres coincidentes/body, CSV/multi/delimitados/deepObject,
campos direccionales, autenticación, mutaciones e independencia de conexiones.
Se añade una comprobación de que el ejecutor y las opciones se heredan de las
clases del runtime, y el smoke publicado ejecuta esa herencia en una instalación
independiente. Los SDKs se instalan y analizan con PHPStan al nivel máximo.

Las reglas de reutilización quedan en los AGENTS.md de ambos repositorios para
que no se vuelva a emitir lógica genérica ni a añadir abstracciones sin necesidad.

Resultado de la entrega: generador **342 pruebas / 2.540 aserciones**; runtime
**80 pruebas / 207 aserciones**, PHPStan máximo y matriz de dependencias mínimas.
**14 SDKs** instalados pasan PHPStan máximo; Casafari conserva **46/46** operaciones
aceptadas. Smokes independientes y HTTP real cloud-local conservan URL, headers,
body, separación de parámetros y rechazo de mutaciones inválidas.

## Aplicación a multipart — v0.15.0 / runtime v0.11.1

El runtime adapta campos DTO a descriptores de `GuzzleHttp\Psr7\MultipartStream`; Guzzle resuelve MIME y boundaries. FormSupport comparte la comprobación de objetos planos entre urlencoded y multipart. Los archivos usan strings nativos para bytes y metadatos declarativos de nombre/tipo; no se introduce una clase de subida. Se conserva el contrato público de transporte y se declara el coste real: cuerpo completo en memoria, sin prometer streaming. Casos de encoding sin una adaptación fiel se diagnostican. [Uso y límites](FORMS.md).

En v0.16.0, Swagger multipart se normaliza al mismo contrato y emite exactamente el mismo SDK que su fixture OpenAPI equivalente. No se modifica el runtime ^0.11.1 ni se duplica el smoke de instalación: solo se adapta type:file y se comprueban consumes/collectionFormat en el contexto efectivo de cada operación.
