# Pendientes — sdk-generator

> **Estado (2026-11-25):** publicada la **1.15.4** (los elementos de las colecciones y mapas generados tienen tipo para el análisis estático; exige el runtime 1.14.1). Antes la **1.15.3** (corrige `components.pathItems` en OpenAPI 3.2; las reparaciones del documento pasan por una sola clase, sin cambiar el código generado; pruebas por dialecto; a la par del runtime 1.14.0). Antes la **1.15.2** (formato del código generado sobre la 1.15.1: arrays indentados y sin líneas en blanco dentro de llaves; la 1.15.1 arregló rutas demasiado largas en Stripe) (cobertura de especificaciones reales: formularios anidados, modo de reparación con aviso, Stripe, uDA, Sendbird, DeepL y otras 10 integraciones; a la par del runtime 1.13.0). La 1.14.0 fue la anterior (hooks en las clases de parámetros, clases de error compartidas).
>
> **Qué es este fichero:** lo que **falta**. Lo hecho y publicado está en [CHANGELOG.md](CHANGELOG.md); lo soportado, en [docs/SUPPORTED_SCOPE.md](docs/SUPPORTED_SCOPE.md). La auditoría larga de noviembre, ya desfasada, se conserva como [archivo histórico](docs/HISTORICO_AUDITORIA_PENDIENTES.md).

## Regla de publicación

Una versión se publica cuando el trabajo está **terminado y probado**, y solo cuando el titular lo confirma. No se suben versiones intermedias. Un cambio que toca lo que usa el código generado se prueba contra la CI de este repositorio con la rama del runtime antes de publicar el runtime (D23 del runtime).

## Probado contra servidores reales

El workflow `Live public APIs` genera un SDK desde la especificación **sin modificar** de cada API pública, lo analiza con PHPStan al máximo y llama al servidor real. Hoy: Swagger Petstore v3 (OpenAPI 3.0), Swagger Petstore v2 (Swagger 2.0), Frankfurter (OpenAPI 3.1) y httpbin (Swagger 2.0). Cada API que se añadió encontró algo que arreglar. El contrato real de Casafari (OpenAPI 3.1) se genera estrictamente y se mide contra OpenAPI Generator con el workflow `audit-casafari` del runtime.

## Pendiente

**Objetivo: cobertura completa de OpenAPI.** Lo que sigue es lo que falta para llegar, no un alcance elegido. Los puntos que el propio repositorio documenta como límites actuales ([docs/SUPPORTED_SCOPE.md](docs/SUPPORTED_SCOPE.md), «Remaining boundaries»):

- [ ] **Referencias:** remotas o dinámicas, ubicaciones arbitrarias de esquema, `$id`/`$anchor`/referencias dinámicas y hermanos de validación generales de un `$ref`. (Se acepta un `default` junto a un `allOf` de una sola rama y un `type` que repite el del esquema referenciado junto a un `$ref`; otras aserciones —`format`, `nullable`, `enum`, un `type` distinto— se rechazan.)
- [ ] **Esquemas:** uniones nativas de objeto/array, `allOf` general que no sea de objetos, `not` y vocabularios condicionales generales, `const` complejo.
- [ ] **Parámetros HTTP:** estructuras anidadas o sin restringir, cookies estructuradas/JSON, cabeceras avanzadas de multipart y comportamiento de `explode`, entradas no seekables.
- [ ] **Medios:** comodines MIME, negociación entre representaciones incompatibles, modelos XML/CSV estructurados, transcodificación de charsets, errores binarios sin buffer completo.
- [ ] **Formularios y multipart:** el cuerpo ha de ser un objeto plano; si no dice nada de `additionalProperties` se construye con sus campos declarados, y si lo declara abierto (`true` o un esquema) se rechaza ([docs/FORMS.md](docs/FORMS.md)).
- [ ] **Cabeceras de respuesta:** los esquemas declarados se conservan pero no se validan ni se exponen en un envoltorio de respuesta tipado.
- [ ] **Servidores:** variables con nombre y selección dinámica de servidor más allá de lo que expone el runtime.

Y lo que se sabe de la última sesión:
- [ ] **Listas de parámetros sin hook:** PHP no permite hook en una propiedad que es un array nativo, así que las reglas de una lista de parámetros (`minItems`) no se comprueban al asignar.
- [ ] **Construir un modelo con argumentos con nombre:** hoy hay que crear el objeto y asignar propiedad a propiedad (`$params = new PokemonFilters(); $params->limit = 20; Pokemon::get($params);`), porque el constructor de `DTO` es `__construct(?object $json = null)` y sirve para hidratar una respuesta. Lo deseable es `Pokemon::get(new PokemonFilters(limit: 20))`. Dos caminos: (a) constructor con argumentos con nombre, que ha de convivir con la hidratación desde JSON y obliga a revisar todos los puntos donde el runtime construye un modelo; (b) un método estático aditivo, `PokemonFilters::of(limit: 20)`, que no toca el constructor ni el hidratador y no rompe los SDK ya publicados. Cualquiera exige una versión nueva del generador y, para que lleguen a los 18 SDK, regenerarlos y publicarlos de nuevo. Antes de decidir: comprobar cómo afecta a las reglas de validación (los hooks `set` y `REQUIRED_FIELDS`) y a PHPStan max. Propuesto el 2026-11-26.
- [ ] **Nombres de las clases de error de varios SDKs ya generados:** quien regenere con la 1.14.0 verá las clases de error idénticas compartidas (`Responses/Errors/Status401Response`); el cambio de nombre está documentado en el CHANGELOG.

