# Historial de versiones — Intranet ASCLA

Este documento registra la evolución funcional del proyecto **Intranet ASCLA / ASCLA Core**. Su objetivo es dejar evidencia clara del progreso realizado entre entregas y facilitar la revisión del repositorio en GitHub.

> **Versión actual:** `1.9.24`  
> **Esquema de base de datos:** `7`  
> La versión `1.9.24` repara referencias huérfanas de microeventos eliminados, permite regenerar propuestas mensuales y mejora la ubicación visual del estado de conexión en el Directorio, manteniendo el esquema de datos 7.

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
| 1.9.24 | Reparación de microeventos y estado visual de conexiones | **Actual** |

---

# Serie 1.9.x — evolución funcional


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
1.9.0 → 1.9.1 → 1.9.2 → ... → 1.9.24
```

Las modificaciones exclusivamente documentales, como la ampliación de este archivo, **no generan por sí solas una nueva versión del plugin**. La versión `1.9.24` se justifica por la reparación del ciclo de microeventos eliminados y el ajuste visual de conexiones; el esquema de datos permanece en 7.

# Notas de trazabilidad

- `VERSION_HISTORY.md` documenta la evolución funcional y no sustituye los commits/tags del repositorio.
- Cuando existe un snapshot verificable se conserva como referencia histórica.
- No se crean tags ficticios para versiones cuyo código fuente original no esté disponible.
- La carpeta `docs/` se mantiene fuera del repositorio público según la configuración actual de `.gitignore`.
- El estado funcional vigente del código fuente corresponde a **ASCLA Core 1.9.24**.
