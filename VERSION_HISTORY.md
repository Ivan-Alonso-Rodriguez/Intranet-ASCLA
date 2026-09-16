# Historial de versiones — Intranet ASCLA

Este documento registra la evolución funcional del proyecto **Intranet ASCLA / ASCLA Core**. Su objetivo es dejar evidencia clara del progreso realizado entre entregas y facilitar la revisión del repositorio en GitHub.

> **Versión actual:** `1.9.50`  
> **Esquema de base de datos:** `10`  
> La versión `1.9.50` evita conservar archivos de cargas canceladas y añade notificaciones tipo toast mediante REST + polling cada 7 segundos, reutilizando el centro de notificaciones sin afectar el sitio público.

## Resumen de versiones

| Versión | Enfoque principal | Estado |
|---|---|---|
| 1.0.3 | Base funcional inicial recuperada del historial Git | Histórica |
| 1.0.4 | Acceso e inicio de sesión ASCLA | Histórica |
| 1.1.0 | Directorio, agenda, networking y filtros | Histórica |
| 1.2.0 | Centro de notificaciones y actividad | Histórica |
| 1.3.0 | Snapshot recuperado | Histórica |
| 1.4.0 | Snapshot recuperado | Histórica |
| 1.4.1 | Snapshot recuperado | Histórica |
| 1.4.2 | Versión documentada, snapshot fuente no disponible | Histórica |
| 1.5.0 | Snapshot recuperado | Histórica |
| 1.6.0 | Snapshot recuperado | Histórica |
| 1.7.0 | Último estado del historial recibido originalmente | Histórica |
| 1.9.0 | Idiomas, seguridad de cuenta y sistema de reportes | Completada |
| 1.9.1 | UX de contraseña y protección de cambios sin guardar | Completada |
| 1.9.2 | Modo oscuro con detección automática | Completada |
| 1.9.3 | Correcciones visuales y de contraste del modo oscuro | Completada |
| 1.9.4 | Preferencias por defecto y reglas para eventos pasados | Completada |
| 1.9.5 | Calendario, retiro de ICS y afinidad mínima | Completada |
| 1.9.6 | Comentarios enriquecidos y menús contextuales | Completada |
| 1.9.7 | Reportes de comentarios y notificaciones identificables | Completada |
| 1.9.8 | Menús exclusivos y ampliación de traducciones ES/EN | Completada |
| 1.9.9 | Asistente ASCLA conversacional con contexto vivo | Completada |
| 1.9.10 | Edición, recorte y optimización de imágenes | Completada |
| 1.9.11 | Permisos de archivos, OpenAI y seguimiento de reportes | Completada |
| 1.9.12 | Filtro de archivos por usuario, mensajes sugeridos y guardia de cambios | Completada |
| 1.9.13 | Cloudflare Turnstile adaptativo y protección de formularios públicos | Completada |
| 1.9.14 | Acceso cerrado de asociados y ajuste de Turnstile | Completada |
| 1.9.15 | Guardia de configuración y tarjetas proporcionales del directorio | Completada |
| 1.9.16 | Pulido visual de login, Inicio y filtros | Completada |
| 1.9.17 | Foros simplificados, completitud de perfil y ubicaciones normalizadas | Completada |
| 1.9.18 | Autocompletado profesional de ubicación y nueva identidad visual | Completada |
| 1.9.19 | Corrección de autofill y logos transparentes | Completada |
| 1.9.20 | Renovación visual de Administración, idioma y branding | Completada |
| 1.9.21 | Pulido del hero, Auditoría y Configuración administrativa | |
| 1.9.22 | Proveedor IA exclusivo, configuración simplificada y auditoría explicativa | Completada |
| 1.9.23 | Gestión de notificaciones y ciclo completo de conexiones | Completada |
| 1.9.24 | Reparación de microeventos y estado visual de conexiones | Completada |
| 1.9.25 | Publicación editorial directa, notas integradas y cápsulas coherentes | Completada |
| 1.9.26 | Nota técnica en recurso original, duración automática y publicación por defecto | Completada |
| 1.9.27 | Resumen editorial, duración verificada y estados visibles | Completada |
| 1.9.28 | Recomendaciones de conocimiento reforzadas por palabras clave | Completada |
| 1.9.29 | Temas automáticos, duración YouTube y transcripción segura | Completada |
| 1.9.30 | Solicitudes de conversación, grupos y lectura | Completada |
| 1.9.31 | Solicitudes con mensaje, foto y eliminación de grupos | Completada |
| 1.9.32 | Bandeja de solicitudes y menú de información de grupos | Completada |
| 1.9.33 | Pulido de perfiles y acciones de integrantes del grupo | Completada |
| 1.9.34 | Edición de identidad del grupo y perfiles en modal | Completada |
| 1.9.35 | Cierre por fondo y perfiles desde comentarios | Completada |
| 1.9.36 | Guardado protegido y notificaciones opcionales por correo | Completada |
| 1.9.37 | RF-033 y RF-038: notificaciones del ciclo de soporte | Completada |
| 1.9.38 | RF-029 y RF-030: lista de espera y liberación de cupo | Completada |
| 1.9.39 | RF-024 y RN-011: cancelación de eventos y correo controlado | Completada |
| 1.9.40 | RF-025: imagen de portada del evento | Completada |
| 1.9.41 | RF-031: participantes visibles después del RSVP | Completada |
| 1.9.42 | RF-040 y RF-041: recomendaciones enriquecidas y explicables | Completada |
| 1.9.50 | Cargas temporales cancelables y notificaciones toast | **Actual** |
| 1.9.49 | Perfil de cumpleaños refinado y Turnstile adaptativo | Completada |
| 1.9.48 | Cumpleaños privados y Turnstile oficial visible | Anterior |
| 1.9.47 | Creación de usuarios dentro de Administración ASCLA | Completada |
| 1.9.46 | Edición y eliminación de usuarios dentro de Administración ASCLA | Completada |
| 1.9.45 | `/login/` limpio con retorno interno seguro sin `redirect_to` visible | Completada |
| 1.9.44 | Login de comunidad con diseño anterior + acceso nativo identificado como Administración ASCLA | Completada |
| 1.9.43 | Acceso ASCLA separado en `/login/` y protección de `/intranet/` | Anterior |

---

# Serie 1.9.x — evolución funcional

## 1.9.50 — cargas temporales y notificaciones toast

- Las imágenes y documentos recién cargados quedan marcados como **temporales** hasta que el formulario que los utiliza se guarda correctamente.
- Si el usuario cancela el formulario, cierra el modal, descarta cambios, quita la imagen o la reemplaza, la carga temporal se descarta mediante una ruta REST propia y deja de ocupar la biblioteca de archivos.
- Los temporales no aparecen en **Mis archivos** ni en el filtro global de propietarios. Un cierre inesperado del navegador no los vuelve visibles: los registros abandonados se limpian automáticamente después de 12 horas.
- Guardar Perfil, identidad de un grupo o contenido promueve/adjunta los archivos utilizados para que permanezcan normalmente en ASCLA.
- Se incorpora un módulo frontend independiente `live-toasts.js` que reutiliza el modelo actual de notificaciones y consulta novedades por REST cada **7 segundos**; no utiliza WebSockets.
- Los toasts cubren **nuevo mensaje, solicitud de conexión, conexión aceptada, solicitud/aceptación de conversación, invitaciones y cambios importantes de eventos, cancelaciones y cupos disponibles**.
- Se muestran abajo a la derecha con icono, categoría, título, descripción, acción y cierre `×`; desaparecen automáticamente en aproximadamente 7 segundos, se apilan como máximo tres y no reproducen sonido.
- Si el asociado ya está viendo la conversación, perfil, evento o sección de destino, el toast redundante no se muestra. La notificación continúa en la campana hasta que el usuario la lea o la elimine desde el centro de notificaciones.
- Las URLs y descripciones de toast se resuelven con el mismo `NotificationTarget`, por lo que conservan permisos, privacidad, bloqueos y disponibilidad del contenido. Los assets se cargan únicamente en páginas ASCLA, sin afectar el sitio público de WordPress.
- Los cambios significativos de un evento (nombre, horario, modalidad, ubicación, enlace o aforo) generan un aviso específico para asociados relacionados con el evento.
- No cambia el esquema de base de datos: continúa en **10**.

## 1.9.49 — perfil de cumpleaños refinado y Turnstile adaptativo

