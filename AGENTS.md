# Flujo de trabajo acordado

- Trabajar desde GitHub en el entorno cloud, sin depender del ordenador del usuario.
- Desarrollar en una rama y abrir un PR. **No se fusiona en `main` nada que cambie `VERSION` sin la confirmación expresa del titular**, porque ese cambio publica.
- **No se publican versiones intermedias.** Una versión se publica solo cuando el trabajo está terminado, probado y el titular lo confirma; una línea de trabajo, una publicación. Un avance terminado que no se ha publicado vive en su rama y en su PR hasta entonces; eso no es "dejarlo a medias".
- Las ramas no se borran sin que el titular lo pida.
- Coordinar dependencias entre repositorios: al publicar, el runtime primero; después el generador usa su versión estable. Lo que se publica no depende de ramas temporales.
- Validar con las pruebas adecuadas, CI y smoke de instalación. Un cambio que toca lo que usa el código generado se prueba contra la CI del generador antes de publicar el runtime. Cambiar `VERSION` activa la publicación existente: comprobar tag inmutable, GitHub Release y workflow finalizados antes de afirmar que está publicado. Nunca mover un tag existente.
- Actualizar `pendientes.md` con lo que realmente falta, sin duplicar el CHANGELOG.

# Simplicidad y reutilización

- Aplicar la revisión y responsabilidad técnica descritas en https://gestycontrol.com/servicios/inteligencia-artificial/ y la regla expresa del usuario: reutilizar código y herencia; no reinventar capacidades disponibles.
- Comprobar PHP, Guzzle y PSR antes de añadir utilidades propias. Reutilizar las librerías existentes cuando preserven el contrato; documentar las diferencias concretas de OpenAPI que exijan adaptación.
- Mantener ejecución, transporte y configuración comunes en el runtime. Los SDKs heredan ese comportamiento y solo declaran tipos, operaciones y valores propios de la API.
- Evitar nuevas capas, fábricas o envoltorios sin una necesidad comprobada. Simplificar con pruebas de comportamiento y conservar la compatibilidad pública salvo decisión explícita.
