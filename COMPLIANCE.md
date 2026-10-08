# Revisión de cumplimiento ASCLA

Revisión documental: 8 de octubre de 2026. Checkout inspeccionado: qa, 626197a, con cambios sin commit. Plugin vigente: 1.10.7; esquema 14. Las ejecuciones de pruebas se identifican por etapa más abajo; esta revisión documental no vuelve a ejecutar el producto.

La [matriz Markdown](requirements/ascla-analysis.md) y su [JSON](requirements/ascla-analysis.json) conservan los 302 textos de requisitos y actualizan estados, evidencias y notas. Código revisado no equivale a aceptación integral del PDF.

## Cambios implementados

| Área | Resultado |
|---|---|
| Casos QA reportados 024, 028, 030, 033, 034, 038, 039, 047, 049, 050 y 058 | Presentación opcional en conexión, buscador local, rechazo silencioso, espera de 30 días, bloqueo que disuelve el vínculo, chat de solo lectura al desconectar, mensajes inmutables, títulos de eventos, borradores, reconfirmación y ofertas con vencimiento. Detalle en [BUGFIXES_QA.md](BUGFIXES_QA.md). |
| Directorio | Incluye perfiles incompletos por autorización expresa del usuario. Conserva membresía activa, participación voluntaria, bloqueos y privacidad por campo. Paginación sin duplicados; búsqueda delante de conexiones, filtros con casillas y diseño adaptable a escritorio/móvil. |
| Sesiones y credenciales | Inactividad de 30 minutos; polling no renueva sesión. Recordarme configura la cookie de 1 a 90 días, pero no evita el cierre por inactividad. Recuperación con vigencia configurable y respuesta pública genérica. Bloqueo predeterminado de 15 minutos tras cinco fallos. Política de contraseñas también validada antes de escrituras REST nativas de WordPress. |
| Membresías | Activo/suspendido/vencido y vigencia. Suspensión o vencimiento revoca sesiones y conserva perfiles, solicitudes, relaciones, inscripciones y mensajes. |
| Perfiles | Todo guardado propio exige mínimos. Recomendaciones y candidatos de microeventos siguen excluyendo incompletos. Teléfono privado; campos ocultos respetados. Revisión periódica configurable. |
| Contacto | Categorías institucionales configurables y validadas en servidor. Pendiente → En revisión → Resuelta → Cerrada; respuesta, historial y responsable administrador/ejecutivo. Solo autor y responsable asignado leen el contenido. |
| Edición administrativa | Nombres, apellidos, Cargo, Empresa, nacimiento y teléfono requieren solicitud activa del asociado asignada al administrador, de uso único. Se impide el cambio de datos personales ajenos por wp-admin/REST nativo. Rol, membresía y credenciales conservan su gestión administrativa. |
| Publicación programada | Editor/API con fecha futura para eventos, galerías, recursos, Hub y aliados según permisos. Estado Oculto, cancelación/reprogramación, validación de permisos al ejecutar, aviso de errores y publicación automática por WP-Cron. |
| Microeventos | Ejecutivos preparan propuestas; aprobación administrativa separada de publicación. Periodicidad de 7–90 días, semanal por defecto, y hasta cuatro propuestas por período. Revalida candidatos antes de publicar: vuelve a revisión si cambia el grupo y cancela con motivo si pierde el mínimo. Prioridad e invitaciones comienzan al publicar. |
| Eventos | Reprogramación exige reconfirmación; cancelación motivada, recordatorios configurables y lista de espera FIFO con vencimiento persistente. |
| Hub / reacciones | Publicación manual explícita inmediata, borrador o revisión; se mantiene configuración administrativa heredada. Por decisión del usuario, Me gusta es la única reacción en publicaciones y comentarios; permite activarla y retirarla sin duplicados. Las reacciones de versiones anteriores se conservan como Me gusta. |
| Internacionalización | Catálogos PO/MO español e inglés, carga con gettext WordPress, soporte de catálogo global y traducciones proyectadas al JavaScript. |
| Integridad local | Transacciones InnoDB con savepoints en conexiones, mensajería directa, eventos, publicaciones, moderación, reportes, gestión administrativa de cuentas y Contacto. Detecta errores silenciosos de metadatos/taxonomías de WordPress. Correos nativos y ASCLA, Google Calendar y consulta de video se difieren hasta confirmar el guardado. Rollback comprobado con fallos reales de auditoría y metadatos en base desechable. |
| Conservación al eliminar cuentas | La limpieza distingue relaciones entre personas de reacciones a contenido, aunque compartan el mismo identificador numérico. Conserva las reacciones ajenas a la cuenta eliminada. |
| Auditoría | LONGTEXT, diferencias antes/después de roles, capacidades y membresías, incluidos hooks nativos de WordPress. Historial de estados y motivos de microeventos, publicaciones y comentarios. Resolver reportes exige justificación, actor y UTC; eliminar el reporte revisado conserva su auditoría. |
| Distribución | Carpeta única ascla-core; instalable dist/ascla-core.zip, versión actual 1.10.7. Se conservan las refactorizaciones recientes del usuario. |

