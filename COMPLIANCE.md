# Revisión de cumplimiento ASCLA

Fecha: 7 de octubre de 2026. Base revisada: development, 22e2d64. Plugin vigente: 1.10.7; esquema 14.

## Cambios implementados

| Área | Resultado |
|---|---|
| Casos QA 024–058 | Presentación opcional en conexión, buscador local, rechazo silencioso, espera de 30 días, bloqueo que disuelve el vínculo, chat de solo lectura al desconectar, mensajes inmutables, títulos de eventos, borradores, reconfirmación y ofertas con vencimiento. Detalle en [BUGFIXES_QA.md](BUGFIXES_QA.md). |
| Directorio | Incluye perfiles incompletos por autorización expresa del usuario. Conserva membresía activa, participación voluntaria, bloqueos y privacidad por campo. Paginación sin duplicados; búsqueda delante de conexiones, filtros con casillas y diseño adaptable a escritorio/móvil. |
| Sesiones y credenciales | Inactividad de 30 minutos; polling no renueva sesión. Bloqueo predeterminado de 15 minutos tras cinco fallos. Política de contraseñas también validada antes de escrituras REST nativas de WordPress. |
| Membresías | Activo/suspendido/vencido y vigencia. Suspensión o vencimiento revoca sesiones y conserva perfiles, solicitudes, relaciones, inscripciones y mensajes. |
| Perfiles | Todo guardado propio exige mínimos. Recomendaciones y candidatos de microeventos siguen excluyendo incompletos. Teléfono privado; campos ocultos respetados. Revisión periódica configurable. |
| Contacto | Categorías institucionales configurables y validadas en servidor. Pendiente → En revisión → Resuelta → Cerrada; respuesta, historial y responsable administrador/ejecutivo. Solo autor y responsable asignado leen el contenido. |
| Edición administrativa | Nombres, apellidos, Cargo, Empresa, nacimiento y teléfono requieren solicitud activa del asociado asignada al administrador, de uso único. Se impide el cambio de datos personales ajenos por wp-admin/REST nativo. Rol, membresía y credenciales conservan su gestión administrativa. |
| Publicación programada | Editor/API con fecha futura para eventos, galerías, recursos, Hub y aliados según permisos. Estado Oculto, cancelación/reprogramación, validación de permisos al ejecutar, aviso de errores y publicación automática por WP-Cron. |
| Microeventos | Ejecutivos preparan propuestas; aprobación administrativa separada de publicación. Periodicidad de 7–90 días, semanal por defecto, y hasta cuatro propuestas por período. Revalida candidatos antes de publicar: vuelve a revisión si cambia el grupo y cancela con motivo si pierde el mínimo. Prioridad e invitaciones comienzan al publicar. |
| Eventos | Reprogramación exige reconfirmación; cancelación motivada, recordatorios configurables y lista de espera FIFO con vencimiento persistente. |
| Hub / reacciones | Publicación manual explícita inmediata, borrador o revisión; se mantiene configuración administrativa heredada. Me gusta, Útil y Celebrar en temas/comentarios con cambio y retiro sin duplicados. |
| Internacionalización | Catálogos PO/MO español e inglés, carga con gettext WordPress, soporte de catálogo global y traducciones proyectadas al JavaScript. |
| Integridad local | Transacciones InnoDB con savepoints en conexiones, mensajería directa, eventos, publicaciones, moderación, reportes, gestión administrativa de cuentas y Contacto. Detecta errores silenciosos de metadatos/taxonomías de WordPress. Correos nativos y ASCLA, Google Calendar y consulta de video se difieren hasta confirmar el guardado. Rollback comprobado con fallos reales de auditoría y metadatos en base desechable. |
| Conservación al eliminar cuentas | La limpieza distingue relaciones entre personas de reacciones a contenido, aunque compartan el mismo identificador numérico. Conserva las reacciones ajenas a la cuenta eliminada. |
| Auditoría | LONGTEXT, diferencias antes/después de roles, capacidades y membresías, incluidos hooks nativos de WordPress. Historial de estados y motivos de microeventos, publicaciones y comentarios. Resolver reportes exige justificación, actor y UTC; eliminar el reporte revisado conserva su auditoría. |
| Distribución | Carpeta única ascla-core; instalable dist/ascla-core.zip, versión actual 1.10.7. Se conservan las refactorizaciones recientes del usuario. |

