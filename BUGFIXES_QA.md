# Correcciones de casos QA

Revisión local: 6 de octubre de 2026. Plugin: ascla-core, versión vigente 1.10.6.

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

Por indicación del usuario, la suite PHP final queda para después. Las pruebas anteriores que esperaban borrar grupos o mensajes de solicitudes canceladas deben adaptarse a la inmutabilidad. No se declara aprobado el Quality Gate de SonarQube ni cumplimiento integral del PDF.
