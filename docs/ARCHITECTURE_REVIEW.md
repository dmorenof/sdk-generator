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

En v0.17.0/runtime v0.12.0 se amplía el campo binary a `string|Psr\Http\Message\UploadedFileInterface` y se reutiliza Guzzle UploadedFile, sin una clase/fábrica propia. La adaptación comprueba tipo MIME explícito, metadatos y stream readable/seekable; Guzzle conserva MIME/boundaries y rebobinado. Se mantiene el transporte string compatible: entrada desde stream no implica envío HTTP en streaming ni memoria acotada. La ruta JSON rechaza objetos upload para evitar `{}` silenciosos.

En v0.18.0/runtime v0.13.0 se conserva MultipartStream hasta el cliente PSR-18 ampliando por contravarianza el método send del adaptador existente. La interfaz antigua no cambia: sus implementaciones siguen recibiendo strings. No hay interfaz/transporte/stream wrapper nuevo ni código streaming en los SDKs. El adaptador rebobina después de middleware y cada envío. Dos subidas reales de 32 MiB con Guzzle/cURL pasan bajo límite PHP de 16 MiB (pico PHP 4 MiB), con archivo íntegro verificado por parser independiente. Este dato no es una cota de RSS ni de clientes/middlewares arbitrarios; respuestas y transportes antiguos conservan buffering.

En v0.19.0/runtime v0.14.0, arrays binarios OpenAPI se representan con listas PHP nativas y UploadedFileInterface. El serializador existente repite cada parte y reutiliza MIME/streams, sin colección de archivos propia. La selección se limita a modelos de body multipart, preserva tipos de colecciones solo JSON y conserva validación en herencia. Dos archivos de 16 MiB por request (32 MiB agregados) pasan dos envíos reales bajo límite PHP de 16 MiB con pico PHP de 4 MiB; parser independiente comprueba ambos archivos y metadatos.

En v0.20.0/runtime v0.15.0, las respuestas binarias reutilizan ResponseInterface y copyToStream de PSR/Guzzle. El adaptador existente incorpora sendResponse() y conserva send()/TransportInterface para consumidores antiguos. Solo JSON/errores convierten la respuesta PSR a HttpResponse string; éxitos binarios conservan stream, status y cabeceras. No se crea una clase de descarga, otra interfaz de transporte ni ejecución en cada SDK. La adaptación OpenAPI selecciona octet-stream solo para un esquema binary fiel y sin representación JSON preferente. La prueba real copia/hash 32 MiB bajo límite PHP 16 MiB con pico PHP 8 MiB; no promete red progresiva ni almacenamiento sin disco. [Contrato y límites](DOWNLOADS.md).

