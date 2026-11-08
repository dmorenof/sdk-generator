# Diseño y aceptación de los SDK generados

El objetivo es automatizar la forma de construir integraciones PHP del autor: clientes entendibles, objetos tipados y comportamiento común heredado. La compatibilidad con OpenAPI permite describir la API, pero generar todos sus archivos no demuestra por sí solo que el SDK sea usable ni eficiente.

## Responsabilidades

- `Dmorenof\SdkRuntime\ApiClient` mantiene clientes independientes que comparten explícitamente transporte, credenciales y middleware entre sus recursos.
- `Client` hereda del runtime el mapeo de argumentos, preparación y ejecución HTTP. `Configuration` hereda las opciones comunes y declara únicamente el baseUrl de la API. Las clases `Resources` contienen las operaciones ejecutables tipadas. Las fachadas estáticas reflejan exactamente los namespaces de las rutas y delegan en el cliente configurado.
- `Dmorenof\SdkRuntime\DTO` implementa conversión, validación y serialización reutilizables. El contexto HTTP determina la dirección de validación, conservando una sola clase base para todos los datos. La hidratación de respuestas, mapas y uniones reutiliza esa conversión.
- Las clases generadas de modelo declaran campos y restricciones. Las colecciones heredan de `Dmorenof\SdkRuntime\TypedArray`, apoyado en la biblioteca TypedArray.

La colección se reutiliza cuando coincide el tipo PHP de elemento, su nullabilidad y sus restricciones. Las colecciones de primitivas sin restricciones tienen nombres comunes como `StringCollection`. No se fusionan enums de dominios distintos solo porque coincidan sus valores. Una unión `anyOf` de enums finitos con el mismo tipo base se representa como un enum con la unión de valores; `oneOf` conserva su comprobación de coincidencia única.

Los identificadores con puntos mantienen su jerarquía. En identificadores aplanados, `__` separa carpetas y un sufijo numérico final se incorpora al nombre de clase: `AlertsApisrc__structures__schemas__ranges__IntRangeSchema__1` se convierte en `DTOs\AlertsApi\Ranges\IntRangeSchema1`. Las variantes `1`, `2`, etc. mantienen tipos separados aunque compartan título o restricciones. Los `$ref` siguen usando el identificador original del contrato; los nombres de campos JSON no cambian.

Para esta convención codificada se omiten los segmentos técnicos `src`, `structures`, `schema` y `schemas`. Si la primera carpeta concatena un prefijo de proveedor con `src` o `api`, se separa esa raíz únicamente cuando el siguiente segmento es `structures`, `schema` o `schemas`; una raíz `api` aislada se omite solo antes de `schema`/`schemas`. Las demás carpetas se conservan y empiezan con mayúscula. Estas reglas no eliminan segmentos de los identificadores ordinarios con puntos. Los nombres inválidos y las colisiones de rutas, incluidas las diferencias solo de mayúsculas, producen errores explícitos.

Los modelos referenciados conservan su identidad y jerarquía incluso cuando solo los usa un campo; no se sustituyen por nombres inferidos de ese campo. Los enums y colecciones derivados siguen la misma jerarquía del modelo propietario. Los errores inline se ubican bajo `Responses\Errors`, siguiendo ruta, método y estado. Los objetos inline de solicitud se ubican en `Requests`, y las respuestas inline en `Responses`, distinguiendo el verbo. Los esquemas nombrados siguen en `DTOs`.

El uso estático mantiene una conexión activa por contexto de ejecución PHP y permite sustituirla explícitamente; la conexión se resuelve en cada llamada. Bajo PHP-FPM, el estado estático es por petición. En servidores persistentes y ejecuciones concurrentes se deben utilizar clientes de instancia para aislar usuarios y credenciales. La fachada no duplica validación, hidratación ni lógica HTTP.

## Criterios de aceptación

1. Un ejemplo mínimo debe permitir crear un cliente, enviar un objeto tipado y leer una respuesta sin implementar infraestructura HTTP.
2. Dos clientes deben conservar sus servidores y credenciales cuando sus llamadas se intercalan.
3. Los campos requeridos, enums y restricciones soportadas deben validarse antes del transporte, incluso después de modificar objetos anidados.
4. DTOs anidados, colecciones, mapas y uniones deben usar las mismas reglas de conversión al construir un modelo o recibir una respuesta.
5. Serializar debe validar cada nodo una vez por recorrido y conservar la distinción entre ausente y null, los nombres JSON y la dirección request/response.
6. Las pruebas deben cubrir comportamiento real con HTTP simulado, las tres versiones de contrato soportadas, instalación/autoload y generación determinista.
7. El rendimiento se evalúa con datos idénticos y comprobaciones de equivalencia, midiendo CPU, tiempo y memoria en procesos separados. Una reducción de archivos no acredita una reducción de memoria de ejecución.

La caché de reflexión guarda tipos por clase y campo, nunca instancias de DTO. No se cachea que un objeto mutable ya fue validado: cada nueva operación comprueba sus valores actuales. Una serialización valida el árbol completo y después convierte ese mismo árbol sin revalidar cada subárbol.

Las mediciones reproducibles y sus límites se documentan en [benchmark/DTO_REFACTOR.md](../benchmark/DTO_REFACTOR.md). La revisión y las pruebas de esta entrega usan PHP 8.5.11; la matriz existente de CI sigue cubriendo PHP 8.3–8.5.

## Reutilización y revisión

Antes de añadir código se contrasta con PHP, Guzzle y PSR. El runtime mantiene una implementación común; los SDKs declaran sus datos y tipos. Se conserva la adaptación de contrato que las librerías no ofrecen, comprobándola con requests exactos. Evidencia y límites: [revisión de arquitectura](ARCHITECTURE_REVIEW.md).

Multipart reutiliza `GuzzleHttp\Psr7\MultipartStream` para MIME y boundaries. Los campos binary admiten strings PHP o la interfaz PSR UploadedFileInterface para nombres, MIME y streams de entrada seekables; no se añade una jerarquía propia de archivos. El adaptador PSR-18 preserva el stream multipart desde v0.18.0 y rebobina cuerpos seekables tras middleware; los transportes personalizados antiguos conservan cuerpos string. Se mide la subida grande con Guzzle/cURL y se documentan los límites de otros clientes, middleware y respuestas; encodings avanzados y streaming de descarga siguen pendientes. Los objetos uploaded file se rechazan explícitamente al serializar JSON.

Las listas binarias de cuerpos multipart OpenAPI usan arrays PHP con PHPDoc de unión string|UploadedFileInterface y validación declarativa de elementos/límites. La adaptación se limita a modelos utilizados por multipart y conserva metadatos en hijos; las colecciones de modelos solo JSON no cambian. No hay colección de archivos propia.
