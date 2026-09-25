=== ASCLA Core ===
Requires at least: 6.6
Requires PHP: 8.2
Stable tag: 1.10.4
License: GPLv2 or later

Intranet privada para la comunidad ASCLA. Instalar este ZIP desde Plugins.
Activación por sitio. Elementor opcional. Datos conservados al desinstalar.
ASCLA > Configuración permite preparar demo e integraciones sin editar PHP.

== Changelog ==

= 1.10.4 =
* Reorganiza Mi perfil en cuatro pestañas: Información, Intereses, Privacidad y avisos, y Cuenta y seguridad, reduciendo el desplazamiento vertical y conservando el guardado existente.
* En móvil, Mensajería abre primero la lista de chats y muestra una sola conversación al seleccionar un chat, con acción para volver a la lista; en escritorio se mantiene la vista dividida.
* RF-013 / CP-021: el Directorio incorpora paginación numérica siempre visible, Anterior/Siguiente, resumen de resultados y un límite fijo de 15 perfiles por página. Frontend y servidor usan el mismo límite; el conjunto demo de 18 perfiles se distribuye en dos páginas (15 + 3).
* Mejora accesibilidad de las pestañas del perfil con roles ARIA, navegación por teclado y apertura automática de la pestaña que contiene un campo obligatorio inválido.
* Refuerza la actualización de app.js/app.css usando versión + hash de contenido para evitar que una caché anterior oculte la interfaz nueva.
* Corrige Google Calendar: al publicar con una cuenta conectada se crea/sincroniza el evento organizador; si el ID remoto dejó de existir, ASCLA recrea el evento.
* Las invitaciones de eventos añaden los correos de los asociados como asistentes de Google Calendar y usan sendUpdates=all para que Google envíe el correo de invitación. Los cambios y cancelaciones del evento organizador también se sincronizan.
* Añade DeepSeek como tercer proveedor de IA real configurable, con modelo independiente (por defecto deepseek-flash), API Key cifrada, eliminación explícita y prueba de conexión.
* Protege los cambios sin guardar de Mi perfil al navegar: el modal ofrece Seguir editando, Descartar cambios y Guardar cambios y salir. La última opción guarda el perfil antes de continuar y cancela la salida si el guardado falla; al recargar o cerrar la pestaña se conserva el aviso nativo del navegador.
* Mantiene el esquema 13 y todas las correcciones funcionales de 1.10.3; no agrega tablas ni modifica la jerarquía de roles.

= 1.10.3 =
* Bloquea la cuenta diez minutos tras cinco intentos fallidos, incluso con contraseña correcta, acceso por correo y Turnstile desactivado.
* Exige seleccionar una solicitud activa del asociado en Contacto para cambiar Cargo o Empresa desde Administración, con uso único y auditoría.
* Valida Nombres, Apellidos, Cargo y Empresa obligatorios en el perfil, tanto en la interfaz como en REST.
* Incluye la etiqueta visible Miembro ASCLA en la búsqueda del directorio respetando privacidad e idioma.
* Permite a ejecutivos preparar propuestas de microeventos; conserva la aprobación administrativa configurada y el esquema 13.

= 1.10.2 =
* Sincroniza la versión pública con el esquema 13 y las migraciones de importaciones/intereses.
* Google Forms admite importación por lotes, normalización por catálogo y sinónimos, revisión administrativa e historial; una fila aprobada queda limitada estrictamente a un máximo de 3 intereses.
* Las respuestas abiertas pueden recibir propuestas de Gemini con confianza y origen visibles, siempre sujetas a aceptar, editar o ignorar antes de modificar el perfil.
* Añade teléfono en formato internacional canónico (+ y código de país), edición/eliminación y privacidad private/members sin exponerlo al contexto de IA o matching.
* Unifica los correos institucionales con plantilla HTML reutilizable, logo, llamada a la acción y pie ASCLA, incluido el correo de prueba SMTP.
* Zoom conserva entradas/salidas, fusiona intervalos superpuestos y clasifica asistencia según un umbral configurable de permanencia; la analítica incorpora intereses de Forms y contenido relacionado.
* Mejora agregaciones/paginación e importaciones por lotes con progreso. La validación final de integraciones reales y QA/UAT se documenta por separado.
* Foros vuelve a una sola acción principal de creación: se elimina el botón redundante Crear tema y se conserva Crear foro.