- **Fecha de nacimiento** se integra en la cuadrícula del perfil junto al tipo de asociado y muestra una nota privada compacta debajo del campo, evitando el espacio vacío que generaba la versión anterior.
- País y ciudad conservan su fila conjunta; LinkedIn y X/Twitter permanecen emparejados y Página personal utiliza todo el ancho disponible.
- El cumpleaños continúa siendo privado y conserva el saludo dentro de ASCLA, correo anual y aviso único a administradores.
- El login vuelve a la política adaptativa: primer, segundo y tercer intento procesan credenciales sin Turnstile; después del **tercer fallo** el siguiente acceso muestra el widget oficial de Cloudflare.
- Tras el **quinto fallo** para el mismo usuario/correo se aplica una espera temporal de 10 minutos. Una ráfaga de **20 fallos** desde la misma IP dentro de la ventana de control activa un bloqueo temporal adicional de 15 minutos.
- El widget, cuando corresponde, sigue siendo el oficial de Cloudflare; “Success” confirma la comprobación anti-bot y no sustituye la validación de credenciales.
- No cambia el esquema de base de datos: continúa en **10**.

## 1.9.48 — cumpleaños privados y Turnstile oficial visible

- El perfil incorpora **Fecha de nacimiento** como dato privado. No se muestra en el directorio ni en perfiles ajenos y no participa en recomendaciones o afinidad.
- En el día del cumpleaños se muestra un saludo especial en Inicio y una bienvenida visual la primera vez que el asociado entra a ASCLA durante ese día.
- ASCLA intenta enviar una felicitación por correo **una sola vez al año** mediante el transporte configurado, sin incluir edad ni exponer la fecha de nacimiento.
- Los Administradores ASCLA reciben una notificación interna única indicando qué asociado cumple años, con acceso a su perfil para poder saludarlo.
- Crear y editar usuarios desde `/administracion/` también permite registrar la fecha de nacimiento.
- Turnstile se normalizó al widget oficial visible de Cloudflare en cada acceso protegido.
- No cambia el esquema de base de datos: continúa en **10**.

## 1.9.47 — alta de usuarios dentro de Administración ASCLA

- **Añadir usuario** deja de abrir `wp-admin/user-new.php`: el Administrador crea la cuenta desde un modal propio de `/administracion/`.
- El formulario solicita nombre de usuario, correo, rol ASCLA, nombres, apellidos, cargo, empresa y tipo de asociado. Los roles disponibles se limitan a **Asociado, Ejecutivo y Moderador ASCLA**; la creación de administradores técnicos continúa exclusivamente en WordPress.
- La contraseña inicial se genera con `wp_generate_password()` y **no se muestra ni se almacena en texto plano**. Por defecto, WordPress envía al correo registrado el flujo para que el usuario establezca su propia contraseña; si se desactiva el envío o el correo no llega, el nuevo usuario puede utilizar “¿Olvidaste tu contraseña?” desde `/login/`.
- Se validan nombres de usuario y correos duplicados tanto antes de crear como dentro del bloqueo de concurrencia. La creación tiene límite de frecuencia administrativo y queda registrada como `member_created` en **Auditoría**.
- El perfil inicial activa Directorio, Networking y Microeventos y guarda los datos profesionales básicos sin crear un esquema paralelo de autenticación. Continúa usando `wp_users`, roles y contraseñas nativas de WordPress.
- No cambia el esquema de base de datos: continúa en **10**.

## 1.9.46 — gestión de usuarios dentro de Administración ASCLA

- **Editar usuario** ya no abre `wp-admin/user-edit.php`: Administrador trabaja en un modal propio dentro de `/administracion/`, con correo, rol ASCLA, nombres, cargo, empresa y tipo de asociado.
- La edición utiliza permisos reales de WordPress y limita los roles seleccionables a **Asociado, Ejecutivo y Moderador ASCLA**. Los administradores técnicos permanecen fuera de este flujo y se gestionan únicamente desde WordPress.
- Se añade **Eliminar usuario** con una ventana de confirmación diferenciada y una advertencia de que la operación es permanente. La propia cuenta y otros administradores técnicos no pueden eliminarse desde ASCLA.
- Al eliminar un asociado, WordPress conserva y reasigna sus publicaciones al administrador que ejecuta la acción. ASCLA limpia relaciones, inscripciones, notificaciones, trabajos y conexión de Google Calendar; los archivos se reasignan para no romper contenido existente.
- La actualización y eliminación quedan registradas en **Auditoría**. No cambia el esquema de base de datos: continúa en **10**.

## 1.9.45 — URL canónica limpia para el acceso de asociados

- Las páginas privadas ya no redirigen a `/login/?redirect_to=...`; guardan el destino interno solicitado en una **cookie HttpOnly firmada**, de corta duración, y redirigen únicamente a **`/login/`**.
- Tras autenticarse correctamente, el destino se valida con las mismas reglas de seguridad existentes, se consume una sola vez y se elimina. URLs externas o manipuladas no se aceptan.
- Los enlaces heredados con `redirect_to` se absorben por compatibilidad y se canonicalizan inmediatamente a `/login/`, por lo que el parámetro deja de permanecer visible en el navegador.
- El cierre de sesión conserva el mensaje de confirmación mediante un aviso temporal y termina en `/login/` limpio, sin `?logged_out=1`.
- El selector ES/EN del login usa POST/Redirect/GET y guarda la elección en cookie, evitando `?wp_lang=...` en la URL del acceso de asociados.
- El flujo nativo de `wp-login.php` no cambia para recuperación/restablecimiento de contraseña y acceso técnico de WordPress. Mantiene el **esquema 10**.

## 1.9.44 — diseño de login restaurado y administración diferenciada

- `/login/` conserva la separación funcional de 1.9.43, pero vuelve a utilizar la misma composición visual del login ASCLA anterior: panel institucional lateral, tarjeta de acceso, logo, espaciado, recuperación y selector de idioma fuera de la tarjeta.
- El acceso nativo `wp-login.php`, utilizado por `/wp-admin/`, se identifica como **ADMINISTRACIÓN ASCLA** y muestra textos específicos de acceso administrativo sin alterar el mecanismo de autenticación de WordPress.
- Recuperación y restablecimiento de contraseña mantienen el lenguaje general de cuenta ASCLA; no se etiquetan como administración.
- No cambia roles, contraseñas ni esquema de datos; se mantiene el **esquema 10**.

## 1.9.43 — acceso ASCLA separado de la Intranet y wp-admin

- Se crea una página pública dedicada **`/login/`** para el acceso de asociados, reutilizando la identidad visual del login existente.
- Las páginas privadas de ASCLA, incluida **`/intranet/`**, ya no invocan directamente `auth_redirect()`; redirigen a `/login/?redirect_to=...` y conservan únicamente destinos internos autorizados.
- Se crea **`/administracion/`** como workspace funcional de Ejecutivo, Moderador y Administrador, reutilizando la misma interfaz de gestión ASCLA sin exponerles el escritorio general de WordPress.
- Ejecutivo y Moderador que intentan abrir **`/wp-admin/`** son redirigidos a `/administracion/`; los Asociados vuelven a `/intranet/`. El backoffice nativo queda reservado a usuarios con `manage_options`.
- El formulario nuevo continúa autenticando con **`wp_signon()` y WordPress**, por lo que no duplica cuentas, contraseñas, cookies ni permisos.
- La protección adaptativa de **Cloudflare Turnstile** y el conteo de intentos fallidos se reutilizan también en el nuevo formulario.
- **`wp-login.php` no se elimina**: permanece disponible para recuperación/restablecimiento de contraseña, diálogos de sesión y autenticación técnica.
- El cierre de sesión de la Intranet vuelve a `/login/?logged_out=1` y muestra una confirmación clara.
- La página `/login/` conserva idioma ES/EN, modo claro/oscuro, diseño responsive y redirección segura a la sección ASCLA solicitada.
- No requiere migración de tablas; mantiene el **esquema 10**.

## 1.9.42 — RF-040 y RF-041: recomendaciones enriquecidas y explicables

- **RF-040 — Recomendación de asociados:** mantiene como señales principales intereses, áreas de conocimiento, industrias, objetivos de networking e idiomas.
- La **experiencia profesional relacionada** añade un refuerzo determinístico acotado al puntaje; si experiencia o cargo están ocultos para el proceso de matching, no se utilizan.
- La actividad pública puede aportar un refuerzo pequeño cuando ambos asociados interactúan con temas similares en publicaciones accesibles. Se excluyen deliberadamente mensajes privados, solicitudes de soporte y registros de asistencia a eventos para evitar inferencias sensibles.
- El cálculo de actividad se ejecuta solo para una lista corta de candidatos cercanos al umbral, preservando el rendimiento del directorio a medida que crece la comunidad.
- Las tarjetas recomendadas muestran contexto breve de la afinidad, como temas compartidos, experiencia relacionada o actividad temática similar.
- **RF-041 — Recomendación de contenidos:** los recursos se ordenan combinando intereses explícitos, áreas/industrias/objetivos, cargo/experiencia profesional y actividad reciente en contenido público que el asociado haya seguido, reaccionado, comentado o publicado.
- La actividad es una señal secundaria y acotada; la **recencia nunca recomienda contenido ajeno por sí sola**.
- Las tarjetas de conocimiento recomendadas indican por qué aparecen sin revelar datos del perfil: intereses, perfil profesional o actividad reciente.
- Mantiene el **esquema 10** y no requiere migración de base de datos.

