# Pendientes — compatibilidad Swagger / OpenAPI del SDK Generator

> **Estado:** auditoría y hoja de ruta; NO equivale a funcionalidades implementadas.
>
> **Auditoría inicial:** 2026-11-05 · **Línea base estable:** `dmorenof/sdk-generator` **v0.2.0** y `dmorenof/sdk-runtime` **v0.3.1** (ramas de desarrollo integradas; ver historial).
>
> **Criterio de actualización:** marcar las casillas solo tras código, pruebas de regresión y aceptación en CI. Registrar el PR y la versión de cierre. Volver a contrastar cada apartado con la especificación oficial correspondiente.
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
- [x] **Integración y publicación estables:** `sdk-runtime` **v0.3.1** y `sdk-generator` **v0.2.0**, con CI y pruebas de aceptación en verde.
- [ ] **Migración de CasafariSDK:** probar operaciones autenticadas reales, revisar compatibilidad de uso/middleware, comparar datos y preparar rollback antes del cambio en producción.

### Versiones de entrada

| Versión | Estado | Detalle |
| --- | --- | --- |
| Swagger 2.0 | Parcial | Reconocida; faltan componentes y parámetros propios de 2.0. |
| OpenAPI 3.0.x | Parcial | Reconocida; no todas sus estructuras HTTP están implementadas. |
| OpenAPI 3.1.x | Parcial | Reconocida; falta buena parte de JSON Schema 2020-12. |
| OpenAPI 3.2.x | Pendiente | Requiere interpretar sus novedades, no solo ampliar la expresión regular. |

---

## 1. [P0] Fidelidad del contrato intermedio y diagnóstico

**Objetivo:** el normalizador nunca debe perder silenciosamente información que cambia la petición o la respuesta HTTP.

- [ ] Conservar la información completa de serialización de parámetros: `style`, `explode`, `allowReserved`, `allowEmptyValue`, `content`, `collectionFormat`.
- [ ] Conservar por operación los servidores aplicables, media types y metadatos de request/response.
- [ ] Conservar encabezados de respuesta, cookies, códigos y esquemas de respuesta diferenciados.
- [ ] Separar **palabras clave que afectan a ejecución/validación** de simples anotaciones.
- [ ] Auditar las propiedades de OpenAPI ignoradas actualmente a nivel de documento, ruta y operación.
- [ ] Modo de compatibilidad por documento: `soportado` / `parcial` / `no soportado`, con ruta JSON Pointer y motivo.
- [ ] Modo estricto: error ante la pérdida de semántica HTTP relevante.
- [ ] Modo de inspección/dry-run sin generar archivos.
- [ ] Diagnósticos deterministas con nombre de archivo, operación, JSON Pointer y cadena de referencias.

**Aceptación:** ninguna opción que cambie URL, método, headers, body, autenticación o interpretación de respuesta puede desaparecer del contrato intermedio sin una advertencia o error explícito.

## 2. [P0] Resolución general de referencias

- [ ] JSON Pointer completo, incluyendo escape `~0`, `~1` y URIs relativas.
- [ ] Referencias a `components/parameters`, `responses`, `requestBodies`, `headers`.
- [ ] Referencias a `components/examples`, `links`, `callbacks`, `pathItems` y `securitySchemes` cuando proceda.
- [ ] Referencias a `Path Item` completos y su reutilización.
- [ ] Referencias a archivos JSON/YAML externos, relativos al documento raíz.
- [ ] Referencias HTTP(S) externas con caché, timeouts, límites de tamaño y restricciones de acceso.
- [ ] Resolución de ciclos, incluidos modelos recursivos y dependencias entre documentos.
- [ ] Referencias válidas entre distintos espacios de nombres de documentos.
- [ ] Soporte JSON Schema: `$id`, `$anchor`, `$dynamicAnchor`, `$dynamicRef` y `$defs`.
- [ ] `jsonSchemaDialect`, `$schema` y dialectos diferentes según el documento/recurso.
- [ ] Reglas de hermanos de `$ref` diferentes entre OAS 3.0 y 3.1.
- [ ] Validación de referencias a elementos inexistentes con errores accionables.

**Aceptación:** un OpenAPI dividido en varios archivos debe generar un SDK equivalente al de su versión consolidada; casos cíclicos válidos deben resolverse de forma segura.

## 3. [P0] Serialización de parámetros HTTP

**Situación actual:** el runtime acepta principalmente parámetros escalares; hay que conservar su estilo en el contrato y serializarlos conforme a cada ubicación.