= 1.10.1 =
* Permite eliminar reportes revisados y enviar a la papelera solicitudes resueltas, con comprobaciones de permisos/estado en servidor, confirmación y auditoría.
* Administración > Estadísticas incorpora resumen, usuarios que más asisten, temas de mayor interés, tendencias e IA y tendencias, para Administrador y Ejecutivo.
* Asistencia manual y CSV de Zoom con vista previa, asociación por correo, reconexiones, importación repetible y prioridad de correcciones manuales; las inscripciones no se convierten en asistencias.
* Tasas basadas en registros completos, interés declarado separado de participación real y comparación de períodos calculada en PHP/SQL.
* IA clasifica títulos depurados en lotes, sin recibir datos de asistencia ni generar cifras; modo demo identificado y validación de resultados.
* Migración compatible al esquema 12; conserva cuentas, roles y datos anteriores. Interfaz en español/inglés y tablas adaptadas a móvil.
* Validación local: 208 pruebas PHP (4.399 comprobaciones) y 28 recorridos de navegador aprobados.

= 1.10 =
* Corrige los 16 pendientes de pruebas: archivos temporales, enlaces de cápsulas, privacidad editorial y respuestas del asistente, junto con pruebas de contratos antiguos.
* La migración al esquema 11 admite archivos temporales sin modificar referencias existentes. Los lectores reciben recursos anonimizados; los editores autorizados conservan las fuentes originales.
* Roles jerárquicos y acumulativos: Administrador > Ejecutivo ASCLA > Moderador > Asociado. Migración compatible de capacidades existentes, también para instalaciones que ya indican 1.10.
* Menús y botones reflejan permisos del servidor; REST y servicios validan capacidades y acceso por objeto. Moderar no concede acceso a archivos personales ni consultas privadas de IA.
* La ficha de asociado muestra sus datos y acciones antes de la explicación de IA. La afinidad determinística se carga en paralelo y la explicación actualiza solo su bloque, respetando cierre y navegación.
* Conserva explicaciones y mensajes sugeridos válidos por pareja en una caché privada limitada a 20 resultados por usuario. Los cambios profesionales, de intereses, privacidad o proveedor invalidan la entrada; las preferencias de correo y cumpleaños no provocan regeneración.
* Valida las respuestas de IA antes de guardarlas y usa respuestas básicas ante errores, cuota o llamadas concurrentes. La corrección de archivos temporales actualiza el esquema a 11, conservando datos existentes.

= 1.9.50 =
* Las imágenes y archivos recién subidos permanecen temporales hasta que el formulario se guarda; si el usuario cancela, cierra o reemplaza la selección, ASCLA los descarta y no aparecen en Mis archivos. Los temporales abandonados se limpian automáticamente.
* Añade toasts de actividad en la esquina inferior derecha para mensajes, conexiones, solicitudes de conversación, eventos y cupos, usando REST + polling cada 7 segundos, sin WebSockets ni sonido.
* Los toasts duran 7 segundos, admiten cerrar/abrir, se apilan hasta 3, respetan permisos y privacidad, evitan avisos redundantes cuando ya se está viendo el destino y no eliminan la notificación persistente de la campana. Mantiene esquema 10.

= 1.9.49 =
* Reordena la fecha de nacimiento dentro del perfil: queda junto al tipo de asociado, con una nota privada compacta, y la página personal aprovecha todo el ancho.
* Restaura Cloudflare Turnstile adaptativo: los primeros intentos no muestran desafío, el widget oficial aparece tras 3 credenciales fallidas y el 5.º fallo activa una espera temporal.
* Añade bloqueo temporal adicional ante ráfagas de intentos fallidos desde una misma IP. Mantiene esquema 10.

= 1.9.48 =
* Añade fecha de nacimiento privada en Perfil y Administración ASCLA; no se muestra a otros asociados ni se utiliza para matching.
* En el cumpleaños, ASCLA muestra un saludo especial dentro de la intranet, intenta enviar un correo anual y avisa una sola vez a los administradores ASCLA con enlace al perfil.
* El login protegido muestra el widget oficial visible de Cloudflare Turnstile en cada acceso; la indicación “Success” corresponde a la comprobación anti-bot y el backend sigue validando usuario y contraseña. Mantiene esquema 10.

= 1.9.47 =
* Añade creación de usuarios directamente desde `/administracion/`, sin abrir `wp-admin`: nombre de usuario, correo, rol ASCLA, nombres y datos profesionales básicos.
* La contraseña inicial se genera de forma segura y no se expone al administrador. Por defecto se solicita a WordPress enviar al nuevo usuario un enlace para configurar su propia contraseña; si el correo no llega, puede usar la recuperación desde `/login/`.
* La creación valida usuario/correo duplicados, limita los roles a Asociado, Ejecutivo y Moderador ASCLA, activa las preferencias base del perfil y registra la operación en Auditoría. Mantiene esquema 10.