## 1.9.41 — RF-031: participantes visibles después del RSVP

- **RF-031:** la lista social de participantes se entrega únicamente cuando el asociado tiene un RSVP con estado **Inscrito/accepted**.
- Antes del RSVP, en lista de espera, con cupo ofrecido o después de cancelar la inscripción, el backend no expone la sección de asistentes al asociado.
- La vista del evento presenta una cuadrícula con fotografía o iniciales, nombre y cargo/empresa únicamente cuando esos datos pueden mostrarse de acuerdo con la configuración de privacidad.
- Los perfiles que han desactivado su participación en el directorio no se identifican en esta vista; solo se indica de forma agregada que existen participantes no visibles por privacidad.
- Cada tarjeta visible abre el perfil del asociado dentro de la misma página, reutilizando el visor modal existente.
- Moderación conserva su listado completo de inscritos, invitados, lista de espera y cupos ofrecidos para tareas administrativas.
- Mantiene el **esquema 10** y no requiere migración de base de datos.

## 1.9.40 — RF-025: imagen de portada del evento

- **RF-025:** el formulario de creación y edición de eventos permite seleccionar una portada JPG, PNG o WebP.
- La imagen utiliza el editor privado existente, con recorte/optimización y proporción recomendada **16:9** antes de guardarse.
- Al editar un evento, la portada actual se previsualiza y puede **reemplazarse o quitarse** sin modificar el resto de la información.
- La portada se muestra en las tarjetas de Eventos y en el detalle del encuentro; si no existe imagen, el evento mantiene su presentación textual normal.
- El backend limita cada evento a **una sola imagen** y rechaza PDF u otros tipos de archivo como portada, aunque el sistema general de medios continúe admitiéndolos en los módulos correspondientes.
- Mantiene el **esquema 10** y no requiere migración de base de datos.

## 1.9.39 — RF-024 y RN-011: cancelación de eventos y correo controlado

- **RF-024:** un Administrador o Ejecutivo ASCLA puede cancelar un evento futuro publicado sin eliminarlo. El evento se conserva visible con estado funcional **Cancelado** y mantiene sus datos, participantes y trazabilidad.
- La cancelación bloquea nuevas inscripciones, lista de espera, invitaciones, seguimiento y nuevas altas en Google Calendar; los calendarios ya guardados pueden retirarse manualmente.
- Las personas inscritas, invitadas, con cupo ofrecido o en lista de espera reciben una notificación interna única de cancelación; si tienen activado correo de Eventos, el aviso puede complementarse por email.
- **RN-011:** cada mensaje continúa generando su notificación interna inmediata, pero los correos de la categoría Mensajes se limitan a un máximo de **un aviso por conversación cada 15 minutos**. El contenido privado del mensaje no se copia al email.
- Mantiene el **esquema 10** y no requiere migración de base de datos.

## 1.9.38 — RF-029 y RF-030: Lista de espera y liberación de cupo

- **RF-029:** si un evento con capacidad limitada alcanza el aforo, la interfaz ofrece **Unirme a la lista de espera** en lugar de intentar una inscripción imposible.
- La cola es **FIFO**: se conserva el orden de ingreso y el asociado puede ver su posición mientras permanezca en espera.
- **RF-030:** cuando una inscripción confirmada se cancela o una persona rechaza un cupo previamente ofrecido, el sistema toma al primer asociado en espera, cambia su estado a **Cupo disponible** y le envía una notificación.
- El cupo liberado queda **reservado temporalmente** para ese asociado hasta que confirme o rechace; no se registra el RSVP automáticamente.
- Si confirma, el estado pasa a **Inscrito**. Si rechaza, el cupo se ofrece automáticamente a la siguiente persona de la lista.
- La vista del evento muestra inscritos, aforo, cupos disponibles y cantidad de personas en espera; Moderación puede revisar también los estados `waitlisted` y `offered`.
- Los avisos de cupo disponible pueden complementarse por correo si el asociado mantiene activada la categoría **Eventos**.
- No requiere migración de tablas; se mantiene el esquema **10**.

## 1.9.37 — RF-033 y RF-038: Soporte notificado

- **RF-033:** al registrar una solicitud de soporte, el asociado recibe confirmación dentro del centro de notificaciones y el equipo con capacidad de Moderación recibe un aviso de nueva solicitud.
- **RF-038:** cuando Moderación cambia el estado de una solicitud, su autor recibe una notificación interna con el nuevo estado: **Recibida**, **En atención** o **Resuelta**.
- Volver a seleccionar el mismo estado no crea notificaciones duplicadas.
- Los avisos del asociado abren **Contacto → Mis solicitudes** y los avisos del equipo abren **ASCLA → Solicitudes**.
- Los avisos de soporte son internos; no dependen de las preferencias opcionales de correo de Conexiones, Mensajes y Eventos.
- No requiere migración de tablas; se mantiene el esquema **10**.

## 1.9.36 — Guardado protegido y RF-035 por correo

- **Editar grupo** detecta modificaciones en nombre, descripción o fotografía. Si el usuario intenta cerrar con el fondo, la X o Escape, aparece una confirmación con **Seguir editando**, **Descartar cambios** y **Guardar cambios**.
- RF-035 queda cubierto para las categorías opcionales **Conexiones**, **Mensajes** y **Eventos**, utilizando el transporte de correo configurado en WordPress/SMTP.
- En **Mi perfil → Notificaciones por correo** cada asociado puede activar o desactivar esas tres categorías de forma independiente. Para cuentas existentes y nuevas, las tres opciones están activadas por defecto hasta que el usuario cambie su preferencia.
- Las notificaciones internas permanecen siempre disponibles aunque se desactive el correo.
- Los correos de mensajería avisan de la existencia del mensaje o solicitud, pero no copian el texto privado del mensaje al correo.
- No requiere migración de tablas; se mantiene el esquema **10** y las preferencias se almacenan en metadatos de usuario.

## 1.9.35 — Navegación contextual en conversaciones y modales

- Las ventanas emergentes ahora se cierran al hacer clic directamente sobre el fondo oscuro exterior; los clics dentro del contenido no las cierran.
- Se mantienen los cierres mediante la X superior y la tecla Escape.
- En conversaciones/comentarios, el nombre de un autor asociado a una cuenta se puede pulsar para abrir su perfil dentro de un modal en la misma página.
- Los comentarios sin usuario asociado continúan mostrándose como texto no interactivo.
- No requiere migración y mantiene el esquema 10.

## 1.9.34 — Edición de grupos y perfiles en contexto

- El creador de un chat grupal puede editar el **nombre**, la **fotografía** y una **descripción breve** desde el menú Información del grupo.
- La edición del grupo se valida también en backend: únicamente `created_by` puede guardar esos cambios.
- La creación de grupos permite registrar opcionalmente la descripción desde el inicio.
- Todos los controles **Ver perfil** del frontend abren el perfil del asociado dentro de un modal sobre la página actual; ya no dependen del enlace genérico que podía llevar al perfil propio.
- El encabezado de una conversación privada usa el mismo visor de perfil en modal.
- Esquema de base de datos: **10**, añadiendo `description` a las conversaciones grupales.


## 1.9.33 — Pulido de mensajería y menú de grupos

- Cuando una conversación privada ya está autorizada, el perfil deja de mostrar la etiqueta redundante **Conversación autorizada** y mantiene únicamente el botón **Enviar mensaje**.
- El menú **Información del grupo** conserva solo la X superior para cerrar; se elimina el botón Cerrar inferior.
- Cada integrante del grupo muestra acciones para **Ver perfil** y, salvo el propio usuario, **Mensaje privado**.
- **Mensaje privado** abre el chat existente cuando ya está autorizado; si hay una solicitud pendiente abre esa conversación, y si aún no existe autorización permite enviar el primer mensaje como solicitud.
- El botón **Eliminar grupo** continúa dentro de la información del grupo y solo está disponible para su creador.
- No requiere migración adicional; se mantiene el esquema de base de datos **9**.


## 1.9.32 — Bandeja de solicitudes y menú de grupos

- Las solicitudes de conversación recibidas se muestran en un apartado **Solicitudes** separado de los chats normales, con vista previa del primer mensaje y contador visible.
- Al aceptar una solicitud, la conversación pasa a **Chats**; al rechazarla deja de mostrarse como solicitud.
- Los grupos incluyen un menú de información accesible desde el encabezado, con foto, nombre, integrantes e identificación del creador.
- El botón **Eliminar grupo** se encuentra dentro de ese menú y solo se muestra al creador.
- No requiere migración adicional; se mantiene el esquema de base de datos **9**.



## 1.9.31 — Solicitudes con mensaje, foto y eliminación de grupos

