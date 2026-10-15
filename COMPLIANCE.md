# Cumplimiento y correcciones ASCLA

Revisión documental: 15 de octubre de 2026. Plugin **1.10.8**, esquema **15**. Este documento describe el estado del código y la evidencia por etapa. La [matriz](requirements/ascla-analysis.md) y su [JSON](requirements/ascla-analysis.json) conservan los textos originales del Documento de Análisis y Diseño; el PDF fuente se proporciona por separado.

<a id="estado"></a>

## Estado de los requisitos

| Estado | Cantidad | Interpretación |
|---|---:|---|
| Código revisado | 265 | Implementación y evidencia registradas, con el alcance de cada etapa. |
| Ajustado por decisión del usuario | 3 | Excepciones autorizadas; no son cumplimiento literal del PDF. |
| Parcial | 7 | Hay implementación y brechas por cerrar. |
| Validación pendiente | 2 | Falta revisión transversal. |
| Externo | 8 | Requiere evidencia del entorno o de proveedores. |
| Fuera de alcance | 17 | Exclusiones conservadas del alcance acordado. |

Inventario: **302 requisitos**, incluidos 62 RN, 213 RF y 27 RNF, con 240 prioridades RF/RNF. RF-214 se menciona pero no está definido; RF-93 se normaliza a RF-093. Los estados no equivalen a aceptación integral, cobertura de pruebas ni una estimación de horas restantes.

Sobre el PDF íntegro, 265/302 (**87,7 %**) están marcados Código revisado. Excluyendo los 17 fuera de alcance e incluyendo las tres decisiones autorizadas, el avance documental es 268/285 (**94,0 %**); quedan 17 (**6,0 %**). Estos porcentajes no aumentan por repetir pruebas o corregir una parte de un requisito transversal.

<a id="decisiones"></a>

## Decisiones vigentes

- Cinco fallos de cuenta bloquean por 15 minutos de forma predeterminada. El límite separado por IP es 20; su alineación institucional y Wordfence/Turnstile reales siguen pendientes.
- Todas las sesiones exigen autenticarse tras 30 minutos de inactividad, incluida Recordarme. La cookie conserva su duración absoluta configurable. Excepción expresa a RN-058; prevalecen RN-051/RNF-005.
- Todo guardado propio de perfil exige los campos mínimos. Posponer el aviso permite navegar, pero no guardar un perfil incompleto.
- El Directorio muestra perfiles incompletos activos que participan, respetando privacidad y bloqueos. Excepciones a RN-031 y RF-131. Las recomendaciones y los candidatos de microeventos mantienen sus mínimos.
- Los ejecutivos pueden crear propuestas de microeventos; otra revisión administrativa debe aprobarlas antes de publicarlas.
- Cambiar datos profesionales ajenos exige una solicitud del asociado en Contacto, asignada al administrador y de un solo uso.
- La única reacción es **Me gusta**. Las reacciones heredadas retiradas se contabilizan de manera compatible y pueden quitarse.
- El flujo Git y las pruebas son manuales, sin Jenkins. La instalación de plugins queda a cargo del responsable del entorno.
- El aprendizaje de intereses es automático y respeta las desmarcaciones guardadas del usuario.

<a id="casos-qa"></a>

## Casos QA corregidos

Esta tabla reúne los casos reportados; no afirma haber ejecutado todos los números intermedios ni todos los escenarios del PDF.