En v0.21.0, Swagger type:file con produces octet-stream se adapta al contrato binario existente. El helper de compatibilidad compartido sirve para auditoría y normalización; cada uso considera produces efectivo y referencias resueltas. La salida es idéntica al OpenAPI equivalente y utiliza runtime ^0.15.0 sin modificarlo. Se reutiliza el smoke de descarga instalado, sin transporte, DTO de archivo ni rama de ejecución nuevos. Swagger no declara MIME por estado: las combinaciones que exigirían esa semántica siguen rechazadas y documentadas. [Uso](DOWNLOADS.md#swagger-20-file-responses-v0210).

En v0.22.0/runtime v0.16.0, los distintos éxitos JSON de una operación usan un mapa status→tipo/nulabilidad en Operation y uniones nativas en el retorno PHP. ApiClient selecciona el descriptor por el estado real y llama al mismo JsonHydrator. No hay fábrica, wrapper, otro hidratador ni switch copiado en cada SDK. Los tipos inline usan nombres por estado; herencia/validación/serialización conservan el modelo existente. Un 204 sin cuerpo no permite aceptar por error un 200 vacío; los SDKs antiguos mantienen su fallback. Se verifican veinte SDKs independientes y 24 escenarios GET/POST reales. [Contrato y límites](RESPONSES.md).

En v0.23.0/runtime v0.17.1, los arrays simple path/header reutilizan el resolvedor y guard de listas, validación declarativa, extracción de DTOs y QuerySerializer existentes. rawurlencode codifica cada miembro path; los headers mantienen texto y rechazan delimitadores/controles ambiguos. No se añade otro serializador HTTP ni intérprete de URI templates. Las listas path usan PathParams para no ampliar executeArguments y romper overrides de SDKs anteriores. Los metadatos declaran formato por ubicación, y Guzzle/PSR sigue gestionando los mensajes. [Contrato y evidencia](SIMPLE_PARAMETERS.md).

En v0.24.0/runtime v0.18.0 se completan objetos planos form/query y simple/path-header reutilizando DTO/Map, validación/serialización direccional, extracción de campos y codificadores nativos ya existentes. Los tipos se indexan por ubicación para que un mismo wire name no mezcle modelos; los accesos/constructores antiguos de query se conservan. La expansión declarativa mantiene ambos explode, falsos/ceros y omisión de parámetros opcionales. Se rechazan colisiones y delimitadores ambiguos antes de HTTP. No se introduce un intérprete URI, hidratador ni fábrica adicionales. [Contrato y evidencia](OBJECT_PARAMETERS.md).

## Label/matrix: adaptación mínima — v0.25.0 / runtime v0.19.0

Se evaluó guzzlehttp/uri-template v2.0.1: distingue objetos y listas por las claves PHP, por lo que un objeto JSON con clave numérica consecutiva 0 se interpreta como lista. El contrato OpenAPI ya conoce el tipo; delegar perdería esa distinción. Se amplía el serializador existente con metadatos por parámetro, reutilizando extracción DTO/Map, escalares y codificación. No se añade dependencia ni motor URI genérico. Los ejemplos oficiales RFC6570 y targets HTTP reales verifican la adaptación. Guzzle conserva el transporte; su opción cURL PATH_AS_IS evita normalizar el segmento punto de un label vacío.

## Cookies escalares — v0.26.0 / runtime v0.20.0

Se reutilizan Params DTOs, QuerySerializer, seguridad y transporte Guzzle/PSR. CookieHeader concentra exclusivamente la unión/validación RFC6265 de cabeceras de petición para configuración, parámetros y autenticación. CookieJar/SetCookie resuelven almacenamiento y respuestas, no esa combinación ni la adaptación OpenAPI; no se añaden jar ni persistencia. La ejecución común elimina ramas repetidas al asignar ubicaciones y conserva las firmas. Los formatos de múltiples valores incompatibles con cookies se rechazan explícitamente; no se inventa una serialización. [Contrato y pruebas](COOKIES.md).

## Query reservada — v0.27.0 / runtime v0.21.0

Se amplía QuerySerializer con selección de codificación por valor y una pequeña tabla de caracteres reservados sobre rawurlencode; Guzzle Query sigue ensamblando pares sin recodificar. Los nombres mantienen el encoder normal y la seguridad elimina la expansión reservada de sus credenciales. No se añade motor URI ni transporte. Se rechazan delimitadores estructurales según las responsabilidades de la aplicación descritas en OpenAPI, en lugar de inventar un escape. [Contrato y ejemplo oficial](RESERVED_QUERY.md).

## Vacíos sin nueva infraestructura — v0.28.0

El runtime ya distinguía name=, null/ausente, false/0 y colecciones vacías. Se elimina el diagnóstico falso de allowEmptyValue en ubicaciones soportadas y se documenta que el esquema gobierna tipos y constraints. No se añade metadata, wrapper, unión ni serializer; runtime ^0.21.0 sin cambios. SDKs OpenAPI/Swagger instalados prueban query/body/form con HTTP real. [Política](EMPTY_VALUES.md).