- El primer mensaje puede enviarse aunque aún no exista conexión profesional; al destinatario le aparece como solicitud de conversación y puede leerlo antes de aceptar o rechazar.
- Mientras la solicitud está pendiente, el remitente no puede enviar mensajes adicionales; al aceptarse se habilita el chat normal sin crear una conexión profesional.
- Los chats grupales admiten fotografía cuadrada privada, accesible únicamente para sus participantes.
- Solo el creador del grupo puede eliminarlo; al hacerlo se eliminan conversación, participantes, mensajes y notificaciones asociadas.
- Se conserva la confirmación de lectura `Enviado` / `Leído` y `Leído por N de M`.
- Esquema de base de datos: **9**, añadiendo `photo_id` a las conversaciones.


## 1.9.30 — Solicitudes de conversación, grupos y lectura

- Incorpora RF-016, RF-017 y RF-018 con solicitudes de conversación independientes de la conexión profesional.
- Permite crear chats grupales con conexiones confirmadas y hasta 50 participantes.
- Muestra confirmación de lectura en mensajes directos y conteo de lectura en grupos.
- Añade esquema 8 para conversaciones directas/grupales, título y creador.

## 1.9.29 — Temas automáticos, duración YouTube y transcripción segura

- Al generar resumen y nota, ASCLA puede activar automáticamente los seis **Temas** del Centro de Conocimiento cuando estén respaldados por el video: Gestión de riesgos, Gobierno corporativo, Inteligencia artificial, Juntas directivas, Sostenibilidad y Transformación digital.
- Los temas automáticos se guardan por separado (`ai_topic_ids`) para que un reprocesamiento pueda reemplazarlos sin borrar los temas elegidos manualmente por el editor.
- Gemini y OpenAI reciben una lista cerrada de Temas permitidos y solo deben devolver los que estén claramente sustentados por el contenido; ASCLA añade además una clasificación determinística basada en el texto verificable.
- La duración del video se desacopla del modo de transcripción: ASCLA intenta siempre consultar `contentDetails.duration` desde YouTube OAuth cuando la cuenta está conectada y usa metadatos públicos del propio video únicamente como fallback.
- El fallback público prueba la página del video y superficies de embed; si el servidor sigue sin obtener duración, la interfaz puede leerla directamente del YouTube IFrame Player API y guardarla como metadato verificado por el reproductor. Nunca usa timestamps de la transcripción para calcular la duración.
- Ejecutivos y Administradores pueden conectar YouTube OAuth para procesar recursos que gestionan.
- Si el recurso no tiene transcripción manual, ASCLA solo genera contenido cuando puede obtener subtítulos autorizados desde YouTube. Si no puede, detiene el trabajo con un mensaje explícito y **no llama a la IA para inventar información**.
- El editor advierte cuando no hay una transcripción guardada y explica que la generación se detendrá si YouTube tampoco puede proporcionar una.
- Esquema de base de datos: **7** (sin migración).

## 1.9.28 — Recomendaciones de conocimiento reforzadas por palabras clave

- Los temas/intereses asignados al recurso siguen siendo la señal de recomendación más fuerte y nunca son desplazados por etiquetas generadas por IA.
- Las palabras clave del Centro de Conocimiento ahora aportan una señal secundaria cuando coinciden con intereses, áreas de conocimiento, industrias u objetivos registrados en el perfil del asociado.
- El comparador normaliza mayúsculas, acentos y puntuación, reconoce acrónimos de varias palabras y admite coincidencias parciales suficientemente claras sin usar una sola palabra genérica como criterio dominante.
- La recencia aporta únicamente un pequeño bono después de comprobar relevancia temática; un recurso reciente sin coincidencias no aparece como recomendado.
- El orden de **Conocimiento para tu día a día** se calcula por puntuación de relevancia y luego por fecha, de modo que una coincidencia explícita de interés queda por encima de una coincidencia basada solo en palabras clave.
- Los avisos de nuevos recursos utilizan la misma señal de relevancia, haciendo consistente el descubrimiento entre Inicio y Notificaciones.
- Se añade una prueba de regresión que comprueba que un recurso relacionado solo por palabra clave puede recomendarse, que un recurso sin relación no entra por ser reciente y que las coincidencias explícitas conservan prioridad.
- Esquema de base de datos: **7** (sin migración).

## 1.9.27 — Resumen editorial, duración verificada y estados visibles

- Los resúmenes y notas generados se redactan como contenido editorial del video y evitan expresiones como `La transcripción aborda...`; los prompts de Gemini/OpenAI también prohíben mencionar transcripción, subtítulos o el proceso de extracción.
- Las publicaciones enriquecidas ya no añaden una sección **Fuente** con el mismo video ni muestran **Descargar infografía**.
- La duración deja de inferirse desde timestamps de la transcripción. ASCLA intenta primero la API real de YouTube cuando está conectada y, como respaldo, consulta metadatos públicos del propio video; si no puede verificarse, la duración queda **por confirmar**.
- Cuando la duración no está verificada, ASCLA no genera cápsulas temporales para evitar referencias que excedan o contradigan el video real.
- Las tarjetas de Centro de Conocimiento muestran **Borrador** o **Pendiente de revisión** cuando corresponde, igual que otros módulos editoriales.
- Esquema de base de datos: **7** (sin migración).

## 1.9.26 — Nota técnica en la publicación original y duración automática

- **Generar resumen y nota** actualiza el mismo recurso desde el que se ejecutó la acción; ya no crea una Nota técnica separada, un borrador del Hub ni recursos independientes para cápsulas.
- Resumen, Nota técnica, marcos, conclusiones, normativas, conceptos, palabras clave y cápsulas se renderizan como secciones internas de la publicación original.
- Las cápsulas permanecen como referencias temporales al mismo video y nunca generan un nuevo post.
- La duración se refresca automáticamente al guardar/procesar un recurso: con YouTube OAuth real se usan metadatos de YouTube; si no están disponibles, se infiere de timestamps válidos de la transcripción y se conserva el dato existente como último respaldo.
- Los campos estructurados eliminan valores genéricos sin significado como `participante`, `una persona`, `dato reservado` o `identidad reservada` cuando constituyen por sí solos un marco, concepto, normativa, conclusión o palabra clave.
- Los prompts de Gemini/OpenAI instruyen explícitamente a omitir esos placeholders en listas estructuradas.
- Para Administradores y Ejecutivos, un nuevo recurso del Centro de Conocimiento abre **Guardar como: Publicado** por defecto.
- Esquema de base de datos: **7** (sin migración).

## 1.9.25 — Publicación editorial directa, notas integradas y cápsulas coherentes

- Administradores y Ejecutivos ASCLA pueden publicar directamente recursos del **Centro de Conocimiento**, incluidos borradores generados por IA cuando eligen explícitamente **Publicar ahora (revisado)**.
- La publicación directa de un recurso generado registra `reviewed=true`, de modo que la protección editorial sigue siendo explícita y el contenido puede indexar sus etiquetas revisadas.
- Las notas técnicas dejan de presentar un bloque separado **Resultados y fuentes**: Resumen, marcos, conclusiones, normativas, conceptos, palabras clave, cápsulas y la fuente de origen se renderizan como secciones de la misma publicación.
- La anonimización Chatham House evita mostrar el marcador artificial `[identidad reservada]`; la salida usa formulaciones neutrales como `participante` o `dato reservado`.
- La generación de cápsulas deja de dividir el video mecánicamente. Se toma la duración real conocida —o la duración inferida de la transcripción cuando no existe metadata— y se aplica esta política: hasta 3 min, ninguna cápsula; 3–10 min, máximo 1; 10–30 min, máximo 2; más de 30 min, máximo 3.
- Los timestamps propuestos por IA se descartan si exceden la duración real y cada cápsula válida conserva una duración de 60 a 180 segundos con tiempos verificados en la transcripción.
- Se agregan pruebas para publicación directa de conocimiento generado, redacción sin `[identidad reservada]` y límites de cápsulas por duración.
- Esquema de base de datos: **7** (sin migración).

## 1.9.24 — Reparación de microeventos y estado visual de conexiones

- Microeventos valida la caché mensual antes de reutilizarla y descarta IDs de eventos eliminados o enviados a la papelera.
- Si todas las propuestas del mes fueron eliminadas, **Preparar propuesta del mes** vuelve a generar encuentros válidos en lugar de devolver referencias antiguas.
- Al eliminar un microevento se actualiza inmediatamente la caché mensual y se retiran sus inscripciones asociadas.
- Los trabajos históricos de microeventos filtran IDs que ya no existen, evitando botones **Revisar #ID** que terminaban en “Contenido no encontrado”.
- En el Directorio, el indicador **Conectados** pasa a mostrarse junto al porcentaje de afinidad; la zona inferior queda reservada para acciones como Ver perfil, Enviar mensaje y Eliminar conexión.
- Se añade una prueba de regresión para comprobar que propuestas eliminadas pueden regenerarse sin reutilizar IDs obsoletos.
- Esquema de base de datos: **7** (sin migración).

