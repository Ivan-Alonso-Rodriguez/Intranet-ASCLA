# Correcciones de casos QA

Revisión documental: 8 de octubre de 2026. Plugin: ascla-core, versión vigente 1.10.7. Esta lista cubre los casos reportados; no implica que todos los casos numerados entre 024 y 058 hayan sido ejecutados. La [matriz vigente](requirements/ascla-analysis.md) conserva la trazabilidad con el PDF.

| Caso | Implementación |
|---|---|
| 024 | Enviar solicitud abre un formulario con presentación opcional de hasta 1000 caracteres. El texto acompaña la solicitud recibida y no crea una autorización de chat. |
| 028 | Rechazar elimina el estado pendiente y aplica 30 días de espera al remitente. El botón vuelve a su estado inicial; al intentarlo aparece un aviso genérico. No se expone el motivo ni la fecha del rechazo. |
| 030 | Buscador por nombre dentro de Conexiones confirmadas, con filtrado inmediato, sin distinción de mayúsculas o acentos y aviso cuando no hay coincidencias. |
| 033 | Bloquear elimina la conexión, solicitudes pendientes y permisos de conversación. Desbloquear no restaura el vínculo ni el acceso al historial bloqueado. |
| 034 | Desconectar aplica 30 días de espera en ambas direcciones; la API valida el plazo bajo el lock de la pareja. |
| 038 | No hay opción para borrar mensajes enviados. DELETE se rechaza; no existe ruta de edición. Cancelar/rechazar solicitudes de conversación conserva los mensajes ya transmitidos. También se impide borrar el historial completo mediante eliminar un grupo; se conserva ocultamiento personal. |
| 039 | Desconectar conserva historial accesible y deshabilita nuevos mensajes. Las desconexiones de versiones anteriores se reconocen mediante su registro de auditoría; un bloqueo posterior revoca ese acceso. |
| 047 | El título institucional del evento conserva el texto escrito. La detección heurística de nombres ya no sustituye títulos como Congreso Anual de Secretarios Operativos 2026. Las identidades introducidas expresamente siguen anonimizándose. |
| 049 | El selector del editor permite Borrador y Publicar ahora. Un borrador se conserva y no puede consultarlo un asociado sin permisos. |
| 050 | Cambiar inicio, fin o modalidad pasa las inscripciones aceptadas/ofrecidas a pendiente de reconfirmación y envía el aviso. Conserva historial y motivo. |
| 058 | Cada oferta tiene vencimiento persistente y registro de cuándo se emitió. WP-Cron expira ofertas, libera reservas y avanza por orden FIFO; vuelve a programarse para las ofertas restantes. La API verifica vencimiento al aceptar y la ficha muestra la fecha límite. |

## Persistencia de ofertas

No se reutiliza created_at de la inscripción como fecha de oferta. Los plazos se guardan en wp_postmeta, bajo _ascla_waitlist_offers, y sus fechas de emisión y vencimiento en _ascla_waitlist_offer_history. Es persistencia en base de datos aunque no sean columnas nuevas de wp_ascla_registrations. Plazo predeterminado: 24 horas; configurable por evento entre 1 y 168 horas, limitado por el cierre aplicable.

WP-Cron necesita el disparador del entorno. El proyecto ya dispone de contenedor cron. Consultar o intentar aceptar una oferta ejecuta además la comprobación del servidor, aunque se haya retrasado el cron.

## Verificación

Recorrido de navegador y API: tests/bugs-ui.cjs, exclusivamente sobre WordPress desechable con ASCLA_E2E_EPHEMERAL=1. 12 comprobaciones aprobadas y sin errores JavaScript no capturados. Resultado y capturas en test-results/bugs-ui.json, connections-filter.png, chat-readonly.png y connections-mobile.png.

Antes del ajuste posterior de reacción única, a petición del usuario del 8 de octubre se ejecutó la suite PHP: 325 pruebas y 5.284 aserciones aprobadas con PHP 8.3.28 y PCOV 1.0.12. Se actualizaron los contratos antiguos sin retirar los controles de permisos, confidencialidad ni motivos de auditoría. No se declara aprobado el Quality Gate de SonarQube ni cumplimiento integral del PDF.