= 1.9.46 =
* La gestión de usuarios deja de abrir WordPress para editar cuentas: Administrador puede modificar correo, rol ASCLA, nombres, cargo, empresa y tipo de asociado desde `/administracion/`.
* Añade eliminación de usuarios con una segunda ventana de confirmación y advertencia permanente. No permite eliminar la cuenta propia ni administradores técnicos de WordPress.
* Al eliminar un asociado, sus publicaciones y archivos necesarios se conservan bajo administración de ASCLA; se limpian relaciones, inscripciones, notificaciones y trabajos vinculados a la cuenta. Las acciones quedan registradas en Auditoría. Mantiene esquema 10.

= 1.9.45 =
* El acceso de asociados se canonicaliza a `/login/` sin `redirect_to`, `logged_out` ni parámetros de idioma visibles en la barra de direcciones.
* El destino interno solicitado antes de autenticarse se conserva durante 15 minutos en una cookie HttpOnly firmada y se consume después del login; destinos externos siguen rechazándose.
* El cierre de sesión y el cambio de idioma mantienen `/login/` limpio mediante avisos/cookies temporales y POST/Redirect/GET. `wp-login.php` conserva su flujo nativo para administración y recuperación. Mantiene esquema 10.

= 1.9.44 =
* `/login/` recupera el mismo diseño visual del login ASCLA anterior: panel institucional, tarjeta, espaciado, control de contraseña y elementos auxiliares conservan la estructura del flujo nativo personalizado.
* El acceso técnico por `wp-login.php` conserva el mismo estilo, pero se identifica claramente como **Administración ASCLA** y usa textos orientados a administradores.
* `/wp-admin/` continúa separado de la Intranet: asociados usan `/login/` y `/intranet/`; la administración técnica usa `wp-admin/wp-login.php`. Mantiene esquema 10.

= 1.9.43 =
* Añade una página pública dedicada `/login/` para el acceso de asociados; `/intranet/` y las demás páginas ASCLA redirigen allí cuando no existe sesión y conservan un destino interno seguro.
* El formulario de acceso sigue autenticando con `wp_signon()` y las cookies/permisos de WordPress; no duplica el sistema de cuentas ni contraseñas.
* Cloudflare Turnstile adaptativo protege también el nuevo formulario y mantiene el conteo/bloqueo de intentos fallidos.
* Añade `/administracion/` como workspace de Ejecutivo, Moderador y Administrador. Ejecutivo/Moderador que intentan abrir `/wp-admin/` son redirigidos allí; el backoffice WordPress queda reservado a administradores técnicos con `manage_options`.
* `wp-login.php` permanece como respaldo para recuperación/restablecimiento de contraseña y autenticación técnica; cerrar sesión desde la Intranet vuelve a `/login/` con confirmación. Mantiene esquema 10.

= 1.9.42 =
* RF-040: la afinidad entre asociados conserva intereses, áreas, industrias, objetivos e idiomas como señales principales y añade experiencia profesional relacionada como señal secundaria, respetando campos ocultos.
* RF-040: la actividad pública en temas de la comunidad puede aportar un refuerzo pequeño a la afinidad; chats privados, soporte y asistencia a eventos no se usan para esta señal.
* RF-041: las recomendaciones del Centro de Conocimiento combinan intereses explícitos, información profesional y actividad reciente en contenido público; la recencia solo desempata recursos ya relevantes.
* Las tarjetas recomendadas explican de forma breve por qué aparecen (intereses, perfil profesional o actividad reciente), sin exponer datos privados. Mantiene esquema 10; no requiere migración.

= 1.9.41 =
* RF-031: un asociado con RSVP confirmado puede consultar a las personas inscritas visibles según las preferencias de privacidad de cada perfil.
* La sección de participantes se presenta como una cuadrícula visual con fotografía/iniciales, nombre, cargo/empresa cuando sean públicos y acceso al perfil dentro de la misma página.
* Los asociados que no participan en el directorio o cuyos datos estén ocultos no exponen información privada; se informa únicamente que existen participantes no visibles. Antes del RSVP la sección no se entrega ni se muestra.
* Moderación conserva su listado administrativo completo. Mantiene esquema 10; no requiere migración de base de datos.

