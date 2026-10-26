# Cumplimiento y correcciones ASCLA

Revisión documental: 26 de octubre de 2026. Plugin **1.11.0**, esquema **15**. Este documento describe el estado del código y la evidencia por etapa. La [matriz](requirements/ascla-analysis.md) y su [JSON](requirements/ascla-analysis.json) conservan los textos originales del Documento de Análisis y Diseño; el PDF fuente se proporciona por separado.

## Validaciones de QA, UAT e imágenes del 25 y 26 de octubre de 2026

La [corrección posterior de imágenes](docs/evidence/release-1.11.0/trivy-remediation1110.json) elimina los 379 hallazgos bloqueantes anteriores mediante runtimes Alpine actualizados y un buzón MailDev con dependencias corregidas. Trivy analiza las cuatro imágenes reales dentro de Jenkins y aprueba con cero hallazgos, sin exclusiones ni cambios a los umbrales. Se conservan WordPress, PHP, Apache y la rama de MariaDB; se comprueban la migración de datos, el correo y la regresión del plugin. Informe local: `test-results/trivy-remediation/index.html`.

La [validación anterior de QA y UAT](docs/evidence/release-1.11.0/full-qa1110.json) ejecutó 810 pruebas PHP por entorno, las suites de navegador y SonarQube real del curso: ambos Quality Gates aprobaron con 0 incidencias abiertas y cobertura global de 91.2 %. En esa ejecución anterior Trivy y ZAP bloquearon el despliegue; ese resultado histórico no describe las imágenes corregidas. No se atribuye un nuevo análisis remoto de SonarQube a la corrección de infraestructura ni se acredita despliegue remoto o aceptación humana de UAT.

## Primera validación de seguridad de Jenkins del 25 de octubre de 2026

La corrección posterior del acceso comunitario separa su presentación de la del login nativo: reutiliza formularios y botones, pero define su propio layout. No carga las paletas administrativas ajenas a ese formulario. Los archivos originales de WordPress y las reglas de ZAP permanecen intactos. La regresión comprueba dependencias, otros contextos, accesibilidad y acceso real; el control positivo independiente exige que ZAP continúe detectando timestamps reales. La decisión final depende de repetir el análisis completo y no se sustituye por ese control positivo.

El Jenkinsfile añade construcción de imágenes, Trivy bloqueante, reportes HTML/JSON, retención de la última ejecución incluyendo un éxito anterior, y limpieza final. En QA/UAT se mantienen las pruebas, SonarQube y Quality Gate y se ejecuta ZAP/CSP en un entorno HTTPS desechable con la imagen construida. Solo LOW se admite en Trivy; errores, UNKNOWN y reportes incompletos también bloquean. La [guía de CI](ci/README.md) distingue los requisitos del servidor del curso del laboratorio local, y no atribuye a sus ejecuciones la evidencia histórica de SonarQube o del hosting. El plugin y su esquema no cambian por esta integración. La [evidencia de Jenkins/Trivy](docs/evidence/release-1.11.0/jenkins-trivy1110.json) acredita el build local 3 bloqueado, HTML/JSON archivados y retención SUCCESS → FAILURE; el ZAP aislado de aquella primera revisión pasó por separado. Ese registro histórico no acredita el análisis posterior de SonarQube ni el Jenkins externo.

## Versión 1.11.0: interacción y calendario

La [revisión de claridad y moderación](docs/evidence/release-1.11.0/summary-review1110.json) reorganiza el bloque de participación sin cambiar fórmulas ni permisos. Conserva las seis cifras y distingue reservas, personas únicas, personas recurrentes, asistencias y promedio con listas verificadas. La cobertura se presenta una sola vez dentro del resumen. El caso cinco eventos/seis reservas/cero asistencias explica las cuatro listas pendientes. La cola editorial mantiene borradores, decisiones con motivo y controles de acceso; Pendiente de revisión usa las variables neutras del tema en toda la interfaz.

La [revisión de destinatarios](docs/evidence/release-1.11.0/comment-replies1110.json) sustituye las menciones automáticas por «Autor ▸ destinatario» en la cabecera y agrupa las respuestas debajo del comentario respondido, sin nuevos niveles visuales. Resuelve el nombre mediante los comentarios autorizados de la consulta, respeta alias y usa un texto neutro si el destinatario no está disponible. Conserva el texto guardado, el padre original y las notificaciones; el formulario abre vacío. La primera revisión de hilos permanece como evidencia histórica con su propio paquete.

La [revisión de Encuentros y Estadísticas](docs/evidence/release-1.11.0/admin-insights1110.json) cambia la presentación, con pasos, diagnóstico, estados, resumen y gráficos locales. Conserva los cálculos y controles de acceso del servidor. La tasa usa `rate_attended` y `rate_registered` de listas verificadas, no el total de personas o inscripciones; las barras por tema no se suman como personas únicas. La comparación identifica los dos períodos y usa una escala por indicador. Los controles de asistencia, preparación, archivo y restauración siguen disponibles.

La [primera revisión de conversaciones](docs/evidence/release-1.11.0/comment-threads1110.json) validó un único nivel visual para las respuestas, expansión por tandas y plegado por comentario principal. Esa entrega utilizaba menciones con el nombre público o alias; la revisión de destinatarios descrita arriba las sustituye. Sus pruebas locales incluyeron permisos, moderación, contadores y borradores, además de escritorio/móvil y ambos temas, con esquema, padres originales y contrato REST conservados.

La previsualización de PDF se abre dentro de la publicación, con páginas, zoom, texto extraído y descarga. El lector PDF.js 6.4.299 se carga desde archivos locales fijados y con licencia Apache-2.0; no se utilizan visores externos. Se conservan las cabeceras de seguridad y el acceso privado a los archivos. [Evidencia del lector PDF](docs/evidence/release-1.11.0/pdf-preview1110.json).

La revisión de perfiles y Galería compacta los intereses del perfil público, muestra el usuario de acceso solo en Mi perfil, mejora las barras de desplazamiento y cambia la acción de las tarjetas a «Abrir». El editor incorpora búsqueda paginada de eventos y publicación seleccionada por defecto para una galería nueva con permisos. El visor coloca las flechas junto a la fotografía; los adjuntos muestran sus nombres alineados. Eliminar una publicación de Galería retira también sus archivos privados y sus copias maestras sin uso. Notificaciones elimina el contador repetido de la cabecera y usa «notificaciones» en el pie. [Evidencia de esta etapa](docs/evidence/release-1.11.0/gallery-profile1110.json).

La [revisión de reactivación y notificaciones](docs/evidence/release-1.11.0/event-reactivation1110.json) registra pruebas locales de permisos, conservación de inscripciones, cupos y avisos por cada ciclo cancelar/reactivar. La operación utiliza el mismo bloqueo que las inscripciones y conserva el motivo anterior en el historial. Calendario se valida con HTTP simulado; no acredita entrega a Google real. La bandeja conserva lectura, filtros, paginación y borrado confirmado con un diseño renovado.

La [revisión de portadas predeterminadas](docs/evidence/release-1.11.0/knowledge-cover1110.json) restaura la composición anterior con fondo y título para recursos sin fotografía y para miniaturas fallidas, conservando las imágenes válidas y el diseño de las tarjetas nuevas. Sus comprobaciones locales tienen un paquete propio; los resultados anteriores mantienen su SHA original.

