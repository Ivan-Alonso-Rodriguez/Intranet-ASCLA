# ASCLA Core — Intranet 2.0

Plugin WordPress portable para una comunidad profesional privada. Incluye perfiles, directorio, networking determinístico, Hub, foros, mensajería, eventos, círculos de conversación, recursos, asistente con fuentes, galerías, aliados, contacto y moderación.

Toda la funcionalidad propia está en `wp-content/plugins/ascla-core/`. Elementor es opcional. No se modifica WordPress Core ni se necesita un tema específico. El plugin conserva sus datos al desactivarse o desinstalarse.

## Versión actual: 1.9.49 · esquema 10

La versión 1.9.49 mejora la presentación de la **fecha de nacimiento** dentro del perfil y recupera la política **adaptativa de Cloudflare Turnstile**. El cumpleaños continúa siendo privado y conserva el saludo, correo anual y aviso a administración. El login permite intentos normales al inicio; tras tres fallos activa el widget oficial de Cloudflare, al quinto fallo aplica una espera temporal por cuenta y una ráfaga mayor de intentos desde la misma IP provoca un bloqueo temporal adicional. Se mantiene el esquema 10.

Administración con solicitudes persistentes, historial y gestión de usuarios desde la propia interfaz ASCLA: alta, búsqueda, edición de datos/rol, suspensión/reactivación y eliminación protegida. Los foros se publican sin aprobación; Galería, Centro de Conocimiento y Eventos sólo permiten crear/editar/publicar a Administradores y Ejecutivos ASCLA. Los eventos ordinarios se publican directamente, salvo borrador explícito; los microeventos conservan revisión administrativa.

El autor o un administrador puede eliminar contenido y comentarios. Al eliminar un foro sus temas pasan a la lista general; eliminar un archivo es permanente y retira sus referencias y foto de perfil. Perfil → Administrar mis archivos siempre se limita a los archivos propios; Administración → Archivos permite a administradores consultar la biblioteca global. Las imágenes de Perfil, Hub, Eventos, Galería, Conocimiento y Aliados se pueden encuadrar antes de subir: Perfil usa 1:1, Hub/Eventos/Conocimiento 16:9, Galería 4:3 y Aliados 1:1. El navegador genera una versión optimizada y, cuando existe recorte, una copia maestra no recortada y optimizada; ambas siguen siendo privadas y se eliminan juntas. La preferencia de idioma del login se guarda por cuenta y se conserva al recargar y navegar. El catálogo inglés cubre navegación, controles, Perfil, actividad/notificaciones y los flujos principales. Las publicaciones, documentos y otros contenidos creados por usuarios conservan su idioma original. Las respuestas del Asistente ASCLA siguen el idioma de interfaz del asociado.

Los proveedores reales configurables son **Google Gemini** y **OpenAI**. Cada uno mantiene modelo y API Key independientes, con claves cifradas, eliminación explícita y botón Probar conexión. OpenAI usa la Responses API y Gemini conserva su integración REST actual. La búsqueda admite términos parciales significativos y fuentes relacionadas. El asistente combina esas fuentes con un contexto vivo y autorizado de la intranet para consultas operativas, por ejemplo próximos eventos o publicaciones recientes. Las respuestas admiten paráfrasis: se valida la pertenencia de las referencias, no la igualdad de frases. Para datos de ASCLA sin evidencia suficiente, el asistente lo indica en lugar de inventar. Chatham House y revisión editorial se mantienen.

Las conexiones confirmadas pueden conversar directamente. Entre asociados no conectados, el primer mensaje llega como **solicitud de conversación**: el destinatario puede leerlo y aceptar o rechazar; solo después de aceptar se habilitan mensajes adicionales. El chat consulta novedades cada **2 segundos** con cursores, conservación de borradores y backoff tras errores. Recuperación de contraseña nativa y SMTP configurable. Las notificaciones internas de conexiones, mensajes y eventos pueden complementarse por correo según las preferencias del asociado; los correos de mensajería no incluyen el texto privado del mensaje y se limitan a **un aviso por conversación cada 15 minutos** para cumplir el uso controlado del correo. Mailpit recibe los correos únicamente en el desarrollo local. En producción se requiere un transporte de correo operativo.

