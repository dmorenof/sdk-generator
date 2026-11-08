# Flujo de trabajo acordado

- Trabajar desde GitHub en el entorno cloud, sin depender del ordenador del usuario.
- Continuar el desarrollo en la rama existente `release/0.2.0`, manteniéndola al día con lo publicado. No crear ramas por funcionalidad. El nombre histórico de la rama no fija la versión de las entregas siguientes ni permite modificar tags ya publicados.
- Cada avance terminado y validado debe integrarse y publicarse como versión estable dentro de la misma tarea. No dar por terminado el trabajo dejando solo una rama o un PR en borrador.
- Preferir commits y push a `main` cuando sus protecciones lo permitan. No abrir PRs como flujo habitual. Si GitHub exige un PR, resolverlo como trámite técnico y completar integración y publicación sin pedir otra confirmación.
- Las tareas futuras de la hoja de ruta no deben retrasar la publicación de funcionalidad ya terminada. Mantener explícitos los límites reales y actualizar `pendientes.md` cuando corresponda.
- Coordinar dependencias entre repositorios: publicar y comprobar el runtime primero; después usar su versión estable en el generador y los SDKs emitidos. No terminar una entrega con dependencias temporales de ramas.
- Validar con las pruebas adecuadas, CI y smoke de instalación. Cambiar `VERSION` para activar la publicación existente; comprobar tag inmutable, GitHub Release y workflow finalizados antes de afirmar que está publicado. Nunca mover un tag existente.
- Mantener el código y cada avance coherente accesibles online. La instrucción del usuario de publicar sustituye las antiguas reglas de dejar entregas acumuladas en PRs de desarrollo.

# Simplicidad y reutilización

- Aplicar la revisión y responsabilidad técnica descritas en https://gestycontrol.com/servicios/inteligencia-artificial/ y la regla expresa del usuario: reutilizar código y herencia; no reinventar capacidades disponibles.
- Comprobar PHP, Guzzle y PSR antes de añadir utilidades propias. Reutilizar las librerías existentes cuando preserven el contrato; documentar las diferencias concretas de OpenAPI que exijan adaptación.
- Mantener ejecución, transporte y configuración comunes en el runtime. Los SDKs heredan ese comportamiento y solo declaran tipos, operaciones y valores propios de la API.
- Evitar nuevas capas, fábricas o envoltorios sin una necesidad comprobada. Simplificar con pruebas de comportamiento y conservar la compatibilidad pública salvo decisión explícita.