= 1.9.40 =
* RF-025: los eventos admiten una portada privada JPG, PNG o WebP desde el editor de creación y edición.
* La imagen del evento se recorta/optimiza con proporción recomendada 16:9, se muestra en las tarjetas y en el detalle, y puede reemplazarse o quitarse al editar.
* El backend limita la portada del evento a una sola imagen y rechaza PDFs u otros tipos de archivo para este campo. Mantiene esquema 10; no requiere migración de base de datos.

= 1.9.39 =
* RF-024: los Administradores y Ejecutivos pueden cancelar un evento publicado sin eliminar su historial; el evento queda visible como Cancelado y bloquea nuevas inscripciones, invitaciones, seguimiento y altas en Google Calendar.
* Al cancelar, ASCLA notifica una sola vez a asociados inscritos, invitados, con cupo ofrecido o en lista de espera. Las notificaciones internas se conservan aunque falle el correo.
* RN-011: los avisos internos de cada mensaje siguen siendo inmediatos, pero el correo de Mensajería se limita a un máximo de un aviso por conversación cada 15 minutos para evitar un email por cada interacción menor.
* Los correos de Mensajería no incluyen el contenido privado. Mantiene esquema 10; no requiere migración de base de datos.

= 1.9.38 =
* RF-029: cuando el aforo está completo, el asociado puede incorporarse explícitamente a una lista de espera y consultar su posición.
* RF-030: al liberarse un cupo, se reserva para la primera persona en espera y se le notifica para que confirme o rechace antes de registrar el RSVP.
* Mientras un cupo está ofrecido, queda reservado para evitar que otro registro salte la cola; si se rechaza, pasa automáticamente al siguiente asociado.
* La vista del evento muestra aforo, cupos disponibles y cantidad de personas en espera. Mantiene esquema 10; no requiere migración de base de datos.

= 1.9.37 =
* RF-033: las solicitudes de soporte generan notificaciones internas de recepción para el asociado y de nueva solicitud para el equipo de moderación.
* RF-038: cuando Moderación cambia el estado de una solicitud, el asociado recibe una notificación interna con el nuevo estado (Recibida, En atención o Resuelta).
* Repetir el mismo estado no duplica avisos. Las notificaciones enlazan a Mis solicitudes para el asociado y a ASCLA > Solicitudes para Moderación.
* Mantiene esquema 10; no requiere migración de base de datos.

= 1.9.36 =
* Editar grupo protege cambios sin guardar: al intentar cerrar desde el fondo, la X o Escape se ofrece seguir editando, descartar o guardar.
* RF-035: conexiones, mensajes y eventos pueden complementar sus avisos internos mediante correo electrónico.
* Perfil incorpora preferencias de correo para Conexiones, Mensajes y Eventos; las tres están activadas por defecto y cada asociado puede desactivarlas independientemente.
* Los correos de mensajería no incluyen el contenido privado del mensaje. Mantiene esquema 10; las preferencias se guardan como metadatos de usuario.

= 1.9.35 =
* Las ventanas emergentes se cierran al hacer clic en el fondo exterior, además de conservar la X y la tecla Escape.
* Los nombres de autores en conversaciones/comentarios son interactivos y abren el perfil del asociado dentro de la misma página.
* Mantiene esquema 10; no requiere migración de base de datos.

= 1.9.34 =
* El creador del grupo puede editar nombre, fotografía y una descripción breve desde Información del grupo.
* Añade esquema 10 para guardar la descripción de los grupos y mantiene la edición protegida en backend para el creador.
* Todos los controles “Ver perfil” de la intranet abren el perfil del asociado dentro de un modal en la página actual, en lugar de navegar a la página de perfil propio.
* El encabezado de los chats privados también abre el perfil del otro asociado dentro de la mensajería.

= 1.9.33 =
* Simplifica las acciones de perfiles: cuando una conversación ya está autorizada, deja de mostrar la etiqueta redundante “Conversación autorizada” y conserva únicamente la acción para enviar mensaje.
* Mejora el menú Información del grupo con una lista de integrantes más clara, acceso al perfil y acción Mensaje privado para cada integrante distinto del usuario actual.
* Mensaje privado reutiliza el flujo existente: abre el chat si ya está autorizado, abre la solicitud pendiente si existe o permite enviar el primer mensaje como solicitud de conversación.
* Elimina el botón Cerrar inferior del menú del grupo y conserva únicamente la X superior. La eliminación del grupo permanece dentro del menú y solo para el creador. No requiere migración; se mantiene el esquema 9.