### OpenAPI 3.x

- [ ] Arrays y objetos en `query`, `path`, `header` y `cookie`.
- [ ] `style: form` y `explode: true/false`.
- [ ] `style: simple` para path y headers.
- [ ] `style: deepObject` para query.
- [ ] `style: spaceDelimited` y `pipeDelimited`.
- [ ] `style: label` y `matrix`.
- [ ] `allowReserved` con codificación correcta de caracteres reservados.
- [ ] `allowEmptyValue` distinguiendo ausente, vacío y null.
- [ ] Parámetros definidos con `content` en lugar de `schema`.
- [ ] Cookies como parámetros y coexistencia con otras cookies.
- [ ] Validación de obligatoriedad de todos los placeholders de path.
- [ ] Reglas de sobreescritura de parámetros en Path Item vs operación.
- [ ] Parámetros `default`, `example`, `examples` y `deprecated` cuando tengan efecto práctico.
- [ ] Cobertura de codificación Unicode, espacios, comas, corchetes y delimitadores.

### Swagger 2.0

- [ ] `collectionFormat: csv`, `ssv`, `tsv`, `pipes` y `multi`.
- [ ] Arrays en parámetros form/query/header/path dentro de las reglas de Swagger 2.0.

**Aceptación:** tests con URLs y headers exactos para cada combinación `in/style/explode`. Ejemplo: `ids=1&ids=2` frente a `ids=1,2`.

## 4. [P0] Requests, media types y respuestas

### Peticiones

- [ ] `application/x-www-form-urlencoded`.
- [ ] `multipart/form-data`, incluido `encoding` por propiedad y mezcla de texto/archivos.
- [ ] Subida de archivos binarios; soporte de streams y manejo de memoria controlado.
- [ ] Body directo de array, mapa, escalar o binario sin envolverlo necesariamente en un DTO de objeto.
- [ ] Selección explícita de `Content-Type` entre representaciones alternativas.
- [ ] Múltiples representaciones JSON con esquemas distintos.
- [ ] Referencias a `components/requestBodies`.
- [ ] Tratar correctamente cuerpos ausentes, `null`, vacíos y opcionales.

### Respuestas

- [ ] Varios códigos `2xx` con cuerpos y tipos diferentes en la misma operación.
- [ ] Selección del DTO de respuesta mediante el código HTTP real.
- [ ] Contrato público de tipos unión PHP cuando haya varias respuestas de éxito compatibles.
- [ ] Respuestas sin contenido: `204`, `205`, `HEAD`, otras combinaciones válidas.
- [ ] Cabeceras de respuesta declaradas en `responses[*].headers`.
- [ ] `Set-Cookie` y acceso tipado a metadatos HTTP.
- [ ] Rangos de status `1XX`, `2XX`, `3XX`, `4XX`, `5XX` y `default`.
- [ ] Política configurable de redirecciones `3xx`.
- [ ] Errores con body escalar, array, mapa, no JSON y con media types alternativos.
- [ ] Descarga de archivos (bytes/stream) y respuestas `application/octet-stream`.
- [ ] Respuestas texto, CSV, XML y otras representaciones cuando sea útil.
- [ ] Negociación `Accept` y uso de `application/*+json` con diversos esquemas.
- [ ] Referencias a `components/responses` y `components/headers`.

