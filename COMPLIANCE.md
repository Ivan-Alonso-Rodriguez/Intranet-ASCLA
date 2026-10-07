# Revisión de cumplimiento ASCLA

Fecha: 6 de octubre de 2026. Base: development. Plugin vigente: 1.10.6; esquema 14.

## Cambios implementados

| Área | Resultado |
|---|---|
| Casos QA 024–058 | Presentación opcional en conexión, buscador local de contactos, rechazo silencioso, espera de 30 días, bloqueo que disuelve el vínculo, chat de solo lectura al desconectar, mensajes inmutables, título institucional de eventos, borradores, reconfirmación y ofertas con vencimiento. Detalle en [BUGFIXES_QA.md](BUGFIXES_QA.md). |
| Sesiones | Inactividad de 30 minutos validada en servidor y navegador; aviso previo y renovación por actividad explícita. Polling no extiende la sesión. |
| Membresías | Estado activo/suspendido/vencido y fecha de vigencia. Expirar o suspender revoca sesiones; conserva perfiles, solicitudes, relaciones, inscripciones y mensajes. Reactivación administrativa. |
| Acceso | Bloqueo predeterminado de 15 minutos tras cinco fallos. Umbrales, captcha, longitud de contraseña, recuperación y Recordarme configurables. Complejidad y datos personales validados en formularios ASCLA, reset y REST nativo. Recuperación pública con respuesta genérica. |
| Perfiles | Todo guardado exige los mínimos del PDF. Incompletos fuera del directorio y recomendaciones. Teléfono privado; campos ocultos respetados. Foto y experiencia opcionales no penalizan completitud. |
| Revisión y directorio | Confirmar o posponer revisión se guarda por usuario; frecuencia y página configurables. Multiselección y orden por coincidencia textual, filtros, afinidad y nombre. |
| Recomendaciones | Cambios de perfil invalidan también lotes de otros usuarios que pudieran recomendarlo. |
| Contacto | Pendiente → En revisión → Resuelta → Cerrada, respuesta e historial, asignación a ejecutivo o administrador. Contenido accesible solo al autor y responsable asignado; archivo conserva datos. |
| Edición profesional | Cambios administrativos en Cargo/Empresa requieren solicitud de Contacto, asignación y uso único. Ampliación al resto de campos profesionales todavía pendiente. |
| Microeventos | Ejecutivos pueden generar propuestas. Hasta cuatro propuestas semanales por intereses distintos, consentimiento independiente, mínimo/cupo configurables y aprobación humana obligatoria. Ventana prioritaria, apertura posterior, cierre, insuficiencia, denegación y cancelación con historial. |
| Eventos y recordatorios | Reprogramación exige reconfirmación; cancelación motivada; lista de espera FIFO con vencimiento y cron. Administración configura anticipación de recordatorios; microeventos la fijan antes de publicar. |
| Aliados | Gestión reservada a administración, incluida edición de contenido heredado. Oculto retira del catálogo y conserva el registro. |
| Auditoría | Esquema 14 amplía detalle a LONGTEXT conservando datos; diferencias antes/después para cambios administrativos de rol/membresía. |
| Distribución | La carpeta del plugin es ascla-core y el archivo instalable dist/ascla-core.zip. La entrega se publica como versión 1.10.6. |

## Brechas restantes

| Área | Trabajo pendiente |
|---|---|
| Publicación programada | Fecha futura para contenido institucional y publicación automática desde Oculto; aprobación de microevento separada de su divulgación. |
| Microeventos | Revisar propuestas cuyos candidatos pierden elegibilidad antes de publicar y configuración de periodicidad distinta de la semanal. |
| Integridad | Transacciones SQL con rollback para operaciones múltiples; los locks actuales no sustituyen transacciones. Auditoría completa de cambios efectuados desde WordPress nativo o terceros. |
| Contacto / perfiles | Categorías institucionales de Contacto y solicitud para la totalidad de cambios profesionales; revisión completa de permisos administrativos. |
| Publicaciones / idiomas | Revisión de publicación inmediata del Hub, reacciones predefinidas de comentarios y gettext MO/PO. |
| Calidad final | Adaptar regresiones PHP antiguas a la conservación de mensajes y repetir suite/cobertura sobre el conjunto definitivo. Revisar concurrencia y vencimientos de ofertas múltiples. |
| Infraestructura | HTTPS/TLS, carga y latencias con usuarios concurrentes, navegadores, Wordfence/Akismet, WCAG, respaldos diarios ≥7 días, restauración y disponibilidad. Requieren evidencia del entorno final. |

## Decisiones y ambigüedades

- Bloqueo de 15 minutos según respuesta explícita del usuario, sustituyendo el caso inicial de 10.
- Rechazar todo guardado de perfil incompleto. Posponer el aviso permite navegar, pero no guardar incompletos ni publicar el perfil en el directorio.
- Ejecutivos crean microeventos por petición explícita; aprobación administrativa obligatoria.
- RF-214 se cita en el documento sin tener definición. RF-93 se normaliza a RF-093.
- Correos de mensajes y eventos respetan preferencias del asociado; CU014 y RF-123 presentan diferencias sobre alertas individuales.
- Recordarme no evita la caducidad del navegador por inactividad.

## Validación y límites

Antes del último bloque, pasaron 310 pruebas PHP y 5.154 aserciones, con cobertura de líneas de 83,40 %. Es evidencia histórica de ese estado, **no del código final de este checkout**. Los reportes anteriores de cobertura quedaron desactualizados al continuar las modificaciones.

El recorrido focalizado tests/bugs-ui.cjs aprobó 12 comprobaciones: los once casos reportados mediante Chromium y API, además del directorio móvil; sin errores JavaScript no capturados. Resultado local en test-results/bugs-ui.json. No sustituye probar la ejecución del cron al vencer ofertas de plazos distintos.

Por indicación del usuario se dejó la suite PHP final para después. Las pruebas antiguas que esperan eliminar mensajes o grupos deben adaptarse a RN-028. No se ejecutó SonarQube remoto ni se afirma un Quality Gate aprobado.

Sintaxis JavaScript y git diff --check correctos. Se comprobó sintaxis de los 118 archivos PHP del plugin sin ejecutar PHPUnit. `php tests/release-sanity.php` aprobó 49 comprobaciones. ZIP regenerado y verificado contra las fuentes: 133 archivos, versión 1.10.6, una sola carpeta ascla-core/, CRC y SHA-256 correctos.