= 1.9.32 =
* Separa las solicitudes de conversación en una bandeja propia dentro de Mensajería, manteniendo el primer mensaje, vista previa y contador de pendientes/no leídos.
* Las solicitudes recibidas dejan de mezclarse con los chats normales; al aceptarlas pasan automáticamente a la bandeja de Chats.
* Añade un menú de información para grupos con fotografía, nombre, lista de integrantes e identificación del creador.
* El botón Eliminar grupo se mueve al menú de información y sigue disponible únicamente para quien creó el grupo. No requiere migración; se mantiene el esquema 9.

= 1.9.31 =
* Las solicitudes de conversación ahora funcionan como solicitudes de mensajes: el remitente escribe el primer mensaje y el destinatario puede leerlo antes de aceptar o rechazar.
* Los chats grupales admiten una foto cuadrada visible para todos los participantes del grupo.
* El creador del grupo puede eliminarlo; la eliminación retira la conversación y sus mensajes para todos los participantes.
* Añade esquema 9 para almacenar la fotografía del grupo y conserva los recibos Enviado/Leído de la versión anterior.

= 1.9.30 =
* Añade solicitudes de conversación independientes de las conexiones: enviar, aceptar, rechazar y cancelar; al aceptar se habilita el chat directo sin crear una conexión profesional.
* Añade chats grupales de hasta 50 participantes, creados a partir de conexiones confirmadas, con nombre de grupo y notificaciones internas.
* Añade confirmación de lectura en mensajería: Enviado/Leído en chat directo y Leído por N de M en grupos, reutilizando el cursor last_read existente.
* Añade esquema 8 para distinguir conversaciones directas y grupales y guardar título y creador del grupo.

= 1.9.29 =
* El análisis de videos activa automáticamente los Temas ASCLA que estén claramente respaldados por el contenido, preservando los temas elegidos manualmente.
* La duración se consulta primero desde YouTube Data API mediante OAuth; como respaldo se prueban metadatos públicos y el YouTube IFrame Player API del navegador. Nunca se usa la transcripción para estimar tiempo.
* Si no existe una transcripción manual y YouTube no puede entregar subtítulos autorizados, ASCLA detiene la generación y muestra un aviso explícito en vez de producir resúmenes, notas o cápsulas sin evidencia.
* Ejecutivos y Administradores pueden conectar YouTube OAuth para metadatos/transcripciones del Centro de Conocimiento.
* No requiere migración de base de datos; se mantiene el esquema 7.

= 1.9.28 =
* Las palabras clave del Centro de Conocimiento participan como señal secundaria de recomendación al coincidir con intereses, áreas, industrias u objetivos del perfil.
* Los intereses explícitos del asociado conservan el mayor peso; una coincidencia directa se ordena por encima de una recomendación basada solo en palabras clave.
* La recencia únicamente desempata recursos ya relevantes y no recomienda contenido sin relación temática.
* Los avisos de nuevos recursos reutilizan la misma lógica de relevancia para mantener consistencia entre Inicio y Notificaciones.
* No requiere migración de base de datos; se mantiene el esquema 7.

= 1.9.27 =
* Los resúmenes generados describen directamente el video y dejan de presentar el contenido como "la transcripción".
* La publicación enriquecida ya no muestra una sección Fuente redundante ni el botón Descargar infografía.
* La duración se obtiene únicamente desde metadatos verificables del video de YouTube (API OAuth o metadatos públicos); nunca se infiere desde la transcripción. Si no puede verificarse, queda por confirmar y no se generan cápsulas temporales.
* Las tarjetas del Centro de Conocimiento muestran Borrador o Pendiente de revisión cuando el recurso aún no está publicado.
* No requiere migración de base de datos; se mantiene el esquema 7.

= 1.9.26 =
* Generar resumen y nota enriquece la publicación original y deja de crear recursos separados, borradores del Hub o posts independientes para cápsulas.
* La duración del video se actualiza automáticamente con metadatos de YouTube cuando están disponibles o, como respaldo, con timestamps válidos de la transcripción.
* Los marcos, conceptos, normativas, conclusiones y palabras clave omiten placeholders de anonimización sin significado como participante o dato reservado.
* Los nuevos recursos creados por Administradores/Ejecutivos usan Publicado como estado predeterminado.
* No requiere migración de base de datos; se mantiene el esquema 7.