El historial técnico de las entregas se resume en `VERSION_HISTORY.md`. Las métricas históricas de Sonar, cobertura y rendimiento no acreditan automáticamente la versión actual.

No se han desplegado estos cambios en el servidor remoto. Gemini, OpenAI, YouTube OAuth y Calendar OAuth requieren credenciales y pruebas con cuentas reales. LinkedIn/X reales siguen sin implementar.

### Procesamiento de video en 1.9.29

- **Temas automáticos:** el análisis puede activar Gestión de riesgos, Gobierno corporativo, Inteligencia artificial, Juntas directivas, Sostenibilidad y Transformación digital cuando el contenido los respalda. Los temas elegidos manualmente se conservan.
- **Duración real:** ASCLA prioriza `videos.list` / `contentDetails.duration` mediante YouTube OAuth; si el servidor no puede verificarla, prueba metadatos públicos del video y, desde la interfaz autenticada, el YouTube IFrame Player API como último respaldo. Nunca calcula la duración usando timestamps de una transcripción.
- **Transcripción obligatoria para IA:** una transcripción manual válida o subtítulos autorizados obtenidos desde YouTube son requisito para generar resumen, nota técnica y cápsulas. Si no se obtienen, el trabajo termina con un mensaje de revisión y no llama al proveedor de IA con contenido ficticio.

### Recomendaciones de personas y conocimiento en 1.9.42

- **RF-040 — asociados:** los intereses, áreas de conocimiento, industrias, objetivos e idiomas siguen siendo la base determinística. La experiencia profesional relacionada puede reforzar la afinidad y la actividad pública en temas similares aporta un ajuste pequeño. Los campos ocultos no se utilizan como señal de experiencia y no se inspeccionan chats privados, soporte ni asistencia a eventos.
- El cálculo de participación se reserva para una lista corta de candidatos cercanos al umbral, evitando que la mejora convierta el directorio en una consulta costosa cuando crezca la comunidad.
- **RF-041 — contenidos:** un recurso puede recomendarse por intereses explícitos, coincidencias con áreas/industrias/objetivos, información profesional o actividad reciente en contenido público que el asociado haya seguido, reaccionado, comentado o publicado.
- La **recencia no crea relevancia por sí sola**; únicamente ordena contenido que ya tiene una relación temática con el asociado.
- La interfaz muestra una explicación breve y segura: por ejemplo, “Coincide con tus intereses”, “Relacionado con tu perfil profesional” o “Relacionado con tu actividad reciente”.

### Recomendaciones de conocimiento en 1.9.28

- Una coincidencia directa entre los **intereses del asociado** y los temas del recurso tiene el mayor peso.
- Las **palabras clave** del recurso refuerzan la relevancia si coinciden con intereses o áreas de conocimiento del perfil; industrias y objetivos aportan una señal menor.
- Se reconocen coincidencias exactas, acrónimos comunes (por ejemplo, `IA` frente a `Inteligencia artificial`) y coincidencias parciales suficientemente claras.
- La **recencia** solo añade un pequeño desempate cuando ya existe relevancia temática; un recurso reciente pero no relacionado no entra en recomendaciones.
- La misma lógica se utiliza para los avisos de nuevos recursos, evitando que una etiqueta aislada tenga más peso que las preferencias explícitas del asociado.

## Historial: versión 1.2.0

La campana abre un panel de actividad con asunto, origen, categoría y acción. Los avisos enlazan a publicaciones, eventos, recursos, perfiles, conversaciones y respuestas guardadas del asistente. Incluye filtros, paginación, contador real y lectura individual o total. Los avisos antiguos sin referencia ofrecen acceso a su sección o al historial de consultas.

## Historial: versión 1.1.0

Incluye filtros combinados por autor, fuente, tema, etiqueta y categoría; navegación por foros; intereses y áreas en el directorio; agenda mensual completa y eventos ordenados antes de paginar. Incorpora invitaciones internas a eventos, avisos de recursos afines y sugerencias semanales de networking, imágenes en el Hub, logos de aliados y resultados multimedia estructurados.