## 1.9.23 — Gestión de notificaciones y ciclo completo de conexiones

- Las notificaciones individuales pueden eliminarse desde la bandeja de actividad mediante una confirmación propia de ASCLA.
- Las solicitudes de conexión enviadas incorporan **Cancelar solicitud**. Al cancelarlas, se elimina la relación pendiente y también la notificación de solicitud que recibió el destinatario.
- Las conexiones confirmadas incorporan **Eliminar conexión** con confirmación explícita. La mensajería deja de estar disponible entre ambos perfiles, pero el historial no se borra y puede recuperarse si vuelven a conectar.
- Auditoría registra cancelaciones de solicitudes y eliminación de conexiones con etiquetas comprensibles.
- La interfaz y las nuevas acciones tienen traducción Español/English y estilos para modo claro/oscuro.
- Esquema de base de datos: **7** (sin migración).

## 1.9.22 — Proveedor IA exclusivo, configuración simplificada y auditoría explicativa

- Inteligencia artificial muestra únicamente la configuración del proveedor activo: DEMO MODE, Google Gemini u OpenAI / ChatGPT.
- Cambiar el proveedor oculta y deshabilita los campos del proveedor inactivo; ASCLA consulta solo al proveedor seleccionado.
- Configuración se reorganiza en bloques más coherentes y el Motor de afinidad deja visible únicamente `Afinidad mínima para recomendar (%)`.
- Auditoría explica qué registra, para qué sirve y qué información no almacena; además muestra el nombre del actor cuando está disponible y etiquetas de acción más legibles.
- El estado `Inscrito` recibe estilos específicos para modo oscuro.
- Mensajería mantiene la actualización automática en segundo plano, pero deja de mostrar el texto “Actualización automática activada · cada 2 segundos”.
- Esquema de base de datos: **7** (sin migración).

## 1.9.21 — Pulido del hero, Auditoría y Configuración administrativa

**Objetivo:** corregir el problema visual del logo sobredimensionado y convertir Auditoría y Configuración en secciones más claras y profesionales sin alterar permisos ni lógica de negocio.

### Cambios principales

- El hero de Administración limita explícitamente el logo a una marca compacta y equilibrada, evitando que el recurso gráfico ocupe el área principal.
- Se corrige el bloque CSS administrativo de 1.9.20 que contenía saltos de línea escapados literalmente, asegurando que los estilos del rediseño sean interpretados por el navegador.
- Auditoría incorpora tarjetas de resumen, recuento de actores, última actividad, indicador de registro protegido y una tabla con acciones/objetos visualmente diferenciados.
- Configuración se reorganiza en tarjetas temáticas para Participación, Seguridad, Afinidad, IA, Google OAuth, Social Listening, Correo y Propiedad intelectual.
- Se añade una barra de guardado persistente y responsive para que la acción principal permanezca clara en formularios extensos.
- El modo oscuro recibe estilos específicos para las nuevas tarjetas, resúmenes y estados.
- No requiere migración de base de datos: se mantiene el esquema `7`.

**Resultado de la iteración:** el panel administrativo conserva la renovación de 1.9.20, pero con un hero proporcionado y dos de sus secciones más técnicas mucho más legibles y consistentes.

---

## 1.9.20 — Renovación visual de Administración, idioma y branding

**Objetivo:** hacer que el panel administrativo se perciba como una extensión cuidada de la intranet y no como una colección de controles aislados, manteniendo los permisos y flujos existentes.

### Cambios principales

- Administración estrena una cabecera con identidad ASCLA actual, mejor jerarquía visual y acceso directo a la intranet.
- Las métricas superiores, pestañas, filtros, tablas, usuarios, solicitudes y tarjetas de configuración reciben un tratamiento visual consistente, con mejores espacios, bordes, estados y lectura en claro/oscuro.
- La barra superior de Administración incorpora selector **Español / English** y control de apariencia, alineados con la experiencia de la intranet.
- El cambio de idioma se guarda por usuario también desde `wp-admin`, sin modificar el idioma global de WordPress para los demás asociados.
- Los logos principales se sirven con versión en la URL para evitar que el navegador siga mostrando una imagen antigua después de actualizar el plugin.
- La biblioteca administrativa recibe una presentación propia y más clara sin cambiar su alcance de permisos.
- No requiere migración de base de datos: se mantiene el esquema `7`.

**Resultado de la iteración:** Administración tiene una identidad visual más uniforme, controles superiores coherentes con el frontend y branding actualizado de forma fiable.

---

## 1.9.19 — Corrección de autofill y logos transparentes

**Objetivo:** eliminar interferencias del autocompletado de direcciones del navegador y pulir el uso de los nuevos recursos de marca sin introducir fondos artificiales.

### Cambios principales

- País/región y Ciudad mantienen el autocompletado propio de ASCLA, pero el campo visible ya no se expone como un campo de dirección al navegador; los valores validados se sincronizan a campos internos antes de guardar.
- Se añaden señales para gestores de autofill y contraseñas, reduciendo la aparición de menús externos sobre el selector de ASCLA.
- Login e Inicio eliminan rectángulos blancos alrededor del logo. En superficies claras se usa el logo principal y en superficies oscuras se utiliza la variante inversa para conservar contraste sin añadir una placa.
- La barra lateral mantiene la variante blanca oficial y añade una separación mayor antes del texto **COMUNIDAD DE ASOCIADOS**.
- No requiere migración de base de datos: se mantiene el esquema `7`.

**Resultado de la iteración:** los selectores geográficos quedan visualmente bajo control de ASCLA y la marca se integra de forma más limpia con los fondos claros y oscuros.

---

## 1.9.18 — Autocompletado profesional de ubicación y nueva identidad visual

**Objetivo:** hacer que la captura de País/región y Ciudad se sienta integrada a la intranet, evitando el aspecto del `datalist` nativo, y aplicar de forma consistente los nuevos recursos oficiales de marca.

### Cambios principales

- Perfil reemplaza el selector nativo del navegador por un autocompletado propio: las sugerencias aparecen debajo del campo, se filtran al escribir y pueden recorrerse con teclado.
- País/región sigue validándose contra el catálogo normalizado; Ciudad continúa consultándose por el país seleccionado, por lo que no se pierde la calidad de datos incorporada en 1.9.17.
- La ciudad se mantiene deshabilitada hasta seleccionar un país válido, reduciendo combinaciones inconsistentes.
- Se incorpora el nuevo logo horizontal oficial de ASCLA como recurso principal para el inicio de sesión y la cabecera de la intranet.
- La barra lateral oscura utiliza una variante blanca independiente (`ascla-logo-white.png`) en lugar de recolorear artificialmente el logo principal con máscaras CSS.
- El login presenta el logo principal sobre una superficie clara para conservar legibilidad también cuando el usuario utiliza modo oscuro.
- No requiere migración de base de datos: se mantiene el esquema `7`.

**Resultado de la iteración:** la selección de ubicación se percibe como un componente propio de ASCLA y la identidad visual mantiene contraste correcto en superficies claras y oscuras.

---

## 1.9.17 — Foros simplificados, completitud de perfil y ubicaciones normalizadas

**Objetivo:** reducir duplicidades de interfaz y mejorar la calidad de la información básica de los perfiles sin convertir el onboarding en un bloqueo.

### Cambios principales

- Foros elimina el botón duplicado de la franja inferior y conserva una sola acción azul superior, ahora denominada **Crear foro**.
- Inicio calcula una completitud de perfil basada en 10 elementos: nombre completo, cargo, empresa, ubicación, biografía, experiencia, fotografía, intereses, áreas de conocimiento e industrias.
- Cuando el perfil está por debajo del **40%**, ASCLA muestra una recomendación al entrar en Inicio con el porcentaje actual y acceso directo a **Completar mi perfil**; la navegación sigue disponible y el aviso aparece una sola vez por sesión del navegador.
- Perfil sustituye la entrada totalmente libre de País/región por sugerencias basadas en un catálogo ISO 3166 normalizado y valida los cambios de país contra dicho catálogo.
- Ciudad ofrece sugerencias dependientes del país. ASCLA consulta CountriesNow desde PHP, cachea cada país durante 7 días y devuelve solo coincidencias relevantes al navegador.
- Si el proveedor de ciudades está temporalmente indisponible, ASCLA conserva la posibilidad de guardar un perfil existente en lugar de convertir la integración externa en un punto único de fallo.
- No requiere migración de base de datos: se mantiene el esquema `7`.

**Resultado de la iteración:** la creación de conversaciones queda más clara, los perfiles incompletos reciben una guía útil y la ubicación profesional queda más consistente para búsquedas y networking.

---

## 1.9.16 — Pulido visual de login, Inicio y filtros

**Objetivo:** corregir detalles de presentación detectados en el uso real sin modificar permisos, datos ni flujos funcionales.