= 1.9.25 =
* Administradores y Ejecutivos pueden publicar directamente recursos del Centro de Conocimiento, incluidos borradores generados por IA cuando se guardan explícitamente como revisados/publicados.
* Las notas técnicas integran Resumen, marcos, conclusiones, conceptos, palabras clave, cápsulas y fuente de origen dentro de la misma publicación, eliminando el bloque separado Resultados y fuentes.
* La anonimización usa redacción natural como participante o dato reservado y elimina el marcador visible [identidad reservada].
* Las cápsulas sugeridas respetan la duración real: videos de hasta 3 minutos no generan cápsulas; 3–10 min máximo 1; 10–30 min máximo 2; más de 30 min máximo 3. Ningún tiempo puede exceder la duración real del video.
* No requiere migración de base de datos; se mantiene el esquema 7.

= 1.9.24 =
* Repara Microeventos: elimina referencias mensuales a propuestas borradas y permite volver a generar encuentros cuando las propuestas anteriores ya no existen.
* Los resultados históricos de trabajos de microeventos dejan de mostrar enlaces Revisar hacia eventos eliminados.
* Al eliminar un microevento se limpia su caché mensual y sus inscripciones asociadas.
* En las tarjetas del Directorio, el estado Conectados se muestra junto a la afinidad y deja libre la zona de acciones para Ver perfil, mensajería y gestión de la conexión.
* No requiere migración de base de datos; se mantiene el esquema 7.

= 1.9.23 =
* Permite eliminar notificaciones individuales desde la bandeja de actividad.
* Permite cancelar solicitudes de conexión enviadas; al cancelarlas se elimina también la notificación de solicitud del destinatario.
* Permite eliminar una conexión confirmada mediante una confirmación explícita; la mensajería queda deshabilitada sin borrar el historial.
* Añade etiquetas ES/EN y auditoría para cancelaciones y eliminación de conexiones.
* No requiere migración de base de datos; se mantiene el esquema 7.

= 1.9.22 =
* Configura un único proveedor de IA a la vez: Gemini, OpenAI/ChatGPT o modo demo, mostrando solo los campos del proveedor activo.
* Reorganiza Configuración para agrupar opciones relacionadas y simplifica el Motor de afinidad al porcentaje mínimo de recomendación.
* Mejora Auditoría con explicaciones de propósito, nombres de actor y acciones legibles.
* Corrige el estado Inscrito en modo oscuro y elimina el mensaje visual de actualización automática de Mensajería.

= 1.9.21 =
* Corrige el hero de Administración: el logo vuelve a una escala compacta y deja de dominar la pantalla.
* Rediseña Auditoría con resumen de actividad, registro protegido, mejor jerarquía de columnas y estilos claro/oscuro.
* Reorganiza Configuración en tarjetas temáticas para participación, seguridad, afinidad, IA, Google, correo y propiedad intelectual.
* Añade una barra de guardado visible y responsive en Configuración sin cambiar los campos ni permisos existentes.
* Corrige los saltos de línea escapados del bloque CSS administrativo de 1.9.20 para asegurar que sus estilos se apliquen correctamente.
* No requiere migración de base de datos; se mantiene el esquema 7.

= 1.9.20 =
* Renueva el panel de Administración con cabecera, métricas, pestañas, filtros, tablas y formularios visualmente consistentes.
* Añade selector Español/English y Apariencia en la barra superior administrativa, guardando el idioma por usuario.
* Actualiza la presentación de la biblioteca administrativa y mejora el comportamiento responsive del panel.
* Versiona las URLs de los logos oficiales para evitar que el navegador conserve recursos de marca antiguos en caché.
* No requiere migración de base de datos; se mantiene el esquema 7.

= 1.9.19 =
* País/región y Ciudad separan el campo visible del valor enviado para evitar que Chrome superponga su autocompletado de direcciones sobre las sugerencias de ASCLA.
* Login e Inicio eliminan las placas blancas alrededor del logotipo; el recurso normal se usa en superficies claras y la variante inversa en superficies oscuras.
* La barra lateral conserva el logo blanco y aumenta el espacio antes de COMUNIDAD DE ASOCIADOS.
* No requiere migración de base de datos; se mantiene el esquema 7.

= 1.9.18 =
* Perfil reemplaza los datalist nativos de País/región y Ciudad por un autocompletado propio, compacto y navegable con teclado, inspirado en patrones de directorios profesionales como LinkedIn.
* Ciudad permanece vinculada al país seleccionado y conserva la validación real del catálogo geográfico introducida en 1.9.17.
* Actualiza el logo horizontal oficial de ASCLA para login y cabecera de Inicio.
* La barra lateral usa un recurso de logo blanco independiente para mejorar legibilidad sobre el fondo oscuro.
* No requiere migración de base de datos; se mantiene el esquema 7.

