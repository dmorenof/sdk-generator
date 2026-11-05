# Partición del runtime de DTOs — 23 de octubre de 2026

Comprueba que repartir `DTO` entre `Hydrator`, `Constraints` y `Serializer` en `dmorenof/sdk-runtime` no empeora el rendimiento. Se usa **el mismo SDK generado** desde Casafari OpenAPI 3.1 (SHA-256 `d6dce87639a6a16b7f2aac2e17ab5845b21e4e7937e68fca20243442be15ba04`) con dos versiones del runtime:

- **antes**: `68fda9d`, `DTO` monolítico (489 líneas).
- **después**: `3b79dd3`, partido en cuatro clases y con las constantes declaradas cacheadas por clase.

Los [datos brutos](RUNTIME_SPLIT_RESULTS.json) incluyen cinco ensayos por versión y carga, las huellas de los árboles fuente y todas las observaciones. PHP 8.5.11 en Docker, imagen `composer:2`. Cada ensayo usa un proceso PHP separado, se alterna el orden y se excluyen cinco iteraciones de calentamiento. Las cargas son las de [DTO_REFACTOR.md](DTO_REFACTOR.md): lotes de 1.000 feeds, 20 iteraciones, y un árbol de 24 DTOs encadenados con 2.000 serializaciones.

| Carga | CPU antes, ms | CPU después, ms | Variación | Pico asignado PHP, antes/después |
|---|---:|---:|---:|---:|
| Hidratación | 396,44 | 384,25 | −3,1 % | 14 / 14 MiB |
| Serialización | 97,05 | 90,19 | −7,1 % | 10 / 10 MiB |
| Hidratación y serialización | 500,59 | 475,37 | −5,0 % | 14 / 14 MiB |
| Serialización de árbol profundo | 42,09 | 23,24 | −44,8 % | 4 / 4 MiB |

Son medianas de tiempo de CPU (`getrusage`, usuario + sistema). La memoria asignada no cambia.

## Qué se aprendió

La primera versión de la partición, sin caché (`97309db`), fue **un 9 % más lenta** en serialización (96,5 → 105,2 ms) y en el árbol profundo (41,8 → 45,6 ms): cada validación y cada serialización resolvía varias constantes generadas con `defined()` y `constant()`. Cachearlas una vez por clase lo corrigió y mejoró el resultado de partida. Las diferencias de unos pocos por ciento están dentro del ruido entre ejecuciones; la del árbol profundo no lo está.

Esto mide solo hidratación y serialización de DTOs, sin red. No es una medición de aplicaciones completas ni de memoria de proceso (RSS).

## Repetir la comparación

```sh
mkdir -p generated/runtime-before generated/runtime-after
git -C ../sdk-runtime archive 68fda9d | tar -x -C generated/runtime-before
git -C ../sdk-runtime archive 3b79dd3 | tar -x -C generated/runtime-after
php bin/generate-sdk.php tests/fixtures/casafari-openapi31.json generated/bench-sdk CasafariSDK example/casafari-sdk
php benchmark/compare-dto.php generated/bench-sdk generated/runtime-before generated/runtime-after generated/runtime-split.json
```

El script `dto-probe.php` carga la versión del runtime indicada por delante de la instalada en `vendor/`, de modo que cada ensayo usa exactamente el árbol que se le pasa. Los directorios `generated/` están ignorados por Git.
