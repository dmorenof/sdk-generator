# Pendientes — compatibilidad Swagger / OpenAPI del SDK Generator

> **Estado:** auditoría y hoja de ruta; NO equivale a funcionalidades implementadas.
>
> **Auditoría inicial:** 2026-11-05 · **Línea base estable:** `dmorenof/sdk-generator` **v0.29.1** y `dmorenof/sdk-runtime` **v0.22.1** (desarrollo en la rama existente `release/0.2.0`; ver historial).
>
> **Criterio de actualización:** `[x]` significa implementado y verificado con pruebas y CI en la rama indicada. Registrar el PR y distinguir **en rama / integrado / publicado**; anotar la versión cuando se publique. Las tareas parcialmente cubiertas conservan `[ ]` con el detalle de lo hecho y lo que falta. Volver a contrastar cada apartado con la especificación oficial correspondiente.
>
> **Principio arquitectónico:** conservar clases API ligeras, namespaces derivados de rutas, DTOs reutilizables y un runtime HTTP común. Las nuevas capacidades de OpenAPI deben implementarse principalmente en el normalizador, contrato intermedio y runtime, sin introducir lógica HTTP duplicada en los métodos generados.

## Leyenda

- **P0:** bloqueante para interpretar con fidelidad APIs habituales; abordar primero.
- **P1:** compatibilidad amplia y requisitos importantes de producción.
- **P2:** funciones avanzadas, versiones recientes o compatibilidad heredada.
- **P3:** utilidades adicionales y metadatos de menor impacto.
- **Parcial:** existe una implementación limitada; la casilla sigue abierta para completar el contrato.
- **Validar:** la implementación puede existir en alguna ruta, pero falta evidencia integral.

## 0. Línea base ya disponible

Estas capacidades están comprobadas para el **subconjunto soportado**; no implican conformidad completa con una versión de OpenAPI.