| Caso | Comportamiento implementado |
|---|---|
| 004 | El bloqueo de cuenta impide entrar incluso con una contraseña correcta durante el plazo activo. |
| 008 / 147 | Cambios administrativos de Cargo/Empresa vinculados a una solicitud profesional autorizada. |
| 013 / 148 | Guardado de perfil rechazado cuando faltan mínimos, incluso con texto invisible. |
| 019 / 149 | Búsqueda coherente con las etiquetas visibles, sin inferir cargos ocultos. |
| 024 | Solicitud de conexión con presentación opcional; no concede autorización de chat por sí misma. |
| 028 | Rechazo silencioso: vuelve el botón inicial, espera de 30 días y aviso genérico al reintentar. |
| 030 | Filtro inmediato por nombre en Contactos (conexiones confirmadas). |
| 033 | Bloquear disuelve conexión y solicitudes. Desbloquear no restaura el vínculo ni el historial bloqueado. |
| 034 | Disolver aplica 30 días de espera en ambas direcciones, validados por el servidor. |
| 038 | Mensajes enviados inmutables: sin edición/borrado; eliminar un grupo no destruye el historial. |
| 039 | Al desconectar se conserva el historial autorizado en solo lectura y se impiden nuevos mensajes. |
| 047 | Se conservan los títulos originales; la privacidad no los sustituye por etiquetas de identidad. |
| 049 | Eventos y microeventos pueden guardarse como borradores incompletos. Publicar exige sus mínimos. |
| 050 | Reprogramar cambia inscripciones aceptadas a pendiente de reconfirmación y notifica. |
| 058 | Oferta de cupo con emisión, vencimiento, comprobación al aceptar y avance FIFO al expirar. |
| 506 / CP 066 | Retirada de Aliados con estado Inactivo, reactivación e historial conservado. |
| 507 | Ficha institucional de Aliados con logotipo, beneficios, condiciones y contacto. |
| 508 / CP 071 | Nueva conversación limpia mensajes y borrador de inmediato, usa otro hilo y descarta recuperaciones tardías del contexto anterior. |

Las ofertas guardan sus plazos en `_ascla_waitlist_offers` y su historial en `_ascla_waitlist_offer_history`, dentro de `wp_postmeta`; no reutilizan la fecha original de inscripción. Plazo predeterminado de 24 horas, configurable entre 1 y 168 y limitado por el cierre aplicable. WP-Cron necesita un disparador operativo; consultar o aceptar también verifica el vencimiento.

<a id="directorio"></a>

## Directorio y gestión de conexiones

Mis conexiones muestra una lista cada vez mediante Contactos, Recibidas, Enviadas y Bloqueados. Las solicitudes de conexión y de conversación permanecen separadas dentro de Recibidas/Enviadas; sus totales corresponden a solicitudes, no a personas únicas. Los mensajes de presentación se despliegan al pedirlos y las acciones secundarias quedan en Más opciones. Contactos conserva los campos autorizados, la fecha de confirmación y el filtro inmediato por nombre, que se mantiene al cambiar de pestaña o actualizar. El panel completo se puede plegar.

La reorganización no cambia permisos, privacidad, estados, espera tras rechazo/desconexión ni el consentimiento de mensajería. Mantiene aceptación/rechazo, cancelación de ambos tipos de solicitudes, mensajes, perfil, eliminación confirmada y desbloqueo. Las pestañas admiten flechas, Inicio y Fin; los estados vacíos y controles se traducen. Se verificaron catorce controles con relaciones reales de WordPress local y seis vistas en ambos temas a 1440/390 px, sin desbordamiento ni infracciones automáticas WCAG A/AA en el panel. Los selectores de las suites anteriores se adaptaron, con sintaxis comprobada; no se atribuye una repetición completa de esas suites. [Recorrido](tests/connections-panel108.cjs) y [evidencia](docs/evidence/release-1.10.8/connections-panel108.json).

<a id="administracion"></a>

## Administración y actividad individual

Administración abre un Resumen por tareas. En Usuarios e intereses → Actividad e intereses se busca una cuenta y se consultan 30 días, 90 días o el historial completo, con filtros y páginas de 20. Volver conserva la búsqueda. Los intereses del perfil son actuales y no dependen de ese período; una inscripción aceptada no equivale a asistencia presente.