La [revisión del visor y Centro de Conocimiento](docs/evidence/release-1.11.0/media-knowledge1110.json) verifica ampliación de fotografías sin recorte, navegación del álbum, conservación del comentario, originales y documentos. Comprueba también tarjetas, filtros y accesos del Centro de Conocimiento en ambos temas y tamaños de pantalla. Esta etapa modifica solo la presentación y conserva los permisos de los archivos privados.

La [revisión de Galería y calendario](docs/evidence/release-1.11.0/gallery-calendar1110.json) comprueba borradores neutros, mayúsculas iniciales en sus etiquetas, estados de inscripción legibles en modo oscuro y el calendario manual para inscritos sin cuenta vinculada. El enlace para asistir se incorpora a esa plantilla únicamente con inscripción confirmada. Galería presenta portadas, recuento real de imágenes y tres accesos equivalentes; conserva filtros, permisos y paginación.

La revisión visual adicional se identifica en [visual1110.json](docs/evidence/release-1.11.0/visual1110.json): navegación desde los indicadores del inicio, estados y Chatham House neutros, acciones del Directorio con tipografía uniforme y separación reducida y tarjetas de eventos reorganizadas. Las pruebas anteriores conservan su SHA y alcance, sin atribuirlas al ZIP posterior.

La inscripción inicia una entrega de calendario con reintentos; conserva la inscripción si Google falla y muestra su estado. Usa la invitación del organizador cuando existe y, en su ausencia, una copia en el calendario personal conectado. La copia incluye el enlace del encuentro y se retira al cancelar. Sin conexión OAuth ni invitación, se ofrece una sola alternativa manual tras inscribirse. El enlace de Zoom se muestra únicamente a inscritos confirmados; la API lo oculta a lectores sin inscripción, conservando el acceso de edición de los responsables.

Se incorporan Enter/Shift+Enter en mensajería, eliminación de notificaciones de la propia cuenta con confirmación y límite de instantánea, historial de ventanas que conserva los formularios y confirmación al descartar comentarios y respuestas. La selección de texto no activa el cierre exterior. Se ajustan el estado Oculto en modo oscuro, el menú editorial, los cursores deshabilitados, las casillas y el aviso de pocas coincidencias.

La evidencia de esta entrega es local y se identifica en [usability1110.json](docs/evidence/release-1.11.0/usability1110.json). Las secciones fechadas siguientes conservan las verificaciones de 1.10.9. No acreditan el despliegue, ZAP, SonarQube ni aceptación UAT de 1.11.0. Las invitaciones de Google se comprueban con respuestas HTTP simuladas; la llegada al correo y la aceptación en una cuenta real requieren OAuth y validación del destinatario.

## Preparación del análisis SonarQube del 22 de octubre de 2026

Se corrige la impresión de scripts del acceso nativo de WordPress bajo CSP: se desactiva su concatenación únicamente durante el login para que las etiquetas registradas reciban nonce/SRI, conservando la política estricta. La prueba dirigida también verifica el bloqueo de HTML con un script sin nonce. Se ajustan contraste del indicador de afinidad, fechas del chat y estado de carga, y los nombres accesibles de las portadas de Galería y la cabecera del chat.

Las suites de QA distinguen el inicio y las acciones de cada rol, el botón Guardar borrador y el alcance de borradores propios de Eventos. La instalación fijada de PCOV permite generar cobertura nueva sobre un commit limpio. Estos cambios y su paquete posterior deben validarse en esa revisión; no se atribuyen a los reportes ZAP ni a los recorridos remotos anteriores y no acreditan despliegue del hosting, aprobación de Quality Gate ni UAT por sí mismos.

## Renovación de pruebas sobre el QA publicado del 22 de octubre de 2026

La [guía para sustentar seguridad](pruebas/seguridad/sustento.html) enlaza autenticación, reglas completas, severidades, CSP, videos y huellas. Una nueva lectura pública confirma el mismo pendiente de Apache en cinco rutas. Solo se dispone de administración WordPress, por lo que queda pendiente la intervención del operador del hosting para aplicar `ServerTokens Prod`/`ServerSignature Off` y repetir el chequeo. No se modifica el criterio ni se declara 20/20 en QA.

La [entrega de pruebas](pruebas/README.md) se renueva contra `https://wordpress.ingsoftware.lat`: **81/81 peticiones y 149/149 comprobaciones Bruno**, **6/6 recorridos principales Playwright** con 12 videos/12 capturas y **14/14 controles recientes** con 16 videos/16 capturas. Se comprueban los borradores en seis secciones, roles administrativos y sus excepciones, filtros de usuarios, tipo de asociado, Directorio de 4 × 3, Foros en lista única, Galería, Archivos y Aliados. Los nueve contenidos ficticios propios de la regresión reciente se retiran a la papelera. El trabajo del asistente devuelve **Google Gemini · API real** y conserva pregunta/respuesta/hilo; no acredita OpenAI ni evaluación semántica integral.

La comparación SHA-256 verifica que JavaScript y CSS del despliegue coinciden con el paquete local actual; el comportamiento del backend se contrasta con las pruebas, sin descargar sus PHP privados. La revisión remota de seguridad aprueba **seis controles en 42 vistas de cuatro roles**, sin errores JavaScript ni infracciones CSP. Los controles HTTP aprueban **19/20**: el hosting expone la versión de Apache en `Server`, pendiente de corregir. El ZAP activo aislado tiene fecha, entorno y resultado propios en el [dashboard de seguridad](pruebas/seguridad/dashboard.html). No se atribuye su configuración de servidor temporal al QA compartido.

El nuevo ZAP del 22 de octubre aprueba en HTTPS aislado: 14 rutas HTML, 18 operaciones GET, 52 reglas activas completadas por alcance, cero alertas altas/medias/bajas y nueve/una informativas en páginas/API. Pasan 20 controles CSP y ocho controles de navegador en 24 vistas locales. El entorno y sus credenciales temporales se retiran; ese resultado no sustituye la comprobación del hosting compartido.

Se versionan los [resultados y las fuentes locales de seguridad](pruebas/seguridad/fuentes.html), incluidas ocho copias verificadas de pruebas/configuración y el manifiesto de 209 archivos con la imagen ZAP. Los ejecutores completos ya forman parte del repositorio. La matriz enlaza esta evidencia del 22 de octubre y conserva los textos, prioridades y estados de sus requisitos; los originales privados de `test-results/` no se publican.

Los [reportes, videos y límites](pruebas/actualizacion/index.html) y el [resumen de esta revisión](docs/evidence/release-1.10.9/qa-refresh109.json) no cambian los 103 CP ni sus estados de cobertura, no renuevan la suite PHP completa, cobertura, Quality Gate o UAT, y no implementan la integración pendiente de ZAP en Jenkins. Los accesos autorizados permanecen fuera de Git.

## Directorio de doce perfiles del 22 de octubre de 2026