## Brechas restantes

| Área / requisitos | Trabajo pendiente |
|---|---|
| Completitud configurable — RF-143 | Los ocho grupos mínimos y su cálculo funcionan; falta poder configurar desde Administración el conjunto/total de campos. La revisión periódica y posposición ya son configurables. |
| Hub y foros — RF-172, RF-177, RF-180 | Completar fecha final del rango de filtros: hoy se aplica `after`, sin límite `before`. Separar el historial de comentarios propios del de temas; `mine` cubre publicaciones. |
| Sesión recordada — RN-058 | Resolver la interpretación de permanencia prolongada frente al cierre obligatorio por 30 minutos de inactividad. El parámetro de duración máxima de la cookie ya existe; no falta implementarlo. |
| Integridad — RNF-011 | Completar perfiles propios y mensajería grupal; revisar importaciones, caché persistente y hooks externos. Correos/Calendar se difieren al commit, pero falta entrega durable y reintento si el proceso termina después de guardar. |
| Permisos y auditoría — RN-036, RF-133, RF-135, RNF-001, RNF-014, RNF-027 | Gobierno de permisos individuales, registro de aprobación de la Junta para acumular roles, terceros, retención protegida y unificación de errores por módulo/severidad/UTC. Los cambios nativos de roles y las resoluciones internas ya se auditan; SQL directo no dispara hooks. |
| Secretos — RNF-007 | La lectura admite constantes de `wp-config.php`, pero el formulario guarda claves cifradas en opciones WordPress. Resolver configuración y migración para ajustarse al almacenamiento de secretos exigido por el PDF. |
| Acceso y formularios — RNF-002, RNF-006, RNF-013 | Verificar Wordfence/Turnstile en destino y la política por IP (20 fallos por defecto, distinta de los 5 por cuenta). Completar la auditoría transversal de rutas, formularios, cachés y plugins. Ya están implementados mínimos del perfil, vigencia de membresía y límites configurables. |
| Idiomas y accesibilidad — RNF-015 a RNF-019 | Revisar cadenas dinámicas, errores, teclado, etiquetas y contraste de todas las vistas; completar anchos y navegadores exigidos. Los PO/MO y pruebas locales de varias pantallas ya existen. |
| Operación — RNF-003, RNF-008 a RNF-010, RNF-012, RNF-025, RNF-026 | Evidencia en destino de HTTPS/TLS, 50 usuarios simultáneos y latencias, fallos de proveedores/SMTP, copias diarias con retención mínima de 7 días, restauración y disponibilidad. |
| Cierre de entrega | Guardar la revisión final, repetir el flujo completo QA/UAT sobre ese commit y obtener el Quality Gate remoto y la aceptación del equipo. No se exige cobertura del 100 %. |

## Decisiones del usuario