## Acceso ASCLA

El acceso de asociados se realiza desde `/login/`, con logo, colores y textos de ASCLA adaptados a móvil. `/intranet/` y las demás páginas privadas quedan reservadas a usuarios autenticados y, si no existe sesión, redirigen a `/login/` conservando la sección solicitada. Ejecutivo, Moderador y Administrador trabajan en `/administracion/`; `/wp-admin/` queda reservado al Administrador técnico de WordPress. El cierre de sesión vuelve a `/login/`. `wp-login.php` permanece disponible para recuperación/restablecimiento de contraseña y autenticación técnica. Se utiliza la autenticación nativa de WordPress; no se cambian las cuentas ni las contraseñas.

## Requisitos

- WordPress 6.6 o posterior; PHP 8.2 o posterior con `mbstring`, `openssl`, `fileinfo` y extensiones habituales de WordPress.
- MySQL 8 o MariaDB 10.6+ con tablas InnoDB, permisos para `CREATE/ALTER TABLE` y disponibilidad de `GET_LOCK`.
- HTTPS en staging y producción. El desarrollo incluido se publica únicamente en `127.0.0.1:8088`.
- Ninguna dependencia PHP/Node en producción. Python 3 genera el ZIP; Docker, PHPUnit y Playwright sólo se usan para desarrollar/probar.

## Generar e instalar

```text
python scripts/build.py
```

Genera `dist/ascla-core.zip` y su SHA-256. El ZIP contiene una única carpeta `ascla-core/`, con archivos de ejecución; no incluye secretos, pruebas, dependencias de desarrollo ni documentos de referencia.

En WordPress: **Plugins → Añadir plugin → Subir plugin → elegir ZIP → Instalar → Activar**. Se crean doce páginas faltantes, roles, permisos, taxonomías y tablas versionadas. El instalador no sobrescribe páginas ajenas ni duplica las páginas que ya creó.

Accede a **ASCLA → Configuración** para elegir modos y políticas. Crea asociados en **Usuarios → Añadir nuevo**, con rol **Asociado ASCLA** o **Moderador ASCLA**. ASCLA no permite auto-registro público; las cuentas se provisionan exclusivamente desde Administración. Los asociados trabajan desde el frontend; no necesitan `wp-admin` para sus actividades.

## Desarrollo local

Copia `.env.example` a `.env` y asigna valores aleatorios distintos de al menos 12 caracteres. Nunca versiones `.env`.

```text
docker compose up -d
docker compose run --rm cli sh -c 'wp core install --url=http://localhost:8088 --title="ASCLA Comunidad" --admin_user=ascla.admin --admin_email=admin@example.invalid --admin_password="$ASCLA_ADMIN_PASSWORD" --skip-email'
docker compose run --rm cli wp eval-file /opt/ascla-scripts/install-local.php
docker compose run --rm cli wp ascla seed
```

La sintaxis de comillas del primer comando de instalación es compatible con PowerShell y shells POSIX. No reemplaces la variable por una contraseña dentro de un archivo versionado. Para un sitio ya instalado omite `wp core install`. Abre `http://localhost:8088/intranet/`. Usuario demo: `demo.asociado`; contraseña: valor local de `ASCLA_DEMO_PASSWORD`. Administrador local: `ascla.admin`; contraseña: `ASCLA_ADMIN_PASSWORD`.

También puedes crear la demo desde **ASCLA → Configuración → Preparar datos de demostración**, introduciendo una contraseña. Se generan 20 asociados ficticios y el perfil de prueba solicitado por Ivan (21 cuentas en total); los datos profesionales son de demostración. Las siguientes ejecuciones conservan ediciones y contraseñas existentes. No hay borrado automático de datos demo.

## Integraciones

