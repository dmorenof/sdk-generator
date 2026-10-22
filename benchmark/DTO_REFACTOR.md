# Revisión del runtime de DTOs — 22 de octubre de 2026

Comparación de la salida del workspace antes y después de la revisión de usabilidad, publicada en el commit `c51393c`. La reconstrucción posterior de jerarquías de nombres no forma parte de estos ensayos. Ambas versiones se generan desde el mismo Casafari OpenAPI 3.1, SHA-256 `d6dce87639a6a16b7f2aac2e17ab5845b21e4e7937e68fca20243442be15ba04`. No es una comparación con OpenAPI Generator ni una medición de latencia de red.

Los [datos brutos](DTO_REFACTOR_RESULTS.json) incluyen cinco ensayos por versión y carga, versiones de PHP, huellas de los árboles fuente y todas las observaciones. PHP 8.5.11 en Docker, imagen `composer:2`, con las dependencias del workspace. Cada ensayo usa un proceso PHP separado; se alterna el orden antes/después y se excluyen cinco iteraciones de calentamiento.

Para las tres primeras cargas se usan lotes de 1.000 feeds con DTO, enum, filtro anidado y 16 identificadores de localización; se realizan 20 iteraciones por ensayo. Se comprueban los valores hidratados y la equivalencia JSON antes de medir. La última carga es una prueba de profundidad con 24 DTOs encadenados y 2.000 serializaciones.

| Carga | CPU anterior, ms | CPU nueva, ms | Reducción | Pico asignado PHP, antes/después |
|---|---:|---:|---:|---:|
| Hidratación | 461,69 | 407,90 | 11,7 % | 14 / 14 MiB |
| Serialización | 118,93 | 97,09 | 18,4 % | 10 / 10 MiB |
| Hidratación y serialización | 572,52 | 500,99 | 12,5 % | 14 / 14 MiB |
| Serialización de árbol profundo | 203,21 | 39,35 | 80,6 % | 4 / 4 MiB |

Son medianas de tiempo de CPU (`getrusage`, usuario + sistema). El JSON también registra tiempo transcurrido y pico del heap sin redondear a bloques. La memoria asignada permanece igual en estas cargas; la diferencia del heap sin redondear es pequeña y no acredita una mejora general de memoria. Estas cifras tampoco equivalen al RSS del proceso ni miden aplicaciones completas.

La salida pasa de 1.038 a 910 archivos PHP, y de 335 a 207 colecciones. Los totales incluyendo `composer.json` son 1.039 y 911. La reducción de archivos mide mantenimiento y volumen de código, no ahorro automático de memoria en una petición.

Para repetir la comparación con dos paquetes generados que utilicen el namespace `CasafariSDK`:

```sh
php benchmark/compare-dto.php generated/CasafariBefore generated/CasafariSDK.before-schema-paths generated/comparison.json
```

El snapshot anterior se conserva localmente en `generated/CasafariBefore`; la versión medida se conserva en `generated/CasafariSDK.before-schema-paths`. En los datos originales figuraba en `generated/CasafariSDK`, cuya salida se ha reorganizado posteriormente. Los directorios generados están ignorados por Git. Para repetir en otro checkout hace falta conservar o regenerar ambos snapshots; las huellas del informe identifican los árboles exactos.

El benchmark histórico [runtime-probe.php](runtime-probe.php) se ha adaptado a los clientes por instancia. Sus resultados anteriores siguen siendo históricos y deben volver a medirse antes de atribuirlos a la salida actual.