### Cambios principales

- El logo del inicio de sesión se muestra con un encuadre más proporcionado tanto en la tarjeta de acceso como en el panel de bienvenida.
- La verificación adaptativa de **Cloudflare Turnstile** usa una tarjeta visual más clara, con jerarquía de título, proveedor y explicación breve, manteniendo la aparición únicamente cuando corresponde.
- El hero de Inicio (`Hola …, sigamos conectando ideas.`) adopta una paleta azul clara en modo claro; el modo oscuro conserva su tratamiento oscuro.
- Se elimina el botón general **Actualizar** de la cabecera de Administración para reducir ruido visual; las acciones específicas de actualización de cada módulo se conservan.
- En Administración → Solicitudes, el selector **Estado** queda alineado con el campo de búsqueda y el botón Buscar.
- En el Directorio, los filtros **Interés** y **Área de conocimiento** quedan alineados con país, industria y búsqueda.
- No requiere migración de base de datos: se mantiene el esquema `7`.

**Resultado de la iteración:** las pantallas principales presentan una composición más consistente y equilibrada sin alterar el comportamiento funcional de ASCLA.

---

## 1.9.15 — Guardia de configuración y tarjetas proporcionales del directorio

**Objetivo:** evitar pérdidas accidentales de configuración administrativa y dar una presentación visual consistente al directorio de asociados.

### Cambios principales

- Administración → Configuración conserva la detección real de cambios y utiliza el modal propio **Cambios sin guardar** al cambiar de pestaña administrativa, actualizar la vista o salir mediante enlaces del panel de WordPress.
- Los enlaces que abandonan la pantalla administrativa con cambios pendientes se interceptan antes de navegar; F5/cierre de pestaña conserva el aviso nativo obligatorio del navegador.
- Las tarjetas del directorio separan información personal, afinidad/intereses y acciones en zonas estables.
- Los botones **Ver perfil**, conexión y mensajería mantienen ancho, altura y separación uniformes independientemente de la cantidad de intereses o del estado de conexión.
- La tarjeta del usuario actual ofrece **Editar mi perfil** para evitar un bloque de acciones visualmente vacío.
- En móvil se elimina la altura mínima rígida para mantener una disposición compacta y adaptable.
- No requiere migración de base de datos: se mantiene el esquema `7`.

**Resultado de la iteración:** la configuración administrativa queda protegida por la misma experiencia de guardado que Perfil y el directorio presenta una cuadrícula más equilibrada y fácil de recorrer.

---

## 1.9.14 — Acceso cerrado de asociados y ajuste de Turnstile

**Objetivo:** reflejar en seguridad que ASCLA no admite auto-registro público y que todas las cuentas pertenecen a asociados provisionados por Administración.

### Cambios principales

- Se fuerza `users_can_register = false` desde `ascla-core`, de modo que una activación accidental de “Cualquiera puede registrarse” en WordPress no abra el alta pública.
- Se elimina **Registro público** de Administración → Configuración → Seguridad.
- Se retiran los hooks y validaciones Turnstile vinculados al formulario nativo de registro de WordPress.
- Turnstile continúa disponible para login adaptativo, recuperación de contraseña y futuros formularios públicos autorizados por ASCLA.
- La creación de cuentas sigue realizándose desde Administración / Usuarios, sin afectar roles, sesiones ni inscripciones a eventos.
- Se mantiene el esquema de base de datos `7`.

**Resultado de la iteración:** ASCLA mantiene un modelo de membresía cerrado y evita que una configuración accidental de WordPress habilite cuentas fuera del proceso administrativo.

---

## 1.9.13 — Cloudflare Turnstile adaptativo

**Objetivo:** añadir protección anti-bot sin introducir CAPTCHA permanente ni alterar la autenticación nativa de WordPress.

### Cambios principales

- Se integra **Cloudflare Turnstile** como capa opcional de seguridad para formularios públicos.
- Los usuarios autenticados nunca reciben un desafío Turnstile.
- En inicio de sesión, Turnstile aparece después de **3 intentos fallidos**; al llegar a **5** se aplica además un bloqueo temporal de 10 minutos y se exige una nueva verificación al finalizar el bloqueo.
- Los intentos de login se contabilizan principalmente con una clave derivada de **IP + usuario/correo**, acompañada de una señal IP secundaria de umbral más alto para detectar rotación de usuarios sin bloquear injustamente redes compartidas.
- La **recuperación de contraseña** comienza a exigir Turnstile después de **2 solicitudes consecutivas**.
- Un navegador/IP que supera correctamente Turnstile queda confiable durante **24 horas**, evitando que el desafío reaparezca continuamente.
- Si vuelven a acumularse cinco fallos de credenciales, la confianza previa se revoca y se exige una nueva verificación después del bloqueo.
- La validación del token se realiza obligatoriamente en PHP mediante `https://challenges.cloudflare.com/turnstile/v0/siteverify`; no se confía únicamente en JavaScript.
- Los tokens se validan también por `action` y, en producción, por hostname.
- Se añade una política reutilizable para futuros formularios públicos ASCLA: desafío únicamente ante señales sospechosas o demasiados envíos.
- Administración → Configuración → **Seguridad** permite activar/desactivar Turnstile, guardar Site Key y Secret Key y seleccionar login, recuperación y formularios públicos.
- La Secret Key se guarda mediante el almacén cifrado de ASCLA y nunca se devuelve al navegador.
- La integración permanece desactivada por defecto para no romper instalaciones existentes que todavía no tengan claves de Cloudflare.
- El widget debe crearse en Cloudflare con modo **Managed**.
- No requiere migración de base de datos: se mantiene el esquema `7`.

**Resultado de la iteración:** ASCLA incorpora una defensa anti-bot progresiva que se activa cuando aumenta el riesgo, conservando una experiencia limpia para usuarios legítimos.

---

## 1.9.12 — Filtro administrativo, mensajes sugeridos y cambios sin guardar

- Administración → Archivos permite filtrar la biblioteca global por usuario propietario, además de buscar por nombre de archivo.
- Perfil → Mis archivos continúa mostrando exclusivamente los archivos del usuario actual, incluso para administradores.
- El generador de “Mensaje sugerido” redacta como el asociado remitente: no se presenta como ASCLA, IA, asistente o chatbot y no menciona análisis de perfiles ni porcentajes de afinidad.
- Se invalidó la caché de sugerencias anterior para evitar reutilizar textos generados con el prompt previo.
- La navegación interna con formularios modificados utiliza un modal propio de ASCLA con “Seguir editando” y “Descartar cambios”.
- El aviso nativo del navegador se conserva sólo para cerrar/recargar la pestaña, una limitación impuesta por los navegadores modernos.
- No requiere migración de base de datos: se mantiene el esquema 7.

## 1.9.11 — Permisos de archivos, OpenAI y seguimiento de reportes

**Objetivo:** separar el alcance de la biblioteca personal y administrativa, permitir elegir entre Gemini y OpenAI para el Asistente ASCLA y dar trazabilidad al cierre de reportes de moderación.

### Cambios principales

- **Perfil → Mis archivos** muestra siempre únicamente los archivos propiedad del usuario actual, incluso si ese usuario es administrador.
- **Administración → Archivos** mantiene la vista global de archivos de la comunidad y continúa disponible solo para administradores.
- Se añade **OpenAI / ChatGPT** como proveedor alternativo de IA, sin retirar Google Gemini ni el modo DEMO.
- El administrador puede guardar de forma independiente el modelo y la API Key de Gemini y de OpenAI, seleccionar el proveedor activo y probar su conexión.
- El proveedor OpenAI utiliza la **Responses API** y recibe el mismo contexto interno autorizado que ASCLA ya prepara para Gemini; no se amplían los permisos del asistente.
- Los reportes de publicaciones y comentarios ahora pueden marcarse como **Revisados** desde Moderación.
- El estado guarda fecha y moderador responsable; los reportes pendientes aparecen primero y el contador principal refleja los que todavía faltan revisar.
- Si un usuario vuelve a reportar el mismo contenido, el reporte vuelve automáticamente a estado pendiente para que se evalúe la nueva información.
- El esquema de base de datos avanza de `6` a `7` para registrar `reviewed_at` y `reviewed_by` en reportes.

**Resultado de la iteración:** la biblioteca respeta mejor el contexto desde el que se abre, ASCLA deja de depender de un único proveedor de IA y Moderación puede distinguir claramente los reportes pendientes de los ya atendidos.

---

## 1.9.10 — Edición, recorte y optimización de imágenes

**Objetivo:** mejorar la experiencia de carga de imágenes y reducir el espacio consumido sin perder un encuadre controlado ni una fuente no recortada.

### Cambios principales