- **Ubicaciones de perfil:** País/región usa un catálogo ISO normalizado y Ciudad ofrece sugerencias dependientes del país mediante CountriesNow. ASCLA consulta el servicio desde el servidor, cachea la respuesta durante 7 días y no bloquea un perfil existente si el proveedor geográfico está temporalmente indisponible.
- **Cloudflare Turnstile:** opcional y desactivado por defecto. Configura un widget en modo **Managed** y guarda Site Key/Secret en **ASCLA → Configuración → Seguridad**. El login usa una política adaptativa: los primeros intentos se procesan normalmente, tras **3 fallos de credenciales** aparece el widget oficial de Cloudflare, al **5.º fallo** se aplica una espera temporal por cuenta y una ráfaga mayor desde la misma IP puede activar un bloqueo temporal adicional. El Secret se cifra y el token siempre se valida en PHP contra Siteverify; ver “Success” no sustituye la validación de usuario y contraseña.
- **IA:** por defecto `DEMO MODE`, sin gasto. Para API real configura una Google Gemini API Key y un modelo habilitado en tu cuenta. El chat prioriza la información visible dentro de ASCLA y el contenido multimedia generado queda revisable, y un Administrador o Ejecutivo puede publicarlo directamente cuando confirma su revisión editorial. La arquitectura permite implementar otra `AIProviderInterface`.
- **YouTube:** introduce una URL válida en el recurso. Los videos se embeben; no se descargan ni almacenan completos. Puedes suministrar transcripción autorizada o conectar OAuth con permisos sobre los subtítulos. Sin permisos, se informa el error; en modo mock se identifica explícitamente la transcripción ficticia.
- **Google Calendar:** los eventos futuros pueden abrirse en Google Calendar sin credenciales o guardarse en un calendario conectado mediante OAuth. La descarga ICS fue retirada. Configura Client ID/Secret y URI de redirección para OAuth; cada asociado conecta su calendario desde Perfil. Guardar, actualizar y quitar un evento requiere pulsar la acción correspondiente.
- **LinkedIn/X:** curaduría demo con filtrado de promoción comercial. Los adaptadores reales declaran explícitamente que requieren aprobación/implementación según los permisos oficiales obtenidos. No hay scraping ni publicación externa automática.

Los secretos se cifran con AES-256-GCM y una clave derivada de las salts de WordPress, en opciones sin autoload. Rotar las salts obliga a reconectar las integraciones. Opcionalmente, un administrador de infraestructura puede usar constantes `ASCLA_AI_KEY` y `ASCLA_GOOGLE_CLIENT_SECRET`; no es necesario editar PHP para la instalación normal.

## Calidad y operación

Las pruebas están en `tests/`; deben ejecutarse exclusivamente contra WordPress local desechable. Las configuraciones de herramientas no sustituyen resultados medidos.

El entorno Docker incluye un servicio `cron` que ejecuta los eventos pendientes cada diez segundos. Se desactivan las actualizaciones automáticas sólo en los contenedores de pruebas para conservar una versión reproducible; mantén el sitio real actualizado mediante su procedimiento de operación.

Jobs: WordPress cron ejecuta `ascla_jobs`, continúa la cola pendiente con `ascla_jobs_continue`, revisa el mes y ejecuta `ascla_discovery` cada día. Las sugerencias de networking se notifican como máximo una vez por semana y asociado. Con WP-CLI puede usarse `wp ascla jobs` o `wp cron event run --due-now`. Los errores se ven en **ASCLA → IA y trabajos** y admiten reintento. Una solicitud HTTP no ejecuta el procesamiento multimedia directamente.

Si faltan páginas, reactiva el plugin o ejecuta `wp ascla migrate`. Si aparecen respuestas antiguas o sesiones mezcladas, excluye todas las páginas ASCLA y `/wp-json/ascla/v1/` del caché y purga la caché. Si falla una integración, comprueba modo, permisos, expiración y conectividad; nunca pegues tokens en logs o capturas. Si la demo ya existe, cambiar el password del formulario no reinicia contraseñas: usa la recuperación de WordPress.

La documentación interna de desarrollo se conserva localmente en `docs/`, pero esa carpeta está excluida deliberadamente del repositorio de GitHub.