- Bloqueo predeterminado de 15 minutos según el PDF, sustituyendo el caso inicial de 10.
- Rechazar todo guardado propio de perfil incompleto. Posponer el aviso permite navegar.
- Mostrar perfiles incompletos en Directorio, respetando privacidad y membresía. **Excepción expresa a RN-031 y RF-131**; los mínimos de recomendaciones y microeventos permanecen.
- Ejecutivos pueden crear propuestas de microeventos; aprobación administrativa obligatoria antes de publicarlas.
- Solicitud del asociado en Contacto seleccionada al realizar cambios administrativos de datos.
- Solo **Me gusta** en publicaciones y comentarios (RF-179). La API rechaza los tipos retirados; las reacciones anteriores se cuentan como Me gusta y se pueden retirar.
- Flujo manual `development → qa → uat → main`, sin Jenkins. La suite PHP inicialmente diferida se ejecutó al solicitar el usuario la corrección de QA.

## Validación registrada

| Etapa local | Resultado | Alcance |
|---|---|---|
| Flujo completo del 8 de octubre, antes del ajuste de reacción única | 325 pruebas PHP, 5.284 aserciones, 40 comprobaciones de navegador y 8 controles del scanner manual, sin fallos | `BRANCH_NAME=qa bash scripts/quality-ci.sh`. Clover, JUnit y LCOV validados. Cobertura de líneas: PHP 82,92 % y JavaScript 94,76 %, correspondiente a esa ejecución. |
| Ajuste posterior de reacción única | 43 pruebas PHP, 512 aserciones y 9 comprobaciones de navegador aprobadas; sin errores JavaScript | `ContentLifecycleTest`, `IntegrationTest` y `tests/editorial107.cjs`. Foros, Hub, Centro de Conocimiento y comentarios; compatibilidad de reacciones anteriores y rechazo de tipos retirados. No se repitió el pipeline completo ni se midió cobertura. |
| Paquete posterior al ajuste | ZIP 1.10.7, 151 archivos, carpeta interna `ascla-core`, CRC y bytes verificados contra fuentes | `scripts/verify-package.py`; no implica instalación en el servidor final. |

Las dos ejecuciones PHP no se suman como pruebas distintas. Sus evidencias locales están en `docs/evidence/release-1.10.7/qa-fixes.json`, `likes-only.json`, `package.json`, `test-results/quality-ci-fixes-final.log` y `test-results/editorial107.json`. `docs/evidence/`, `test-results/` y `coverage/` son artefactos locales ignorados por Git; los resultados resumidos quedan en este documento. Antes de adjuntar artefactos al PR, revisar que no incluyan credenciales temporales de fixtures.

Se conservan además resultados previos de `tests/bugs-ui.cjs` (12 comprobaciones), `tests/directory-publication.cjs` (9), `tests/workflows-ui.cjs` (5) y `tests/run-atomic107.cjs` (13). Incluyen ofertas FIFO concurrentes, publicación por cron, rollback con fallos reales y privacidad. No representan una nueva ejecución conjunta del checkout actual.

Mis conexiones se verificó a 1920, 1440, 768 y 390 px sin desbordamiento. En la configuración documentada de escritorio el panel pasó de 817 a 491 px. Esta comprobación no certifica todas las rutas, navegadores ni WCAG. Catálogos actuales: 1.365 mensajes por idioma; los 1.367 de la etapa anterior incluían las dos reacciones retiradas.

## Límites de entrega

El ZIP es `dist/ascla-core.zip`; la versión continúa siendo **1.10.7**, esquema **14**. La revisión de Markdown y matriz no modifica archivos de ejecución ni exige reconstruir el ZIP. El flujo de instalación y promoción está en [MANUAL_QA_UAT.md](MANUAL_QA_UAT.md).

WP-Cron necesita un disparador periódico operativo en el despliegue. No hay evidencia de ejecución remota de SonarQube, aprobación de su Quality Gate, publicación en producción ni cumplimiento íntegro del PDF. Las respuestas antiguas del Asistente que ya almacenaron texto anonimizado necesitan una consulta nueva para regenerar ese texto; sus etiquetas de fuentes sí se obtienen del original autorizado.