**Decisión de diseño pendiente:** para respuestas `200: User` y `201: CreatedUser`, estudiar `User|CreatedUser` como retorno, dejando la selección del hidratador al runtime.

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
- La versión estable v0.2.0 **aplana** `allOf` compatible, genera modelos como `final class ... extends DTO` y **rechaza `discriminator`**.
- En desarrollo ([generador PR #19](https://github.com/dmorenof/sdk-generator/pull/19) + [runtime PR #6](https://github.com/dmorenof/sdk-runtime/pull/6), ambos en `feature/native-model-inheritance`): herencia PHP de un único padre compatible, metadatos completos y refinamientos por intersección de enums `string`/`integer` sin redeclarar la propiedad heredada. Parsing de `discriminator.propertyName` y mapping local explícito/implícito; hidratación compartida en propiedades, arrays, mapas y `DTO::fromValue()`. Los envoltorios evalúan las ramas originales antes de hidratar y vuelven a comprobar cardinalidad/identidad al serializar la variante seleccionada. Las familias discriminadas conservan propiedades adicionales permitidas para no perder JSON. Dependencia temporal explícita `dev-feature/native-model-inheritance` del runtime; no publicar ni integrar por separado.
- Validación cloud ampliada: PHP 8.3.36, 181 pruebas / 1.916 aserciones del generador (28 pruebas de discriminadores); SDKs accounts, catalog, casafari y Pet/Dog/Cat instalados y sin errores PHPStan max. Runtime: 50 pruebas / 101 aserciones y PHPStan max. CI remota verde para el generador `7b0ab1d` (cuatro workflows) y el runtime `b27d9e8` (PHP package CI); incluye PHP 8.3/8.4/8.5, dependencias mínimas y auditoría. Casos añadidos: HTTP real simulado, recursividad, discriminador con enum heredado, renombrado JSON, required direccional y mutación que introduce ambigüedad. Las casillas siguen abiertas hasta aceptación completa y entrega coordinada.
- Límites actuales: mapping a referencias locales/nombres de componentes; ramas discriminadas con nombre y herencia compatible para bases objeto. `new Pet(...)` conserva la clase solicitada; usar `Pet::fromValue()` para despachar. Refinamientos distintos de enums, `const`, `not`, uniones nativas generales, composición general de `allOf` y schemas externos siguen pendientes. Varios matches de `anyOf` sin selección son vistas del snapshot original, no una API para fusionar mutaciones.
- Al introducir herencia, combinar correctamente `REQUIRED_FIELDS`, `VALIDATION`, `PROPERTY_MAP`, `READ_ONLY_FIELDS`, `WRITE_ONLY_FIELDS` y demás metadatos de padres/hijos; comprobar reflexión, serialización e hidratación de propiedades heredadas.

**Tareas del refinamiento:**

- [ ] Reconocer y preservar `discriminator.propertyName` y `mapping`, con mapeo explícito/implícito y diagnóstico si el valor no identifica una rama.
- [ ] Detectar patrón `allOf` de un único padre `$ref` + propiedades propias, con ausencia de conflictos de validación, y generar herencia nativa cuando sea fiel.
- [ ] Permitir extender modelos base (ajustar `final` únicamente en las clases necesarias) sin romper usos independientes de `Pet`.
- [ ] Fusionar o aplicar de forma incremental las reglas declarativas de toda la cadena de clases.
- [ ] Hidratar `oneOf`/`anyOf` orientándose por el discriminador y validar la cardinalidad real de los esquemas originales.
- [ ] Incluir tests de `Pet`, `Dog`, `Cat`: selección de clase, herencia, serialización, caso ambiguo y `mapping` con nombres diferentes.
- [ ] Mantener fallback a DTO aplanado/representaciones auxiliares para composiciones sin herencia PHP válida.

Referencias: [OAS 3.1.2, Composition and Inheritance / Discriminator](https://spec.openapis.org/oas/v3.1.2.html#composition-and-inheritance-polymorphism); [JSON Schema: Combining Schemas](https://json-schema.org/understanding-json-schema/reference/combining).

**Pruebas imprescindibles:**

- [ ] `anyOf` con 0, 1 y 2+ ramas válidas; distinguir validez de la decisión de hidratación.
- [ ] `oneOf` con 0, 1 y 2+ coincidencias, incluido el caso de propiedades adicionales toleradas por más de un esquema.
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
- [ ] Validación exacta de `oneOf` cuando varias ramas son compatibles.
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
- [ ] API key ubicada en cookies.
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
- [ ] Sobreescrituras de servidor por Path Item y operación.
- [ ] Manejo de operaciones con varios servidores disponibles.
- [ ] Soporte completo de referencias a `Path Item`.
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
- [ ] `collectionFormat` (vinculado al paquete de parámetros).
- [ ] Parámetros `formData`.
- [ ] Tipo `file` y transmisión de archivos.
- [ ] Arrays en parámetros HTTP de Swagger 2.0.
- [ ] Referencias `#/parameters/...` y `#/responses/...`.
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

- [ ] Nueva funcionalidad en el contrato canónico cuando corresponda.
- [ ] Implementación en un solo lugar del runtime; evitar replicarla por operación.
- [ ] Fixtures positivos, negativos y casos límite.
- [ ] Prueba de compatibilidad con los SDKs ya generados (Casafari y FakeRESTApi).
- [ ] Comprobación de sintaxis, PHPStan, PSR-12, Composer y matriz PHP.
- [ ] Documentación del subconjunto soportado actualizada.
- [ ] Cambios de API pública revisados y notas de versión; para este trabajo acumular el generador en PR #19 y coordinar runtime #6 en una única entrega (sin PR por funcionalidad).
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