«Ver intranet» adapta su fondo y texto al tema claro u oscuro. El acceso «Administrar WordPress» solo se genera para cuentas con `manage_options`, siguiendo la protección nativa vigente; tener acceso a Administración ASCLA no concede ese permiso.

Contacto cuenta con formulario, preguntas frecuentes desplegables y solicitudes con estado e historial separados, adaptados a escritorio y móvil. Conserva campos obligatorios, límites de longitud, protección contra envíos automáticos y visibilidad privada; las respuestas siguen en Mis solicitudes. La revisión local incluyó envío real, persistencia, historial, traducción y cuatro roles, sin cambios de permisos.

La ficha exige `ascla_manage` y membresía activa. Excluye conversaciones privadas, Contacto, borradores, eliminados, comentarios sin aprobar, microeventos y contenido protegido por Chatham House, incluidos derivados. No devuelve cuerpos ni transcripciones. Filtra antes de contar y paginar; consulta los registros de la cuenta por lotes de 200, sin truncar a los primeros 100. Un error de consulta muestra fallo/reintento, sin inventar cifras cero. Las cifras del Resumen corresponden a sus bandejas limitadas, no a un nuevo censo global.

Fuentes: [MemberInsights](wp-content/plugins/ascla-core/src/Services/MemberInsights.php), [pruebas PHP](tests/php/MemberInsightsTest.php) y [recorrido de interfaz](tests/admin-members108.cjs).

<a id="asistente"></a>

## Asistente y reinicio de conversación

RF-167 / QA 508: Nueva conversación regresa al mensaje de bienvenida, limpia el borrador, enfoca el campo de pregunta y retira los parámetros de la consulta anterior del enlace. Genera un identificador nuevo y dibuja la vista vacía sin esperar una petición de historial. El hilo activo se mantiene en memoria; el almacenamiento de sesión y local se usa cuando está disponible. Una escritura rechazada no devuelve el chat al identificador anterior durante la sesión.

Las cargas y recuperaciones pendientes validan la vista y la conversación antes de pintar mensajes o mostrar errores. Los trabajos anteriores pueden terminar, pero su respuesta no se incorpora al hilo nuevo. RF-168 conserva las consultas privadas en Historial: recuperarlas exige seleccionarlas explícitamente. El reinicio mantiene las fuentes autorizadas actuales de ASCLA y envía `history: []` al proveedor; no borra el historial guardado.

La regresión local reprodujo el fallo de almacenamiento antes de corregirlo. Incluye once controles de navegador con historial y envíos REST reales, y respuestas de trabajos/retrasos controlados. Seis pruebas PHP/55 aserciones verifican el aislamiento por usuario/hilo y el cuerpo enviado al transporte Gemini, interceptado antes de cualquier llamada externa. No acredita una llamada real a Google ni una instalación en QA. [Recorrido](tests/assistant-reset108.cjs), [pruebas de contexto](tests/php/AssistantReset108Test.php) y [evidencia](docs/evidence/release-1.10.8/assistant-reset108.json).

<a id="microeventos"></a>

## Revisión y cancelación de microeventos

La revisión administrativa actualiza los candidatos en ese momento, sin esperar a cron. Comprueba membresía, consentimiento, campos mínimos y bloqueos; retira candidatos no elegibles y devuelve la propuesta a Pendiente de revisión si el grupo cambió. Si queda bajo el mínimo, la cancela con motivo e historial. Las propuestas aprobadas también permiten actualizar sus candidatos: un grupo vigente mantiene su aprobación y programación; un cambio exige revisarlas otra vez. Los cambios posteriores a esta comprobación siguen validándose en el backend antes de aprobar o publicar.

La interfaz distingue Aprobar propuesta de publicar. Después de aprobar, Editar y publicar permite decidir cuándo hacerla visible; no confirma asistencias por el asociado. Una propuesta publicada se gestiona desde su ficha y no ofrece la moderación genérica que podía confundir ocultación con cancelación.