## Directorio y continuidad del PDF

El Directorio ahora muestra también perfiles incompletos activos que no hayan ocultado su participación, conforme a la autorización del usuario. Se conservaron privacidad por campo, bloqueo, membresía y paginación. El diseño aprovecha el ancho disponible, prioriza el buscador y reemplaza la multiselección nativa por casillas; Mis conexiones queda agrupado en un panel plegable.

Pruebas adicionales: tests/directory-publication.cjs (9 comprobaciones) y tests/workflows-ui.cjs (5). Incluyen cron real de publicación y pérdida de candidatos, autorización por Contacto, categorías y auditoría nativa. Detalle del alcance y brechas en COMPLIANCE.md.

En 1.10.7, tests/run-atomic107.cjs verifica además expiración simultánea con avance FIFO, rechazo de aceptación vencida y ausencia de sobreaforo. La reprogramación y cancelación se revierten completas ante fallos intermedios.

## Corrección de QA y referencias públicas — 8 de octubre

Se corrigió el error 500 al aprobar de nuevo un comentario aprobado, conservando una sola notificación. Las pruebas ahora distinguen aprobación y publicación de microeventos, permiten perfiles incompletos visibles según la decisión del usuario, envían motivos de revisión y eligen explícitamente revisión cuando corresponde en el Hub.

Los títulos editoriales publicados se conservan en listados, fichas, contexto del Asistente y fuentes de respuestas guardadas. Las identidades expresamente reservadas tienen prioridad; el cuerpo de sesiones Chatham House sigue protegido antes y después del proveedor. No se modifica el contenido original. Las respuestas antiguas que ya guardaron texto anonimizado no se reconstruyen: hay que repetir la consulta para generar ese texto nuevamente; las etiquetas de sus fuentes sí se actualizan desde el original autorizado.

Mis conexiones se verificó a 1920, 1440, 768 y 390 px, sin desbordamiento. Con una solicitud y conexiones confirmadas desplegadas, el panel de escritorio pasó de 817 a 491 px. Evidencia local: test-results/directory-spacing.json y capturas directory-spacing-*.png.

Ejecución local completa del 8 de octubre, anterior al ajuste de reacción única: `BRANCH_NAME=qa bash scripts/quality-ci.sh` completado en WordPress desechable. 325 pruebas PHP, 5.284 aserciones, 40 comprobaciones de navegador y 8 controles del flujo manual de Sonar aprobados. Clover, JUnit y LCOV validados; cobertura de líneas PHP 82,92 % y JavaScript 94,76 %. No se exige 100 % de cobertura. Instalador verificado: `dist/ascla-core.zip`, carpeta interna `ascla-core`, versión 1.10.7. Evidencia local: `docs/evidence/release-1.10.7/qa-fixes.json` y `test-results/quality-ci-fixes-final.log`. Esto no declara aprobado el Quality Gate remoto ni cumplimiento íntegro del PDF.

## Reacción única por decisión del usuario — 8 de octubre

Se mantiene únicamente Me gusta en publicaciones y comentarios de toda la aplicación. Se retiraron los botones y traducciones de las otras reacciones; la API rechaza sus tipos. Las interacciones previas se muestran y cuentan como Me gusta, y pueden retirarse sin duplicados ni pérdida de registros.

Validación posterior a este cambio: 43 pruebas PHP y 512 aserciones de ContentLifecycleTest e IntegrationTest aprobadas; tests/editorial107.cjs pasó sus 9 comprobaciones, sin errores JavaScript. Incluye Foros, Hub y Centro de Conocimiento. Catálogos regenerados con 1.365 mensajes por idioma. No se volvió a ejecutar el pipeline completo ni a medir cobertura para este ajuste. Evidencia: docs/evidence/release-1.10.7/likes-only.json y test-results/editorial107.json.

Los resultados anteriores se conservan como evidencia de sus etapas; esta actualización documental no vuelve a ejecutar las pruebas ni declara aprobado el despliegue. Pendientes reales y criterios de validación: [COMPLIANCE.md](COMPLIANCE.md). Promoción sin Jenkins: [MANUAL_QA_UAT.md](MANUAL_QA_UAT.md).