- [x] Leer JSON y YAML con subconjuntos de Swagger 2.0, OpenAPI 3.0.x y OpenAPI 3.1.x.
- [x] Generar SDK instalable con Composer, fachadas organizadas por rutas, recursos por instancia y una dependencia de `sdk-runtime`.
- [x] DTOs tipados, enums de `string`/`integer`, TypedArrays, modelos locales y ciertas composiciones `allOf`/`anyOf`/`oneOf`.
- [x] Parámetros básicos `path`, `query` y `header`; cuerpo JSON; respuestas JSON tipadas.
- [x] HTTP Basic, Bearer y API key en `query` o `header`, con herencia de requisitos y alternativas AND/OR.
- [x] DTO único como clase base y namespaces `DTOs`/`Requests`/`Responses` para tipos propios de cada operación **publicado en v0.2.0**.
- [x] Formateo PSR-12 automático del código generado **publicado en v0.2.0**.
- [x] Casafari: generación de 46 operaciones y comprobaciones de Composer, PSR-12 y sintaxis **sin llamadas autenticadas a Casafari**.
- [x] FakeRESTApi: generación del contrato original completo y peticiones reales GET individual, GET de lista y POST; no se han probado todas sus operaciones.
- [x] CI del generador: 145 pruebas / 1.828 aserciones (estado comprobado al elaborar la auditoría).
- [x] **Entrega coordinada:** `sdk-runtime` **v0.4.0** y `sdk-generator` **v0.3.0**, con herencia compatible, discriminadores locales y dependencia estable `^0.4.0`. Cierre: [Runtime #6](https://github.com/dmorenof/sdk-runtime/pull/6) y [Generator #19](https://github.com/dmorenof/sdk-generator/pull/19).
- [ ] **Migración de CasafariSDK:** probar operaciones autenticadas reales, revisar compatibilidad de uso/middleware, comparar datos y preparar rollback antes del cambio en producción.

### Versiones de entrada

| Versión | Estado | Detalle |
| --- | --- | --- |
| Swagger 2.0 | Parcial | Reconocida; faltan componentes y parámetros propios de 2.0. |
| OpenAPI 3.0.x | Parcial | Reconocida; no todas sus estructuras HTTP están implementadas. |
| OpenAPI 3.1.x | Parcial | Reconocida; falta buena parte de JSON Schema 2020-12. |
| OpenAPI 3.2.x | Pendiente | Requiere interpretar sus novedades, no solo ampliar la expresión regular. |

---

## Revisión transversal de simplicidad — v0.14.0 / runtime v0.10.1

- [x] Aplicar la regla expresa de reutilización/herencia a la preparación HTTP; conservarla en AGENTS.md de ambos repositorios junto con la guía de Gestycontrol.
- [x] Eliminar la copia por SDK del ejecutor/mapeo de argumentos y de las opciones comunes de configuración: ahora se heredan del runtime.
- [x] Reutilizar Query::build de Guzzle PSR-7 y percent-encoding nativo para query/forms, preservando la adaptación específica de OpenAPI y las firmas/DTOs existentes.
- **Medición:** Accounts Client+Configuration pasa de 158 a 50 líneas generadas (-108; aproximadamente 68%). La conexión estática conserva su aislamiento por SDK.
- **Límite:** esta revisión simplifica comportamiento existente; multipart, formatos pendientes y conformidad general conservan sus tareas abiertas. Los DTOs mantienen validación del contrato; los nombres/types de dominio no se fusionan por mera igualdad estructural.
- **Validación:** generador 342 pruebas / 2.540 aserciones; runtime 80 / 207 y dependencias mínimas; catorce SDKs con PHPStan max, Casafari 46/46, instalación independiente y HTTP real cloud-local.
- **Informe:** [revisión y evidencia](docs/ARCHITECTURE_REVIEW.md).

## 1. [P0] Fidelidad del contrato intermedio y diagnóstico

**Objetivo:** el normalizador nunca debe perder silenciosamente información que cambia la petición o la respuesta HTTP.

**Estado del paquete 01: parcial — 3 tareas resueltas y 6 parcialmente cubiertas.**

- **Publicado:** [sdk-generator v0.4.0](https://github.com/dmorenof/sdk-generator/releases/tag/v0.4.0) y [sdk-runtime v0.5.0](https://github.com/dmorenof/sdk-runtime/releases/tag/v0.5.0), integrados en `main`. El generador y sus SDKs usan dependencia estable `^0.5.0`.
- **Flujo acordado:** cada avance terminado y validado se integra y publica. Un PR o una rama no constituyen una entrega final; los pendientes futuros se desarrollan y publican por avances terminados, sin esperar a cerrar toda la hoja de ruta.
- **Evidencia:** 216 pruebas / 2.061 aserciones; cinco SDKs generados pasan PHPStan max; runtime: 54 pruebas / 108 aserciones. [CI del generador en verde](https://github.com/dmorenof/sdk-generator/actions/runs/42059103852) y [CI del runtime en verde](https://github.com/dmorenof/sdk-runtime/actions/runs/42051180373).
- **Alcance:** v0.4.0 publica los avances terminados del paquete 01 y el enrutamiento literal. Los paquetes P0 02 (referencias), 03 (serialización HTTP) y 04 (cuerpos/respuestas) siguen pendientes de implementación general.

### Lista de trabajo del paquete 01

- [ ] **Parcial — Conservar toda la serialización de parámetros.** Hecho: conservar `style`, `explode`, `allowReserved`, `allowEmptyValue`, `collectionFormat`, valores `false` explícitos y representaciones de parámetros con `content` JSON. Falta: normalización general de `content` fuera de JSON y referencias. La ejecución general corresponde al paquete 03: v0.10.0 ejecuta arrays query primitivos form/multi/CSV y delimitados por espacio, tabulador o pipe; otros estilos y ubicaciones siguen pendientes.
- [ ] **Parcial — Conservar servidores, media types y metadatos de request/response.** Hecho: conservar servidores efectivos con precedencia raíz/ruta/operación y orden de preferencia; media types, encoding y anotaciones de las representaciones normalizables. Las alternativas no soportadas quedan explícitas en `unparsedSchema`. Falta: normalización general de representaciones sin JSON, variantes incompatibles y particularidades de dialecto. El enrutamiento de servidores literales HTTP(S) está implementado con sustituciones por entorno en el runtime compartido; relativos, plantillas y variables siguen pendientes.
- [ ] **Parcial — Conservar encabezados, cookies y respuestas diferenciadas.** Hecho: conservar los objetos originales de headers/links; mantener códigos y esquemas de respuesta ya soportados. Falta: normalización tipada general de headers/cookies y todas las formas de respuesta. Conservar headers en el contrato todavía no los expone desde el runtime.
- [ ] **Parcial — Separar ejecución/validación de anotaciones.** Hecho: conservar anotaciones de documento, operación, parámetros y media types; distinguir las opciones HTTP auditadas y tratar los ejemplos como datos. Falta: completar esa clasificación en todos los componentes anidados y dialectos.
- [ ] **Parcial — Auditar opciones ignoradas.** Hecho: detectar opciones conocidas de documento, ruta, operación y parámetros; estilos no ejecutados, overrides de servidor, headers/links, encoding y keywords no soportadas. Respetar sobrescrituras para evitar falsos errores. Hecho adicional en v0.4.1: auditar declaraciones reutilizables de parámetros, requestBodies, respuestas, headers, links y Path Items; cabeceras anidadas en encoding, callbacks y esquemas/requisitos de seguridad. Falta: validación estructural por dialecto y cobertura exhaustiva de la semántica HTTP restante.
- [x] **Publicado en v0.4.0 — Modo de compatibilidad por documento.** `--inspect` informa `supported` / `partial` / `unsupported`, motivo y JSON Pointer para el subconjunto auditado. La clasificación no equivale a conformidad completa con OpenAPI.
- [x] **Publicado en v0.4.0 — Modo estricto.** `--strict` rechaza los errores detectados antes de crear archivos; `fromJson()` / `fromYaml()` admiten `strict: true`. El analizador de aceptación usa `compatibility_failed` cuando emitir PHP ocultaría una opción HTTP no ejecutada. La cobertura depende de la auditoría, que sigue parcial en la tarea anterior.
- [x] **Publicado en v0.4.0 — Inspección/dry-run sin generar archivos.** `--inspect` / `--dry-run` funcionan con JSON y YAML, conservan el hash del origen y no escriben el SDK ni consultan referencias externas.
- [ ] **Parcial — Diagnósticos completos.** Hecho: archivo, operación cuando está identificada, JSON Pointer escapado, código estable y cadena local de referencias para los casos auditados. Hecho adicional en v0.4.1: localizar opciones no ejecutadas en componentes, encoding y seguridad con punteros escapados y cadenas locales acotadas. Falta: localizar con precisión todos los errores antiguos del parser/emisor; algunos aún se informan en la raíz del documento.

### Avance de auditoría anidada — v0.4.1

- [x] Auditar declaraciones reutilizables OpenAPI y parámetros/respuestas reutilizables de Swagger, incluidos schemas de cabeceras. Las declaraciones se revisan aunque no se usen; las referencias a esos objetos todavía no se ejecutan.
- [x] Revisar cabeceras de `encoding`, contenido de headers, links y operaciones de callbacks. Los callbacks siguen sin generar contratos entrantes.
- [x] Localizar tipos de seguridad no ejecutados, scopes y requisitos desconocidos; conservar JSON Pointer escapado, operación cuando existe y cadenas de referencias, incluso con ciclos.
- [x] Mantener ejemplos y valores/expresiones de links como datos; no consultar referencias externas durante la inspección.
- **Validación:** 13 regresiones nuevas; suite cloud PHP 8.4: 229 pruebas / 2.097 aserciones; cinco SDKs sin errores PHPStan max. CI multiversión de la entrega. Smoke de instalación comprueba el diagnóstico de un parámetro reutilizable desde el generador publicado.
- **Límites:** no cierra las seis tareas parciales del paquete 01, la validación general de documentos ni el paquete 02 de referencias. Runtime estable `^0.5.0`, sin cambios en este avance.

### Qué cambia para quien usa el generador

| Caso | Antes de PR #20 | En esta rama | Trabajo restante |
| --- | --- | --- | --- |
| Parámetro con `allowReserved: true` | La opción podía desaparecer del contrato sin aviso. | Se conserva y se diagnostica; el modo estricto bloquea la generación. | Implementar su serialización HTTP. |
| Ruta con servidor distinto del raíz | El cliente usaba el servidor raíz y podía pasar la aceptación. | Se conserva y ejecuta el servidor efectivo con precedencia operación → ruta → raíz; configurable por entorno. | Relativos, variables y selección dinámica entre servidores. |
| Revisar una especificación sin generar un SDK | Faltaba este modo de inspección en el generador. | `--inspect` devuelve un informe de compatibilidad sin crear archivos. | Ampliar la cobertura de la auditoría. |
| Analizar por separado una operación polimórfica | La selección de esquemas podía eliminar subclases necesarias. | Se conserva el cierre de discriminadores explícitos/implícitos y los metadatos de ruta. | Resolución general de referencias del paquete 02. |

**Corrección urgente publicada en generador v0.4.0 / runtime v0.5.0:** las 46 operaciones de Casafari pasan ahora la aceptación estricta. Siete pruebas HTTP simuladas ejecutan los métodos generados afectados y comprueban la URL exacta de `platform.casafari.com`, parámetros y autenticación. Runtime: 54 pruebas / 108 aserciones y PHPStan max; [CI del runtime en verde](https://github.com/dmorenof/sdk-runtime/actions/runs/42051180373). No se han realizado llamadas reales/autenticadas a Casafari.


### Corrección urgente de servidores — publicada en v0.4.0 / v0.5.0

- [x] Ejecutar el servidor literal HTTP(S) efectivo con precedencia operación → ruta → raíz, conservando prefijos de URL y parámetros codificados.
- [x] Permitir sustituciones por entorno con `serverOverrides` por conexión y `Configuration::$serverOverrides`, compartidas por sus recursos y aisladas entre clientes.
- [x] Comprobar los siete métodos reales de Casafari con HTTP simulado y aceptación estricta de las 46 operaciones. Código validado: generador `7aa5593` y runtime `09ce07f`.

Los servidores relativos, las plantillas/variables y la selección dinámica entre varios servidores permanecen pendientes en el apartado 7.

Detalle técnico y comandos: [inspección de contrato](docs/CONTRACT_INSPECTION.md). Las casillas resueltas están publicadas en **v0.4.0 / v0.5.0**. P0 y el paquete 01 conservan sus tareas futuras abiertas.

**Aceptación:** ninguna opción que cambie URL, método, headers, body, autenticación o interpretación de respuesta puede desaparecer del contrato intermedio sin una advertencia o error explícito.

## 2. [P0] Resolución general de referencias

- [ ] **Parcial — JSON Pointer completo.** v0.5.0 decodifica escapes URI y `~0`/`~1` en las referencias HTTP locales soportadas. Faltan ubicaciones arbitrarias, URIs relativas y la aplicación general al resto de referencias.
- [x] **v0.5.0 — Referencias locales con nombre a `components/parameters`, `responses` y `requestBodies`**, con alias, parámetros en Path Item/operación y precedencia tras resolver `in`/`name`.
- [x] **v0.5.0 — Referencias locales Swagger `#/parameters/...` y `#/responses/...`**, incluidos parámetros de body.
- [x] **v0.6.0 — Referencias locales a `components/headers`**, con alias y anotaciones de Reference Objects, en metadatos de respuestas y encoding. Su exposición/validación tipada en el runtime sigue pendiente en el paquete 04.
- [ ] Referencias a `components/examples`, `links` y `callbacks` cuando proceda.
- [x] **v0.8.0 — Referencias locales OpenAPI a `components/securitySchemes`**, con alias, diagnóstico de origen y conservación de los nombres públicos de credenciales. Importaciones externas de seguridad y flujos OAuth siguen pendientes.
- [x] **v0.6.0 — Referencias locales a Path Items completos:** `#/paths/...` en Swagger/OpenAPI y `components/pathItems` en OAS 3.1; alias, operaciones, parámetros y servidores efectivos. Combinar hermanos independientes y rechazar solapamientos indefinidos conservando el origen de cada campo. Ubicaciones arbitrarias y documentos externos quedan en sus tareas generales.
- [ ] **Parcial — Archivos JSON/YAML externos.** v0.7.0 carga explícitamente archivos locales relativos a cada documento, con raíz configurable, caché y límites. Incluye schemas y fragmentos HTTP soportados; faltan otras categorías/contextos, Path Items externos en Swagger/OAS 3.0 y alias de schemas con hermanos.
- [ ] Referencias HTTP(S) externas con caché, timeouts, límites de tamaño y restricciones de acceso.
- [ ] **Parcial — Ciclos.** v0.7.0 conserva referencias de modelos recursivos entre archivos y rechaza ciclos puros de alias; quedan grafos/dialectos dinámicos y otras categorías.
- [ ] **Parcial — Espacios de nombres.** v0.7.0 rebasa referencias locales al documento de origen, conserva nombres públicos de modelos raíz y distingue importaciones homónimas. Quedan ámbitos de IDs/anchors, discriminadores implícitos entre archivos y servidores/seguridad de otros documentos API completos.
- [ ] Soporte JSON Schema: `$id`, `$anchor`, `$dynamicAnchor`, `$dynamicRef` y `$defs`.
- [ ] `jsonSchemaDialect`, `$schema` y dialectos diferentes según el documento/recurso.
- [ ] **Parcial — Reglas de hermanos de `$ref`.** v0.5.0 ignora hermanos de Reference Objects HTTP en OAS 3.0/Swagger y aplica description/summary en OAS 3.1 sin cambiar required/schema del destino. Faltan reglas generales de Schema Objects y otros dialectos.
- [ ] **Parcial — Referencias inexistentes con errores accionables.** v0.5.0 añade puntero de uso/declaración y cadena de alias para los objetos HTTP locales soportados; referencias externas, anchors y otras categorías siguen pendientes.

### Entrega local de referencias HTTP — v0.5.0

- [x] Resolver compartido de normalización/inspección con referencias locales con nombre de las categorías indicadas, sin consultar la red ni expandir schemas o ejemplos.
- [x] Rechazar ciclos de alias y limitar cadenas a 128 saltos; conservar la identidad de esquemas recursivos ya soportados.
- [x] Equivalencia del contrato y código emitido frente a la versión inline; entrada JSON/YAML, overrides y anotaciones por dialecto.
- [x] Mantener las dependencias de modelos al aislar operaciones con requestBody/response referenciados; no interpretar payloads de ejemplos/links como referencias de schemas.
- [x] SDK Accounts con referencias ejecutado por HTTP simulado y añadido al análisis PHPStan max y al smoke de instalación de la versión publicada.
- **Validación:** cloud PHP 8.4: 245 pruebas / 2.149 aserciones; seis SDKs sin errores PHPStan max; Casafari conserva 46/46 operaciones aceptadas. La publicación comprueba instalación independiente y ejecución HTTP simulada del SDK con referencias.
- **Límite:** este avance local no cierra el paquete 02. Las referencias a documentos divididos y otras categorías permanecen abiertas; headers y Path Items locales se completan en v0.6.0, en el apartado siguiente. Runtime estable `^0.5.0`.

### Entrega local de Path Items y cabeceras — v0.6.0

- [x] Generar operaciones heredadas de Path Items referenciados sin perder parámetros, servidores ni detección de IDs duplicados.
- [x] Distinguir los hermanos de Path Item de los Reference Objects: combinar campos independientes y diagnosticar solapamientos en el origen real.
- [x] Conservar punteros/cadenas por campo en inspección y aceptación aislada. Contar operaciones resueltas e informar `unresolvedPathCount` sin aceptar rutas inválidas como SDK vacío.
- [x] Resolver alias locales de cabeceras de respuesta y encoding como metadatos; conservar diagnósticos de exposición/ejecución aún no implementada.
- [x] Acotar grafos recursivos de metadatos HTTP, sin expandir recursivamente schemas ni interpretar ejemplos como referencias.
- **Validación:** cloud PHP 8.4: 264 pruebas / 2.218 aserciones; siete SDKs con PHPStan max. Smoke de publicación: SDKs con rutas referenciadas, HTTP simulado con servidores heredados y metadatos de headers resueltos.
- **Límites:** runtime estable `^0.5.0`; referencias externas/arbitrarias, otros Reference Objects, encabezados tipados y encoding HTTP siguen pendientes.

### Entrega de archivos locales JSON/YAML — v0.7.0

- [x] Carga explícita mediante `--resolve-local-references`, `--reference-root=...` y APIs `fromFile`/`inspectFile`/`analyzeFile`; APIs de texto conservan su comportamiento en memoria.
- [x] Resolver archivos relativos a cada origen y fragments JSON Pointer, incluidos nombres URI-encoded; caché por ruta real y huellas del raíz, dependencias y bundle.
- [x] Preservar identidades públicas de schemas raíz, colapsar alias puros y mantener modelos recursivos entre archivos sin expansión infinita; distinguir nombres importados en colisión.
- [x] Mantener archivo/puntero/cadena de referencias en los diagnósticos auditados. Comprobar el hash raíz fijado antes de leer dependencias.
- [x] Restringir rutas/symlinks a la raíz elegida; acotar archivos, bytes, nodos, profundidad y alias; no abrir HTTP(S) ni URI streams.
- [x] Equivalencia del SDK Accounts de ocho archivos frente al consolidado, JSON/YAML mezclados, ciclos, mappings explícitos Pet, HTTP simulado e instalación independiente.
- **Validación:** cloud PHP 8.4: 291 pruebas / 2.310 aserciones; ocho SDKs con PHPStan max; Casafari conserva aceptación estricta 46/46. Smoke de publicación instala el generador exacto, genera el SDK multifichero y comprueba ejecución/huellas de entrada.
- **Límites:** HTTP(S), IDs/anchors/dialects, otras categorías, alias de schemas con hermanos, mappings implícitos entre archivos y namespaces HTTP de otros documentos completos siguen pendientes. Runtime estable `^0.5.0`.
- Uso y límites exactos: [referencias de archivos locales](docs/FILE_REFERENCES.md).

### Entrega de referencias locales de seguridad — v0.8.0

- [x] Resolver alias locales de esquemas de seguridad OAS 3.0/3.1 con las reglas compartidas de Reference Objects y cadenas acotadas.
- [x] Conservar el nombre del alias para credenciales y requisitos; mantener herencia raíz/operación, override vacío y semántica AND/OR.
- [x] Comprobar HTTP simulado: Bearer + API key, alternativa query, Basic, ausencia de credenciales y rechazo de credenciales dadas solo al nombre de la base.
- [x] Localizar el tipo/ubicación del destino no soportado; impedir que hermanos del alias habiliten OAuth u otros tipos pendientes.
- **Validación:** cloud PHP 8.4: 299 pruebas / 2.342 aserciones; nueve SDKs con PHPStan max; SDK de alias instalado y ejecutado independientemente. El smoke publicado incluye esa comprobación.
- **Límites:** únicamente referencias locales OpenAPI y tipos de autenticación ya soportados; referencias externas de seguridad, Swagger 2.0 `$ref` y OAuth/scopes siguen pendientes. API key cookie se incorpora posteriormente en v0.26.0. Runtime original `^0.5.0`.

**Aceptación:** un OpenAPI dividido en varios archivos debe generar un SDK equivalente al de su versión consolidada; casos cíclicos válidos deben resolverse de forma segura.

## 3. [P0] Serialización de parámetros HTTP

**Situación actual:** generador v0.29.0 y runtime v0.22.1 ejecutan escalares, arrays query primitivos, objetos query planos deepObject y cuerpos JSON/urlencoded/multipart acotados de OpenAPI y Swagger. v0.18.0 añade streaming multipart por PSR-18; v0.20.0/v0.21.0 incorporan descargas y v0.22.0 selecciona respuestas JSON por estado, manteniendo GetParams/PostParams independientes del body. El paquete sigue parcial para otros objetos, ubicaciones, estilos y escenarios de streaming.

### Revisión acordada de getParameters y postParameters

- [ ] **Parcial — Revisión de `getParameters`/`postParameters`.** v0.13.0 revisa los DTOs actuales `GetParams`/`PostParams`, firmas de fachadas/recursos y query + body JSON/urlencoded OpenAPI/Swagger. No existen símbolos literales getParameters/postParameters en el emisor actual. v0.15.0 añade multipart OpenAPI y v0.16.0 Swagger con bytes binarios; v0.18.0 preserva streams por PSR-18. Quedan encoding avanzado y otros escenarios de streaming.
- [ ] **Parcial — Separar método y ubicación.** v0.13.0 comprueba query en GET/POST junto con headers y body JSON/urlencoded OpenAPI/Swagger, y distingue un query llamado body del payload real. v0.15.0/v0.16.0 verifican también el subconjunto multipart OpenAPI/Swagger; quedan las combinaciones de sus formatos pendientes.
- [ ] **Parcial — Pruebas GET/POST HTTP y forms.** v0.12.0 cubre GET query, POST query + headers + JSON/urlencoded, nombres coincidentes/body, arrays, ausente/null/vacío y mutaciones; URL, Content-Type y bytes exactos, PSR-18, HTTP real cloud-local e instalación independiente. v0.15.0/v0.16.0 añaden esas comprobaciones a multipart OpenAPI/Swagger; las extensiones de multipart siguen abiertas.

### OpenAPI 3.x

- [ ] **Parcial — Arrays y objetos por ubicación.** Arrays query publicados en v0.9.0, arrays simples path/header en v0.23.0 y objetos planos form/query y simple/path-header en v0.24.0. Quedan cookies estructuradas/JSON y esquemas no representables por estos estilos.
- [x] **v0.9.0 — Arrays query primitivos con `style: form` y `explode: true/false`**, incluidos defaults OpenAPI.
- [x] **v0.24.0 — style: form para objetos query planos**, cerrado o mapa escalar tipado, con defaults y explode true/false. Cookies escalares form se incorporan en v0.26.0; las estructuradas/content siguen pendientes en su apartado.
- [x] **v0.24.0 — style: simple path/header:** escalares, listas y objetos planos soportados, con defaults y explode true/false. Esquemas anidados/composiciones no cubiertos conservan diagnóstico explícito.
- [x] **v0.11.0 — `deepObject` query plano** con explode true, propiedades escalares no null y additionalProperties false o schema escalar tipado; referencias locales e inline, DTOs/Maps y mutaciones.
- [ ] Ampliar objetos query abiertos sin schema escalar de valores y composiciones compatibles. La serialización anidada de deepObject no está definida por OpenAPI y no se asume.
- [x] **v0.10.0 — `style: spaceDelimited` y `pipeDelimited` para arrays query primitivos**, con explode ausente/false y rechazo de true (no definido).
- [ ] Estilos delimitados para objetos.
- [x] `style: label` y `matrix`: escalares, listas primitivas y objetos planos tipados OpenAPI 3.0/3.1, ambos explode; v0.25.0 / runtime v0.19.0. [Contrato y límites](docs/PATH_STYLES.md).
- [x] **v0.27.0 — `allowReserved` en parámetros query OpenAPI:** escalares, listas y objetos planos ya soportados; conservar reservados legales y triples `%XX`, nombres/credenciales con codificación normal y rechazo explícito de delimitadores ambiguos. [Reglas de pre-encoding y límites](docs/RESERVED_QUERY.md).
- [x] **v0.28.0 — `allowEmptyValue`:** query OpenAPI/Swagger y formData Swagger soportados con política de esquema autoritativo; `""` se conserva como `name=`, ausente/null opcionales se omiten y required/constraints/tipos permanecen activos. [Reglas y límites](docs/EMPTY_VALUES.md).
- [ ] **Parcial — v0.29.0:** content JSON en path/query/header y text/plain en path/query/header/cookie, con modelos y colecciones tipados. Una representación, sin schema/style coexistentes. Quedan otros MIME, raíces nullable/composiciones generales y JSON cookie. [Contrato](docs/PARAMETER_CONTENT.md).
- [ ] **Parcial — Cookies como parámetros:** escalares form y coexistencia con cookies configuradas/cabeceras/seguridad publicados en v0.26.0 / runtime v0.20.0. v0.29.0 añade content text/plain raw. Arrays/objetos y JSON cookie siguen pendientes; el conflicto de delimitadores OpenAPI/HTTP exige una política explícita. [Contrato](docs/COOKIES.md).
- [x] **v0.23.0 — Placeholders path:** declaraciones requeridas/no null y correspondencia exacta con los placeholders; entradas runtime ausentes/null y mutaciones fallan antes de HTTP.
- [x] **v0.26.1 — Reglas de sobreescritura de parámetros:** reemplazo completo por identidad `(in, name)`, herencia con lista ausente/vacía y rechazo de duplicados en cada nivel, también mediante referencias. Swagger 2.0/OpenAPI 3.0/3.1 dentro del subconjunto soportado. [Reglas y pruebas](docs/PARAMETER_OVERRIDES.md).
- [ ] Parámetros `default`, `example`, `examples` y `deprecated` cuando tengan efecto práctico.
- [ ] **Parcial — Cobertura de encoding:** Unicode, espacios, comas, percent y delimitadores comprobados en query soportada y listas simple path/header; faltan los estilos/ubicaciones aún pendientes.

### Swagger 2.0

- [x] **v0.9.0 — Arrays query primitivos con `collectionFormat: csv/multi`**, incluido default CSV de Swagger.
- [x] **v0.10.0 — Arrays query primitivos con `collectionFormat: ssv`, `tsv` y `pipes`.**
- [ ] **Parcial — Formatos en otras ubicaciones:** CSV path/header en v0.23.0; ssv/tsv/pipes y otros casos siguen pendientes.
- [ ] **Parcial — Arrays Swagger por ubicación.** Query primitivo csv/multi/ssv/tsv/pipes , formData csv/multi y CSV path/header publicados; otros formatos pendientes.

### Entrega de arrays query — v0.9.0 / runtime v0.6.0

- [x] Propiedades PHP `array` con PHPDoc `list<T>`; validación declarativa de elementos, límites, enum, patrón y rango soportados, también tras mutaciones.
- [x] Serializador común de claves repetidas/CSV, con comas de valores codificadas, Unicode/espacios, booleanos y números. Ausente/null y listas vacías se omiten; elementos string vacíos se envían explícitamente.
- [x] Equivalencia de SDKs OpenAPI/Swagger y entrada JSON/YAML; URLs exactas con transporte simulado y PSR-18; SDK independiente instalado con runtime estable `^0.6.0`.
- **Validación:** PHP 8.4 cloud: generador 306 pruebas / 2.372 aserciones, diez SDKs con PHPStan max; runtime 62 pruebas / 138 aserciones y PHPStan max. Casafari mantiene 46/46 operaciones aceptadas. CI multiversión e instalación de paquetes en los workflows de publicación.
- **Límites:** no admite objetos, listas anidadas/elementos null, otras ubicaciones, content, allowReserved/allowEmptyValue ni otros estilos. Los casos no soportados conservan diagnóstico estricto. [Uso y límites](docs/QUERY_ARRAYS.md).

### Entrega de listas delimitadas — v0.10.0 / runtime v0.7.0

- [x] Serializar arrays query spaceDelimited/pipeDelimited OpenAPI y ssv/tsv/pipes Swagger con separadores legales `%20`, `%09` y `%7C` en el runtime común.
- [x] Rechazar valores que contengan el separador de su formato, también tras mutaciones, sin enviar HTTP ambiguo. Mantener literales percent-encoded como datos y las reglas existentes de null/lista vacía/elementos vacíos.
- [x] Conservar diagnóstico de explode true indefinido, objetos, ubicaciones no soportadas, content y flags pendientes. Cubrir JSON/YAML, OpenAPI 3.0/3.1/Swagger, parámetros referenciados y HTTP PSR-18.
- **Validación:** generador 313 pruebas / 2.406 aserciones; once SDKs con PHPStan max; Casafari 46/46. Runtime 65 pruebas / 156 aserciones y PHPStan max. CI multiversión y smoke de instalación de las versiones publicadas.
- **Límites:** las listas con espacio/tab/pipe dentro de un elemento se rechazan para su formato correspondiente; no hay convención de escape adicional. Otros tipos, ubicaciones y estilos siguen abiertos. Dependencia estable `^0.7.0`.

### Entrega de objetos query planos — v0.11.0 / runtime v0.8.0

- [x] Modelos reutilizados e inline y mapas escalares tipados para deepObject con explode true; nombres JSON, enums, required, reglas escalares y readOnly/writeOnly.
- [x] GetParams y PostParams, fachadas y recursos, query + body JSON y colisión de nombre body. Marcar explícitamente cuerpos de operación y conservar queryBody separado del campo body real.
- [x] URLs y bytes de body exactos mediante transporte simulado/PSR-18, JSON/YAML, OpenAPI 3.0/3.1, parámetros reutilizables, valores ausentes/null/vacíos y mutaciones.
- **Validación:** PHP 8.4 cloud: generador 322 pruebas / 2.444 aserciones, doce SDKs con PHPStan max y Casafari 46/46. Runtime: 70 pruebas / 177 aserciones y PHPStan max. Smoke independiente de instalación con query + JSON y mutaciones; CI multiversión de la entrega.
- **Límites:** solo objetos planos cerrados o con valores adicionales escalares tipados; sin propiedades null/anidadas, extras sin tipo, composiciones ni otros estilos/ubicaciones. Forms urlencoded se añaden en v0.12.0 y multipart OpenAPI básico en v0.15.0; sus formatos avanzados siguen pendientes. Dependencia de esta entrega histórica `^0.8.0`. [Uso y revisión GetParams/PostParams](docs/QUERY_OBJECTS.md).

### Entrega de listas simples path/header — v0.23.0 / runtime v0.17.1

- [x] Ampliar el guard/resolvedor de arrays existentes a listas primitivas simple OpenAPI y CSV Swagger path/header. Percent-encoding nativo por elemento de ruta y header raw con rechazo de comas ambiguas, controles y espacios exteriores; sin inventar escapes ni añadir intérprete URI. Mantener nombres numéricos como 0 tras la conversión de claves de PHP.
- [x] PathParams con array nativo incluso para una única lista; preservar escalares y firma heredada executeArguments. GetParams/PostParams, query ids independiente de path ids, query body separado del JSON DTO, límites/items/mutaciones, referencias/alias, JSON/YAML y DTOs compuestos.
- [x] Validar correspondencia exacta de placeholders, required y no null de parámetros path; rechazar entradas ausentes/null y metadata/formatos incompatibles antes de transporte. Cabeceras ausentes/null se omiten; listas vacías y tokens vacíos siguen el CSV, sujeto al esquema.
- **Validación:** runtime 114 pruebas / 452 aserciones, PHPStan máximo y smoke instalado. Generador: 389 pruebas / 2.946 aserciones y veintidós SDKs instalados con PHPStan máximo; ocho requests HTTP reales GET/POST en SDKs OpenAPI/Swagger, bytes/path/query/header/body y mutaciones. CI/publicación verifican versiones estables coordinadas.
- **Límites:** listas primitivas no nullable con schemas inline; no objetos/nesting, content, label/matrix/cookies, arrays header/path multi/ssv/tsv/pipes ni negociación de estilo. No cierra el paquete 03. [Uso](docs/SIMPLE_PARAMETERS.md).

### Entrega de objetos planos HTTP — v0.24.0 / runtime v0.18.0

- [x] Completar las familias planas form/query y simple/path-header con ambos explode/defaults. Reutilizar modelos DTO/Map, guard de objetos, validación y extracción/codificadores comunes; sin duplicar ejecución HTTP en SDKs ni añadir un hidratador/fábrica.
- [x] Modelos de objeto referenciados/inline y mapas escalares tipados, nombres JSON, campos required/readOnly, booleanos/ceros y mutaciones. Mantener tipos separados por ubicación cuando comparten wire name, PathParams y GetParams/PostParams, query body distinto del DTO JSON real.
- [x] Rechazar colisiones de propiedades query expandidas, claves vacías, valores null/nested y delimitadores/controles ambiguos de header. Conservar texto de header y percent-encoding separado de claves/valores en URI; deepObject conserva sus reglas.
- **Validación:** runtime 119 pruebas / 486 aserciones, PHPStan máximo, CI multiversión/dependencias mínimas e instalación publicada. Generador: 395 pruebas / 2.977 aserciones y veintitrés SDKs instalados con PHPStan máximo, ejecución HTTP real GET/POST y smoke independiente en publicación.
- **Límites:** objetos planos OpenAPI 3.0/3.1 con propiedades primitivas no nullable y claves no vacías; no mapas sin tipo, composiciones, contenido JSON en parámetros, cookies, label/matrix ni flags de reservado/vacío. Swagger no define estos parámetros de objeto. [Uso y límites](docs/OBJECT_PARAMETERS.md).

**Aceptación:** tests con URLs y headers exactos para cada combinación `in/style/explode`. Ejemplo: `ids=1&ids=2` frente a `ids=1,2`.

## 4. [P0] Requests, media types y respuestas

### Peticiones

- [ ] **Parcial — Revisión HTTP/forms del paquete 03.** GetParams/PostParams con query + urlencoded en v0.12.0 y multipart básico OpenAPI en v0.15.0/Swagger en v0.16.0. Streaming PSR-18 se añade en v0.18.0; quedan encoding avanzado y otros escenarios de streaming.
- [x] **v0.12.0 — application/x-www-form-urlencoded:** objetos cerrados con campos escalares/listas primitivas, encoding form/explode, inline/referencias locales, DTOs y validación tras mutaciones.
- [ ] Ampliar formularios a objetos/mapas abiertos, estructuras complejas, otros estilos y negociación de representaciones.
- [ ] **Parcial — multipart/form-data:** v0.15.0 ejecuta objetos cerrados planos con texto, listas repetidas y archivos binary en strings; contentType por campo binario y MIME mediante Guzzle. v0.16.0 incorpora Swagger type:file y listas multi. v0.19.0 añade listas de archivos binarias OpenAPI. Quedan headers por parte, objetos/nesting y otros encoding.
- [ ] **Parcial — Subida de archivos binarios:** v0.17.0 acepta strings o UploadedFileInterface con nombres/MIME configurables y streams de entrada readable/seekable. v0.18.0 preserva streams por PSR-18 y verifica 32 MiB bajo límite PHP 16 MiB; solo los transportes personalizados antiguos mantienen el string completo. v0.19.0 añade listas OpenAPI. Quedan nesting, entradas no seekables y garantías generales para otros clientes/middleware/respuestas.
- [x] **v0.19.0 — Listas de archivos OpenAPI:** arrays nativos string|UploadedFileInterface, nombres de parte repetidos, MIME/nombre por archivo, validación/mutaciones/herencia y memoria comprobada. Swagger 2.0 no define arrays de file válidos.
- [x] **v0.18.0 — Streaming multipart PSR-18:** adaptador existente, archivos seekables, rebobinado tras middleware y repetición; compatibilidad de transportes antiguos y prueba de memoria en instalación independiente.
- [ ] Body directo de array, mapa, escalar o binario sin envolverlo necesariamente en un DTO de objeto.
- [ ] Selección explícita de `Content-Type` entre representaciones alternativas.
- [ ] Múltiples representaciones JSON con esquemas distintos.
- [ ] **Parcial — Referencias a components/requestBodies:** alias locales resueltos y verificados también en multipart; resolución general/remota sigue en el paquete 02.
- [ ] Tratar correctamente cuerpos ausentes, `null`, vacíos y opcionales.

### Entrega de formularios URL-encoded — v0.12.0 / runtime v0.9.0

- [x] Selección de form cuando no hay JSON; mantener prioridad JSON existente y diagnosticar alternativas incompatibles. Contrato y ejecución comparten schema/encoding; runtime estable `^0.9.0`.
- [x] Campos escalares y arrays primitivos multi/CSV, JSON names/enums, required/reglas/dirección y mutaciones. Distinguir body ausente de formulario vacío, omitir campos null/listas vacías y conservar strings vacíos.
- [x] GetParams/PostParams y body-only, query + headers + body/form con nombres coincidentes y query llamado body, API estática y por instancia. JSON/YAML, OpenAPI 3.0/3.1, requestBody reutilizado e inline.
- **Validación:** generador 332 pruebas / 2.486 aserciones; trece SDKs con PHPStan max y Casafari 46/46. Runtime: 77 pruebas / 196 aserciones y PHPStan max. HTTP real cloud-local comprueba URL, headers y bytes; publicación comprueba SDK instalado independiente y mutaciones.
- **Límites:** objetos form cerrados con campos escalares/listas primitivas; sin items null/nesting, mapas abiertos, encoding de contentType/headers, allowReserved true, multipart/archivos ni Swagger formData. No cierra el paquete 04 ni toda la revisión de forms. [Uso y límites](docs/FORMS.md).

### Entrega Swagger formData — v0.13.0, runtime v0.9.0 sin cambios

- [x] Agregar parámetros formData escalares/listas a un body tipado canónico, conservando required, límites, enums, nombres y extensiones nullable soportadas. Defaults conservados sin materializar.
- [x] CSV por defecto de Swagger y csv/multi explícitos; consumes de operación sobre raíz, alias locales y override por in/name. No crear body solo por heredar consumes en un GET sin formData.
- [x] GetParams/PostParams con query + headers + form y query llamado body; formularios opcionales/body-only, equivalencia byte a byte con OpenAPI, JSON/YAML, PSR-18, HTTP real cloud-local y mutaciones.
- **Validación:** 341 pruebas / 2.537 aserciones; catorce SDKs con PHPStan max; Casafari 46/46; Composer e instalación independiente del SDK Swagger. CI multiversión e instalación de paquete publicado. Runtime estable `^0.9.0`, sin cambios.
- **Límites:** un único consumes URL-encoded; no archivos/multipart, objetos/listas anidadas/items null, ssv/tsv/pipes en form, collectionFormat escalar ni mezcla body+formData. La revisión HTTP/forms sigue parcial para multipart. [Uso](docs/FORMS.md#swagger-20-formdata-v0130).

### Entrega multipart OpenAPI — v0.15.0 / runtime v0.11.1

- [x] Reutilizar FormSupport para objetos cerrados planos y Guzzle PSR-7 MultipartStream para MIME/boundaries. DTOs con strings nativos para binary, escalares/listas repetidas y metadatos estáticos de parte; sin un encoder MIME ni clase de subida propios.
- [x] contentType binario explícito o application/octet-stream por defecto; texto text/plain. Nombres de archivo basename(campo) + .bin. JSON mantiene prioridad sobre urlencoded y multipart. Rechazar opciones que no se ejecutan fielmente, incluidos límites Unicode sobre bytes binarios.
- [x] GetParams/PostParams con query + headers + multipart, query llamado body/nombres coincidentes, fachadas/instancias, JSON/YAML, OpenAPI 3.0/3.1, inline/referencias locales, required/enums/dirección y mutaciones. Body ausente, vacío, campos null/vacíos y archivos vacíos diferenciados.
- **Validación:** 350 pruebas / 2.603 aserciones del generador; quince SDKs instalados con PHPStan máximo. Runtime: 85 pruebas / 239 aserciones, PHPStan máximo, CI con dependencias mínimas e instalación publicada. HTTP real cloud-local con parser MIME independiente e instalación de SDK multipart independiente del generador. La publicación comprueba el SDK instalado desde el tag inmutable.
- **Límites:** transporte string y cuerpo completo en memoria; sin promesa de streaming/memoria acotada. Swagger multipart llega en v0.16.0. Nombres/MIME configurables llegan vía PSR en v0.17.0. Pendientes listas binarias, objetos/maps/nesting, headers por parte, CSV/otros estilos y selección por llamada. No cierra los paquetes 03/04 generales. [Uso y límites](docs/FORMS.md#openapi-multipart-v0150--runtime-v0111).

### Entrega multipart Swagger — v0.16.0, runtime v0.11.1 sin cambios

- [x] Normalizar formData type:file a string binary y listas multipart multi al contrato común. Reutilizar el serializador Guzzle existente y el smoke de SDK independiente; sin clases de subida ni transporte/encoder adicionales.
- [x] Un consumes explícito, precedencia de operación, alias locales, overrides de campos path/operación y diagnóstico por uso efectivo. Las declaraciones reutilizables de archivos no heredan indebidamente el media type global; cada uso de archivo requiere multipart.
- [x] SDK idéntico byte a byte al OpenAPI equivalente, JSON/YAML, GetParams/PostParams, query + headers + body con nombres coincidentes/body, archivos required/opcionales/vacíos/null y mutaciones. Rechazar CSV por defecto/expreso, listas de archivos, constraints impropios de archivo y body+formData.
- **Validación:** 356 pruebas / 2.671 aserciones; dieciséis SDKs instalados con PHPStan máximo. HTTP real cloud-local con parser MIME independiente, SDK instalado sin generador y smoke publicado específico. Runtime estable ^0.11.1 sin cambios.
- **Límites:** cuerpo completo en memoria, filenames fijos basename(campo) + .bin y application/octet-stream para archivos Swagger. Nombres/tipos por llamada llegan vía PSR en v0.17.0. Siguen pendientes streaming HTTP/memoria acotada, CSV multipart, estructuras anidadas y headers por parte. No cierra el paquete 04. [Uso](docs/FORMS.md#swagger-20-multipart-v0160).

### Entrega de archivos PSR — v0.17.0 / runtime v0.12.0

- [x] Ampliar campos binary a string|UploadedFileInterface (más null cuando corresponda) conservando strings, firmas de operaciones y transportes. Usar Guzzle UploadedFile/MultipartStream e interfaces PSR; sin clase, fábrica ni encoder de archivos propios.
- [x] Nombre del upload y MIME configurables; fallback basename(campo) + .bin y octet-stream para strings/metadata ausente. MIME explícito de contrato obligatorio, comparación case-insensitive, metadatos seguros y streams readable/seekable. Guzzle rebobina para repetir el envío; cada mutación se comprueba de nuevo.
- [x] Hidratación de la unión específica de entrada, campos requeridos/nullables/direccionales, JSON names y rechazo explícito de objetos upload al serializar JSON/urlencoded. No implementa uniones generales de esquemas.
- **Validación:** generador 359 pruebas / 2.690 aserciones; dieciséis SDKs instalados con PHPStan máximo. Runtime 92 pruebas / 273 aserciones, PHPStan máximo, CI multiversión/dependencias mínimas e instalación publicada. SDKs OpenAPI/Swagger independientes con strings/PSR, PSR-18 y dos requests HTTP reales cloud-local decodificados por parser MIME independiente. Smoke publicado de runtime y ambos SDKs con archivos PSR.
- **Límites:** entrada por UploadedFileInterface con stream seekable, no stream/resource directo como propiedad. Transporte string y cuerpo completo en memoria; streaming HTTP/memoria acotada siguen pendientes. Nombres ASCII sin comillas/backslash ni filename 0, MIME simple sin parámetros, listas de archivos, objetos/nesting y encoding avanzado siguen limitados. [Uso y límites](docs/FORMS.md#psr-uploaded-files-v0170--runtime-v0120).

### Entrega streaming multipart PSR-18 — v0.18.0 / runtime v0.13.0

- [x] Preservar Guzzle MultipartStream hasta el cliente PSR-18, sin materializar el multipart completo. Ampliación contravariante del método del adaptador existente; interfaz TransportInterface, clientes personalizados anteriores y firmas GetParams/PostParams conservadas. Sin nueva interfaz ni wrapper de streams.
- [x] Rebobinar cuerpos seekables tras inspección/reemplazo de middleware y antes de cada envío. Repetición completa de archivos; rechazar partes con el mismo cursor PSR para no truncar archivos posteriores. Mantener validación, metadatos, query/headers/body y ausente/vacío.
- **Validación:** generador 359 pruebas / 2.692 aserciones; dieciséis SDKs con PHPStan máximo. Runtime 96 pruebas / 298 aserciones, PHPStan máximo, dependencias mínimas y smoke instalado. Dos envíos de 32 MiB con Guzzle/cURL y HTTP real bajo límite PHP de 16 MiB, pico PHP 4 MiB y parser MIME independiente. SDKs OpenAPI/Swagger instalados repiten dos envíos de 32 MiB bajo 16 MiB en CI y publicación.
- **Límites:** subida desde archivo/stream seekable por PSR-18; legacy TransportInterface y strings siguen usando memoria, y otros clientes/middleware/opciones pueden bufferizar. Las respuestas siguen como strings. No equivale a una cota de RSS universal: pendientes garantías para no seekable/tamaño desconocido, listas/nesting, descarga streaming y encoding avanzado. [Uso y medida](docs/FORMS.md#streaming-multipart-v0180--runtime-v0130).

### Entrega de listas binarias OpenAPI — v0.19.0 / runtime v0.14.0

- [x] Arrays de string/binary en cuerpos multipart planos/cerrados como listas PHP string|UploadedFileInterface, sin colección de archivos propia. Reutilizar hidratación, validación y expansión Guzzle; preservar colecciones solo JSON y validación en hijos sin redeclarar campos heredados.
- [x] Partes con nombre exacto repetido, orden, metadatos por archivo, mezcla de strings/PSR, minItems/maxItems, required, lista nullable exterior y mutaciones. Ausente/null/lista vacía sin partes; string vacío como archivo vacío. Mantener query + headers + body y rechazo JSON de objetos PSR en listas.
- **Validación:** generador 366 pruebas / 2.741 aserciones; diecisiete SDKs con PHPStan máximo. Runtime 101 pruebas / 328 aserciones, CI/dependencias mínimas e instalación. Dos archivos de 16 MiB (32 MiB agregados) por request, dos envíos reales con Guzzle/cURL en runtime y SDK independiente bajo límite PHP 16 MiB, pico PHP 4 MiB y parser MIME independiente. Smoke de lista en CI y publicación desde tag inmutable.
- **Límites:** solo OpenAPI 3.0/3.1, JSON/YAML, named/inline. Los arrays file de Swagger 2.0 no son válidos y siguen diagnosticados. Sin items null/nested/enum/pattern/límites Unicode, CSV/explode false ni headers por parte. Se mantienen límites de nombres/MIME/streams y memoria de clientes/middleware/transportes antiguos/respuestas. [Uso](docs/FORMS.md#openapi-binary-file-lists-v0190).

### Entrega de descargas binarias OpenAPI — v0.20.0 / runtime v0.15.0

- [x] Respuesta nativa PSR-7 con status, cabeceras multivalor y stream original; reutilizar adaptador PSR-18 y Guzzle copyToStream, sin wrapper/DTO de archivo ni ejecución duplicada en SDKs. Mantener TransportInterface/send() y JSON/errores compatibles.
- [x] OpenAPI 3.0/3.1 JSON/YAML, alias components/responses, prioridad JSON, varios éxitos binarios 200/206 y 204 vacío. GetParams/PostParams, fachadas/instancias, query llamado body separado del DTO JSON, validación tras mutaciones y errores JSON tipados.
- **Validación:** runtime 105 pruebas / 361 aserciones, PHPStan máximo y CI con versiones/dependencias mínimas. Generador: 371 pruebas / 2.781 aserciones y dieciocho SDKs instalados con PHPStan máximo; smoke de descarga en CI e instalación publicada. Runtime descarga dos archivos de 32 MiB; SDK repite GET/POST de 32 MiB bajo límite PHP 16 MiB. HTTP real Guzzle/cURL mide pico PHP 8 MiB en runtime y 6 MiB en SDK con hash/cabeceras completos.
- **Límites:** solo application/octet-stream con esquema inline string/binary no nullable/sin constraints; sin Swagger, otros MIME ni mezcla de cuerpos JSON/binario por estado. PSR expone cabeceras pero sus esquemas siguen sin validarse. Guzzle puede almacenar temporalmente en disco; clientes/middleware/transportes antiguos y errores pueden bufferizar. No promete consumo de red progresivo, RSS universal ni cierra el paquete 04. [Uso y límites](docs/DOWNLOADS.md).

### Entrega de descargas Swagger — v0.21.0, runtime v0.15.0 sin cambios

- [x] Adaptar respuestas Swagger 2.0 schema type:file con un produces application/octet-stream al contrato PSR ya publicado; raíz/operación, referencias/alias y evaluación por uso efectivo. Salida idéntica al OpenAPI equivalente, sin transporte/DTO de archivo nuevo ni cambios del runtime.
- [x] JSON/YAML, éxitos binarios 200/206 y 204 vacío, GetParams/PostParams, fachada/instancia, query llamado body separado del DTO JSON, bytes/cabeceras, repetición, mutaciones y ApiException con respuesta raw.
- **Validación:** 376 pruebas / 2.851 aserciones; diecinueve SDKs instalados con PHPStan máximo. Reutilizar smoke instalado de descarga en CI/publicación. Cuatro descargas reales GET/POST de 32 MiB bajo límite PHP 16 MiB, pico PHP 8 MiB, hashes/cabeceras y valores HTTP comprobados.
- **Límites:** un único produces explícito octet-stream; no otros MIME, negociación, file nullable/con constraints, errores file ni DTO JSON de error con produces octet-stream común. No sustituir type:file por string/binary en Swagger. Se mantienen buffering de errores/transportes antiguos, posibles temporales en disco y límites de clientes/middleware; no cierra streaming general ni el paquete 04. [Uso y límites](docs/DOWNLOADS.md#swagger-20-file-responses-v0210).

### Entrega de respuestas JSON por estado — v0.22.0 / runtime v0.16.0

- [x] Metadatos declarativos responseTypes por éxito explícito, con tipo/nulabilidad por estado y null para ausencia de body. Reutilizar ApiClient/JsonHydrator/validación existentes; sin wrapper, fábrica ni switch de ejecución en cada SDK. Compatibilidad de metadatos/argumentos antiguos y errores tipados/raw.
- [x] Firmas PHP nativas User|CreatedUser|null, herencia real, tipos repetidos y nombres de respuestas inline por status. 204 sin contenido no oculta un 200 inválido/vacío/null. Swagger/OpenAPI 3.0/3.1 JSON/YAML equivalentes; GetParams/PostParams, query body, JSON DTO, serialización y mutaciones.
- **Validación:** runtime 109 pruebas / 414 aserciones, PHPStan máximo, CI multiversión/dependencias mínimas e instalación publicada. Generador: 383 pruebas / 2.896 aserciones y veinte SDKs instalados con PHPStan máximo. SDK independiente con 24 escenarios HTTP reales GET/POST; smoke de selección/validación en CI y publicación inmutable.
- **Límites:** éxitos JSON con códigos numéricos explícitos y esquemas ya representables; no mezcla JSON/binario por estado, rangos, codecs alternativos ni unión general de propiedades. Se conservan los límites JSON Schema y buffering de JSON/errores. [Uso y comportamiento](docs/RESPONSES.md).

### Respuestas

- [x] **v0.22.0 — Varios códigos 2xx con cuerpos JSON y tipos representables diferentes** en una operación. El runtime selecciona el hidratador por el estado real; no adivina esquemas por el contenido.
- [x] **v0.22.0 — Selección del DTO de respuesta mediante el código HTTP real**, para éxitos numéricos explícitos, con required/validación y nulabilidad por estado.
- [x] **v0.22.0 — Uniones PHP nativas en firmas de recursos y fachadas** para varias respuestas JSON de éxito representables, incluida herencia y null; sin wrapper de resultado.
- [ ] **Parcial — Respuestas sin contenido:** v0.22.0 distingue cada estado explícito sin body de otro con JSON; 204 vacío no permite aceptar 200 vacío. Quedan políticas generales para HEAD/205 y otras combinaciones del protocolo.
- [ ] Mezclar respuestas de éxito JSON/binarias o negociar codecs distintos por estado.
- [ ] Cabeceras de respuesta declaradas en `responses[*].headers`: v0.20.0 permite leer cabeceras PSR de descargas, pero no valida sus esquemas declarados.
- [ ] `Set-Cookie` y acceso tipado a metadatos HTTP.
- [ ] Rangos de status `1XX`, `2XX`, `3XX`, `4XX`, `5XX` y `default`.
- [ ] Política configurable de redirecciones `3xx`.
- [ ] Errores con body escalar, array, mapa, no JSON y con media types alternativos.
- [ ] **Parcial — Descargas binarias:** v0.20.0 devuelve ResponseInterface PSR-7 para éxitos application/octet-stream OpenAPI 3.0/3.1 sin materializar bytes completos en el runtime. v0.21.0 añade Swagger type:file con produces octet-stream; quedan otros MIME, negociación y garantías generales de streaming. [Uso y medidas](docs/DOWNLOADS.md).
- [ ] Respuestas texto, CSV, XML y otras representaciones cuando sea útil.
- [ ] Negociación `Accept` y uso de `application/*+json` con diversos esquemas.
- [ ] Referencias a `components/responses` y `components/headers`.

**Decisión aplicada en v0.22.0:** `200: User` y `201: CreatedUser` producen un retorno nativo `User|CreatedUser`; el runtime conserva la selección del hidratador por status. La unión no modifica la validez de los modelos ni implementa uniones generales de propiedades.

**Aceptación:** fixtures para cada caso, transporte simulado verificando bytes del request y estado/tipo del response, más un smoke HTTP real para JSON, multipart y binarios.

## 5. [P1] JSON Schema y modelos de datos

OpenAPI 3.1 se apoya en JSON Schema 2020-12. Este bloque puede avanzar en paralelo a la capa HTTP.

### Decisión de arquitectura: tipos nativos primero, validación declarativa después

> **Principio acordado:** «Utilizar el sistema de tipos del lenguaje hasta donde alcance; completar su expresividad mediante validación declarativa».
>
> Separar tres responsabilidades: **PHP representa los tipos**; **sdk-runtime valida la semántica del contrato**; **sdk-generator elige la representación pública más simple que sea fiel al esquema**. Conservar DTOs tipados, legibilidad y código generado mínimo.

**Aplicación a composiciones (diseño acordado; implementación pendiente):**

- **`anyOf` (OR):** usar `Clase1|Clase2` en propiedades y firmas PHP **cuando sea representable sin pérdida semántica**. El valor JSON debe satisfacer **al menos un** esquema. Puede coincidir con varios; el hidratador necesita una estrategia explícita para la ambigüedad, preservando la información original cuando sea necesario.
- **`oneOf` (XOR):** también preferir `Clase1|Clase2` cuando sea adecuado. El valor debe satisfacer **exactamente un** esquema; `required` solo exige presencia y **no garantiza exclusividad**. La validación debe contar las ramas compatibles, incluso cuando PHP pueda asignar una de las clases.
- **`allOf` (AND):** el **mismo valor JSON** debe satisfacer **todos** los esquemas. No representa un array con una instancia de cada clase ni exige modificar `TypedArray`. Para objetos compatibles, fusionar propiedades en un DTO concreto solo si se conservan todas las restricciones, especialmente `required`, `additionalProperties`, composición y restricciones cruzadas; usar validación de intersección o representación auxiliar cuando la fusión no sea fiel.
- **Fallback:** mantener envoltorios `AnyOf`/`OneOf` u otra representación explícita en los casos donde una unión nativa no baste (tipos solapados, ambigüedad de hidratación, varios esquemas para un único objeto JSON o necesidad de conservar variantes). No eliminar los actuales antes de comprobar compatibilidad.
- **Hidratación y validación:** validar **el dato JSON de origen** contra los esquemas aplicables, seleccionar la clase PHP sin alterar arbitrariamente su significado y volver a validar al serializar/tras mutaciones. Compartir el motor de evaluación de ramas: `anyOf >= 1`, `oneOf === 1`, `allOf === total`.

### Refinamiento: representar polimorfismo como herencia PHP cuando el contrato lo permita

En el patrón habitual OpenAPI `Pet` + `Dog`/`Cat`, donde `Dog` y `Cat` declaran `allOf: [{$ref: Pet}, {type: object, properties: ...}]`, preferir `Pet extends DTO` y `Dog extends Pet`/`Cat extends Pet` **si la estructura es realmente compatible con herencia simple**. `oneOf: [Dog, Cat]` puede dar una firma PHP `Dog|Cat`, con validación del `oneOf` original. El `discriminator.propertyName` y su `mapping` explícito/implícito ayudan a elegir el DTO concreto durante la hidratación.

**Matices normativos que debemos preservar:**

- `allOf` es intersección de esquemas, **no implica siempre una jerarquía de clases**. No convertir automáticamente `allOf` con dos bases independientes en herencia múltiple PHP.
- `discriminator` es una ayuda para decidir qué esquema instanciar; **no modifica el resultado de validar** `oneOf`/`anyOf`. Todas las ramas mantienen su semántica lógica.
- Ejemplo de ambigüedad: si `Pet` solo exige `pet_type` y `Dog`/`Cat` añaden únicamente propiedades opcionales, `{"pet_type":"Dog"}` puede satisfacer **ambas** ramas y resulta inválido para `oneOf`. Para distinguir esquemas mediante validación, declarar `pet_type: {type: string, enum: [Dog]}` y su equivalente para `Cat` en OAS 3.0 (o `const` en OAS 3.1).
- En PHP no redeclarar propiedades heredadas con tipos incompatibles: aplicar las restricciones del discriminador mediante metadatos/reglas de validación del hijo.
- Hasta v0.2.0, el generador **aplana** `allOf` compatible, genera modelos como `final class ... extends DTO` y **rechaza `discriminator`**.
- En v0.3.0 ([generador PR #19](https://github.com/dmorenof/sdk-generator/pull/19), runtime v0.4.0 [PR #6](https://github.com/dmorenof/sdk-runtime/pull/6)): herencia PHP de un único padre compatible, metadatos completos y refinamientos por intersección de enums `string`/`integer` sin redeclarar la propiedad heredada. Parsing de `discriminator.propertyName` y mapping local explícito/implícito; hidratación compartida en propiedades, arrays, mapas, respuestas HTTP y `DTO::fromValue()`. Los envoltorios evalúan las ramas originales antes de hidratar y vuelven a comprobar cardinalidad/identidad al serializar la variante seleccionada. Las familias discriminadas conservan propiedades adicionales permitidas para no perder JSON. Dependencia estable del runtime `^0.4.0`.
- Validación cloud: PHP 8.3.36, 182 pruebas / 1.920 aserciones del generador (28 pruebas de discriminadores); SDKs accounts, catalog, casafari y Pet/Dog/Cat instalados y sin errores PHPStan max. Runtime: 50 pruebas / 101 aserciones y PHPStan max. CI de ambos incluye PHP 8.3/8.4/8.5, dependencias mínimas y auditoría. Casos: HTTP simulado, recursividad, enum heredado, renombrado JSON, required direccional y mutación que introduce ambigüedad.
- Límites actuales: mapping a referencias locales/nombres de componentes; ramas discriminadas con nombre y herencia compatible para bases objeto. `new Pet(...)` conserva la clase solicitada; usar `Pet::fromValue()` para despachar. Refinamientos distintos de enums, `const`, `not`, uniones nativas generales, composición general de `allOf` y schemas externos siguen pendientes. Varios matches de `anyOf` sin selección son vistas del snapshot original, no una API para fusionar mutaciones.
- Al introducir herencia, combinar correctamente `REQUIRED_FIELDS`, `VALIDATION`, `PROPERTY_MAP`, `READ_ONLY_FIELDS`, `WRITE_ONLY_FIELDS` y demás metadatos de padres/hijos; comprobar reflexión, serialización e hidratación de propiedades heredadas.

**Tareas del refinamiento:**

- [x] Reconocer y preservar `discriminator.propertyName` y `mapping`, con mapeo explícito/implícito y diagnóstico si el valor no identifica una rama.
- [x] Detectar patrón `allOf` de un único padre `$ref` + propiedades propias, con ausencia de conflictos de validación, y generar herencia nativa cuando sea fiel.
- [x] Permitir extender modelos base (ajustar `final` únicamente en las clases necesarias) sin romper usos independientes de `Pet`.
- [x] Fusionar o aplicar de forma incremental las reglas declarativas de toda la cadena de clases.
- [x] Hidratar `oneOf`/`anyOf` orientándose por el discriminador y validar la cardinalidad real de los esquemas originales.
- [x] Incluir tests de `Pet`, `Dog`, `Cat`: selección de clase, herencia, serialización, caso ambiguo y `mapping` con nombres diferentes.
- [x] Mantener fallback a DTO aplanado/representaciones auxiliares para composiciones sin herencia PHP válida.


Cierre del **subconjunto descrito**: generador v0.3.0 / runtime v0.4.0, PRs #19 / #6. No cierra el bloque general de JSON Schema.
Referencias: [OAS 3.1.2, Composition and Inheritance / Discriminator](https://spec.openapis.org/oas/v3.1.2.html#composition-and-inheritance-polymorphism); [JSON Schema: Combining Schemas](https://json-schema.org/understanding-json-schema/reference/combining).

**Pruebas imprescindibles:**

- [x] `anyOf` con 0, 1 y 2+ ramas válidas en el subconjunto soportado; distinguir validez de hidratación (v0.3.0, #19/#6).
- [x] `oneOf` con 0, 1 y 2+ coincidencias, incluidas propiedades adicionales toleradas por varios esquemas y ambigüedad tras mutación (v0.3.0, #19/#6).
- [ ] `allOf` que combina `id` y `name` en un objeto; casos conflictivos de `additionalProperties: false`, `required` y restricciones numéricas.
- [ ] Composiciones anidadas, ramas `$ref`, tipos primitivos, enums, arrays y modelos recursivos.
- [ ] Comparación de salida y API pública con los envoltorios existentes para evitar cambios incompatibles no justificados.
- [ ] Comprobar que solo se generan clases auxiliares cuando aportan semántica que PHP no puede expresar.

**Criterio de cierre:** minimizar clases generadas sin perder validación ni información necesaria para hidratar. **No confundir representación nativa con conformidad JSON Schema.**

### Tipos y composición

- [ ] Unión de tipos arbitrarios: `type: [string, integer, 'null']`.
- [ ] Esquemas booleanos `true` y `false`.
- [ ] `const` y `not`.
- [ ] `if`/`then`/`else`.
- [ ] `discriminator` con `propertyName`, `mapping` y casos por defecto de nuevas versiones.
- [ ] `allOf` completo, incluidos hermanos, fusiones y conflictos complejos.
- [ ] `oneOf` y `anyOf` con restricciones hermanas y composición anidada.
- [x] Validación exacta de `oneOf` sobre los esquemas originales soportados cuando varias ramas son compatibles (v0.3.0, #19/#6).
- [ ] Uniones de objetos, mapas, arrays y escalares, incluyendo recursividad.
- [ ] Preservación de identidad y ciclos de tipos en grafos complejos.

### Restricciones

- [ ] `exclusiveMinimum`, `exclusiveMaximum`, `multipleOf`.
- [ ] `minProperties`, `maxProperties`.
- [ ] `uniqueItems`, `contains`, `minContains`, `maxContains`.
- [ ] `dependentRequired`, `dependentSchemas`.
- [ ] `propertyNames`, `patternProperties`.
- [ ] `unevaluatedProperties`, `unevaluatedItems`.
- [ ] `prefixItems` y tuplas.
- [ ] Semántica completa de `items` y arrays de Draft 2020-12.
- [ ] `additionalProperties`: restricciones y composición completa.
- [ ] `minLength`/`maxLength`: conteo correcto según semántica Unicode.
- [ ] `pattern`: diferencias entre regex JSON Schema y PCRE, y sus límites.

### Format, contenido y anotaciones

- [ ] Validadores de `date`, `date-time`, `time` y `duration`.
- [ ] `email`, `idn-email`, `hostname`, `idn-hostname`.
- [ ] `ipv4`, `ipv6`, `uri`, `uri-reference` y `uuid`.
- [ ] `json-pointer`, `relative-json-pointer` y `regex`.
- [ ] Formatos heredados OpenAPI: `byte`, `binary` y `password`.
- [ ] `contentEncoding`, `contentMediaType` y `contentSchema`.
- [ ] Diferenciar validación de `format` de una conversión optativa a clases PHP como `DateTimeImmutable`.
- [ ] Aplicación opcional de `default` (actualmente se conserva, pero no se materializa).
- [ ] `examples`, `deprecated`, `summary`, `externalDocs`, `xml`, `$comment`.
- [ ] PHPDoc de propiedades con descripción, formatos y ejemplos.
- [ ] Política explícita para propiedades JSON desconocidas: ignorar, conservar o rechazar.

**Aceptación:** pruebas de aceptación/rechazo con fixtures de JSON Schema, validación antes de enviar y después de hidratar, y serialización coherente de DTOs anidados.

## 6. [P1] Autenticación y seguridad

### Soporte pendiente

- [ ] OAuth2 `clientCredentials`.
- [ ] OAuth2 `authorizationCode` y PKCE.
- [ ] OAuth2 `password` e `implicit` por compatibilidad heredada, sin recomendarlos para nuevas aplicaciones.
- [ ] Definición, asociación y comprobación de scopes.
- [ ] Renovación de tokens y gestión de expiración.
- [ ] OpenID Connect y descubrimiento de configuración.
- [ ] OAuth2 `refreshUrl` y documentos de metadatos.
- [x] API key ubicada en cookies: v0.26.0 / runtime v0.20.0, credencial raw RFC6265, AND/OR y coexistencia con diagnóstico de conflictos.
- [ ] Autenticación mutual TLS, certificados y claves de cliente.
- [ ] Almacenamiento configurable y seguro de credenciales/tokens.
- [ ] Reintento de autenticación por expiración cuando sea apropiado.
- [ ] Extensiones de seguridad específicas de proveedores.
- [ ] Device Authorization y otras novedades de OpenAPI 3.2.

**Arquitectura:** el contrato declara los esquemas y scopes; `sdk-runtime` utiliza proveedores de credenciales intercambiables y reutilizables. Nunca generar lógica OAuth duplicada por endpoint.

**Aceptación:** un proveedor de tokens compartido funciona con dos SDKs, respeta scopes, caducidad y concurrencia, y no expone secretos en trazas.

## 7. [P1] Servidores, rutas y operaciones

- [ ] Variables de `servers`, valores por defecto y `enum`.
- [ ] Servidores relativos y resolución frente al documento o base URL.
- [x] **Publicado en v0.4.0 / v0.5.0:** servidores literales HTTP(S) por Path Item y operación, precedencia operación → ruta → raíz y `serverOverrides` por conexión para otros entornos. Relativos/variables siguen en sus tareas separadas.
- [ ] Manejo de operaciones con varios servidores disponibles.
- [ ] **Parcial — Referencias a Path Item:** referencias locales completas en v0.6.0 (ver paquete 02); faltan documentos externos y ubicaciones arbitrarias.
- [ ] Detección de rutas ambiguas, placeholders inconsistentes y nombres PHP en conflicto.
- [ ] Semántica de `HEAD`, `OPTIONS` y `TRACE` más allá de reconocer el verbo.
- [ ] Metadatos de ruta/operación: `summary`, `description`, `tags`, `deprecated`, `externalDocs`.
- [ ] PHPDoc enriquecido sin cambiar los namespaces derivados de URL.
- [ ] URLs y reglas heredadas `host`/`basePath`/`schemes` de Swagger 2.0.

**Aceptación:** un SDK puede conmutar entornos y resolver overrides de servidor sin cambiar las firmas de sus operaciones.

## 8. [P2] OpenAPI 3.2.x y streaming

- [ ] Aceptar el dialecto 3.2.x tras validar las reglas nuevas que afecten al contrato.
- [ ] Método HTTP `QUERY`.
- [ ] Métodos HTTP adicionales mediante `additionalOperations`.
- [ ] Parámetros `in: querystring`.
- [ ] Streaming `text/event-stream` (SSE).
- [ ] Streaming `application/jsonl`.
- [ ] Streaming `application/json-seq`.
- [ ] Multipart secuencial: `itemSchema` e `itemEncoding`.
- [ ] OAuth2 Device Authorization y `oauth2MetadataUrl`.
- [ ] `discriminator.defaultMapping`.
- [ ] Ejemplos estructurados con `dataValue` y `serializedValue`.
- [ ] Identidad de documento mediante `$self`.
- [ ] Metadatos: nombre de servidores, jerarquía `tags.parent` y `tags.kind`/`summary`.

**Aceptación:** fixtures oficiales de características 3.2 y al menos un test HTTP real por familia de transporte añadida; no declarar soporte 3.2 simplemente aceptando la versión.

## 9. [P2] Funcionalidades específicas de Swagger 2.0

- [ ] Múltiples `consumes` y `produces`.
- [ ] **Parcial — `collectionFormat`.** Query primitivo csv/multi/ssv/tsv/pipes y formData csv/multi publicados; otros formatos/ubicaciones pendientes (paquete 03).
- [x] **v0.13.0 — formData URL-encoded Swagger:** escalares/listas primitivas csv (default)/multi, required/validación, alias locales y overrides. Multipart/archivos y otros formatos quedan abiertos.
- [ ] **Parcial — Tipo file/transmisión:** subida multipart Swagger en v0.16.0, streams PSR en v0.18.0 y descarga PSR type:file octet-stream en v0.21.0. Quedan MIME/negociación, entradas no seekables y garantías generales de streaming.
- [ ] **Parcial — Arrays HTTP Swagger.** Query primitivo y formData csv/multi publicados; otras ubicaciones y formatos form pendientes.
- [x] **v0.5.0:** referencias locales con nombre `#/parameters/...` y `#/responses/...`, con alias y parámetros body.
- [ ] `securityDefinitions` OAuth2 y scopes.
- [ ] Validación y metadatos específicos del dialecto.
- [ ] Manejo completo de `host`, `basePath`, `schemes`.
- [ ] Tests con especificaciones Swagger 2.0 reales.

**Alcance:** no abrir un proyecto Swagger 1.x sin un consumidor concreto.

## 10. [P3] Relaciones, webhooks, callbacks y documentación

- [ ] `links` entre respuestas y operaciones.
- [ ] Expresiones runtime de OpenAPI para obtener parámetros de peticiones/respuestas anteriores.
- [ ] `callbacks`, sus expresiones y contratos de entrada.
- [ ] `webhooks` y generación de contratos/DTOs para peticiones entrantes.
- [ ] `components/examples`, `example` y `examples`.
- [ ] `externalDocs`, contacto, licencia, términos de servicio y metadatos de `info`.
- [ ] `tags` como navegación documental, manteniendo el namespace de las rutas.
- [ ] `deprecated` con anotaciones IDE/PHPDoc.
- [ ] Extensiones `x-*` bajo un mecanismo explícito y extensible.

**Arquitectura:** un webhook es una operación entrante; no mezclarlo con las fachadas de cliente HTTP saliente.

## 11. [P1 transversal] Robustez y herramientas

- [ ] Validación de documentos contra los esquemas oficiales de cada dialecto.
- [ ] Informe automático de cobertura por documento y número de operaciones bloqueadas.
- [ ] Pruebas diferenciales con clientes de referencia, validando URL, body, headers y responses.
- [ ] Fixtures públicos variados (Petstore, OpenAPI de GitHub y otras APIs reales).
- [ ] Fuzzing de parser, nombres PHP, URI encoding, JSON Pointer y grafos de referencias.
- [ ] Regresión de namespaces y firma pública del SDK entre generaciones.
- [ ] Detección de breaking changes entre dos versiones de OpenAPI.
- [ ] Pruebas de memoria, tiempo y escalabilidad con miles de rutas/modelos.
- [ ] Seguridad de acceso a referencias externas: SSRF, límites de profundidad, tamaño, tiempo y esquemas URI permitidos.
- [ ] Tests de formatos de archivo, respuestas inesperadas, timeouts y fallos de red.
- [ ] Política de reintentos/backoff para rate limits y errores transitorios (capacidad de runtime, no requerida por OAS).
- [ ] Documentar límites frente a OpenAPI Generator sin atribuir paridad funcional por la reducción de código.
- [ ] Fijar versión estable del runtime unificado, actualizar dependencias Composer y verificar instalación aislada de Casafari.
- [ ] Migración real de CasafariSDK con credenciales privadas, smoke de operaciones representativas, comparación de respuestas y rollback.

---

## 12. Paquetes de trabajo independientes y orden recomendado

| Orden | Paquete | Prioridad | Principalmente en | Criterio para cerrarlo |
| ---: | --- | --- | --- | --- |
| 01 | Contrato fiel y diagnóstico | P0 | Parser + contrato | No se pierde semántica HTTP sin error/informe. |
| 02 | Referencias generales | P0 | Resolver + parser | OpenAPI dividido y consolidado producen resultados equivalentes. |
| 03 | Parámetros HTTP completos | P0 | Contrato + runtime | Serialización exacta de estilos/ubicaciones. |
| 04 | Cuerpos y respuestas | P0 | Runtime + generador | Media types, archivos y múltiples respuestas verificadas. |
| 05 | JSON Schema 2020-12 | P1 | Parser + DTO/runtime | Fixtures de aceptación/rechazo y recursividad. |
| 06 | Seguridad/OAuth | P1 | Runtime + credenciales | Tokens y scopes reutilizables entre SDKs. |
| 07 | Servidores y metadatos | P1 | Parser + emisión | Overrides y documentación correctos. |
| 08 | OpenAPI 3.2/streaming | P2 | Parser + transporte | Fixtures 3.2 y streams reales. |
| 09 | Swagger 2.0 heredado | P2 | Adaptador de dialecto | APIs 2.0 reales sin conversión manual. |
| 10 | Webhooks/links/extensiones | P3 | Módulos optativos | Funciones adicionales sin engordar las fachadas. |

### Dependencias principales

`01 Contrato` → `02 Referencias` y `03 Parámetros` → `04 HTTP` → `08 Streaming`.

`02 Referencias` → `05 JSON Schema` → `10 Webhooks/links`.

`01 Contrato` → `06 Seguridad` y `07 Servidores`.

JSON Schema y seguridad pueden avanzar en paralelo una vez estabilizado el contrato.

### Reglas de entrega para cada paquete

Desarrollar en `release/0.2.0` sin crear ramas por funcionalidad. Mantenerla alineada con las integraciones y versiones publicadas; su nombre no limita los números de versión.

- [ ] Nueva funcionalidad en el contrato canónico cuando corresponda.
- [ ] Implementación en un solo lugar del runtime; evitar replicarla por operación.
- [ ] Fixtures positivos, negativos y casos límite.
- [ ] Prueba de compatibilidad con los SDKs ya generados (Casafari y FakeRESTApi).
- [ ] Comprobación de sintaxis, PHPStan, PSR-12, Composer y matriz PHP.
- [ ] Documentación del subconjunto soportado actualizada.
- [ ] Cambios de API pública y notas de versión revisados; integrar y publicar cada avance terminado. Preferir `main` sin PR cuando las protecciones lo permitan; si se requiere PR, completarlo como trámite técnico. Ver [AGENTS.md](AGENTS.md) y [RELEASING.md](RELEASING.md).
- [ ] Solo entonces marcar las casillas de este archivo.

## 13. Referencias de especificación

- [Swagger / OpenAPI 2.0](https://spec.openapis.org/oas/v2.0.html)
- [OpenAPI 3.0](https://spec.openapis.org/oas/v3.0.4.html)
- [OpenAPI 3.1](https://spec.openapis.org/oas/v3.1.2.html)
- [Índice oficial y últimas especificaciones OpenAPI](https://spec.openapis.org/oas/)
- [JSON Schema Draft 2020-12](https://json-schema.org/draft/2020-12)
- [API pública usada para smoke real: FakeRESTApi](https://fakerestapi.azurewebsites.net/swagger/v1/swagger.json)

## 14. Historial de seguimiento

| Fecha | Cambio | Referencia |
| --- | --- | --- |
| 2026-11-05 | Se guarda la auditoría inicial de funcionalidades ausentes/parciales y se divide en 10 paquetes. | Conversación de arquitectura SDK Generator |
| 2026-11-05 | Decisión de diseño: tipos unión nativos cuando sean fieles, validación declarativa OR/XOR/AND y `allOf` como intersección del mismo JSON (no colección). | Debate sobre anyOf/oneOf/allOf |
| 2026-11-05 | Publicados `sdk-runtime` v0.3.1 y `sdk-generator` v0.2.0, ambas CI verdes; permanece pendiente la prueba autenticada de Casafari. | [Runtime v0.3.1](https://github.com/dmorenof/sdk-runtime/releases/tag/v0.3.1), [Generator v0.2.0](https://github.com/dmorenof/sdk-generator/releases/tag/v0.2.0) |
| 2026-11-05 | Refinamiento de polimorfismo: representar `Pet -> Dog/Cat` como herencia PHP cuando `allOf` sea apto y usar `discriminator` para orientar la hidratación sin reemplazar la validación `oneOf`. | Debate sobre herencia y `discriminator` OpenAPI |
| 2026-11-05 | Desarrollo coordinado de discriminator, refinamientos de enums y evaluación de ramas originales; dependencia temporal de la rama remota del runtime. Sin publicación ni merge. | [Generator #19](https://github.com/dmorenof/sdk-generator/pull/19), [Runtime #6](https://github.com/dmorenof/sdk-runtime/pull/6) |
| 2026-11-05 | Entrega coordinada v0.3.0 del generador + v0.4.0 del runtime; dependencia estable `^0.4.0`, cierre del subconjunto de herencia/discriminadores y mantenimiento de los límites generales pendientes. | [Generator v0.3.0](https://github.com/dmorenof/sdk-generator/releases/tag/v0.3.0), [Runtime v0.4.0](https://github.com/dmorenof/sdk-runtime/releases/tag/v0.4.0) |

| 2026-11-05 | Publicados los avances de contrato/diagnóstico y enrutamiento: generador v0.4.0 + runtime v0.5.0, dependencia estable ^0.5.0. Se adopta integración/publicación de cada avance terminado; no finalizar con PRs en borrador. | [Generator v0.4.0](https://github.com/dmorenof/sdk-generator/releases/tag/v0.4.0), [Runtime v0.5.0](https://github.com/dmorenof/sdk-runtime/releases/tag/v0.5.0) |

| 2026-11-06 | Actualizada la rama existente `release/0.2.0` con la base publicada; v0.4.1 amplía auditoría anidada y localización de seguridad, manteniendo runtime ^0.5.0 y abiertos los límites generales de P0. | [Generator v0.4.1](https://github.com/dmorenof/sdk-generator/releases/tag/v0.4.1) |

| 2026-11-06 | Generador v0.5.0: referencias HTTP locales OpenAPI/Swagger, alias acotados, reglas de hermanos de Reference Objects, diagnóstico de origen y dependencias de aceptación; runtime estable ^0.5.0 sin cambios. | [Generator v0.5.0](https://github.com/dmorenof/sdk-generator/releases/tag/v0.5.0) |

| 2026-11-06 | Generador v0.6.0: Path Items y headers locales, hermanos independientes/solapamientos explícitos, procedencia por campo y aceptación con operaciones resueltas; runtime estable ^0.5.0 sin cambios. | [Generator v0.6.0](https://github.com/dmorenof/sdk-generator/releases/tag/v0.6.0) |

| 2026-11-06 | Generador v0.7.0: archivos locales JSON/YAML explícitos, bundling de fragmentos soportados, identidades/ciclos de schemas, huellas y diagnóstico de origen; límites generales y HTTP(S) siguen abiertos. Runtime estable ^0.5.0. | [Generator v0.7.0](https://github.com/dmorenof/sdk-generator/releases/tag/v0.7.0) |

| 2026-11-06 | Generador v0.8.0: referencias locales de securitySchemes, claves públicas de credenciales conservadas y pruebas AND/OR/Basic/alias; importaciones externas y OAuth siguen abiertos. Runtime ^0.5.0 sin cambios. | [Generator v0.8.0](https://github.com/dmorenof/sdk-generator/releases/tag/v0.8.0) |

| 2026-11-07 | Entrega coordinada: generador v0.9.0 + runtime v0.6.0, arrays query nativos con claves repetidas/CSV y validación tras mutaciones. Dependencia estable ^0.6.0; el resto del paquete 03 sigue parcial. | [Generator v0.9.0](https://github.com/dmorenof/sdk-generator/releases/tag/v0.9.0), [Runtime v0.6.0](https://github.com/dmorenof/sdk-runtime/releases/tag/v0.6.0) |
| 2026-11-08 | Entrega coordinada: generador v0.10.0 + runtime v0.7.0, arrays query spaceDelimited/pipeDelimited y Swagger ssv/tsv/pipes con rechazo de valores ambiguos. Dependencia estable ^0.7.0; el paquete 03 sigue parcial. | [Generator v0.10.0](https://github.com/dmorenof/sdk-generator/releases/tag/v0.10.0), [Runtime v0.7.0](https://github.com/dmorenof/sdk-runtime/releases/tag/v0.7.0) |
| 2026-11-08 | Entrega coordinada: generador v0.11.0 + runtime v0.8.0, deepObject query plano, DTOs/Maps y separación de query body frente a JSON body en GetParams/PostParams. Revisión de HTTP parcial; formularios pendientes. | [Generator v0.11.0](https://github.com/dmorenof/sdk-generator/releases/tag/v0.11.0), [Runtime v0.8.0](https://github.com/dmorenof/sdk-runtime/releases/tag/v0.8.0) |
| 2026-11-08 | Entrega coordinada: generador v0.12.0 + runtime v0.9.0, forms URL-encoded escalares/listas, encoding form/explode y revisión GetParams/PostParams con query/headers/body. Multipart y Swagger formData siguen pendientes. | [Generator v0.12.0](https://github.com/dmorenof/sdk-generator/releases/tag/v0.12.0), [Runtime v0.9.0](https://github.com/dmorenof/sdk-runtime/releases/tag/v0.9.0) |
| 2026-11-08 | Generador v0.13.0: formData Swagger URL-encoded normalizado al contrato común, CSV por defecto/multi, alias y overrides, GetParams/PostParams y diagnóstico de media/conflictos. Runtime ^0.9.0 sin cambios; multipart y otros formatos form pendientes. | [Generator v0.13.0](https://github.com/dmorenof/sdk-generator/releases/tag/v0.13.0) |
| 2026-11-08 | Entrega de simplificación: generador v0.14.0 + runtime v0.10.1, ejecutor/configuración comunes por herencia y Query::build/nativo para query/forms. Revisión de arquitectura y reglas de reutilización documentadas. | [Generator v0.14.0](https://github.com/dmorenof/sdk-generator/releases/tag/v0.14.0), [Runtime v0.10.1](https://github.com/dmorenof/sdk-runtime/releases/tag/v0.10.1) |
| 2026-11-08 | Generador v0.15.0 y runtime v0.11.1: multipart OpenAPI acotado mediante Guzzle, strings binary, listas repetidas, GetParams/PostParams, validación/mutaciones e instalación independiente. Streaming y encoding general siguen pendientes. | [Generator v0.15.0](https://github.com/dmorenof/sdk-generator/releases/tag/v0.15.0), [Runtime v0.11.1](https://github.com/dmorenof/sdk-runtime/releases/tag/v0.11.1) |
| 2026-11-08 | Generador v0.16.0: multipart Swagger type:file/formData normalizado al contrato común, multi explícito, consumes/alias/overrides y diagnóstico contextual; mismo SDK que OpenAPI y runtime estable ^0.11.1 sin cambios. | [Generator v0.16.0](https://github.com/dmorenof/sdk-generator/releases/tag/v0.16.0) |
| 2026-11-08 | Generador v0.17.0 + runtime v0.12.0: archivos multipart PSR con nombres/MIME y streams seekables, unión nativa string|UploadedFileInterface, validación y protección JSON. Sin wrappers propios; el transporte sigue buffered y streaming HTTP/memoria acotada queda pendiente. | [Generator v0.17.0](https://github.com/dmorenof/sdk-generator/releases/tag/v0.17.0), [Runtime v0.12.0](https://github.com/dmorenof/sdk-runtime/releases/tag/v0.12.0) |
| 2026-11-08 | Generador v0.18.0 + runtime v0.13.0: multipart en streaming por el adaptador PSR-18 existente, compatibilidad legacy, rebobinado y smoke 32 MiB bajo límite PHP 16 MiB. HTTP real con Guzzle/cURL y archivo íntegro; límites de clientes/middleware/respuestas explícitos. | [Generator v0.18.0](https://github.com/dmorenof/sdk-generator/releases/tag/v0.18.0), [Runtime v0.13.0](https://github.com/dmorenof/sdk-runtime/releases/tag/v0.13.0) |
| 2026-11-08 | Generador v0.19.0 + runtime v0.14.0: listas de archivos binarios OpenAPI con PHP/PSR nativos, partes repetidas, metadatos individuales, validación e herencia; colecciones solo JSON conservadas. Dos archivos de 16 MiB por request bajo límite PHP 16 MiB, SDK instalado y parser MIME independiente. | [Generator v0.19.0](https://github.com/dmorenof/sdk-generator/releases/tag/v0.19.0), [Runtime v0.14.0](https://github.com/dmorenof/sdk-runtime/releases/tag/v0.14.0) |
| 2026-11-08 | Generador v0.20.0 + runtime v0.15.0: descargas OpenAPI application/octet-stream como ResponseInterface PSR nativa; GET/POST con query, headers y DTO JSON, errores tipados y memoria medida en 32 MiB. | [Generator v0.20.0](https://github.com/dmorenof/sdk-generator/releases/tag/v0.20.0), [Runtime v0.15.0](https://github.com/dmorenof/sdk-runtime/releases/tag/v0.15.0) |
| 2026-11-09 | Generador v0.21.0: respuestas Swagger type:file/produces octet-stream adaptadas al contrato PSR existente, precedencia/alias, SDK equivalente y pruebas GET/POST de 32 MiB; runtime estable ^0.15.0 sin cambios. | [Generator v0.21.0](https://github.com/dmorenof/sdk-generator/releases/tag/v0.21.0) |
| 2026-11-09 | Generador v0.22.0 + runtime v0.16.0: selección de respuestas JSON por estado HTTP, firmas unión PHP, nulabilidad/ausencia por status y corrección de 200 vacío con 204 alternativo; herencia, DTOs y ejecución común reutilizados. | [Generator v0.22.0](https://github.com/dmorenof/sdk-generator/releases/tag/v0.22.0), [Runtime v0.16.0](https://github.com/dmorenof/sdk-runtime/releases/tag/v0.16.0) |
| 2026-11-09 | Generador v0.23.0 + runtime v0.17.1: arrays primitivos simple/CSV en path y headers, validación de placeholders y PathParams nativo; reuse de serialización/DTOs y contratos GetParams/PostParams, SDKs equivalentes y HTTP real. | [Generator v0.23.0](https://github.com/dmorenof/sdk-generator/releases/tag/v0.23.0), [Runtime v0.17.1](https://github.com/dmorenof/sdk-runtime/releases/tag/v0.17.1) |
| 2026-11-09 | Generador v0.24.0 + runtime v0.18.0: objetos planos form/query y simple/path-header con explode/defaults, DTOs/Maps compartidos y validación por ubicación; colisiones query, delimitadores header, mutaciones y contrato GET/POST comprobados. | [Generator v0.24.0](https://github.com/dmorenof/sdk-generator/releases/tag/v0.24.0), [Runtime v0.18.0](https://github.com/dmorenof/sdk-runtime/releases/tag/v0.18.0) |

### Paths label/matrix — v0.25.0 / runtime v0.19.0

- [x] Reutilizar DTO/Map y serializador común para escalares, listas y objetos planos, ambos explode y defaults.
- [x] Verificar ejemplos RFC 6570, claves numéricas, codificación, vacíos y mutaciones; conservar GetParams/PostParams, query body y payload JSON independientes.
- [x] Conservar segmentos punto con Guzzle/cURL y comprobar el target HTTP real.
- **Límites:** no cookies, nesting, propiedades nullable/sin tipo, composiciones generales ni intérprete de plantillas URI. Los límites de entregas anteriores se describen históricamente; label/matrix queda cubierto aquí para el subconjunto indicado.

### Cookies escalares y autenticación — v0.26.0 / runtime v0.20.0

- [x] Parámetros cookie escalares form en GetParams/PostParams, tipos/constraints y separación de query/header/body; omisión opcional, falsos/ceros/vacíos y percent encoding reutilizado.
- [x] API keys cookie con selección de seguridad existente; conservar base64 raw y rechazar octetos inválidos.
- [x] Unificar Cookie sin distinguir casing de cabecera, preservar casing del nombre de cookie y detectar conflictos entre configuración/operación/seguridad.
- [x] SDK independiente y smoke GET/POST HTTP real en CI y paquete publicado.
- **Límites:** sin cookie jar, persistencia ni lectura de Set-Cookie; arrays/objetos y content no cubiertos. No se traducen silenciosamente los delimitadores incompatibles advertidos por OpenAPI 3.1.2, apéndice D. [Detalles](docs/COOKIES.md).

### Herencia y duplicados de parámetros — v0.26.1

- [x] Conservar reemplazo completo entre niveles e identidad por nombre/ubicación; no arrastrar tipos, constraints, required o estilos de la declaración sustituida. Una lista vacía no elimina parámetros heredados.
- [x] Rechazar duplicados dentro del mismo nivel incluso si un override oculta la lista del Path Item; soportar referencias/alias con diagnósticos precisos. Los mapas de declaraciones reutilizables no se confunden con listas de uso.
- [x] Verificar GetParams/PostParams, tipos diferentes GET/POST, header/query homónimos, cookie heredada, query body y JSON separados, mutaciones y HTTP real desde SDK independiente.
- **Límites:** no amplía schemas/estilos ni introduce defaults o validación general del documento; runtime estable ^0.20.0 sin cambios. [Detalle](docs/PARAMETER_OVERRIDES.md).

### Query reservada — v0.27.0 / runtime v0.21.0

- [x] Metadatos declarativos por parámetro y ampliación del serializador existente; sin motor URI ni ejecución generada duplicada. Flags ausentes/false conservan salida anterior.
- [x] Escalares/listas y objetos planos form/deepObject, Unicode/percent, falsos/ceros/vacíos, ejemplos OpenAPI y restricciones de delimitadores. Nombres y credenciales de seguridad mantienen codificación habitual.
- [x] GetParams/PostParams, query body separado de JSON, cookies/cabeceras intactas, mutaciones, ambos transportes y HTTP real en SDK independiente/publicado.
- **Límites:** la aplicación debe preparar datos con delimitadores reservados según OpenAPI; se rechazan `#[]&=+` crudos y delimitadores de formato ambiguos. No cubre Swagger, otras ubicaciones, content, schemas nuevos ni encoding avanzado de forms. [Contrato](docs/RESERVED_QUERY.md).

### Valores vacíos — v0.28.0 / runtime estable v0.21.0 sin cambios

- [x] Aceptar allowEmptyValue booleano en query OpenAPI/Swagger y formData Swagger soportados, reutilizando DTOs y serializers existentes. No añadir metadatos, wrappers ni uniones artificiales al runtime.
- [x] Política de esquema autoritativo: vacío explícito distinto de ausente/null, falsos/ceros y listas vacías/elementos vacíos; required, minLength y tipos no se relajan.
- [x] GetParams/PostParams, query body separado del payload, forms URL-encoded frente a JSON null, mutaciones y ocho peticiones HTTP reales por pareja de SDKs independientes/publicados.
- **Límites:** sin bare-key API nueva, sin conversión de tipos a string vacío ni ampliación de estilos/schemas. El flag no aplica a cabeceras/cookies/path ni sustituye las reglas de campos del body OpenAPI. [Contrato](docs/EMPTY_VALUES.md).

### Content de parámetros — v0.29.0 / runtime v0.22.1

- [x] JSON path/query/header y text/plain path/query/header/cookie; DTOs/TypedArrays/Maps existentes, dirección request/readOnly, nombres JSON y claves numéricas.
- [x] Una representación, sin mezclar schema/style ni reservar caracteres de contenido; serializar una vez y codificar URI después. Cabeceras/cookies mantienen bytes propios.
- [x] GetParams/PostParams/PathParams, query body y payload JSON separados, validación lógica antes de encoding, colecciones vacías y mutaciones.
- [x] Corregir colecciones tipadas con índices borrados para conservar JSON array en raíz y anidado; no modificar claves Map.
- **Límites:** otros MIME/variantes, schema aliases primitivas, nullable raíz, composiciones generales y JSON cookie pendientes. No hay motor de codecs ni wrappers nuevos. [Detalle](docs/PARAMETER_CONTENT.md).