Administración puede cancelar propuestas pendientes, aprobadas/programadas y microeventos publicados vigentes. El motivo ocupa todo el ancho y los botones una fila inferior; el formulario conserva validación y traducción. Cancelar retira publicación programada, recordatorios y revisión de cupos, impide nuevas confirmaciones y conserva las inscripciones. Una tarea antigua no vuelve a publicarlo. Se registran motivo, fecha y responsable; los asociados inscritos, invitados o en espera reciben el aviso. Una escritura fallida revierte estado, historial y programación.

Los microeventos se conservan en el historial administrativo. RN-025 exige no eliminar las propuestas denegadas; cancelar permite retirar la actividad con su trazabilidad, sin borrar registros ni volver a generar el mismo encuentro dentro del período.

Archivar del panel está disponible para administración en encuentros cancelados, denegados o finalizados. Los separa del listado principal y permite consultarlos y restaurarlos en Archivados; no elimina la ficha, inscripciones, motivos ni estados del ciclo. Restaurar no reactiva un encuentro cancelado ni aprueba uno denegado. La operación verifica rol y estado en el servidor, registra actor y fecha, y utiliza la transacción del evento.

Antes y después de generar, el panel muestra candidatos elegibles, mínimo por grupo y mayor cantidad de asociados que comparten un interés visible. Explica si falta el mínimo, un tema compartido suficiente o un grupo compatible con los bloqueos; ofrece conteos agregados de exclusión, sin nombres ni intereses ocultos. Cuando ya existe un lote del período, informa su recuperación sin atribuirle nuevas propuestas. Los intentos con cero grupos no quedan almacenados como un impedimento: corregir perfiles, intereses autorizados o configuración permite reintentar. Esto no reduce mínimos ni sustituye consentimiento para forzar grupos.

Esta etapa adicional pasó 29 pruebas PHP/230 aserciones, nueve controles de navegador con archivado/recuperación y un trabajo real de generación, 49 portables y dos vistas con revisión automática WCAG A/AA del diagnóstico. Los cuatro asociados con un interés común y mínimo cuatro sí generan una propuesta; cuatro repartidos en dos intereses distintos quedan en espera con causa explícita. La etiqueta residual «y participante» se retira solo de títulos IA heredados reconocidos, conservando fuentes y títulos escritos por usuarios. [Evidencia](docs/evidence/release-1.10.8/micro-admin108.json), [JUnit](docs/evidence/release-1.10.8/micro-admin108-junit.xml) y [recorrido](tests/micro-admin108.cjs). La causa concreta de los cuatro asociados del QA remoto no se ha inspeccionado; el diagnóstico se mostrará al instalar esta entrega.

Validación local: 71 pruebas PHP/734 aserciones, 14 controles de navegador del ciclo real y cuatro vistas de cancelación a 1440/390 px en ambos temas, sin desbordamiento ni infracciones automáticas WCAG A/AA en los diálogos. [Recorrido](tests/micro-flow108.cjs), [regresiones](tests/php/MicroEventsTest.php), [evidencia](docs/evidence/release-1.10.8/micro-flow108.json), [JUnit principal](docs/evidence/release-1.10.8/micro-flow108-junit.xml) y [regresión de eventos](docs/evidence/release-1.10.8/micro-flow108-events-junit.xml). No se ejecutó una suite completa, nueva cobertura ni aceptación remota.

<a id="intereses"></a>

## Intereses automáticos y privacidad

El asociado usa las opciones normales de Perfil → Intereses, sin panel ni controles globales de aprendizaje. ASCLA puede seleccionar términos existentes de `ascla_interest` a partir de señales elegibles de los últimos 90 días. No crea nuevos términos ni activa áreas, industrias, idiomas u otros campos.

Las señales incluyen Me gusta, comentarios aprobados, inscripciones aceptadas, asistencias presentes y lecturas. Leer exige 15 segundos con el detalle abierto, visible y enfocado, más un comprobante del servidor de un solo uso. Listados y tarjetas no cuentan. Se toma la señal más fuerte por contenido; el umbral es 4, frente al peso 1 de una lectura aislada.