El Directorio muestra cuatro columnas y tres filas en escritorio, con un máximo fijo de doce perfiles por página. Tableta y móvil usan dos y una columnas respectivamente, conservando doce perfiles por página. Se mantienen búsqueda, filtros, orden y permisos al paginar. Los tamaños guardados en instalaciones anteriores se interpretan como doce; Configuración muestra ese valor de solo lectura.

La [evidencia dirigida](docs/evidence/release-1.10.9/directory-grid109.json) registra **40 pruebas PHP/255 aserciones**, **tres controles de navegador** y **49 controles portables** aprobados. Incluye trece perfiles ficticios para comprobar la segunda página y la ausencia de duplicados. Las evidencias anteriores conservan su fecha y alcance.

## Lista de Foros, Archivos y botones del 22 de octubre de 2026

Foros muestra los foros y sus publicaciones en una sola lista, sin el apartado Explorar foros ni una separación por tarjetas de directorio. Comparten búsqueda, fechas, filtros, total y paginación; Mis temas y Mis borradores conservan el alcance propio. Los clientes API que no solicitan incluir foros mantienen el listado exclusivo de temas.

Administración → Archivos muestra directamente la biblioteca, con búsqueda, propietario, apertura, eliminación confirmada y paginación. Al cancelar se conserva el archivo; al eliminar el último de una página se vuelve a una página válida manteniendo los filtros. Ejecutivo utiliza la misma biblioteca. Perfil → Cuenta y seguridad ofrece Mis archivos en su ventana privada. En Directorio, Ver perfil, Conectar y Enviar mensaje comparten una separación de 12 px.

La [evidencia dirigida](docs/evidence/release-1.10.9/layout-ui109.json) registra **74 pruebas PHP/631 aserciones** y **seis controles de navegador** aprobados. Se comprueban búsqueda y paginación conjunta, borradores, permisos de archivos, biblioteca integrada, cancelación/eliminación y separación de botones en escritorio y móvil. Esta etapa no repite la suite completa anterior, cobertura, ZAP ni UAT.

## Paridad de Ejecutivo y Administrador del 22 de octubre de 2026

Por solicitud explícita del responsable, Ejecutivo ASCLA comparte el inicio, la navegación y la gestión administrativa de Administrador ASCLA. Incluye Usuarios e intereses, importaciones, archivos, aliados, auditoría, contenido, eventos y atención de Contacto. Ambos quedan fuera de Directorio, conexiones y microeventos como participantes. Asociado y Moderador conservan su participación comunitaria.

Configuración requiere `ascla_configure`, exclusivo de Administrador; Ejecutivo no recibe ese permiso ni los permisos generales de WordPress. Las rutas de configuración, sus diagnósticos y la preparación de datos demo rechazan al Ejecutivo. La gestión de cuentas del Ejecutivo se limita a los roles comunitarios mediante las rutas de ASCLA; no permite crear administradores técnicos ni otorgar Configuración. La migración de capacidades versión 2 actualiza los roles existentes sin cambiar contraseñas, perfiles ni restricciones individuales.

La [evidencia de paridad](docs/evidence/release-1.10.9/executive-admin109.json) registra la suite PHP completa: **740 pruebas/8.296 aserciones, sin fallos**, además de **cinco controles de navegador** y **49 controles portables**. Identifica el paquete de esta etapa; se retiraron el entorno y los accesos temporales. Las evidencias anteriores conservan su alcance original; este ajuste no acredita despliegue remoto, ZAP, cobertura ni aceptación UAT. Es una decisión posterior al modelo de roles del documento de análisis; se conserva el texto original de sus requisitos y se registra la excepción en las notas de la matriz.

## Administración y perfil del 22 de octubre de 2026

Aliados agrupa Activar/Desactivar y Eliminar en un menú de tres puntos; ambas operaciones tienen confirmación y eliminar envía la ficha a la papelera. Se retira la sección Tareas de IA y su contador. Administrador y Ejecutivo no ven los accesos a Directorio, Mensajería y Contacto en la navegación; Asociado y Moderador conservan sus accesos. El tipo de asociado de los participantes se mueve a la cabecera derecha del perfil, y Registro e historial queda junto a Nueva galería.

Usuarios e intereses añade país y filtros múltiples de industria, interés y área de conocimiento, conserva rol y acceso, y permite buscar datos profesionales además de nombre, usuario y correo. Las selecciones se combinan antes de calcular totales y páginas. El alcance administrativo incluye cuentas suspendidas o que no aparecen en Directorio; el listado comunitario conserva sus restricciones de visibilidad. La función común de URL conserva los valores múltiples en ambas pantallas.

La [evidencia dirigida](docs/evidence/release-1.10.9/admin-ui109.json) registra **38 pruebas PHP/335 aserciones** y **nueve controles de navegador** aprobados en WordPress desechable local. El paquete de esa etapa contenía 201 archivos y 2.062 mensajes por idioma. No se repiten cobertura, ZAP, Quality Gate ni UAT; sus resultados anteriores mantienen su fecha y alcance.

## Borradores, foros y galerías del 21 de octubre de 2026

Se corrigen #573–#576: Hub, foros/temas, Galería, recursos, Eventos y Aliados admiten borradores incompletos y ofrecen guardarlos al cerrar una creación con cambios. Cada sección dispone de **Mis borradores**; Foros reúne sus foros y temas propios. Al guardar desde estas secciones se abre esa vista. **Mis publicaciones** incluye los eventos propios sin fecha; Comunidad, próximos eventos y eventos anteriores muestran publicaciones. Publicar o enviar a revisión conserva sus mínimos, permisos y validación de metadatos.

Los foros tienen tarjetas visibles con descripción, autor y acceso. La portada de Galería abre el contenido con clic o teclado. La [evidencia de esta regresión](docs/evidence/release-1.10.9/drafts-ui109.json) registra **76 pruebas PHP dirigidas/859 aserciones**, **diez controles de navegador** y **49 controles portables** aprobados; no es una nueva suite completa. El ZIP de esa etapa contenía 201 archivos y 2.056 mensajes por idioma; su huella se conserva en el resumen, separada del [manifiesto actual](docs/evidence/release-1.10.9/package.json). Se retiraron el entorno temporal y sus credenciales. No se genera cobertura ni se repite ZAP; la aceptación remota, UAT y el Quality Gate mantienen sus validaciones pendientes. Los estados y porcentajes de los 302 requisitos no aumentan por esta corrección documental.

Las copias revisadas del [dashboard de seguridad](pruebas/seguridad/dashboard.html) conservan el análisis local del 19 de octubre y omiten datos de sesión. Aquel registro no acredita Jenkins. La [validación posterior de QA/UAT](docs/evidence/release-1.11.0/full-qa1110.json) sí comprueba la integración real de ZAP/CSP, el archivado, la retención del último build y la limpieza; conserva su resultado bloqueante sin excepciones.

## Perfil, mensajería y retiro editorial del 20 de octubre de 2026

LinkedIn solo acepta enlaces de linkedin.com y sus subdominios; X acepta x.com y twitter.com. Se validan en la interfaz y en las escrituras del perfil y de administración, incluyendo dominios parecidos, credenciales en la URL y puertos ajenos al sitio. El sitio personal mantiene su campo independiente. La clasificación se muestra como «Individual» y conserva el código almacenado, sin reclasificar cuentas.