- Se añadió una vista previa antes de subir imágenes en Perfil y editores de contenido.
- El usuario puede **mover**, **hacer zoom**, **restablecer** y **recortar** la imagen antes de guardarla.
- Se aplican proporciones recomendadas según el contexto:
  - Perfil: `1:1`;
  - Hub y Centro de Conocimiento: `16:9`;
  - Galería: `4:3`;
  - Aliados: `1:1`, con opción de conservar la imagen completa para evitar cortar logotipos.
- En contextos donde conviene preservar la composición se ofrece **Usar imagen completa**.
- Las imágenes se procesan en el navegador antes de enviarse:
  - se limita la resolución máxima de la copia maestra;
  - se evita ampliar artificialmente imágenes pequeñas;
  - se usa WebP cuando el navegador puede generarlo y JPEG/PNG como alternativa;
  - se ajusta la calidad de forma progresiva cuando el archivo resultante es demasiado grande.
- Cuando se aplica un recorte, ASCLA conserva una **copia maestra privada, no recortada y optimizada** y una versión preparada para mostrarse.
- La biblioteca oculta la copia maestra para que el usuario gestione la imagen como una sola unidad.
- Al eliminar la versión visible, la copia maestra asociada también se elimina cuando deja de tener referencias.
- Se añadió `original_id` a la tabla privada de medios para enlazar ambas versiones.
- El esquema de base de datos avanza de `5` a `6`.
- Se mejoró la separación visual entre **Conocimiento para tu día a día** y **Publicados recientemente** en Inicio.
- La interfaz de edición es compatible con modo claro, modo oscuro y pantallas móviles.

**Resultado de la iteración:** las imágenes dejan de subirse “a ciegas”; el asociado controla el encuadre antes de guardar y ASCLA reduce la resolución/peso del archivo de uso habitual mientras conserva una fuente no recortada para evitar pérdidas de composición.

---

## 1.9.9 — Asistente ASCLA conversacional

**Objetivo:** convertir el asistente existente en una experiencia más cercana a un chatbot, sin perder la regla de que las respuestas sobre ASCLA deben sustentarse principalmente en información real de la propia intranet.

### Cambios principales

- Se rediseñó el **Asistente ASCLA** como una conversación por mensajes.
- Se añadió continuidad contextual para preguntas de seguimiento dentro de una conversación.
- Se incorporó la acción **Nueva conversación** para reiniciar el contexto cuando el usuario cambia de tema.
- Se mantiene historial de consultas para facilitar la continuidad de uso.
- El asistente obtiene **contexto vivo y autorizado de la intranet** antes de generar respuestas sobre información interna.
- Puede consultar, según los permisos del asociado:
  - próximos eventos y reuniones;
  - fecha, hora, modalidad y lugar de los eventos;
  - estado de inscripción;
  - publicaciones recientes;
  - recursos disponibles en el Centro de Conocimiento;
  - notificaciones pendientes;
  - recomendaciones de contactos por afinidad.
- Las fuentes internas actuales tienen prioridad sobre el historial de la conversación.
- Si no existe evidencia suficiente dentro de ASCLA, el asistente debe indicarlo en lugar de inventar información.
- Se mantienen las restricciones de permisos y la protección de información asociada a **Chatham House**.
- Las respuestas siguen el idioma seleccionado por el usuario en la interfaz.
- Se añadieron comportamientos de chat como envío con **Enter** y salto de línea con **Shift + Enter**.

**Resultado de la iteración:** el asistente deja de funcionar únicamente como un buscador de documentos y pasa a servir como punto de consulta conversacional de la propia intranet.

---

## 1.9.8 — Traducciones y comportamiento de menús contextuales

**Objetivo:** mejorar la consistencia de la interfaz bilingüe y evitar que varios menús contextuales permanezcan abiertos simultáneamente.

### Cambios principales

- Los menús de **tres puntos (⋯)** pasan a ser mutuamente exclusivos:
  - abrir un menú cierra automáticamente el anterior;
  - hacer clic fuera del menú también lo cierra.
- Se revisaron textos que estaban escritos directamente en español y no utilizaban el sistema de traducciones.
- Se amplió el catálogo **Español / English** en:
  - Inicio;
  - Perfil;
  - Eventos;
  - comentarios y respuestas;
  - filtros;
  - Notificaciones;
  - controles y mensajes dinámicos.
- Se corrigieron textos dinámicos como:
  - `Hoy / Today`;
  - `Ayer / Yesterday`;
  - `Ahora / Now`;
  - `Sin leer / Unread`;
  - paginación y estados de actividad.
- Las notificaciones dinámicas pueden construir correctamente frases con el nombre del asociado en ambos idiomas.
- El contenido creado por los usuarios conserva su idioma original; solo se traduce la interfaz de ASCLA.

**Resultado de la iteración:** la navegación se siente más ordenada y la interfaz bilingüe deja de mezclar textos de ambos idiomas en los flujos revisados.

---

## 1.9.7 — Reporte de comentarios y mejor identificación en notificaciones

**Objetivo:** extender la moderación a los comentarios y hacer más claras las notificaciones sociales.

### Cambios principales

- Se añadió **Reportar comentario** dentro del menú `⋯` de comentarios ajenos.
- Un asociado **no puede reportar su propio comentario**.
- La restricción existe tanto en la interfaz como en el backend.
- El reporte de comentarios reutiliza el sistema general de motivos de reporte.
- Los reportes quedan disponibles para revisión en el área de moderación administrativa.
- Las notificaciones indican de forma explícita qué asociado realizó la acción, por ejemplo:
  - `María Pérez comentó en tu publicación`;
  - `Carlos Gómez respondió a tu comentario`.
- En contenido protegido por Chatham House se mantiene la anonimización correspondiente.

**Resultado de la iteración:** los comentarios pasan a formar parte real del flujo de moderación y las notificaciones sociales aportan más contexto al usuario.

---

## 1.9.6 — Respuestas, Me gusta y acciones discretas en comentarios

**Objetivo:** hacer las conversaciones de la comunidad más naturales y reducir acciones visualmente invasivas.

### Cambios principales

- Se reemplazó el botón visible de eliminación de mensajes por un menú discreto de **tres puntos (⋯)**.
- En mensajes propios, el menú permite **Eliminar mensaje** con confirmación.
- Se aplicó el mismo patrón visual a los comentarios.
- Se añadió la posibilidad de **responder a un comentario**.
- Las respuestas se muestran anidadas debajo del comentario correspondiente.
- Se agregó **Me gusta** independiente para comentarios y respuestas.
- El contador de Me gusta de un comentario es independiente del de la publicación principal.
- Al responder un comentario de otro asociado se genera una notificación para dicho usuario.
- No se genera una auto-notificación al responderse a uno mismo.
- Las respuestas sujetas a moderación respetan el flujo de aprobación antes de notificar.
- Los nuevos controles se adaptan a modo claro, modo oscuro y pantallas móviles.

**Resultado de la iteración:** los comentarios evolucionan de un listado plano a una conversación social con respuestas, reacciones y acciones contextuales.

---

## 1.9.5 — Afinidad mínima y simplificación de calendario

**Objetivo:** mejorar la calidad de las recomendaciones de networking y simplificar las opciones de calendario de los eventos.

### Cambios principales

- Se eliminó **Descargar ICS** de toda la intranet.
- Se retiró la generación de archivos ICS del backend.
- Los eventos futuros pueden seguir utilizando Google Calendar cuando corresponda.
- Los eventos finalizados ya no muestran **Añadir a Google Calendar**.
- El backend también impide guardar en Calendar un evento que ya ha finalizado.
- Se añadió en Administración un parámetro configurable de **Afinidad mínima para recomendar (%)**.
- El valor predeterminado es **30 %**.
- Los perfiles por debajo del mínimo:
  - continúan existiendo normalmente en el directorio;
  - dejan de aparecer en recomendaciones automáticas de networking.
- Se mejoró el mensaje mostrado cuando no existen personas que alcancen el umbral de afinidad configurado.

**Resultado de la iteración:** las recomendaciones se vuelven más relevantes y el flujo de calendario queda centrado en eventos futuros y en Google Calendar.

---

## 1.9.4 — Reglas de participación y eventos finalizados

**Objetivo:** establecer valores iniciales más útiles para nuevos asociados y cerrar correctamente las acciones que ya no tienen sentido en eventos anteriores.

### Cambios principales

- En Perfil se activan por defecto para usuarios nuevos:
  - **Descubrir nuevas conexiones**;
  - **Participar en microeventos**.
- Si el usuario posteriormente desactiva una preferencia y guarda el perfil, su decisión se conserva.
- Los eventos pasados siguen visibles como historial, pero ya no permiten:
  - registrarse;
  - cancelar inscripción;
  - rechazar invitaciones;
  - seguir la conversación;
  - invitar asociados.
- Los eventos finalizados muestran un estado informativo **Evento finalizado**.
- Las restricciones de eventos pasados también se validan en el servidor.
- Si un usuario comenta su propia publicación, ya no recibe una notificación por su propio comentario.
- La misma regla se conserva cuando el comentario pasa previamente por moderación.