Desmarcar un interés y guardar conserva la exclusión ante nuevas señales, recargas y cambios parciales. Solo una selección manual posterior guardada la levanta. Un guardado fallido revierte perfil y decisiones. Las opciones ya incorporadas no se borran solo por caducar una señal. Continúan los mínimos obligatorios del perfil.

Se excluyen mensajes privados, Contacto, microeventos y contenido protegido. El resumen administrativo distingue actividad y estimaciones; no se publica en el Directorio ni depende del filtro temporal del historial. Otros consentimientos y campos privados se conservan.

Fuentes: [AutomaticInterests](wp-content/plugins/ascla-core/src/Services/AutomaticInterests.php), [LearnedInterests](wp-content/plugins/ascla-core/src/Services/LearnedInterests.php), [pruebas de prioridad manual](tests/php/AutomaticInterestsTest.php).

<a id="aliados"></a>

## Aliados y búsqueda por convenio

El catálogo muestra únicamente aliados activos. Registro de aliados incluye activos, inactivos, borradores, programados y pendientes de todos los administradores, con filtro de estado y páginas de 18; excluye papelera. Retirar conserva identificador, datos, archivos, interacciones e historial y cancela una publicación futura. Activar devuelve la misma ficha. Ambas acciones requieren membresía activa y `ascla_manage`; no dependen del autor.

La ficha es institucional, sin autor/fecha editorial, aviso Chatham House, reacciones ni conversación. Conserva las interacciones antiguas sin mostrarlas allí. Administración puede consultar los últimos 20 cambios de estado; el resto permanece en Auditoría. Las fichas heredadas no reciben un historial inventado.

RF-077: catálogo y registro buscan por nombre, descripción y tipo de convenio antes de contar/paginar. Reconocen las etiquetas institucionales mostradas en inglés; los textos aportados por usuarios conservan su idioma. El índice derivado `_ascla_alliance_type` se actualiza al editar y se limpia al eliminar los metadatos originales. La migración por lotes de 200 es transaccional e idempotente y conserva los datos, fechas, autores y estados. Se detecta también en instalaciones que ya declaran 1.10.8; no cambia el esquema 15.

Fuentes: [Allies](wp-content/plugins/ascla-core/src/Services/Allies.php), [búsqueda](wp-content/plugins/ascla-core/src/Repositories/AllySearch.php), [migración](wp-content/plugins/ascla-core/src/Database/AllySearchMigration.php), [pruebas](tests/php/AllySearchTest.php).

<a id="formularios"></a>

## Formularios, idiomas y privacidad editorial

Las validaciones REST y de servicio comprueban tipos, formatos, rangos, listas, metadatos y texto obligatorio visible antes de escribir. Un rechazo conserva los datos y el formulario. Las escrituras críticas comprueban que WordPress haya persistido el cambio y revierten ante fallos. El cambio de contraseña emite la cookie después del commit.

Foros, Hub y las tarjetas compartidas sincronizan su contador de comentarios con el total autorizado al actualizar el detalle. Publicar, responder y eliminar reflejan el resultado al cerrar la conversación, sin recargar el documento ni sustituir la tarjeta; se conservan los filtros. Se utiliza el total publicado del servidor, incluso si el detalle está limitado a cien comentarios, y los pendientes de moderación no se suman. Singular/plural y traducción se mantienen. [Recorrido local](tests/comment-count108.cjs) y [evidencia](docs/evidence/release-1.10.8/comment-count108.json).

