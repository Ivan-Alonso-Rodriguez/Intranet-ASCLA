# Historial de versiones — Intranet ASCLA

Este documento registra la evolución funcional del proyecto **Intranet ASCLA / ASCLA Core**. Su objetivo es dejar evidencia clara del progreso realizado entre entregas y facilitar la revisión del repositorio en GitHub.

> **Versión actual:** `1.9.9`  
> **Esquema de base de datos:** `5`  
> Esta actualización del historial es únicamente documental y **no modifica la versión del plugin**.

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
| 1.9.9 | Asistente ASCLA conversacional con contexto vivo | **Actual** |

---

# Serie 1.9.x — evolución funcional

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
1.9.0 → 1.9.1 → 1.9.2 → ... → 1.9.9
```

Las modificaciones exclusivamente documentales, como la ampliación de este archivo, **no generan por sí solas una nueva versión del plugin**. Por ello, después de actualizar este historial la versión actual continúa siendo **1.9.9**.

# Notas de trazabilidad

- `VERSION_HISTORY.md` documenta la evolución funcional y no sustituye los commits/tags del repositorio.
- Cuando existe un snapshot verificable se conserva como referencia histórica.
- No se crean tags ficticios para versiones cuyo código fuente original no esté disponible.
- La carpeta `docs/` se mantiene fuera del repositorio público según la configuración actual de `.gitignore`.
- El estado funcional vigente del código fuente corresponde a **ASCLA Core 1.9.9**.
