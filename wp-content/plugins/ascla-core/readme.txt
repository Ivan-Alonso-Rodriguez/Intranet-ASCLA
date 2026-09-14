=== ASCLA Core ===
Requires at least: 6.6
Requires PHP: 8.2
Stable tag: 1.9.15
License: GPLv2 or later

Intranet privada para la comunidad ASCLA. Instalar este ZIP desde Plugins.
Activación por sitio. Elementor opcional. Datos conservados al desinstalar.
ASCLA > Configuración permite preparar demo e integraciones sin editar PHP.

== Changelog ==

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
Navegación interna sin recarga con History API, shell inicial desde WordPress y usuario Ivan idempotente con contraseña configurable.
= 1.3.0 =
Privacidad consistente, avisos robustos, validación previa de ediciones, fidelidad extractiva y redacción pre/post IA. Networking y microagendas conectados a providers; multimedia demo con cápsulas e infografía sustentadas.
= 1.2.0 =
Notificaciones con contexto y destinos autorizados, filtros, paginación, contador y lectura. Historial de consultas y respuestas guardadas. Migración de esquema 4 compatible con avisos anteriores.