El PDF contempla comentarios (RF-178) y reacciones (RF-179), sin un RF específico para Seguir conversación. Por petición del usuario, se retira esa acción de todas las fichas, traducciones y manejadores de interfaz. El backend rechaza tanto activar como desactivar `follow` con HTTP 400; la respuesta de contenido ya no expone `following`. Las filas históricas se conservan para evitar una migración destructiva, sin usarlas como señales de aprendizaje o afinidad. Me gusta, comentarios, reportes y los intereses ya guardados se conservan. Validación local: 48 pruebas PHP/433 aserciones, 16 comprobaciones de navegador sobre siete tipos de contenido en ambos idiomas y 49 controles portables. [Recorrido](tests/follow-removal108.cjs), [evidencia](docs/evidence/release-1.10.8/follow-removal108.json) y [JUnit](docs/evidence/release-1.10.8/follow-removal108-junit.xml).

Eventos pagina 18 elementos, conserva filtros, informa intervalo/total y aplica permisos antes de contar. Fecha se refiere al horario del evento, con días completos de la zona del sitio y solapamiento del intervalo; modalidad usa el catálogo. El editor separa fecha y hora, y distingue publicación del horario del encuentro. Los borradores validan lo proporcionado, pero pueden estar incompletos.

Forms valida el objeto de columnas, índices dentro del CSV, IDs del catálogo y confirmación explícita de advertencias. La asistencia manual valida asociado, minutos, duración del encuentro y umbral entero 1–100 %. No confunde RSVP con asistencia. Las correcciones manuales se conservan al importar.

La búsqueda editorial respeta la vista autorizada del lector y no recupera identidades ocultas. Los títulos originales se conservan; los nombres institucionales de microeventos generados y los enlaces de Calendar usan la proyección autorizada. Solo se traducen etiquetas del sistema, sin reescribir contenido del asociado. Gettext incluye **1.753 mensajes por idioma**; la revisión completa de cadenas sigue pendiente.

<a id="csv-asistencia"></a>

### CSV de asistencia

Zoom admite CSV UTF-8 de hasta 512 KB y 5.000 filas, coma/punto y coma/tabulador, nombres entrecomillados, BOM, cabeceras españolas con acentos y preámbulos vacíos. Rechaza más de 100 columnas, columnas reconocidas repetidas y filas desalineadas antes de atribuir asistencia; el error identifica la fila y se traduce.

Las fechas ISO imposibles, el 29 de febrero en año no bisiesto y los horarios/desfases fuera de rango quedan en Revisión manual, sin minutos o porcentaje inventados. La duración de respaldo no valida un horario inválido. Las duraciones deben ser decimales finitos entre 0 y 10.080 minutos y se verifican antes de convertirlas a enteros. Una fila inválida no oculta una fila válida posterior del mismo correo. La agrupación de reconexiones no cuenta dos veces intervalos solapados.

Fuentes: [ZoomCsv](wp-content/plugins/ascla-core/src/Domain/ZoomCsv.php), [AttendanceSessions](wp-content/plugins/ascla-core/src/Domain/AttendanceSessions.php), [pruebas de formato](tests/php/ZoomInput108Test.php), [conservación por REST](tests/php/AttendanceCsvInput108Test.php).

<a id="correo"></a>

### Correo y credenciales

SMTP exige texto y rechaza tipos incorrectos, null y controles antes de sanear. Servidor: 253 caracteres; usuario/remitente/nombre: 254; contraseña: 2.000 bytes en PHP. El formulario recibe límites compartidos; su maxlength cuenta caracteres. Los puertos admitidos son 465 con SSL/TLS y 587/2525 con STARTTLS.

Las banderas `clear_…` se validan en REST y en el servicio. false, `"false"`, 0 y `"0"` conservan claves; el borrado requiere una bandera verdadera. Una contraseña textual `"0"` y sus espacios significativos se cifran/descifran sin perderse. Vacío conserva la anterior. Los errores no cambian configuración, credenciales ni auditoría; la API informa presencia/origen, sin valores.