Una conexión activa prevalece sobre solicitudes antiguas de conversación: el chat permite escribir y no muestra avisos de aceptación pendientes. El texto de entrada ya dependía del permiso de escritura; se corrige además el estado heredado que podía mostrar un aviso contradictorio.

Galerías y recursos disponen de «Ocultar del catálogo», «Volver a publicar» y un registro administrativo con búsqueda, estado e historial. Ocultar conserva el ID, el contenido y sus archivos, retira la publicación del catálogo y cancela su programación. Se conservan los permisos por rol y autor y la revisión necesaria para republicar material generado. RF-190 y RF-194 mantienen sus textos y estados de cumplimiento.

La limpieza autorizada de resultados retiró **29.794 archivos generados (1.097.257.064 bytes)**, principalmente snapshots antiguos de Sonar y ejecuciones temporales. Conserva los informes referenciados por evidencia, los resultados recientes y las copias de recuperación. La [evidencia de esta revisión](docs/evidence/release-1.10.9/profile-editorial109.json) identifica su ZIP y sus verificaciones; no atribuye a este paquete los análisis ZAP completos de etapas anteriores.

## YouTube automático y ajuste CSP del 20 de octubre de 2026

Se retira «Actualizar datos de YouTube» del detalle de recursos y su acción manual. La sincronización de metadatos al guardar o procesar un video continúa; «Generar resumen y nota» conserva la detección automática de duración como alternativa cuando el servidor no la obtiene.

Los scripts del HTML ASCLA requieren nonce por respuesta y utilizan `strict-dynamic` para sus dependencias. Se retiran de `script-src` tanto `self` como los permisos generales de YouTube, ytimg y Cloudflare; los dominios necesarios para imágenes, conexiones y marcos conservan sus directivas específicas. Esto evita autorizar scripts arbitrarios o endpoints JSONP de esos hosts. El cargador oficial de YouTube recibe el nonce de la aplicación.

El [resumen del ajuste](docs/evidence/release-1.10.9/youtube-csp109.json) identifica sus verificaciones y el ZIP correspondiente. El análisis ZAP completo del apartado siguiente corresponde al paquete anterior identificado en su propio manifiesto; no se presenta como una nueva ejecución sobre este ajuste. La versión permanece en 1.10.9 y la instalación es manual.

## Seguridad y preparación de ZAP del 19 de octubre de 2026

La versión sigue siendo **1.10.9**, esquema 15. Se incorpora CSP impuesta con nonce por respuesta, restricciones de eventos y estilos en atributos, SRI de assets locales y cabeceras contra clickjacking, sniffing y filtraciones entre orígenes. La API ASCLA conserva permisos y respuestas de error y deja de reflejar orígenes externos con credenciales. Los formularios de acceso de ASCLA y WordPress vinculan el nonce anónimo a una cookie aleatoria de cada navegador; las cookies reciben sus restricciones de seguridad sin cambiar el flujo de autenticación.

