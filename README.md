# ASCLA Core — Intranet ASCLA

## Versión actual: 1.11.0 · esquema 15

Plugin WordPress para una comunidad profesional privada: perfiles, Directorio, conexiones, mensajería, Hub, foros, eventos, microeventos, Centro de Conocimiento, Galería, Aliados, Contacto y administración. Elementor es opcional; no se modifica WordPress Core.

El código instalable está en [wp-content/plugins/ascla-core](wp-content/plugins/ascla-core). La carpeta del plugin siempre es `ascla-core`; el número de versión no forma parte de su nombre. Sus datos se conservan al desactivarlo o desinstalarlo.

La versión 1.11.0 mejora la inscripción con Google Calendar, los comentarios sin publicar, la mensajería y la navegación entre ventanas. El [paquete](docs/evidence/release-1.11.0/package.json) y la [validación local](docs/evidence/release-1.11.0/usability1110.json) tienen su propia identificación. Los reportes existentes de `pruebas/` conservan la versión, fecha y entorno de su ejecución; no se presentan como resultados nuevos de 1.11.0.

La nueva revisión añade reactivación de eventos cancelados, conserva inscripciones y espera, reprograma recordatorios y calendario y renueva la bandeja de notificaciones con tonos neutros. [Evidencia de reactivación y notificaciones](docs/evidence/release-1.11.0/event-reactivation1110.json).

## Documentación

La recuperación de contraseña mantiene el formulario y una confirmación genérica para correos registrados y no registrados, también al recargar y en la confirmación nativa de WordPress. El Ejecutivo muestra su rol y «Panel Ejecutivo» sin cambiar sus permisos. El PDF incorpora la distribución oficial de compatibilidad de PDF.js 6.4.299, servida como módulos JS para evitar problemas de MIME, respaldo cuando los workers están bloqueados, reintento y errores diferenciados. [Evidencia de recuperación, identidad y PDF](docs/evidence/release-1.11.0/recovery-role-pdf1110.json).

La duración de los recursos con subtítulos se calcula con el mayor tiempo final válido de la transcripción, redondeado al segundo superior. Esa referencia puede terminar antes que el video si hay un cierre sin subtítulos; si no hay tiempos válidos, se conservan las alternativas de metadatos o duración manual. La IA recibe todo el texto hablado sin marcas de tiempo, números de secuencia ni metadatos de subtítulos; el original completo se conserva para edición y enlaces temporales. [Evidencia de duración y contexto](docs/evidence/release-1.11.0/transcript-context1110.json).

La optimización retira traducciones antiguas sin referencias y funciones de interfaz ya retiradas. Los catálogos compilados omiten las traducciones idénticas al original mediante el comportamiento estándar de gettext, conservando los archivos PO editables y los valores personalizados. Las entradas dinámicas o dudosas se conservan. [Auditoría y validación](docs/evidence/release-1.11.0/code-optimization1110.json).

El resumen generado desarrolla las ideas en varios párrafos, con una extensión orientativa de 220 a 350 palabras cuando la fuente lo permite. «Actualizar resumen y nota» permite regenerar el material sin volver a obtener una transcripción ya guardada; conserva la versión anterior hasta completar el proceso y notifica los errores. La ficha oculta el desplegable de la transcripción y muestra los marcos de trabajo en etiquetas a todo el ancho. [Evidencia de regeneración](docs/evidence/release-1.11.0/summary-refresh1110.json).

Los PDF se abren automáticamente con controles de lectura refinados y sin el panel visible «Texto de la página». Galería oculta el nombre del archivo y amplía las zonas laterales para cambiar de foto. Al publicar un video con Supadata configurado, los subtítulos y el resumen se preparan en segundo plano, sin botones manuales; los fallos generan una notificación. [Evidencia de visores y automatización](docs/evidence/release-1.11.0/readers-auto1110.json).