## Brechas restantes

| Área | Trabajo pendiente |
|---|---|
| Integridad | Completar perfiles propios, mensajería grupal y revisar la integración de importaciones con las transacciones existentes. Validar cachés persistentes, hooks de terceros y entrega durable/reintento de efectos externos. La publicación editorial, gestión administrativa de cuentas y estados de Contacto/microeventos ya usan transacciones. |
| Auditoría / permisos | Integraciones de terceros, gobierno de permisos individuales y retención protegida en producción. Las resoluciones internas de moderación ya registran diferencias; los hooks no observan escrituras SQL directas. |
| Idiomas | Catálogos gettext incorporados; revisión exhaustiva de cadenas dinámicas y mensajes de error para evitar mezclas en todas las pantallas. |
| Calidad final | Adaptar regresiones PHP antiguas a inmutabilidad y motivos obligatorios de moderación, y ejecutar suite/cobertura sobre el conjunto definitivo. Ampliar pruebas de carga y concurrencia sostenida. Ya se verificaron ofertas simultáneas y programación de todos los tipos editoriales admitidos. |
| Infraestructura | HTTPS/TLS, carga y latencias, navegadores, Wordfence/Akismet, WCAG, respaldos diarios ≥7 días, restauración y disponibilidad. Requieren evidencia del entorno final. |

## Decisiones del usuario

- Bloqueo de 15 minutos según el PDF, sustituyendo el caso inicial de 10.
- Rechazar todo guardado propio de perfil incompleto. Posponer el aviso permite navegar.
- Mostrar también perfiles incompletos en Directorio, respetando privacidad y membresía. **Sustituye expresamente RF-131; no es cumplimiento literal de ese requisito.** No cambia los mínimos para recomendaciones ni microeventos.
- Ejecutivos pueden crear propuestas de microeventos; aprobación administrativa obligatoria.
- Solicitud del asociado en Contacto seleccionada al realizar cambios administrativos de datos.
- Suite PHP final diferida. Trabajo y validaciones locales; sin Jenkins ni publicación remota.

## Validación y límites

En WordPress/MariaDB desechables se ejecutaron pruebas de navegador y API: tests/bugs-ui.cjs (12 comprobaciones), tests/directory-publication.cjs (9) y tests/workflows-ui.cjs (5). Los dos últimos recorren privacidad de incompletos, bloqueos, todas las páginas, filtros, tamaños 1920/1440/768/390, publicación real por cron en todas las secciones admitidas, revocación de permisos, autorización por Contacto, rechazo REST nativo, categorías, auditoría de roles y cancelación por pérdida de candidatos. Resultados y capturas: test-results/.

Para 1.10.7 se añadieron tests/editorial107.cjs (8 comprobaciones) y tests/run-atomic107.cjs (13 comprobaciones con fallos exclusivos de usuarios temporales y limpieza automática). Las cinco suites suman 47 comprobaciones. Incluyen rollback de alta/edición/papelera de contenido, eventos, cuentas, solicitudes y moderación; conservación de sesión y autorización tras un fallo; ausencia de correo de cambios revertidos; error/reprogramación de cron y vencimiento FIFO concurrente sin duplicar cupos. Se verificó carga del MO en WordPress y lectura independiente GNU gettext de ambos catálogos (1.367 mensajes cada uno). No se ejecutó PHPUnit.

La suite PHP no se ejecutó en esta revisión por indicación del usuario. La evidencia previa de 310 pruebas, 5.154 aserciones y 83,40 % de cobertura es histórica; no representa este checkout. Tampoco se ejecutó SonarQube remoto ni se declara Quality Gate aprobado o cumplimiento integral del PDF.

La entrega 1.10.7 pasó validación de sintaxis de 132 archivos PHP, JavaScript, git diff --check y verificación de los 151 archivos del ZIP contra las fuentes. Evidencia en docs/evidence/release-1.10.7/. WP-Cron necesita el disparador periódico del despliegue; el proyecto utiliza un contenedor cron. Los correos posteriores al commit aún no constituyen una cola durable: un cierre del proceso puede impedir su entrega, aunque la operación local ya esté confirmada.