Fuentes: [Mailer](wp-content/plugins/ascla-core/src/Integrations/Mailer.php), [Secrets](wp-content/plugins/ascla-core/src/Integrations/Secrets.php), [pruebas de configuración](tests/php/MailSettingsInput108Test.php). La [custodia externa](MANUAL_QA_UAT.md#credenciales) y el envío con el proveedor real requieren comprobación en destino.

<a id="pendientes"></a>

## Pendientes reales

| Requisitos | Cierre necesario |
|---|---|
| RNF-002 | Wordfence/Turnstile reales y política institucional del límite por IP. |
| RNF-003 | HTTPS/TLS 1.2 o superior en los entornos de destino. |
| RNF-006 | Auditoría de acceso a rutas, descargas, REST, cachés y plugins con cuentas anónimas/inactivas. |
| RNF-007 | Migración y custodia externa activa en HTTP/CLI/cron; retirar copias heredadas tras validación. |
| RNF-008/009/010 | Medir navegación <2 s con 50 asociados, mensajería/notificaciones <1,5 s y estadísticas <3 s. |
| RNF-011 | Integridad con el proveedor real de caché y los hooks/efectos externos de terceros. |
| RNF-012 | Degradación y recuperación con SMTP/OAuth/cron y proveedores reales. |
| RNF-013 | Rutas y formatos de entrada restantes, incluidos campos administrativos, entradas nativas y plugins. |
| RNF-014 | Acceso privilegiado, protección y retención de auditoría en el servidor. |
| RNF-016 | Evaluación humana de accesibilidad, lector de pantalla, zoom/reflujo y contenido editorial. |
| RNF-017 | Revisión lingüística de todas las cadenas dinámicas, errores y texto estático. |
| RNF-018 | Compatibilidad real con Wordfence/Akismet y demás componentes instalados. |
| RNF-019 | Las dos versiones estables exigidas de Chrome, Firefox, Safari y Edge. |
| RNF-025 | Copias diarias, retención mínima de siete días y ensayo de restauración. |
| RNF-026 | Historial de disponibilidad y monitoreo operativo. |

El [manual de operación](MANUAL_QA_UAT.md#operacion) reúne el procedimiento. También falta aceptar la revisión final en UAT y comprobar su Quality Gate. La revisión local no acredita esas condiciones por sí sola.

<a id="evidencia"></a>

## Evidencia y estado del paquete

Cada fila conserva su propia revisión: no se suman ejecuciones repetidas como pruebas distintas ni se atribuye una aceptación antigua al paquete posterior. Los resúmenes y JUnit enlazados son publicables; videos, capturas y reportes completos permanecen como artefactos locales en `test-results/`.

| Etapa | Resultado y alcance | Evidencia pública |
|---|---|---|
| Privacidad/Forms 1.10.8 | 529 PHP/6.673 aserciones; cobertura 84,36 % de esa etapa. | [Resumen](docs/evidence/release-1.10.8/privacy108.json) |
| Aceptación completa del QA publicado | 33 peticiones/53 comprobaciones HTTP y tres recorridos UI, cinco videos/capturas; limpieza confirmada. Corresponde al hash de esa ejecución. | [QA remoto](docs/evidence/release-1.10.8/public-qa.json) |
| Administración | 44 PHP/347 aserciones, ocho controles UI; revisión remota de lectura. | [Local](docs/evidence/release-1.10.8/admin108.json), [QA](docs/evidence/release-1.10.8/admin108-qa.json) |
| Aliados institucionales | 98 PHP/831 aserciones, siete controles UI; revisión de cuatro fichas remotas sin modificarlas. | [Local](docs/evidence/release-1.10.8/allies108.json), [QA](docs/evidence/release-1.10.8/allies108-qa.json) |
| Intereses automáticos | 101 PHP/611 aserciones, nueve controles UI; revisión de perfil remoto. Sustituye el comportamiento de la etapa previa con controles globales. | [Local](docs/evidence/release-1.10.8/automatic108.json), [QA](docs/evidence/release-1.10.8/automatic108-qa.json) |
| Búsqueda de Aliados RF-077 | 127 PHP/960 aserciones y siete controles UI; 12 casos específicos repetidos al final, incluidos en el alcance. Revisión de búsqueda en QA. | [Local](docs/evidence/release-1.10.8/allysearch108.json), [QA](docs/evidence/release-1.10.8/allysearch108-qa.json) |
| CSV de asistencia | 156 PHP/729 aserciones, incluidos 33 casos nuevos. Sin despliegue automático. | [Resumen](docs/evidence/release-1.10.8/zoominput108.json), [JUnit](docs/evidence/release-1.10.8/zoominput108-junit.xml) |
| Correo y credenciales | 186 PHP/924 aserciones, incluidos 45 casos nuevos; 49 controles portables y sintaxis JS. Sin envío SMTP real ni despliegue automático. | [Resumen](docs/evidence/release-1.10.8/mailinput108.json), [JUnit](docs/evidence/release-1.10.8/mailinput108-junit.xml) |
| Contacto y accesos de administración | Ocho controles de navegador con datos locales reales, ocho vistas en dos temas y dos tamaños, sin desbordamiento ni infracciones automáticas WCAG A/AA en los componentes revisados; 49 controles portables. Sin nueva suite PHP, cobertura ni despliegue en QA. | [Resumen](docs/evidence/release-1.10.8/contact-layout108.json), [recorrido](tests/contact-layout108.cjs) |
| Reinicio del Asistente CP 071 | Once controles de navegador, seis pruebas PHP/55 aserciones y 49 controles portables. Gemini interceptado; sin consumo de API real, nueva cobertura o aceptación remota completa. | [Resumen](docs/evidence/release-1.10.8/assistant-reset108.json), [JUnit](docs/evidence/release-1.10.8/assistant-reset108-junit.xml) |
| Contadores de comentarios | Ocho controles de navegador con envíos, respuestas, borrado, moderación y totales reales en WordPress local; 49 controles portables. Sin cambios de backend, nueva suite PHP, cobertura ni instalación en QA. | [Resumen](docs/evidence/release-1.10.8/comment-count108.json) |
| Panel de conexiones compacto | Catorce controles de navegador y seis vistas: pestañas, búsqueda, acciones reales, teclado, privacidad y traducción; sin desbordamiento ni infracciones automáticas WCAG A/AA en el panel. 49 controles portables; sin nueva suite PHP, cobertura ni despliegue remoto. | [Resumen](docs/evidence/release-1.10.8/connections-panel108.json) |
| Correcciones de SonarQube | Las 137 incidencias exportadas se reproducen exactamente en SonarQube local 26.9; el código corregido obtiene cero. 677 pruebas PHP/7.565 aserciones, 49 controles portables y doce recorridos de navegador/110 controles aprobados; 148 estados axe registrados sin infracciones automáticas ni desbordamiento. Sin casos nuevos, nueva cobertura, cambios de reglas, exclusiones o umbrales. | [Resumen verificable](docs/evidence/release-1.10.8/sonar-fixes108.json) |

El [paquete vigente](docs/evidence/release-1.10.8/package.json) identifica el ZIP 1.10.8, esquema 15. Las últimas correcciones de CSV, correo, Contacto, Asistente, contadores, Directorio y SonarQube quedan preparadas para instalación manual; no se presentan como ya instaladas en QA. La última aceptación HTTP/UI completa tampoco certifica los ZIP posteriores. No se midió nueva cobertura en esas etapas ni se exige el 100 %. El análisis estático local no acredita el Quality Gate publicado: debe repetirse el flujo de QA sobre el commit que incluya estas correcciones. Los reportes de cobertura anteriores conservan su revisión original.

La consolidación documental no cambia el código del plugin, su ZIP, los resultados anteriores ni los estados de requisitos. La [verificación documental](scripts/check-docs.py) comprueba inventario, enlaces, Git y sincronización de la matriz.