**Resultado de la iteración:** se eliminan acciones inconsistentes en eventos históricos y se reducen notificaciones innecesarias.

---

## 1.9.3 — Ajustes visuales del modo oscuro

**Objetivo:** corregir elementos que todavía utilizaban fondos o colores propios del modo claro después de introducir el tema oscuro.

### Cambios principales

- Se corrigió el contraste de **Publicados recientemente** en Inicio.
- Se corrigió la tarjeta **Un espacio de confianza**:
  - se eliminó el fondo blanco forzado;
  - se adaptaron fondo, borde, iconos y textos al tema activo.
- Se revisó **Perfil → Privacidad y participación** para mejorar la legibilidad de:
  - títulos;
  - descripciones;
  - opciones de participación;
  - notas informativas;
  - controles de visibilidad.
- Los estados **Visible / Oculto** dejaron de utilizar fondos blancos incompatibles con modo oscuro.
- Se ajustó el icono de apariencia:
  - ☀️ sol cuando el tema efectivo es claro;
  - 🌙 luna cuando el tema efectivo es oscuro.
- En modo Automático, el icono refleja el tema que el dispositivo está utilizando realmente.

**Resultado de la iteración:** el modo oscuro pasa de ser funcional a presentar una apariencia más consistente en Inicio y Perfil.

---

## 1.9.2 — Modo oscuro y apariencia automática

**Objetivo:** incorporar una experiencia visual adaptable a las preferencias del dispositivo.

### Cambios principales

- Se añadió **modo oscuro** a la intranet.
- Se agregó un selector de apariencia con tres opciones:
  - **Automático (sistema)**;
  - **Claro**;
  - **Oscuro**.
- La opción predeterminada utiliza `prefers-color-scheme` para detectar la configuración del dispositivo.
- Si el sistema cambia de claro a oscuro mientras ASCLA está abierto, la interfaz puede adaptarse automáticamente cuando se usa el modo Automático.
- Las selecciones manuales de Claro u Oscuro se conservan en el navegador.
- El tema se aplica también en el inicio de sesión y en el panel administrativo ASCLA.
- Se aplicó el tema lo antes posible durante la carga para reducir el parpadeo blanco inicial.
- Se retiró del Perfil el texto técnico:
  - `El envío usa la configuración de correo de WordPress/SMTP`.

**Resultado de la iteración:** ASCLA incorpora un sistema de apariencia moderno con detección automática y control manual.

---

## 1.9.1 — Cambios sin guardar y mejora del formulario de contraseña

**Objetivo:** mejorar la distribución del formulario de seguridad y evitar pérdidas accidentales de información editada.

### Cambios principales

- En **Seguridad de la cuenta** se reorganizaron los campos:
  - Contraseña actual queda en una fila independiente;
  - Nueva contraseña y Confirmar nueva contraseña aparecen en paralelo en escritorio;
  - en móvil vuelven a apilarse para conservar la legibilidad.
- Se añadió detección de **cambios sin guardar** en:
  - Perfil;
  - Configuración del panel de administración.
- Si un usuario modifica información y trata de navegar a otra sección, se muestra una advertencia antes de perder los cambios.
- Al recargar, cerrar la pestaña o abandonar la página se utiliza además la protección estándar del navegador.
- Si un campo vuelve exactamente a su valor original, deja de considerarse modificado.
- Después de guardar correctamente ya no se muestra la advertencia.

**Resultado de la iteración:** disminuye el riesgo de perder configuraciones o cambios de perfil por navegación accidental.

---

## 1.9.0 — Idiomas, seguridad de cuenta y reportes estructurados

**Objetivo:** consolidar mejoras de acceso, preferencias de idioma, seguridad del asociado y moderación comunitaria.

### Idiomas

- El selector del inicio de sesión queda limitado a:
  - **Español**;
  - **English**.
- Se elimina la aparición adicional de `en_US` en el selector.
- Se evita que el selector nativo de WordPress añada idiomas no soportados por la interfaz ASCLA.
- Se incorpora selector de idioma dentro de la intranet.
- La preferencia queda asociada al usuario y se conserva al navegar, recargar o volver a iniciar sesión.

### Seguridad de cuenta

- Se añade **Cambiar contraseña** directamente desde el Perfil.
- Para cambiarla se solicita:
  - contraseña actual;
  - nueva contraseña;
  - confirmación de la nueva contraseña.
- Se valida la contraseña actual antes de efectuar el cambio.
- Se comprueba coincidencia entre los dos campos de nueva contraseña.
- Se impide reutilizar inmediatamente la misma contraseña como nueva contraseña.
- Se incorpora recuperación por correo para quien no recuerde la contraseña actual, utilizando el flujo seguro de WordPress.

### Reportes y moderación

- El botón Reportar deja de enviar una denuncia inmediatamente.
- Se incorpora un menú de motivos similar a las plataformas sociales modernas.
- Entre los motivos disponibles se incluyen categorías como:
  - acoso;
  - fraude o estafa;
  - spam;
  - información falsa;
  - odio;
  - amenazas o violencia;
  - contenido sexual o explícito;
  - cuenta falsa;
  - bienes o servicios restringidos;
  - otro motivo.
- Se añade un campo opcional de información adicional.
- El reporte conserva motivo y detalle para que el equipo de moderación pueda revisarlo.
- Se evita mostrar la acción de reporte en publicaciones propias cuando no corresponde.

**Resultado de la iteración:** la serie 1.9.x comienza con una base más coherente para seguridad, internacionalización y moderación.

---

# Versiones anteriores conservadas

Las siguientes versiones ya formaban parte del historial técnico recibido antes de la serie 1.9.x. Se mantienen para no perder trazabilidad del proyecto.

## 1.7.0

Último estado del historial fuente recibido antes de las iteraciones documentadas en la serie 1.9.x.

## 1.6.0

Snapshot recuperado del proyecto.

## 1.5.0

Snapshot recuperado del proyecto.

## 1.4.2

La versión aparece documentada y existe evidencia de hashes de sus archivos, pero su snapshot completo no estaba presente en el ZIP recibido ni en los objetos recuperables del repositorio. Por este motivo no se creó un tag `v1.4.2` con contenido aproximado o inventado.

Si se recupera el ZIP original de esta versión, puede incorporarse entre `v1.4.1` y `v1.5.0` conservando la trazabilidad exacta.

## 1.4.1

Snapshot recuperado del proyecto.

## 1.4.0

Snapshot recuperado del proyecto.

## 1.3.0

Snapshot recuperado del proyecto.

## 1.2.0 — Centro de actividad y notificaciones

- La campana abre un panel de actividad.
- Los avisos pueden incluir asunto, origen, categoría y acción.
- Las notificaciones enlazan a publicaciones, eventos, recursos, perfiles, conversaciones y respuestas guardadas del asistente.
- Se incorporan filtros, paginación, contador real y lectura individual o total.

## 1.1.0 — Directorio, networking y agenda

- Filtros combinados por autor, fuente, tema, etiqueta y categoría.
- Navegación por foros.
- Intereses y áreas de conocimiento en el directorio.
- Agenda mensual y eventos ordenados antes de paginar.
- Invitaciones internas a eventos.
- Avisos de recursos afines.
- Sugerencias semanales de networking.
- Imágenes en el Hub y logos de aliados.

## 1.0.4 — Acceso ASCLA

- Inicio de sesión y recuperación adaptados a la identidad visual de ASCLA.
- Interfaz responsive para acceso desde móvil.
- Entrada principal por `/intranet/`.
- Conservación de la sección solicitada después de iniciar sesión.
- Cierre de sesión con retorno al formulario de acceso.
- Uso de autenticación nativa de WordPress sin reemplazar el sistema de cuentas y contraseñas.

## 1.0.3

Versión histórica recuperada del repositorio Git original.

---

# Criterio de versionado utilizado

Durante la serie 1.9.x se adoptó un versionado incremental para reflejar cambios pequeños y verificables sin producir saltos innecesarios:

```text
1.9.0 → 1.9.1 → 1.9.2 → ... → 1.9.27
```

Las modificaciones exclusivamente documentales, como la ampliación de este archivo, **no generan por sí solas una nueva versión del plugin**. La versión `1.9.27` se justifica por depurar la presentación editorial del conocimiento, verificar la duración directamente contra YouTube y visibilizar los estados editoriales; el esquema de datos permanece en 7.

# Notas de trazabilidad

- `VERSION_HISTORY.md` documenta la evolución funcional y no sustituye los commits/tags del repositorio.
- Cuando existe un snapshot verificable se conserva como referencia histórica.
- No se crean tags ficticios para versiones cuyo código fuente original no esté disponible.
- La carpeta `docs/` se mantiene fuera del repositorio público según la configuración actual de `.gitignore`.
- El estado funcional vigente del código fuente corresponde a **ASCLA Core 1.9.50**.