= 1.9.17 =
* Simplifica Foros: elimina la acción duplicada inferior y conserva el botón azul superior con la etiqueta Crear foro.
* Añade una recomendación no invasiva al iniciar en Inicio cuando el perfil está por debajo del 40% de completitud.
* Perfil ofrece sugerencias normalizadas de país y ciudad; las ciudades se consultan según el país elegido y se cachean en WordPress.
* El país se valida contra ISO 3166 y la ciudad se valida contra el catálogo remoto cuando está disponible, sin bloquear el guardado si el servicio geográfico está temporalmente caído.
* No requiere migración de base de datos; se mantiene el esquema 7.

= 1.9.16 =
* Refina el login: logo ASCLA mejor proporcionado y presentación más limpia de Cloudflare Turnstile.
* Aclara visualmente el hero de Inicio en modo claro sin alterar el modo oscuro.
* Retira el botón general Actualizar de la cabecera de Administración.
* Alinea los filtros de Estado en Solicitudes y de Interés/Área de conocimiento en el Directorio.


= 1.9.15 =
* Administración > Configuración usa el modal propio de ASCLA al intentar salir con cambios pendientes, incluso al navegar mediante enlaces del panel de WordPress.
* El directorio reorganiza las tarjetas de asociados en bloques proporcionales de información, afinidad/intereses y acciones.
* Las acciones de conexión quedan alineadas y con alturas consistentes; la tarjeta propia ofrece Editar mi perfil.
* No requiere migración de base de datos; se mantiene el esquema 7.

= 1.9.14 =
* ASCLA bloquea el auto-registro público de WordPress: las cuentas solo pueden ser creadas por la administración.
* Retira Registro público de Configuración → Seguridad y elimina ese flujo de la integración Turnstile.
* Turnstile permanece disponible para login, recuperación de contraseña y formularios públicos adaptativos.
* No requiere migración de base de datos; se mantiene el esquema 7.

= 1.9.13 =
* Añade Cloudflare Turnstile opcional en modo Managed para login y recuperación de contraseña, además de una política adaptativa reutilizable para otros formularios públicos.
* Login solicita Turnstile después de 3 fallos y aplica bloqueo temporal desde 5 fallos.
* Recuperación de contraseña solicita Turnstile después de 2 solicitudes consecutivas.
* Un navegador/IP verificado se considera confiable durante 24 horas para evitar desafíos repetitivos, salvo nuevos patrones de abuso.
* Los intentos usan principalmente IP + usuario/correo y una señal IP secundaria para reducir bloqueos injustos en redes compartidas.
* La validación del token se realiza del lado del servidor con Cloudflare Siteverify y la Secret Key se almacena cifrada.
* Administración → Configuración → Seguridad permite activar Turnstile, guardar claves y elegir los formularios protegidos.
* No requiere migración de base de datos; se mantiene el esquema 7.

= 1.9.12 =
* Filtro por usuario propietario en Administración > Archivos.
* Mensajes sugeridos por IA redactados como el asociado remitente, sin voz de asistente.
* Modal ASCLA para advertir cambios sin guardar durante la navegación interna.
* Esquema de base de datos sin cambios (7).

= 1.9.11 =
* Perfil > Mis archivos muestra únicamente los archivos propios, incluso para administradores; la vista global queda en Administración > Archivos.
* Añade OpenAI / ChatGPT como proveedor alternativo de IA junto a Gemini y DEMO, con modelo, clave y prueba de conexión independientes.
* Permite marcar reportes como revisados, registra moderador y fecha, y prioriza los reportes pendientes en Moderación.
* Añade esquema 7 para el estado de revisión de reportes.


= 1.9.10 =
* Mejora el espaciado de Inicio entre Conocimiento para tu día a día y Publicados recientemente.
* Añade editor previo de imágenes con vista previa, movimiento, zoom y recorte por contexto antes de guardar.
* Optimiza imágenes en el navegador, evita ampliar fuentes pequeñas y conserva una copia maestra no recortada y optimizada cuando se aplica recorte.
* Añade esquema 6 para enlazar la versión visible de una imagen con su copia maestra privada y gestionarlas juntas.

