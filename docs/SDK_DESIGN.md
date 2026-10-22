# Diseño y aceptación de los SDK generados

El objetivo es automatizar la forma de construir integraciones PHP del autor: clientes entendibles, objetos tipados y comportamiento común heredado. La compatibilidad con OpenAPI permite describir la API, pero generar todos sus archivos no demuestra por sí solo que el SDK sea usable ni eficiente.

## Responsabilidades

- `Core\ApiClient` construye clientes independientes y comparte explícitamente transporte, credenciales y middleware entre sus recursos.
- `Client` implementa la ejecución HTTP común. Las clases de `Api` declaran metadatos constantes y métodos de instancia con firmas tipadas.
- `Core\DTO` contiene la conversión de tipos, validación y serialización. `Request` y `Response` seleccionan la dirección apropiada. La decodificación de respuestas, los mapas y las uniones usan esa misma conversión.
- Las clases de modelo declaran campos y restricciones. Las colecciones heredan el comportamiento de `Core\TypedArray`, que sigue utilizando la biblioteca TypedArray.

La colección se reutiliza cuando coincide el tipo PHP de elemento, su nullabilidad y sus restricciones. Las colecciones de primitivas sin restricciones tienen nombres comunes como `StringCollection`. No se fusionan enums de dominios distintos solo porque coincidan sus valores. Una unión `anyOf` de enums finitos con el mismo tipo base se representa como un enum con la unión de valores; `oneOf` conserva su comprobación de coincidencia única.

Los identificadores ordinarios mantienen su jerarquía. Los componentes marcados como `__schema__` usan el título y los límites en nombres como `Ranges\IntRangeFrom1To15000`; las variantes distintas conservan tipos separados. Los errores inline se ubican bajo `Responses\Errors`, siguiendo ruta, método y estado, sin sufijos hash para resolver esas rutas.

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