## Cobertura medida con especificaciones oficiales

`--inspect` el 2026-11-24, con este cambio. Solo dice si el generador **acepta** el contrato; no que el SDK generado funcione.

| Especificación | Resultado | Bloqueo |
| --- | --- | --- |
| Casafari | genera | — |
| SendGrid (46 documentos, uno por producto, OpenAPI 3.1) | **los 46 se generan**, y fusionados en uno (240 rutas, 391 operaciones, 2.012 ficheros, estricto, PHPStan max limpio) se publican como `sendgrid-sdk`; los 13 eventos de `logs` se hidratan por el `OneOf` | la fusión se hizo con un script a medida que no está en este repositorio: el contrato fusionado sí (`sendgrid-sdk/contract/sendgrid.json`) |
| DeepL | **genera** (574 ficheros, estricto) | — |
| Sendbird | **genera** tal como se publica (457 ficheros), con 3 reparaciones avisadas (dos `type` junto a `oneOf`, un parámetro de ruta sin marcador) | — |
| Stripe | **genera** (13.564 ficheros, estricto; PHPStan max limpio) y está publicado (`stripe-sdk` 0.1.0) | requiere runtime 1.13.0; falta probarlo contra su servidor real |
| Mailjet, GoCardless, NeutrinoAPI, Marketo, Infobip, Floorfy | **generan** (ver el CHANGELOG): carpetas `mailjet-sdk`, `gocardless-sdk`, `neutrinoapi-sdk`, `marketo-sdk`, `infobip-sdk`, `floorfy-sdk`; PHPStan max limpio | falta probarlos contra el servicio real (necesitan cuenta) |
| Aplazame (reconstruido de su documentación, leída con el navegador) y Restb.ai (solo lo que muestra el código de su SDK público: su documentación exige login) | **generan** (`aplazame-sdk`, `restb-sdk`); PHPStan max limpio | el contrato de Aplazame deja abiertos la respuesta de `POST /checkout` y los artículos de campaña; el de Restb.ai no conoce los modelos ni sus respuestas |
| Redsys (reconstruido de sus documentos públicos) | **genera** (50 ficheros; PHPStan max limpio); es solo el contrato REST sin firma: la firma HMAC y la verificación están en `redsys-sdk`, hecho a mano | no se publica como paquete aparte |
| Trustpilot (apis.io, 2 definiciones, OpenAPI 3.2) | **genera** (66 ficheros; PHPStan max limpio); `trustpilot-sdk/` | la especificación no es oficial: falta contrastarla y probarla con una clave |
| Catastro (16 operaciones, escrita a mano) | **genera** (74 ficheros; PHPStan max limpio; probado contra el servicio real); `catastro-sdk/` | solo 3 respuestas están tipadas: las demás son JSON sin tipo |
| uDA | **genera** tal como se publica (61 ficheros), con 24 reparaciones avisadas | el marcador `{url_path}` conserva sus barras (1.15.0: un marcador sin declarar cuyo nombre acaba en `path` es un resto de ruta); `uda-sdk` está publicado |

- [ ] Reproducir cada bloqueo con una especificación mínima antes de arreglarlo.
- [x] **Stripe:** genera (supported, strictly accepted) desde su especificación publicada y está publicado como `stripe-sdk`; necesita el runtime 1.13.0 (serialización anidada). Falta probarlo contra su servidor real (clave de prueba del titular).
- [x] **uDA:** `{url_path}` es un resto de ruta con barras y se envía sin codificarlas (`pathAllowReserved`, runtime 1.13.0). Queda por decidir si un marcador pegado a texto (`asset{url_path}`) las admite también.

## Historial

| Fecha | Cambio |
| --- | --- |
| 2026-11-25 | Publicadas 15 integraciones (`*-sdk`, 0.1.0 cada una), incluido SendGrid agrupado en un solo `sendgrid-sdk`; reparaciones del documento centralizadas en `RepairPolicy`. Se ponen al día las tablas de cobertura. |
| 2026-11-24 | Se acepta un `type` repetido junto a un `$ref` y se renombran con sufijo las clases cuyo nombre PHP reserva; DeepL y SendGrid (13 → 46 de 46) se generan, Sendbird con su especificación corregida. Se anota la cobertura medida con especificaciones oficiales. |
| 2026-11-23 | Se archiva la auditoría de noviembre (desfasada) y se reescribe esta hoja de ruta: solo lo pendiente, más la regla de publicación. |