El [procedimiento manual de ZAP](MANUAL_QA_UAT.md#zap) prepara HTTPS y WordPress desechable, analiza páginas y API por separado y comprueba CSP fuera de ZAP. Las pruebas no requieren Jenkins ni generan cobertura. El [resumen de seguridad](docs/evidence/release-1.10.9/security109.json) debe leerse con sus rutas, intensidad, operaciones incluidas y limitaciones; los informes nativos privados permanecen en el directorio de resultados ignorado. El administrador nativo completo, otros temas/plugins, escrituras destructivas e integraciones externas reales requieren validación adicional. Esta revisión no aumenta los estados ni el porcentaje de cumplimiento del análisis.

El endurecimiento de Apache/PHP y HSTS de recursos estáticos necesita configuración del servidor: no viaja dentro del ZIP del plugin. La prueba local no acredita que esas medidas estén instaladas en el WordPress publicado ni el cumplimiento de RNF-003 en el hosting de destino.

La alerta baja sobre marcas Unix de revisión del perfil se corrige retirando de las respuestas HTML/REST las fechas internas que la interfaz no utiliza. Conserva en el servidor los plazos, confirmaciones y decisiones de posponer; el navegador recibe el estado necesario para mostrar sus avisos. La regla de ZAP permanece habilitada y no se añade una exclusión para ocultarla.

Las cookies propias de Turnstile que conservan el control de intentos y la confianza del navegador cifran su contenido con AES-256-GCM y autentican su finalidad. Mantienen la comprobación de firma, caducidad, IP y agente de usuario que corresponda, los límites de intentos y la validación del captcha. Las cookies firmadas anteriores solo se aceptan hasta su vencimiento original; no se altera el formato de autenticación de WordPress. Se comprueban el rechazo de manipulaciones, el intercambio entre finalidades y la caducidad.

La ejecución final de ZAP 2.17.0 queda **PASS** en el entorno local HTTPS desechable. Ambos análisis completan sus **52 reglas aplicables**, con intensidad LOW; XSS DOM utiliza un navegador y las reglas HTTP ocho hilos. Log4Shell (40043) se registra como no aplicable a este WordPress/PHP sin Java/Log4j; no se declara ejecutada ni se filtran alertas. Una regla pendiente, un fallo del navegador o la pérdida de autenticación producen ERROR. El recorrido DOM excluye el acceso nativo y las acciones de contraseña para conservar su sesión; esas funciones mantienen sus pruebas dedicadas.

| Análisis | Alcance | Solicitudes de ataque | Altas / medias / bajas | Categorías informativas |
|---|---|---:|---|---:|
| Páginas | 14 rutas HTML ASCLA | 30.996 | 0 / 0 / 0 | 9 |
| API | 18 operaciones GET documentadas | 3.686 | 0 / 0 / 0 | 1 |

Los originales conservan las alertas informativas. La sesión administrativa es válida antes y después de cada análisis y el tráfico del servidor confirma los accesos previstos. También aprueban **20 controles CSP independientes**, **ocho comprobaciones de navegador en 24 vistas**, **708 pruebas PHP/7.992 aserciones**, **49 controles portables**, **33 solicitudes/54 comprobaciones de integración y E2E**, el cliente de acceso nativo y tres recorridos UI. Turnstile se comprueba con una clave pública de pruebas y YouTube con su reproductor real. No se genera cobertura: los cuatro archivos existentes conservan sus SHA-256. El ZIP 1.10.9 de 200 archivos coincide con las fuentes; los servicios, volúmenes y accesos temporales se retiraron. El [resumen verificable](docs/evidence/release-1.10.9/security109.json) conserva las huellas, reglas completadas, resultados y límites. Este resultado acotado no certifica el hosting publicado ni ausencia universal de vulnerabilidades.

## Entrega 1.10.9 del 17 de octubre de 2026

Los cambios del 17 de octubre se consolidan en **1.10.9**: rendimiento de los módulos, perfil y Directorio, teléfono con banderas, interfaz administrativa, Consultas de Contacto, edición de usuarios, estadísticas, RAG/privacidad e invitados de microeventos. Conserva el esquema 15 y los estados de la matriz.

El [paquete vigente](docs/evidence/release-1.10.9/package.json) y la [verificación de versión](docs/evidence/release-1.10.9/release109.json) corresponden a 1.10.9. Las evidencias de las etapas anteriores conservan la etiqueta provisional 1.10.8 y sus huellas originales; no se reescriben ni se presentan como ejecuciones de esta nueva versión. La instalación continúa siendo manual.

El ajuste posterior de ancho conserva **1.10.9**: los formularios nativos de WordPress limitan card a 520 px; Estadísticas establece su propio ancho completo para filtros, indicadores y vistas, evitando el espacio vacío a la derecha. La [comprobación de diseño](docs/evidence/release-1.10.9/statistics-width109.json) conserva la reproducción anterior y el alcance de la validación. No modifica cálculos, datos ni permisos.

## Personas seleccionadas para microeventos del 17 de octubre de 2026

La ficha del microevento y su revisión muestran a administración las personas seleccionadas para la propuesta y su estado actual. Antes de publicar se indica Por invitar; después se distinguen invitaciones sin responder, aceptación, rechazo, lista de espera, cupos y reconfirmaciones. La selección no se presenta como asistencia confirmada ni como prueba de entrega de correo o Google Calendar. Las personas que se inscriban después de la apertura pública siguen en la gestión general de inscripciones; no se atribuyen a la selección automática.

Los nombres se consultan exclusivamente con permiso de administración, incluso cuando el asociado no figura en el Directorio. La lista no se añade a los listados generales ni a las respuestas del detalle para asociados o moderadores. La revisión refleja los candidatos que sigan siendo elegibles; consultar la ficha no modifica la selección. Una cuenta eliminada se muestra como no disponible. No cambia los estados de cumplimiento de la matriz.

Validación local: **42 pruebas PHP/545 aserciones**, **14 recorridos de regresión del ciclo de microeventos**, **nueve controles del nuevo flujo** y **49 controles portables** aprobados. La lista se contrasta con REST y respuestas reales de cuentas temporales; se revisan cinco vistas, ambos temas, móvil de hasta 360 px e inglés, sin desbordamiento ni incidencias automáticas WCAG A/AA en el área revisada. La [evidencia](docs/evidence/release-1.10.8/micro-invitations108.json) identifica la instantánea y el ZIP de 197 archivos y 2.018 mensajes por idioma. Datos y volúmenes temporales retirados, cuatro reportes de cobertura intactos. Sin despliegue remoto ni certificación de entrega externa, accesibilidad integral o UAT.

## Estadísticas más claras del 17 de octubre de 2026

El panel organiza sus tareas en **Resumen, Participación, Intereses, Comparar períodos y Temas con IA**, con una explicación de cada vista. Los indicadores distinguen personas únicas, asistencias por evento e inscripciones. Se separan las cifras de participación y las cuentas de la comunidad; cada una indica qué cuenta y qué filtros le afectan. Las listas verificadas tienen una barra de avance y acceso directo al registro de eventos. Cuando no existe una base para calcular tasas o promedios, no se presenta un cero como si fuera una medición.

Los filtros habituales quedan visibles y categoría/búsqueda/evento se despliegan al necesitarlos. Se explica que buscar actualiza las opciones del selector, mientras elegir un evento filtra las cifras; quitar filtros conserva las fechas. Un error del servidor mantiene el formulario para poder corregirlo. La comparación muestra ambos períodos y explica el cambio porcentual. Intereses distingue selecciones actuales, fuentes y participación, sin sumar personas duplicadas. La vista de IA explica su función, su cobertura y la acción explícita de analizar pendientes.

El registro de asistencia guía **registrar, revisar y confirmar**. La importación de Zoom y su criterio de permanencia quedan en apartados desplegables; las ayudas explican cada estado y el efecto de confirmar la lista. Conserva los permisos, cálculos, paginación, correcciones manuales y revisión del CSV del servidor. Esta etapa modifica presentación y manejo de errores de la interfaz; no introduce métricas nuevas ni cambia los estados de cumplimiento del análisis.

Validación: **13 recorridos funcionales**, **10 controles de usabilidad** y **49 controles portables** aprobados. Se revisan **11 vistas** en escritorio, tableta y móvil de hasta 360 px, con ambos temas, sin desbordamiento ni incidencias automáticas WCAG A/AA. Las cifras se contrastan con REST y se comprueban la edición de una fila y la verificación posterior. La [evidencia local](docs/evidence/release-1.10.8/statistics-layout108.json) diferencia la regresión del ajuste final de contraste, campos y unidades. ZIP de 196 archivos y 2.001 mensajes por idioma verificados; fuentes PHP conservadas. Los entornos y datos temporales se retiran. Sin cobertura nueva, despliegue remoto ni certificación integral de accesibilidad o UAT.

## Edición administrativa de usuarios del 17 de octubre de 2026

Editar usuario permite cambiar directamente nombres, apellidos, correo, cargo, empresa, tipo de asociado, nacimiento, teléfono, país y ciudad. Añade biografía, experiencia, enlaces profesionales y los ocho grupos de intereses, áreas, sectores, objetivos, idiomas y preferencias de conocimiento. Los apartados adicionales se despliegan por tema. La solicitud de Contacto es opcional; si se vincula, debe ser válida, asignada y de uso único.

El servidor conserva las preferencias de privacidad y participación del asociado. Valida los valores antes de escribir, comprueba persistencia y revierte la cuenta, permisos y perfil si falla un guardado. La revisión del perfil impide que un formulario antiguo sobrescriba datos recientes. El registro administrativo identifica actor, destinatario y campos modificados, sin copiar sus valores privados. Las desmarcaciones de intereses se conservan frente al aprendizaje automático.

Esta ampliación responde a la nueva instrucción del usuario y constituye una excepción expresa a **RN-001 y RN-032**. Ambos pasan de Código revisado a Ajustado por decisión del usuario; se conserva el texto del PDF y el total de requisitos revisados o ajustados. Las cuentas técnicas continúan gestionándose desde WordPress.

Validación: **693 pruebas PHP/7.890 aserciones** en la instantánea de regresión y **79 pruebas dirigidas/472 aserciones** con las fuentes PHP finales; esos casos se solapan y no se suman. Pasan **49 controles portables**, **cinco recorridos de navegador** y dos vistas con comprobaciones automáticas WCAG A/AA y sin desbordamiento. La [evidencia de esta etapa](docs/evidence/release-1.10.8/admin-edit108.json) diferencia las instantáneas, el ajuste final y el ZIP de 196 archivos verificado. Los catálogos contienen 1.851 mensajes por idioma. No acredita actualización remota ni aceptación UAT y no se recoge cobertura nueva.

## Referencias públicas y privacidad de la IA del 17 de octubre de 2026

La detección deja de considerar confidencial cualquier expresión con mayúsculas o cualquier organización mencionada. Conserva referencias públicas a autores, instituciones, normas, conceptos y documentos; por ejemplo, Michael Porter, Banco Mundial, Balanced Scorecard, COSO ERM e ISO 37000. En contenido protegido se retiran las identidades expresamente indicadas y las atribuciones detectadas de oradores y sus afiliaciones. El correo y los enlaces de perfil/contacto o con credenciales siguen tratándose como privados. Los enlaces a documentos públicos se conservan. La revisión editorial y el campo de identidades reservadas siguen siendo necesarios: la detección contextual no es un clasificador infalible.

Las instrucciones compartidas de Gemini, OpenAI y DeepSeek permiten esas referencias y piden expresar las ideas protegidas de forma impersonal. La aplicación retira el marcador identidad reservada de respuestas nuevas, historial y campos editoriales generados. Una coincidencia con el nombre o empresa de algún asociado ya no convierte por sí sola una cita pública en información confidencial. Se mantienen el RAG, los permisos por objeto, la exclusión de borradores ajenos y la prioridad de las identidades declaradas sobre las referencias públicas.

No se sobrescriben las fuentes ni se regeneran recursos en bloque. La limpieza de visualización no puede reconstruir nombres que un resumen anterior ya omitió: recuperar esos datos exige regenerar ese recurso desde su fuente autorizada. Las pruebas de los transportes interceptan HTTP; no acreditan calidad, disponibilidad ni costes de una respuesta real del proveedor.

Validación: **691 pruebas PHP/7.845 aserciones** en la regresión completa y **59 pruebas dirigidas/3.084 aserciones** sobre el ajuste final del decodificador compartido; esos 59 casos se solapan con la regresión y no se suman como casos únicos. La [evidencia](docs/evidence/release-1.10.8/ai-privacy108.json) diferencia ambas instantáneas. Pasan **49 controles portables** y **dos recorridos de navegador** sobre historial y fuente reales con datos sintéticos. Se comprueban los tres transportes interceptados, referencias públicas conservadas, reserva explícita, borradores ajenos rechazados y originales intactos. ZIP 1.10.8 de **196 archivos** verificado, esquema 15; datos y entornos temporales retirados. No se recoge cobertura y los cuatro reportes anteriores permanecen intactos.

## Bandeja de Consultas de Contacto del 17 de octubre de 2026

Administración presenta las consultas en una bandeja compacta, con filtros Todas, Pendiente, En revisión, Resuelta y Cerrada. Cada resumen muestra asunto, fecha, asociado, responsable y estado; las consultas ajenas muestran Consulta privada. Al abrir una consulta aparecen mensaje, historial y respuesta. Solo una queda desplegada a la vez y cerrar su resumen conserva el borrador mientras no se recargue la bandeja. Tras guardar la atención, la consulta permanece abierta.

Cambiar responsable queda en un apartado desplegable; archivar mantiene la confirmación y el historial. La búsqueda conserva el filtro de estado y se mantiene limitada a consultas asignadas. Se respetan el orden de estados, la respuesta obligatoria para resolver y la privacidad del servidor. El diseño se adapta a móvil y a ambos temas, con etiquetas en español e inglés.

Validación selectiva: **cinco pruebas PHP/38 aserciones**, **49 controles portables** y **siete controles de navegador** aprobados. Cuatro vistas de escritorio/móvil, con consultas cerradas o desplegadas y ambos temas, no presentan desbordamiento ni infracciones automáticas WCAG A/AA. La [evidencia](docs/evidence/release-1.10.8/contact-inbox108.json) registra el ajuste final del filtro activo en modo oscuro y el origen de las pruebas. Los catálogos incluyen **1.836 mensajes por idioma**. ZIP 1.10.8 de 196 archivos verificado; esquema 15. Se retiran los datos y entornos temporales; los cuatro reportes anteriores de cobertura permanecen intactos. No se repite ni se atribuye a esta etapa la regresión completa anterior.

## Textos claros y recuperación de fuentes del 17 de octubre de 2026

**Revisar contenido** separa Publicaciones, Comentarios y Reportes recibidos, con sus contadores y una explicación de las acciones. Las publicaciones por aprobar no se mezclan con los borradores guardados; los reportes atendidos quedan en un apartado desplegable. La bandeja usa filas amplias en escritorio y bloques adaptados en móvil, con título, autor, estado y acción. Su contenedor propio evita el máximo de 520 px de la clase nativa de WordPress; se verifica en `wp-admin` y en la página independiente. Los estados vacíos mantienen el ancho y los borradores permanecen plegados al entrar. [Validación de ancho y decisiones](docs/evidence/release-1.11.0/moderation-layout1110.json). Se conservan los permisos, motivos de decisión y la condición de revisar un reporte antes de eliminarlo. El panel diferencia Consultas de Contacto, Encuentros e Historial de cambios. Desde el ajuste del 22 de octubre, Tareas de IA deja de aparecer en sus secciones y en los contadores del Resumen.

Crear un evento distingue su publicación de la modalidad del encuentro. Se aclaran descripción, lugar, enlace para asistir, máximo de asistentes, espera para aceptar un cupo y avisos previos con un ejemplo. Se mantienen los selectores separados de fecha/hora, los estados y el guardado de borradores incompletos. En Perfil se retiran las dos ayudas de ubicación solicitadas y el encabezado Ubicación; solo País/región lleva el indicador de ese grupo. La validación existente de ubicaciones se conserva. Teléfono muestra un aviso con escudo: **Dato privado. Solo yo y administradores.**, con el estilo de Fecha de nacimiento.

El Asistente ya utiliza **RAG léxico**: busca y puntúa fuentes internas, filtra publicación, revisión y acceso, selecciona fragmentos y los incorpora al contexto antes de llamar al modelo. La salida conserva solo referencias que pertenecen a esas fuentes. Las preguntas de seguimiento ahora recuperan también el tema anterior, incluso al encadenar varias repreguntas; un hilo nuevo no hereda ese contexto. Las pruebas recorren los transportes de Gemini, OpenAI y DeepSeek con HTTP interceptado y comprueban que no se envían borradores ajenos, material generado sin revisar ni identidades reservadas. La comprobación de referencias no equivale a verificar semánticamente cada frase generada; la evaluación del proveedor real y del sitio publicado mantiene su alcance externo.

Validación local: **688 pruebas PHP/7.749 aserciones**, que incluyen **seis pruebas RAG/117 aserciones**, **49 controles portables** y **ocho controles de navegador** aprobados. Cuatro vistas de escritorio/móvil en temas claro/oscuro no presentan desbordamiento ni infracciones automáticas WCAG A/AA. La [evidencia](docs/evidence/release-1.10.8/usability-rag108.json) distingue la regresión PHP del recorrido final tras completar traducciones; los archivos PHP y sus pruebas coinciden con los del paquete final. Se comprueban permisos de asociado/moderador/administrador, guardado del perfil, borrador de evento e idioma inglés. Los catálogos incluyen **1.826 mensajes por idioma**. Se verifican los **196 archivos** del ZIP 1.10.8 y se retiran los datos y entornos temporales. Los cuatro reportes anteriores de cobertura permanecen intactos; no se recoge cobertura ni se atribuye aceptación remota a esta etapa.

## Directorio y teléfono del perfil del 17 de octubre de 2026

Las tarjetas del Directorio comparten las filas de información, aviso y acciones para alinear **Ver perfil** aunque una cuenta tenga restricciones adicionales. Durante la espera tras eliminar una conexión se muestra **Disponible a partir del** y la fecha, sin ofrecer Conectar ni repetir un aviso largo bajo los botones. Las solicitudes enviadas muestran **Solicitud enviada** y **Cancelar** en una misma fila, sin insignia Pendiente ni menú de tres puntos; se conserva la confirmación antes de cancelar. Las actualizaciones de relación refrescan también el aviso de la tarjeta.

El teléfono del perfil y el editor administrativo incorporan un selector nativo de país con bandera y prefijo. Reconoce números pegados con + o 00 y distingue códigos compartidos mediante sus códigos de área. Cambiar de país conserva la parte nacional; vaciar el campo conserva su carácter opcional. El selector respeta los campos de solo lectura; en el editor administrativo el teléfono se modifica directamente, con Contacto opcional según la decisión vigente. No modifica el país de residencia ni la validación o privacidad del servidor. Prefijos y banderas de [intl-tel-input 29.5.3](https://github.com/jackocnr/intl-tel-input/releases/tag/v29.5.3) se incluyen localmente con su [licencia MIT](wp-content/plugins/ascla-core/assets/phone/LICENSE.txt), y se cargan al abrir el formulario. Si falla la mejora visual, permanece el campo internacional original.

Validación de esta etapa: **59 pruebas PHP/432 aserciones**, **49 controles portables** y **ocho controles de navegador** aprobados. Se revisan ocho vistas de escritorio/móvil en ambos temas, sin desbordamiento ni infracciones automáticas WCAG A/AA. La [evidencia](docs/evidence/release-1.10.8/directory-phone108.json) conserva método, alcance y huellas de los reportes y capturas. Los cuatro reportes previos de cobertura permanecen intactos; no se repite la suite completa ni se recoge cobertura. El [ZIP verificado](docs/evidence/release-1.10.8/package.json) contiene **196 archivos** coincidentes con la instantánea probada. Se retiran el entorno y las cuentas temporales; la instalación remota queda a cargo del responsable.

## Optimización general de carga del 17 de octubre de 2026

El arranque común de los 13 módulos se incluye en el HTML privado del usuario, con la misma construcción y autorización del endpoint REST. Se reutiliza su perfil al dibujar la cabecera y al abrir inicialmente Perfil. Si el HTML no contiene el arranque, se conserva la consulta REST de respaldo. No se guardan respuestas personales en almacenamiento del navegador ni en una caché compartida.

Las consultas GET idénticas que coinciden durante una misma vista comparten una petición en curso; al terminar, fallar, escribir o cambiar de vista dejan de reutilizarse. La navegación se habilita antes de esperar al módulo y cancela las consultas de la vista anterior. La consulta de notificaciones no retrasa la apertura de detalles. Recursos/autores y temas/foros se consultan en paralelo.

Publicaciones y comentarios reutilizan el resumen de reacciones para sus contadores, eliminando dos consultas por elemento. Las recomendaciones conservan el ranking completo y solo serializan la página que se devuelve. Administración carga directamente las bandejas independientes y limita los datos de Resumen, Moderación, Trabajos y Auditoría a lo que necesita la sección; la consulta administrativa completa sigue disponible con sus permisos anteriores.

La [evidencia de carga](docs/evidence/release-1.10.8/load108.json) compara tres navegaciones completas por módulo antes y después sobre el mismo WordPress local y datos sintéticos: **78 navegaciones**, sin errores HTTP ni de JavaScript. El promedio de las medianas de los 13 módulos pasa de **452,2 a 351,7 ms (22,2 % menos)** hasta que el contenido principal queda disponible. Foros pasa de 495 a 295 ms; Centro de Conocimiento, de 510 a 325 ms. La consulta REST inicial de arranque pasa de 39 a cero en las 39 navegaciones posteriores. El tiempo se registra dentro del navegador, sin incluir la demora del programa que lo controla. No mide la descarga de todas las imágenes ni respuestas de proveedores externos; trasladar el arranque al HTML puede aumentar el tiempo de su primera respuesta aunque el módulo termine antes. Es una muestra local pequeña, no una garantía del servidor publicado ni una prueba de 50 usuarios simultáneos.

Sobre el código empaquetado pasan **682 pruebas PHP/7.625 aserciones**, **49 controles portables**, **siete controles de carga y navegación** y los **nueve de perfil/Directorio**; seis vistas no presentan desbordamiento ni infracciones automáticas WCAG A/AA. No se recoge cobertura y los cuatro reportes anteriores conservan sus huellas. En esa etapa se verifica un ZIP de **190 archivos**, coincidente con la instantánea probada; el manifiesto 1.10.9 registra la entrega actual, posterior a esa instantánea. Los entornos temporales de prueba se retiran. La instalación remota queda a cargo del responsable; no cambia el estado pendiente de RNF-008.

## Perfil y gestión desde Directorio del 17 de octubre de 2026

Se integra por avance directo el último cambio de `development` (`877568c`), conservando la navegación administrativa y la entrega publicada de pruebas. El guardado del perfil comprueba que existan las casillas de participación antes de leerlas: el administrador técnico puede guardar sus datos sin recuperar funciones sociales excluidas por el último cambio. Los campos obligatorios muestran un asterisco y se explican los mínimos de ubicación y selección de intereses, sectores y objetivos.

El tipo de asociado lo establece administración mediante un selector con **Individual** y **Empresa asociada**. El perfil propio lo muestra como dato de lectura y el servidor rechaza su modificación por un asociado. Los valores antiguos ajenos a esas dos opciones se conservan en el registro hasta que administración asigne un tipo válido; no se reclasifican automáticamente. Por decisión expresa del responsable, esta entrega solo clasifica la membresía: no agrupa cuentas empresariales ni controla un cupo de tres personas.

El Directorio muestra **Pendiente** para una conexión enviada y conserva su cancelación en el menú de opciones. Administración puede abrir **Actividad e intereses** y **Gestionar usuario** desde cada ficha. Se reutilizan los permisos y los flujos existentes de edición, suspensión y confirmación de eliminación; el acceso administrativo no habilita networking ni expone esas acciones al asociado. Continúan las restricciones de privacidad y membresía, y la autorización de Contacto para cambiar datos profesionales ajenos.

Validación local: **682 pruebas PHP/7.625 aserciones**, **49 controles portables** y **nueve controles de navegador** aprobados. Se revisan seis vistas de escritorio/móvil en ambos temas, sin desbordamiento ni infracciones automáticas WCAG A/AA; se corrige el ajuste de correos largos en el perfil móvil. Las preparaciones antiguas de grupos, mensajes y afinidad usan asociados separados del administrador, sin retirar comprobaciones. La demo deja pendiente la clasificación administrativa y conserva los tipos ya asignados. Las pruebas no recogen cobertura y los cuatro reportes previos permanecen intactos. El [resumen verificable](docs/evidence/release-1.10.8/profile-directory108.json) distingue las instantáneas PHP e interfaz y conserva huellas de reportes, capturas y limpieza. En esa etapa se verificó un ZIP de 189 archivos. El [manifiesto provisional](docs/evidence/release-1.10.8/package.json) conserva el último paquete anterior a la renumeración 1.10.9; la instalación y comprobación en QA remoto siguen a cargo del responsable.

## Ajuste de administración del 16 de octubre de 2026

Por solicitud del responsable se elimina el logo duplicado en escritorio y se coloca la navegación administrativa sobre el contenido. El inicio del administrador pasa a Administración ASCLA. La cuenta mantiene sus capacidades de gestión, pero no participa en directorio, afinidad, solicitudes de conexión/conversación ni microeventos; la restricción se aplica en el backend y cubre relaciones antiguas. Asociado y Moderador conservan su networking; Ejecutivo comparte la exclusión administrativa desde el ajuste del 22 de octubre.

Las [pruebas del rol](tests/php/AdministratorRoleTest.php) y de [interfaz](tests/admin-navigation.cjs) corresponden a WordPress local aislado. Este ajuste requiere instalar el ZIP actualizado para aparecer en el sitio publicado; las evidencias anteriores mantienen su fecha y alcance.

La etapa del 16 de octubre aprobó 81 peticiones y 149 comprobaciones de Bruno, más seis recorridos de Playwright con 12 videos y 12 capturas en WordPress local aislado y con asistente mock. Incluyó el inicio administrativo, el logo único y el rechazo del networking administrativo. Es un antecedente histórico: la entrega actual de `pruebas/` conserva la nueva ejecución remota del 22 de octubre, descrita al principio de este documento. Las regresiones adicionales mantienen su propio alcance y no aumentan los CP únicos ni acreditan cobertura o UAT.

<a id="estado"></a>

## Estado de los requisitos

| Estado | Cantidad | Interpretación |
|---|---:|---|
| Código revisado | 263 | Implementación y evidencia registradas, con el alcance de cada etapa. |
| Ajustado por decisión del usuario | 5 | Excepciones autorizadas; no son cumplimiento literal del PDF. |
| Parcial | 7 | Hay implementación y brechas por cerrar. |
| Validación pendiente | 2 | Falta revisión transversal. |
| Externo | 8 | Requiere evidencia del entorno o de proveedores. |
| Fuera de alcance | 17 | Exclusiones conservadas del alcance acordado. |

Inventario: **302 requisitos**, incluidos 62 RN, 213 RF y 27 RNF, con 240 prioridades RF/RNF. RF-214 se menciona pero no está definido; RF-93 se normaliza a RF-093. Los estados no equivalen a aceptación integral, cobertura de pruebas ni una estimación de horas restantes.

Sobre el PDF íntegro, 263/302 (**87,1 %**) están marcados Código revisado. Excluyendo los 17 fuera de alcance e incluyendo las cinco decisiones autorizadas, el avance documental es 268/285 (**94,0 %**); quedan 17 (**6,0 %**). Estos porcentajes no aumentan por repetir pruebas o corregir una parte de un requisito transversal.

<a id="decisiones"></a>

## Decisiones vigentes

- Cinco fallos de cuenta bloquean por 15 minutos de forma predeterminada. El límite separado por IP es 20; su alineación institucional y Wordfence/Turnstile reales siguen pendientes.
- Todas las sesiones exigen autenticarse tras 30 minutos de inactividad, incluida Recordarme. La cookie conserva su duración absoluta configurable. Excepción expresa a RN-058; prevalecen RN-051/RNF-005.
- Todo guardado propio de perfil exige los campos mínimos. Posponer el aviso permite navegar, pero no guardar un perfil incompleto.
- El Directorio muestra perfiles incompletos activos que participan, respetando privacidad y bloqueos. Excepciones a RN-031 y RF-131. Las recomendaciones y los candidatos de microeventos mantienen sus mínimos.
- Ejecutivo y Administrador pueden crear y revisar propuestas de microeventos; se requiere revisión antes de publicarlas.
- Administración puede editar directamente los datos del asociado. Contacto queda como referencia opcional de uso único; excepción autorizada a RN-001 y RN-032, con auditoría y preferencias personales conservadas.
- La única reacción es **Me gusta**. Las reacciones heredadas retiradas se contabilizan de manera compatible y pueden quitarse.
- La promoción Git conserva el orden `development → qa → uat → main`. Las pruebas pueden ejecutarse manualmente y el [Jenkinsfile](Jenkinsfile) aplica Trivy antes del despliegue y pruebas, SonarQube/Quality Gate y ZAP/CSP en QA/UAT. Su archivado, retención y limpieza están implementados; la [guía de CI](ci/README.md) distingue su validación local de la configuración y aprobación del entorno de destino. La instalación manual de plugins queda a cargo de su responsable.
- El aprendizaje de intereses es automático y respeta las desmarcaciones guardadas del usuario.
- Administración asigna el tipo Individual o Empresa asociada; por ahora esta clasificación no administra grupos ni cupos empresariales.

<a id="casos-qa"></a>

## Casos QA corregidos

Esta tabla reúne los casos reportados; no afirma haber ejecutado todos los números intermedios ni todos los escenarios del PDF.

| Caso | Comportamiento implementado |
|---|---|
| 004 | El bloqueo de cuenta impide entrar incluso con una contraseña correcta durante el plazo activo. |
| 008 / 147 | Cambios administrativos de Cargo/Empresa directos; vinculación opcional a una solicitud válida de Contacto, según la decisión vigente. |
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
| Integración y E2E desde UAT, 15 de octubre | Bruno: 10 peticiones/15 comprobaciones de integración y 23/38 del backend E2E; Playwright: tres recorridos, cinco videos y cinco capturas aprobados. Rama `uat`, base `c525b99`, ajustes del ejecutor sin commit y fuentes capturadas. WordPress local desechable, limpieza confirmada; sin Jenkins, nueva cobertura ni despliegue público. Conserva el intento inicial con un recorrido fallido y su evidencia. | [Resumen](docs/evidence/release-1.10.8/uat-acceptance108.json), [guía para exponer](MANUAL_QA_UAT.md#exponer-pruebas) |

El [manifiesto actual](docs/evidence/release-1.11.0/package.json) identifica el ZIP 1.11.0, esquema 15. Los manifiestos [1.10.9](docs/evidence/release-1.10.9/package.json) y [1.10.8](docs/evidence/release-1.10.8/package.json) conservan sus paquetes históricos. Las ejecuciones HTTP/UI, SonarQube y ZAP acreditan únicamente las revisiones identificadas en cada resumen; no certifican un ZIP posterior ni su instalación remota. Los resultados de QA/UAT y la corrección posterior de imágenes se distinguen al inicio de este documento. Esta revisión documental no genera cobertura ni cambia las huellas o la revisión de los reportes existentes.

La consolidación documental no cambia el código del plugin, su ZIP, los resultados anteriores ni los estados de requisitos. La [verificación documental](scripts/check-docs.py) comprueba inventario, enlaces, Git y sincronización de la matriz.

## Transcripciones públicas con Supadata · 1.11.0

Supadata sustituye la descarga de subtítulos mediante OAuth. La clave es cifrada y editable por configuración autorizada de WordPress salvo política externa explícita; no sale por REST ni se incluye en URLs. El proveedor solo recibe el enlace público. Se conservan las restricciones editoriales de la fuente, el acceso a recursos y Google Calendar. La cola persiste el identificador remoto, respeta límites y conserva el original completo o falla sin guardar fragmentos. [Configuración, costes, límites y verificación](MANUAL_QA_UAT.md#subtítulos-públicos-de-youtube-con-supadata).