= 1.9.9 =
* Convierte el Asistente ASCLA en una experiencia de chat con conversaciones separadas e historial por hilo.
* Consulta contexto vivo y autorizado de la intranet para responder sobre próximos eventos, publicaciones recientes, notificaciones y recomendaciones de asociados.
* Mantiene el Centro de Conocimiento como fuente verificable y prioriza los datos internos actuales sobre el historial conversacional.
* Conserva Chatham House y los permisos del usuario al construir el contexto enviado al proveedor de IA.
= 1.9.8 =
* Los menús contextuales de tres puntos son exclusivos: al abrir uno se cierra cualquier otro menú abierto y al pulsar fuera se cierran.
* Revisión de traducciones inglés/español en Inicio, Perfil, comentarios, eventos, filtros, contenido generado y centro de notificaciones, incluidas fechas relativas y avisos dinámicos.

= 1.9.7 =
* Los comentarios ajenos pueden reportarse desde el menú de tres puntos; los comentarios propios nunca muestran esa opción y el servidor también la bloquea.
* Las notificaciones de comentarios y respuestas incluyen el nombre del asociado remitente cuando las reglas de privacidad permiten mostrarlo.

= 1.9.6 =
* Menús discretos de tres puntos para eliminar mensajes propios y comentarios.
* Respuestas anidadas y Me gusta en comentarios; las respuestas notifican al autor del comentario respondido.


= 1.9.5 =
* Se elimina la descarga ICS, los eventos finalizados ya no ofrecen acciones para añadirlos a Google Calendar y el administrador puede definir la afinidad mínima para recomendaciones.

= 1.9.4 =
* Activa por defecto Descubrir nuevas conexiones y Participar en microeventos, respetando cambios posteriores del usuario.
* Los eventos finalizados ya no permiten inscripción, invitaciones ni comenzar a seguir la conversación.
* Los comentarios del autor en su propia publicación no generan una notificación para sí mismo.

= 1.9.3 =
* Corrige contrastes del modo oscuro en Inicio y Perfil, incluidos “Publicados recientemente”, la tarjeta “Un espacio de confianza” y los estados Visible/Oculto.
* El control de apariencia muestra un sol en modo claro y una luna en modo oscuro.

= 1.9.2 =
* Añade apariencia automática/clara/oscura con detección del dispositivo y persistencia local.
* Simplifica el texto de recuperación de contraseña del perfil.

= 1.9.1 =
Ajuste visual del cambio de contraseña: contraseña actual en una fila y nueva contraseña con confirmación en paralelo. Aviso de cambios sin guardar al salir del Perfil o de Configuración administrativa, incluyendo navegación interna y cierre/recarga del navegador.

= 1.9.0 =
Cambio de contraseña dentro de la intranet con validación de la contraseña actual y recuperación por correo; reportes con motivo y detalle opcional, visibles para moderación. Se conserva el selector de idioma ASCLA con Español e English.

= 1.7.0 =
Eliminación de contenido y comentarios por autor o administrador; biblioteca y eliminación de archivos privados; eventos exclusivos de administradores con publicación directa; idioma de WordPress guardado por cuenta y navegación inglés/español. Corrección de abstenciones guardadas.

= 1.6.0 =
Panel de administración con solicitudes y usuarios; mensajería cada 2 segundos; Foros sin aprobación; Galería y Conocimiento administrados por administradores. Google Gemini REST configurable, prueba de conexión y respuestas naturales con hasta seis fuentes relevantes.
= 1.5.0 =
* Solicitudes de conexión: aceptar, rechazar, estado entrante/saliente y conexiones confirmadas.
* RN-010: mensajería privada solo entre conexiones confirmadas, con validación en todas las rutas.
* Chat con foto privada, fallback y acceso al perfil; sin migración de esquema.

= 1.4.2 =
* Mensajería: recepción automática, conversaciones nuevas, contadores, borradores e historial incremental.
* Correo: SMTP configurable y cifrado; recuperación nativa de WordPress probada con buzón local.
* Configurar un proveedor SMTP para entregar correos reales en el servidor destino.

= 1.4.1 =
* Perfil: etiquetas de intereses, grupos desplegables, interruptores y estados de privacidad accesibles.

= 1.4.0 =
Navegación interna sin recarga con History API, shell inicial desde WordPress y perfil demo idempotente con contraseña configurable.
= 1.3.0 =
Privacidad consistente, avisos robustos, validación previa de ediciones, fidelidad extractiva y redacción pre/post IA. Networking y microagendas conectados a providers; multimedia demo con cápsulas e infografía sustentadas.
= 1.2.0 =
Notificaciones con contexto y destinos autorizados, filtros, paginación, contador y lectura. Historial de consultas y respuestas guardadas. Migración de esquema 4 compatible con avisos anteriores.