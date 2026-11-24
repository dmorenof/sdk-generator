# Pendientes — sdk-generator

> **Estado (2026-11-24):** publicada la **1.14.0** (hooks en las clases de parámetros, clases de error compartidas, a la par del runtime 1.12.0). En la rama de trabajo, sin publicar, está la **1.15.0** (cobertura de especificaciones reales: `type` repetido junto a un `$ref` y nombres de esquema que PHP reserva). `VERSION` sigue en 1.14.0 hasta que el titular confirme la publicación.
>
> **Qué es este fichero:** lo que **falta**. Lo hecho y publicado está en [CHANGELOG.md](CHANGELOG.md); lo soportado, en [docs/SUPPORTED_SCOPE.md](docs/SUPPORTED_SCOPE.md). La auditoría larga de noviembre, ya desfasada, se conserva como [archivo histórico](docs/HISTORICO_AUDITORIA_PENDIENTES.md).

## Regla de publicación

Una versión se publica cuando el trabajo está **terminado y probado**, y solo cuando el titular lo confirma. No se suben versiones intermedias. Un cambio que toca lo que usa el código generado se prueba contra la CI de este repositorio con la rama del runtime antes de publicar el runtime (D23 del runtime).

## Probado contra servidores reales

El workflow `Live public APIs` genera un SDK desde la especificación **sin modificar** de cada API pública, lo analiza con PHPStan al máximo y llama al servidor real. Hoy: Swagger Petstore v3 (OpenAPI 3.0), Swagger Petstore v2 (Swagger 2.0), Frankfurter (OpenAPI 3.1) y httpbin (Swagger 2.0). Cada API que se añadió encontró algo que arreglar. El contrato real de Casafari (OpenAPI 3.1) se genera estrictamente y se mide contra OpenAPI Generator con el workflow `audit-casafari` del runtime.

## Pendiente

Los puntos que el propio repositorio documenta como límites actuales ([docs/SUPPORTED_SCOPE.md](docs/SUPPORTED_SCOPE.md), «Remaining boundaries»):

- [ ] **Referencias:** remotas o dinámicas, ubicaciones arbitrarias de esquema, `$id`/`$anchor`/referencias dinámicas y hermanos de validación generales de un `$ref`. (Se acepta un `default` junto a un `allOf` de una sola rama y un `type` que repite el del esquema referenciado junto a un `$ref`; otras aserciones —`format`, `nullable`, `enum`, un `type` distinto— se rechazan.)
- [ ] **Esquemas:** uniones nativas de objeto/array, `allOf` general que no sea de objetos, `not` y vocabularios condicionales generales, `const` complejo.
- [ ] **Parámetros HTTP:** estructuras anidadas o sin restringir, cookies estructuradas/JSON, cabeceras avanzadas de multipart y comportamiento de `explode`, entradas no seekables.
- [ ] **Medios:** comodines MIME, negociación entre representaciones incompatibles, modelos XML/CSV estructurados, transcodificación de charsets, errores binarios sin buffer completo.
- [ ] **Cabeceras de respuesta:** los esquemas declarados se conservan pero no se validan ni se exponen en un envoltorio de respuesta tipado.
- [ ] **Servidores:** variables con nombre y selección dinámica de servidor más allá de lo que expone el runtime.

Y lo que se sabe de la última sesión:
- [ ] **Listas de parámetros sin hook:** PHP no permite hook en una propiedad que es un array nativo, así que las reglas de una lista de parámetros (`minItems`) no se comprueban al asignar.
- [ ] **Nombres de las clases de error de varios SDKs ya generados:** quien regenere con la 1.14.0 verá las clases de error idénticas compartidas (`Responses/Errors/Status401Response`); el cambio de nombre está documentado en el CHANGELOG.

## Cobertura medida con especificaciones oficiales

`--inspect` el 2026-11-24, con este cambio. Solo dice si el generador **acepta** el contrato; no que el SDK generado funcione.

| Especificación | Resultado | Bloqueo |
| --- | --- | --- |
| Casafari | genera | — |
| SendGrid (46, una por producto, OpenAPI 3.1) | 36 supported, 1 partial, 9 unsupported: 37 se generan (antes 13) | ver abajo |
| DeepL | no genera | `allOf` con una rama no soportada (`IgnoreTagCommaSeparatedList`); multipart con codificación por propiedad |
| Sendbird | no genera | «Composition siblings» (`sendAMessage_request_push_message_template`); un cuerpo sin serializador |
| Stripe | no genera (1.292 errores) | «Composition siblings» (`account.business_profile`); 315 cuerpos sin serializador; 301 parámetros estructurados |
| uDA | no genera | defecto de su especificación: parámetros de ruta sin declarar |

Los 9 de SendGrid que siguen: `format`, `nullable` o `summary` junto a un `$ref`; la palabra clave `example-1`; un patrón regex no válido (`mc_segments`); `discriminator` en `logs`; `$ref` dentro de `patternProperties` (`mc_contacts`); un media type sin esquema (`seq`).

- [ ] Reproducir cada bloqueo con una especificación mínima antes de arreglarlo.

## Historial

| Fecha | Cambio |
| --- | --- |
| 2026-11-24 | Se acepta un `type` repetido junto a un `$ref` y se renombran con sufijo las clases cuyo nombre PHP reserva (SendGrid: 13 → 37 se generan) y se anota la cobertura medida con especificaciones oficiales. |
| 2026-11-23 | Se archiva la auditoría de noviembre (desfasada) y se reescribe esta hoja de ruta: solo lo pendiente, más la regla de publicación. |