El resumen de los recursos presenta una estructura editorial con tipografía legible, párrafos separados, conclusiones numeradas, referencias en secciones completas y conceptos en etiquetas. La vista se adapta al tema y al ancho de pantalla; reutiliza los textos guardados sin volver a generarlos. [Evidencia de presentación](docs/evidence/release-1.11.0/summary1110.json).

La obtención de subtítulos públicos incorpora Supadata sin Google OAuth, con prioridad a subtítulos existentes, generación opcional, caché y seguimiento de trabajos largos. El original completo se conserva y puede consultarse en la publicación o utilizarse para generar resumen y nota. [Configuración y límites de Supadata](MANUAL_QA_UAT.md#subtítulos-públicos-de-youtube-con-supadata).

La previsualización de PDF se abre dentro de la publicación, con páginas, zoom, texto para tecnologías de asistencia y descarga. El lector PDF.js 6.4.299 se carga desde archivos locales fijados y con licencia Apache-2.0; no se utilizan visores externos. Se conservan las cabeceras de seguridad y el acceso privado a los archivos. [Evidencia del lector PDF](docs/evidence/release-1.11.0/pdf-preview1110.json).

La revisión de perfiles y Galería compacta los intereses del perfil público, muestra el usuario de acceso solo en Mi perfil, mejora las barras de desplazamiento y cambia la acción de las tarjetas a «Abrir». El editor incorpora búsqueda paginada de eventos y publicación seleccionada por defecto para una galería nueva con permisos. El visor coloca las flechas junto a la fotografía; los adjuntos muestran sus nombres alineados. Eliminar una publicación de Galería retira también sus archivos privados y sus copias maestras sin uso. Notificaciones elimina el contador repetido de la cabecera y usa «notificaciones» en el pie. [Evidencia de esta etapa](docs/evidence/release-1.11.0/gallery-profile1110.json).

Se restaura la portada predeterminada anterior para los recursos sin foto o con una miniatura no disponible. Conserva las tarjetas ampliadas del Centro de Conocimiento. La [evidencia de portadas](docs/evidence/release-1.11.0/knowledge-cover1110.json) identifica el ZIP de esa etapa; las etapas anteriores mantienen su propio manifiesto.

La [revisión del visor y Centro de Conocimiento](docs/evidence/release-1.11.0/media-knowledge1110.json) amplía las fotografías dentro de Galería, añade navegación por miniaturas y conserva el acceso al original y los documentos. Centro de Conocimiento presenta tarjetas más amplias, tipos y duraciones claros y portadas de respaldo. Cada revisión conserva su propio manifiesto; el manifiesto vigente identifica el ZIP más reciente.

La revisión visual adicional de 1.11.0 incorpora tarjetas del inicio navegables, estados y Chatham House neutros, acciones uniformes en Directorio y tarjetas de eventos con fecha destacada. Su [validación visual local](docs/evidence/release-1.11.0/visual1110.json) identifica el ZIP actualizado; la validación anterior conserva su propio SHA.

La [revisión de Galería y calendario](docs/evidence/release-1.11.0/gallery-calendar1110.json) añade portadas más amplias, títulos y acciones claros, borradores neutros y estados de inscripción legibles en modo oscuro. Los inscritos sin Google vinculado conservan el enlace manual de calendario aunque haya una invitación pendiente. El [manifiesto vigente](docs/evidence/release-1.11.0/package.json) identifica el ZIP más reciente; cada evidencia anterior conserva su propio paquete.

Los cinco documentos principales tienen un propósito propio:

| Documento | Contenido |
|---|---|
| [README](README.md) | Entrada al proyecto, instalación y estructura. |
| [Manual QA/UAT](MANUAL_QA_UAT.md) | Guía paso a paso de PHPUnit, SonarQube, Bruno, Playwright, instalación y aceptación UAT, con resultados y solución de errores. |
| [Cumplimiento](COMPLIANCE.md) | Estado vigente, reglas, correcciones QA, evidencia y pendientes. |
| [Historial](VERSION_HISTORY.md) | Evolución por versión; las cifras antiguas conservan su fecha y alcance. |
| [Matriz](requirements/ascla-analysis.md) | Los 302 requisitos originales, prioridades y trazabilidad. Su fuente editable es el [JSON](requirements/ascla-analysis.json). |

La [entrega de pruebas para GitHub](pruebas/README.md) reúne Bruno, Playwright y el [dashboard de seguridad](pruebas/seguridad/dashboard.html), con fechas, alcance, procedencia y copias sin datos de sesión. El **22 de octubre** se repiten **81 peticiones/149 comprobaciones de Bruno y seis recorridos Playwright** contra el QA publicado. La [regresión reciente](pruebas/actualizacion/index.html) añade 14 controles de interfaz con videos y seguridad autenticada de cuatro roles. Se muestra el hallazgo de cabeceras del hosting y se distingue del ZAP activo aislado. Las evidencias anteriores conservan su fecha y alcance.

## Preparar un entorno local

Desde la raíz del repositorio en WSL, con Docker Desktop funcionando, sigue el [paso a paso local](MANUAL_QA_UAT.md#local): configuración privada, publicación de puertos de loopback e inicialización. El [Compose base](compose.yaml) no publica puertos al navegador; la guía muestra cómo añadirlos para desarrollo. `.env`, credenciales, cargas, resultados y dependencias locales permanecen fuera de Git.

## Crear e instalar el plugin

Desde la raíz del repositorio:

```bash
python3 scripts/build.py
python3 scripts/verify-package.py
```

Se generan `dist/ascla-core.zip` y `dist/ascla-core.sha256`, ignorados por Git. El [resumen público del paquete](docs/evidence/release-1.10.9/package.json) registra versión, tamaño, SHA-256 y coincidencia con las fuentes. En WordPress: Plugins → Añadir plugin → Subir plugin → seleccionar el ZIP → reemplazar la versión instalada. El responsable del entorno realiza esta instalación; la promoción de Git y las pruebas no despliegan el plugin.

## Calidad y entrega

La preparación de QA para SonarQube incorpora la instalación verificada de PCOV, compatibilidad del acceso nativo de WordPress con la CSP estricta y ajustes de accesibilidad detectados por las suites. Los tests comprueban el inicio administrativo o comunitario según el rol y usan cuentas comunitarias para Mensajería y Contacto. La [entrega de pruebas del 22 de octubre](pruebas/index.html) conserva las revisiones y huellas que se ejecutaron entonces; esos reportes no acreditan automáticamente este ajuste posterior ni un nuevo despliegue del hosting.

El flujo vigente es manual: `development → qa → uat → main`. El [Jenkinsfile](Jenkinsfile) conserva pruebas, SonarQube, Quality Gate y despliegue. La integración solicitada de ZAP y CSP después del despliegue en `qa`, con archivado, retención de un build y limpieza final, sigue pendiente; las ejecuciones locales publicadas no la acreditan. UAT requiere revisión de testers y autorización del PM. `main` debe estar protegida y recibir los cambios mediante pull request. No se exige cobertura del 100 %.

El [manual](MANUAL_QA_UAT.md) indica el orden de ejecución y los resultados esperados. Para una nueva validación completa de QA, `quality-ci.sh` genera los resultados y la cobertura de esa revisión antes de enviarla a [SonarQube](MANUAL_QA_UAT.md#sonarqube). La revisión documental de esta entrega conserva la cobertura existente: no se ejecuta ese generador ni se atribuyen sus reportes a un commit nuevo. El scanner no despliega ni prueba el WordPress publicado.

La [colección Bruno vigente](pruebas/backend/coleccion-bruno) y [Playwright](tests/acceptance-ui.cjs) ejecutan los mismos **seis flujos**: perfil, conexión/chat/bloqueo, borrador/publicación/inscripción, aforo/espera/notificación, suspensión/reactivación y asistente IA. Bruno añade integración técnica: **81 peticiones y 149 comprobaciones** en total. Playwright conserva videos, capturas y resultados por flujo. El [catálogo comparado](pruebas/flujos.json) y el verificador comprueban su coincidencia. El [manual](MANUAL_QA_UAT.md#pruebas-http-e2e) explica esta entrega y la aceptación complementaria con cuentas temporales.

La [matriz de cumplimiento](COMPLIANCE.md#estado) distingue código revisado, decisiones del usuario y validaciones pendientes. La aceptación remota existente corresponde al paquete identificado en su propia evidencia; no acredita automáticamente los ZIP posteriores.

Los resultados de la [corrección de borradores, foros y galerías](MANUAL_QA_UAT.md#regresion-borradores) registran 76 pruebas PHP dirigidas, 859 aserciones, diez controles de navegador y 49 controles portables, sobre el ZIP identificado en su resumen. No reemplazan ni actualizan las ejecuciones históricas publicadas.

Las pruebas de seguridad se ejecutan sin Jenkins con `bash scripts/security-tests.sh` en Bash/WSL y Docker Desktop con motor Linux. Preparan WordPress desechable con HTTPS, ejecutan ZAP sobre las páginas ASCLA y 18 operaciones GET de la API, y verifican CSP por separado. El [manual de ZAP](MANUAL_QA_UAT.md#zap) explica alcance, reportes, límites y configuración del servidor. No generan cobertura ni atacan el sitio publicado.

La [guía para sustentar seguridad](pruebas/seguridad/sustento.html) compara **20/20 en el entorno aislado y 19/20 en QA** con evidencia por control. El hosting sigue publicando la versión de Apache; su operador debe aplicar la configuración de servidor ya incluida y repetir la verificación. El acceso WordPress disponible no permite cambiar esa configuración global.

La entrega que se versiona incluye también las [pruebas de seguridad locales](pruebas/seguridad/fuentes.html): ocho fuentes de pruebas/configuración que coinciden con el [manifiesto de la ejecución](pruebas/seguridad/source-manifest.json), además de los reportes ZAP, CSP y navegador. Los ejecutores y fixtures completos viajan en `scripts/`, `tests/`, `security/` y los archivos Compose; las sesiones, contraseñas y reportes nativos con datos HTTP privados siguen excluidos.

## Política documental y evidencias

Los cinco MD principales, los tres MD de la entrega y el JSON de requisitos deben viajar con el repositorio. Usa rutas relativas y dominios del proyecto; no incluyas carpetas personales, rutas absolutas de equipos, nombres de dispositivo ni IP privadas. Los ejemplos de loopback son genéricos. `docs/evidence/` contiene únicamente los resúmenes JSON y JUnit revisados y permitidos expresamente por `.gitignore`. Los originales completos y accesos temporales permanecen en `test-results/`, fuera de Git. Por solicitud del responsable, `pruebas/` conserva un lote publicado de la colección Bruno y de los seis flujos Playwright, además de las copias revisadas de la última validación de seguridad disponible. Los reportes, videos, capturas y ZIP de Bruno registran procedencia y huellas. Los resúmenes de regresiones adicionales se identifican con su propia fecha y paquete en `docs/evidence/`.

Para preparar un commit en `development`, revisa los cambios con `git status --short` y `git diff`, comprueba el paquete y ejecuta las verificaciones de documentación y de la entrega. Los accesos privados, dependencias, cobertura y ZIP con contraseñas permanecen ignorados. El desarrollo se confirma primero en `development` y después se promueve a `qa` para las validaciones y SonarQube.

Para verificar documentos, referencias personales, enlaces, anclas, exclusiones de Git y sincronización de la matriz:

```bash
python3 scripts/check-docs.py
```
