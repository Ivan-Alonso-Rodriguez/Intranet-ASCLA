# Guía práctica de QA, SonarQube y UAT

Versión documentada: **1.11.0**, esquema **15**. Este proyecto WordPress utiliza un flujo manual; el [Jenkinsfile](Jenkinsfile) se conserva como referencia opcional para un uso futuro. Aquí se explica cómo ejecutar las herramientas, revisar sus resultados e instalar la entrega. No se exige cobertura del 100 %; las pruebas y el Quality Gate deben satisfacer los criterios acordados para la entrega.

Todos los comandos se ejecutan **desde la raíz del repositorio**, en una terminal Bash; en Windows, usa WSL con Docker Desktop operativo e integrado con esa distribución. Las rutas de los ejemplos son relativas al repositorio. Los dominios publicados corresponden a servicios del proyecto; `localhost` y `127.0.0.1` son ejemplos genéricos de acceso local.

Esta guía distingue procedimientos disponibles de pruebas ya ejecutadas. La validación local de 1.11.0 renueva los informes PHP y JavaScript sobre el árbol de trabajo; esos informes no acreditan por sí solos una revisión Git limpia. Los reportes históricos de `pruebas/` conservan su propia versión y alcance. `quality-ci.sh` genera cobertura: úsalo cuando corresponda realizar una nueva validación completa de QA. Las comprobaciones de documentación y paquete no requieren modificar esos reportes.

## Validar los cambios de 1.11.0

Para el rediseño de Revisar contenido, prepara un sitio loopback desechable y [summary-review1110-fixture.php](tests/summary-review1110-fixture.php), y ejecuta [moderation-layout1110.cjs](tests/moderation-layout1110.cjs) con Chrome y `ASCLA_AXE_PATH`. Comprueba `/wp-admin/admin.php?page=ascla&section=moderacion` y `/administracion/?section=moderacion` a 1920, 1440 y 390 px: la cola debe ocupar el ancho del panel, sin el límite de 520 px ni desbordamiento. Valida pestañas, filas, borradores plegados, claro/oscuro, vistas vacías, historial, traducción, permisos y decisiones con motivo. El test aprueba contenido sintético y revisa un reporte; para repetirlo, [moderation-layout1110-fixture.php](tests/moderation-layout1110-fixture.php) restablece únicamente sus publicaciones, comentarios y reporte identificados por títulos y textos de prueba. [Evidencia](docs/evidence/release-1.11.0/moderation-layout1110.json); informe: `test-results/moderation-layout1110/index.html`.

Para el bloque de participación y Revisar contenido, ejecuta las regresiones PHP de estadísticas, ciclo del contenido, roles, validación y privacidad editorial. En WordPress loopback desechable prepara los accesos compartidos y [summary-review1110-fixture.php](tests/summary-review1110-fixture.php), guardando su salida en `test-results/sonar-preparation/summary-review1110-fixture.json`. Este fixture incorpora los casos de Encuentros/Estadísticas: no ejecutes también su preparación anterior en el mismo sitio. Usa [summary-review1110.cjs](tests/summary-review1110.cjs) con Chrome y `ASCLA_AXE_PATH`. Comprueba seis cifras en tres grupos, listas verificadas sin duplicar la cobertura, cero con registros pendientes, ausencia de base, filtros y acceso al registro. En Revisar contenido valida filas, borradores, ficha sin publicación automática, gris neutro en ambos temas, comentarios, reportes y guardado de decisiones con motivo. Para repetir el test, el modo CLI local `ASCLA_FIXTURE_ACTION=reset_reviews` del fixture restablece únicamente sus publicaciones/comentarios sintéticos. [Evidencia](docs/evidence/release-1.11.0/summary-review1110.json); informe: `test-results/summary-review1110/index.html`. Los gráficos existentes y las fórmulas del servidor se conservan; estas pruebas no acreditan despliegue remoto ni cobertura nueva.

Para Encuentros y Estadísticas ejecuta las regresiones PHP `StatisticsTest`, `MicroEventsTest`, `MicroGeneration108Test`, reactivación y roles. En WordPress local desechable, prepara el fixture compartido de accesos y [admin-insights1110-fixture.php](tests/admin-insights1110-fixture.php); guarda su salida solo en `test-results/sonar-preparation/admin-insights1110-fixture.json`. Ejecuta [admin-insights1110.cjs](tests/admin-insights1110.cjs) con Chrome y `ASCLA_AXE_PATH`; `ASCLA_RUN_LOCAL_QUEUE=1` permite ejecutar la cola de ese laboratorio mediante el proyecto Docker `ascla_release1110`. Verifica que la tasa de ejemplo sea 3/6 = 50 %, aunque haya nueve inscripciones totales y cinco asistencias registradas. Prueba filtros, período anterior, cero verificado, falta de base, lista pendiente, asistencia, estados, archivo/restauración, preparación sin candidatos, idiomas y permisos. Los gráficos conservan cifras legibles y tablas; no utilizan servicios gráficos externos ni inventan series temporales. Informe: `test-results/admin-insights1110/index.html`; [evidencia](docs/evidence/release-1.11.0/admin-insights1110.json). El esquema y las fórmulas del servidor permanecen en su estado previo; los informes anteriores mantienen sus paquetes y alcance.

Para comentarios ejecuta [CommentThreadsTest.php](tests/php/CommentThreadsTest.php) con las regresiones de historial, ciclo del contenido, moderación, permisos y validación. En el WordPress local desechable, usa el fixture compartido de accesos y [comment-threads1110-fixture.php](tests/comment-threads1110-fixture.php), cuya salida se guarda en `test-results/sonar-preparation/comment-threads1110-fixture.json`; ejecuta `node tests/comment-threads1110.cjs` con Chrome y `ASCLA_AXE_PATH`. Comprueba los seis tipos de contenido, cabecera Autor ▸ destinatario, formulario vacío sin arroba, orden bajo el destinatario también al reabrir, varios niveles en un solo margen, expansión por tandas, alias públicos y destinatario retirado. El prefijo antiguo solo se oculta si coincide con el destinatario disponible y queda texto; no se modifica el original. Verifica también notificaciones, pendientes propios, Me gusta, eliminación, borradores, idiomas y ambos temas. [Evidencia actual](docs/evidence/release-1.11.0/comment-replies1110.json); informe: `test-results/comment-replies1110/index.html`. La [primera entrega](docs/evidence/release-1.11.0/comment-threads1110.json) mantiene su evidencia histórica. Se conservan padres originales y el límite existente de cien entradas REST. La regresión `tests/comment-count108.cjs` admite `ASCLA_BROWSER_CHANNEL=chrome`; espera la actualización asíncrona del contador y despliega las tandas para comprobar las cien entradas. Estas pruebas no acreditan una validación remota ni cobertura nueva.

Para recuperación, identidad del Ejecutivo y compatibilidad PDF ejecuta [RecoveryRolePdfTest.php](tests/php/RecoveryRolePdfTest.php) junto a las regresiones de recuperación, login, roles, medios, seguridad y generación. En el sitio local desechable prepara [el fixture](tests/recovery-role-pdf1110-fixture.php) y guarda su salida únicamente en `test-results/sonar-preparation/recovery-role-pdf1110-users.json`; usa también el fixture compartido de accesos. Ejecuta `node tests/recovery-role-pdf1110.cjs` con Playwright, Chrome, Edge, `ASCLA_AXE_PATH` y `ASCLA_PYTHON` (Python con pypdf para el PDF cifrado de prueba). Comprueba confirmación genérica, formulario conservado, recarga sin reenvío, idiomas, rol Ejecutivo sin cambiar privilegios, PDF normal, compatibilidad, workers bloqueados, permisos, sesión caducada, red, MIME, contraseña, corrupción, tamaño y timeout. La falta de funciones y los fallos se simulan: no equivalen a probar una versión antigua instalada ni Safari. El original requiere autenticación y conserva sus bytes; el PDF no sale del sitio. La cuenta afectada se describió como Chrome en un portátil Windows, sin versión confirmada. [Evidencia](docs/evidence/release-1.11.0/recovery-role-pdf1110.json); informe: `test-results/recovery-role-pdf1110/index.html`. El ZIP completo es la entrega principal y ya no se prepara buscando un máximo de 2 MiB.

Para duración y contexto de IA ejecuta [TranscriptContextTest.php](tests/php/TranscriptContextTest.php), [SupadataTranscriptTest.php](tests/php/SupadataTranscriptTest.php) y las regresiones de proveedores, preparación automática y recursos en WordPress local desechable. Comprueba SRT, WebVTT y marcas entre corchetes; conserva todas las palabras y cifras habladas, incluso el cierre de fuentes de más de dos horas. El mayor tiempo final válido determina la duración, aunque el video pueda tener un cierre sin subtítulos. Sin tiempos válidos se mantiene el respaldo de metadatos/datos manuales. Los tres transportes reciben texto sin tiempos; la fuente guardada y los extractos locales conservan sus marcas originales. El contexto sigue sujeto al límite del modelo: se elimina formato redundante, sin recortar contenido para ajustarlo. [Evidencia](docs/evidence/release-1.11.0/transcript-context1110.json); informe local: `test-results/transcript-context1110/index.html`. Las respuestas de proveedores se simulan y no se acredita una conexión con claves reales.

Para la vista previa PDF y las flechas de Galería usa `node tests/readers-auto1110.cjs` en WordPress local desechable con el fixture compartido y `ASCLA_AXE_PATH`. Genera un documento real de dos páginas y verifica apertura automática, páginas, zoom, texto accesible sin panel visible, comentarios conservados, descarga original y errores. Las zonas de las flechas deben responder por encima y por debajo de sus círculos, sin mostrar el nombre del archivo. Comprueba acceso sin sesión/denegado y eliminación, y la integridad de PDF.js bajo `assets/pdfjs/`. Ejecuta también `tests/php/VideoPreparationTest.php` y las regresiones PHP de cabeceras, medios y ciclo del contenido. Informe actual: `test-results/readers-auto1110/index.html`; [evidencia](docs/evidence/release-1.11.0/readers-auto1110.json). El informe PDF anterior conserva su alcance histórico. No se ejecutan scripts del PDF ni se admite edición interactiva de formularios; los PDF protegidos con contraseña conservan la descarga. La librería oficial de terceros tiene licencia y procedencia en `assets/pdfjs/NOTICE.txt` y queda fuera del análisis de código propio de SonarQube.

Para perfiles y Galería, ejecuta `tests/php/GalleryProfile1110Test.php` con las regresiones de medios temporales, perfiles y notificaciones. En el sitio desechable con el fixture compartido, usa `node tests/gallery-profile1110.cjs` y `ASCLA_AXE_PATH`: valida privacidad del usuario, diseño adaptable, búsqueda con más de veinte eventos reales, paginación, selección por teclado, estado inicial de publicación, flechas, adjuntos y eliminación con respuestas 404 de los archivos. Informe: `test-results/gallery-profile1110/index.html`; [evidencia](docs/evidence/release-1.11.0/gallery-profile1110.json). La eliminación permanente de los adjuntos se limita a los archivos de esa galería; un maestro aún usado por otro recorte se conserva.

Para reactivación ejecuta `tests/php/EventReactivationTest.php` junto a las regresiones de eventos, recordatorios, notificaciones y `UserExperience1110Test.php` en WordPress desechable. Prepara los avisos con `tests/event-reactivation1110-fixture.php` (solo CLI/local), y usa `node tests/event-reactivation1110.cjs` con el fixture compartido y `ASCLA_AXE_PATH`. Verifica cancelar → reactivar, permisos, historial, reservas, filtros, lectura y borrado; incluye escritorio/móvil y ambos temas. Informe: `test-results/event-reactivation1110/index.html`; [evidencia](docs/evidence/release-1.11.0/event-reactivation1110.json). Los seis flujos compartidos de Bruno/Playwright mantienen el mismo contrato; esta regresión dirigida es adicional y no sustituye su ejecución completa.

El esquema continúa en 15. Ejecuta `tests/php/UserExperience1110Test.php` y la regresión PHP en un WordPress local desechable con los datos demo preparados. El fixture `tests/shared-test-users-fixture.php` genera los accesos privados de Ana, Bruno, Carla y Administrador; `tests/usability1110-fixture.php` prepara el chat y la bandeja para la regresión dirigida. Conserva ambos JSON solo dentro de `test-results/`.

Ejecuta `node tests/usability1110.cjs` con Playwright disponible. `ASCLA_UX_FIXTURE` señala el JSON privado; el archivo adicional del chat se guarda en `test-results/sonar-preparation/release1110-ui-fixture.json`. El runner admite solo origen local y loopback. Revisa `test-results/usability1110/summary.json` y las capturas. El estado OAuth de la interfaz y el escenario de pocas coincidencias se simulan; las operaciones Google y los errores se comprueban aparte en PHPUnit. Para validar una integración real, conecta una cuenta autorizada, inscríbete, verifica un único evento con su enlace y cancela para comprobar su retiro. Si se usa una invitación del organizador, comprueba también el correo de Google y su aceptación; una respuesta HTTP correcta no acredita recepción del correo.

No sustituyas con estos resultados los informes antiguos ni `coverage/source-revision`. Genera la cobertura completa desde la revisión limpia que se vaya a promover y repite SonarQube para QA/UAT según las secciones de este manual. El nuevo manifiesto está en `docs/evidence/release-1.11.0/package.json`.

Para la revisión visual adicional, ejecuta `node tests/visual1110.cjs` con el mismo fixture local, Playwright y `axe-core` disponibles. `ASCLA_AXE_PATH` permite señalar una copia local de `axe.min.js`. Comprueba navegación por teclado desde las cuatro tarjetas del inicio, tipografía y espacios del Directorio, estados y Chatham House y tarjetas de eventos en escritorio/móvil y ambos temas. El resultado y las capturas quedan en `test-results/visual1110/`; [visual1110.json](docs/evidence/release-1.11.0/visual1110.json) identifica el ZIP revisado. Las ejecuciones anteriores mantienen su SHA original y [su manifiesto](docs/evidence/release-1.11.0/package-initial.json).

La revisión posterior usa `node tests/gallery-calendar1110.cjs` con ese fixture local, Playwright y axe. `ASCLA_GALLERY_IMAGE_A` y `ASCLA_GALLERY_IMAGE_B` permiten usar imágenes de prueba locales; por defecto utiliza el logo del plugin. Comprueba borradores, estados, Galería y el calendario manual antes/después de inscribirse o cancelar. Los estados de Google pendiente/invitado y la conexión OAuth se simulan solo para comprobar la disponibilidad del botón; no se envía ninguna invitación real. Ejecuta también `tests/php/UserExperience1110Test.php` para validar los enlaces de calendario y la sincronización. [gallery-calendar1110.json](docs/evidence/release-1.11.0/gallery-calendar1110.json) identifica el nuevo ZIP y sus resultados; las etapas anteriores conservan sus manifiestos.

## 1. Elegir qué vas a comprobar

La revisión dirigida de la portada predeterminada usa `ASCLA_MEDIA_REPORT=test-results/knowledge-cover1110 node tests/media-knowledge1110.cjs` con el fixture local, Playwright, axe y las mismas variables de imágenes. Comprueba portada sin imagen, fotografía válida y respaldo ante error, en ambos temas y tamaños. Los metadatos de la miniatura se simulan; no depende de YouTube. Los resultados quedan en `test-results/knowledge-cover1110/` y [su evidencia](docs/evidence/release-1.11.0/knowledge-cover1110.json) identifica el paquete.

Para la revisión del visor y Centro de Conocimiento, ejecuta `node tests/media-knowledge1110.cjs` con el fixture loopback, Playwright, axe y las variables de imágenes locales descritas arriba. Comprueba imagen única/álbum, botones y teclado, acceso al original/PDF y conservación de comentarios; después, tarjetas, filtro por tipo y accesos de recursos. La miniatura y duración de un video se simulan para validar la presentación sin depender de YouTube. El informe y las capturas quedan en `test-results/media-knowledge1110/`; [la evidencia](docs/evidence/release-1.11.0/media-knowledge1110.json) identifica el paquete revisado.

| Objetivo | Comando principal | Dónde actúa | Qué revisar |
|---|---|---|---|
| Verificar documentación | `python3 scripts/check-docs.py` | Repositorio | Mensaje `Documentation OK`. |
| Verificar evidencias publicables | `python3 scripts/verify-qa-delivery.py` | Carpeta `pruebas/` | Inventario, huellas, ZIP y enlaces correctos; no ejecuta pruebas nuevas. |
| Probar PHP e interfaz y generar cobertura | `BRANCH_NAME=qa bash scripts/quality-ci.sh` | WordPress Docker desechable | Pruebas, JUnit, Clover y LCOV en `coverage/`. |
| Analizar código con SonarQube | `bash scripts/sonar-manual.sh qa` | Fuentes y reportes anteriores | Análisis de ASCLA-QA y su Quality Gate. |
| Probar integración y E2E con Bruno/Playwright | [Ejecutores de la entrega vigente](pruebas/README.md#ejecutar-y-actualizar) | QA con cuentas dedicadas o fixture local explícita | 81 peticiones, 149 comprobaciones y seis flujos UI con evidencias. |
| Preparar el plugin | `python3 scripts/build.py` y `python3 scripts/verify-package.py` | Fuentes del plugin | ZIP, SHA-256 y manifiesto. |
| Comprobar seguridad con ZAP | [Procedimiento ZAP](#zap) | WordPress local HTTPS desechable | Informes separados de páginas y API, CSP y límites del análisis. |

Sigue **2 → 3 → 4 → 5** para validar una revisión de QA. Usa la sección **6** para probar el sitio instalado. La aceptación UAT requiere después revisión humana y evidencia del entorno.

## 2. Preparar herramientas y una revisión reproducible

Comprueba que estás en el repositorio y que las herramientas responden:

```bash
git status --short
git branch --show-current
python3 --version
docker version
docker compose version
openssl version
```

Necesitas Git, Python 3, Bash, OpenSSL y Docker con Compose. La primera ejecución descarga imágenes y dependencias, por lo que requiere acceso a sus registros. PHP, PHPUnit/PCOV, Bruno CLI y Playwright se preparan en contenedores; no necesitas instalarlos globalmente ni dentro del WordPress publicado.

Antes de cambiar de rama, revisa `git diff` y guarda los cambios intencionados en un commit. Revisa también los archivos nuevos con `git status`: los que todavía no se agregaron no aparecen en `git diff`. No incluyas contraseñas, archivos `.env`, accesos temporales ni reportes privados.

Si la entrega incluye cambios del plugin, prepara primero su paquete:

```bash
python3 scripts/build.py
python3 scripts/verify-package.py
python3 scripts/check-docs.py
```

El verificador puede actualizar el [manifiesto público](docs/evidence/release-1.11.0/package.json); incorpora ese cambio al commit de la entrega antes de generar cobertura. Revisa y agrega los archivos correspondientes, confirma el commit y comparte `development` según el procedimiento del equipo.

Para promover esa revisión a QA, con el checkout ya limpio:

```bash
git fetch origin
git switch qa
git pull --ff-only origin qa
git merge --ff-only development
git push origin qa
git status --short
git rev-parse HEAD
```

`git status --short` debe quedar sin salida. Conserva el identificador del commit. Si `--ff-only` falla, las ramas divergieron: revisa `git log --graph --oneline --decorate --all -30` y resuelve la integración con el equipo antes de continuar. No fuerces el avance ni descartes cambios para pasar el análisis.

## 3. Ejecutar PHPUnit, pruebas de interfaz y cobertura

En la rama `qa`, ejecuta:

```bash
python3 scripts/check-docs.py
docker network inspect proxy_net >/dev/null 2>&1 || docker network create proxy_net
BRANCH_NAME=qa bash scripts/quality-ci.sh
```

El script crea su propia base y WordPress desechables, ejecuta PHPUnit con PCOV, comprobaciones portables y suites de navegador. Al terminar retira sus propios servicios y volúmenes y conserva los resultados. No requiere arrancar el local de la sección 9, no instala el plugin en el servidor y no ejecuta Bruno ni SonarQube.

Espera a que termine sin errores. Si PHPUnit muestra `Failures` o `Errors`, o un recorrido de navegador falla, corrige la causa antes de aprobar la revisión. El resultado debe corresponder al comando que acabas de terminar.

| Archivo o carpeta | Para qué sirve |
|---|---|
| `coverage/php-summary.txt` | Porcentaje y detalle de cobertura PHP. |
| `coverage/junit.xml` | Casos PHP, tiempos y fallos. |
| `coverage/clover.xml` | Cobertura PHP importada por SonarQube. |
| `coverage/lcov.info` | Cobertura JavaScript importada por SonarQube. |
| `coverage/source-revision` | Commit de la ejecución válida y sin cambios pendientes. |
| `test-results/` | Resultados y capturas de las suites de navegador ejecutadas. |

El script valida que los reportes sean utilizables y correspondan a rutas del repositorio; no impone llegar al 100 %. No todas las suites puntuales del repositorio forman parte de este comando: la evidencia de una corrección específica debe indicar qué se ejecutó.

Si PHP termina con `OK` pero después aparece `Disposable test site required`, la suite completa todavía no ha pasado: se detuvo al preparar los datos del navegador. El servicio `cli` debe recibir el mismo entorno `local` y la captura de correo que WordPress mediante `WORDPRESS_CONFIG_EXTRA`. El script comprueba ese contexto antes de PHPUnit. Actualiza la configuración y repite el comando; no retires la protección de las fixtures ni fabriques `coverage/source-revision` para continuar con SonarQube.

Los mensajes `ASCLA technical_error` durante PHPUnit pueden corresponder a casos que simulan fallos de base, proveedores o correo. Su presencia no determina por sí sola el resultado: revisa el resumen de PHPUnit y el final del script. Los avisos de instalación de paquetes/PCOV tampoco equivalen a pruebas fallidas.

Si la preparación termina con `Package "pcov" Version "1.0.12" does not have REST xml available` o `install failed`, las pruebas todavía no se han ejecutado. El lanzador descarga directamente el [archivo oficial de PCOV 1.0.12](https://pecl.php.net/package/pcov/1.0.12), verifica su SHA-256 fijado, lo instala desde el archivo y comprueba que PHP carga la extensión. Así no depende de los metadatos REST del canal PECL. No se desactiva TLS ni se omite la cobertura. Actualiza el script, conserva la corrección en un commit limpio y repite `BRANCH_NAME=qa bash scripts/quality-ci.sh`; no reutilices `coverage/source-revision` de otra revisión.

El acceso nativo imprime por separado sus scripts registrados para que WordPress les añada nonce y SRI: su agrupación mediante `load-scripts.php` omitía esos filtros y provocaba errores de dependencias bajo CSP. La prueba `tests/native-login-security.cjs` comprueba las dependencias, rotación del nonce, conservación de CSRF y bloqueo de un script sin nonce insertado en una respuesta HTML controlada. Conserva por separado los bloqueos de estilos de las sondas internas de jQuery; no los presenta como ausencia total de infracciones CSP del acceso nativo.

El inicio de Administrador/Ejecutivo es el panel administrativo; las cuentas comunitarias conservan el inicio con recomendaciones. Las fixtures de accesibilidad y formularios usan sesiones independientes para cada alcance y restauran sus idiomas. Las pruebas no deben esperar el bloque `.hero` para un administrador ni usarlo como participante en Mensajería/Contacto. Los resultados históricos de `pruebas/` mantienen su revisión y no sustituyen la nueva ejecución completa de cobertura.

Si un recorrido falla con `Timeout`, revisa `test-results/e2e.json` y las capturas `test-results/failure-admin.png` y `test-results/failure-member.png`. Contrasta la sección y los controles visibles con la navegación del caso. El reporte JavaScript se guarda también al fallar y puede ser parcial: su porcentaje no acredita que terminó la suite. SonarQube rechaza esa ejecución porque todavía no existe `coverage/source-revision`; corrige el caso y repite el flujo completo sobre un commit limpio.

En accesibilidad, revisa `test-results/accessibility107.json` y `test-results/accessibility107-progress.json`; al fallar también se guardan `test-results/accessibility107-failure.png` y `test-results/accessibility107-failure.json` con el foco y estado de los adjuntos. El caso de carga debe abrir el selector nativo con teclado, en ambos temas, antes de comprobar el editor de imagen. No sustituyas esa comprobación por asignar directamente el archivo al campo.

<a id="sonarqube"></a>

## 4. Enviar el análisis a SonarQube y revisar el Quality Gate

### Preparar el acceso

1. Abre [SonarQube del proyecto](https://sonarqube.ingsoftware.lat) e inicia sesión.
2. Comprueba que tienes acceso a **ASCLA-QA** y permiso para analizarlo. Si falta ese acceso, debe habilitarlo quien administra SonarQube; ser administrador de WordPress no concede ese permiso.
3. Obtén un token desde la gestión de seguridad de tu cuenta o solicita el acceso correspondiente. Consérvalo privado; no lo escribas en comandos, Markdown, capturas ni commits.

### Ejecutar el scanner

Sin editar archivos ni cambiar de commit después de las pruebas:

```bash
export SONAR_HOST_URL=https://sonarqube.ingsoftware.lat
unset SONAR_TOKEN
bash scripts/sonar-manual.sh qa
```

Cuando la terminal solicite el token de ASCLA-QA, pégalo allí y pulsa Enter. La entrada no se muestra. La URL debe ser texto simple, sin los corchetes ni paréntesis de un enlace Markdown.

El script exige rama `qa`, checkout limpio, reportes presentes y `coverage/source-revision` igual al commit actual. Ejecuta SonarScanner en Docker con la configuración de [sonar-project.properties](sonar-project.properties), importa cobertura PHP/JavaScript y espera el Quality Gate hasta cinco minutos. No ejecuta pruebas contra WordPress ni despliega.

El directorio de trabajo del scanner se conserva en `.scannerwork/`, ignorado por Git. Su `report-task.txt` permite recuperar el identificador de procesamiento y consultar el análisis exacto después de que Docker retire el contenedor. Conserva ese archivo con el log privado y registra la revisión; no atribuyas el estado de otro análisis a esta ejecución.

### Leer y guardar el resultado

1. Abre el enlace al análisis que devuelve el scanner y verifica **ASCLA-QA**, fecha y revisión analizada.
2. Comprueba el **Quality Gate** y abre cada condición incumplida si aparece fallido. Subir los archivos no basta para aprobar el gate.
3. Revisa incidencias, seguridad, duplicación y cobertura. Evalúa los hallazgos que requieren revisión humana; no los cierres solo para cambiar el indicador.
4. Registra enlace al análisis, commit, fecha, estado y condiciones evaluadas. No captures el token.

Los umbrales efectivos son los configurados en SonarQube. Si falla una condición de cobertura, consulta ese umbral y las líneas afectadas; no se requiere convertir todo el proyecto a 100 %. Cambiar las condiciones debe ser una decisión explícita del equipo, no una forma de ocultar fallos.

Un gate puede fallar por incidencias aunque la cobertura ya cumpla. En ese caso, corrige las reglas señaladas y valida el comportamiento con las pruebas existentes; no necesitas añadir casos para aumentar ese porcentaje. Conserva por separado la exportación original y el análisis posterior. Una comprobación local sin cobertura no reemplaza el análisis de QA ni permite atribuir reportes de cobertura anteriores a otro commit.

### Si algo falla

| Situación | Cómo continuar |
|---|---|
| Docker no conecta | Abre Docker Desktop, comprueba la integración WSL y repite `docker version`. |
| Rama incorrecta | Conserva tus cambios y sitúate en `qa`; el argumento del scanner no cambia de rama. |
| Cambios sin commit | Revisa y confirma los cambios intencionados; repite `quality-ci.sh` sobre esa revisión limpia. |
| Git muestra `Unable to read current working directory` | En WSL, sal del directorio con `cd /` y vuelve a entrar a la raíz real del repositorio, o abre una terminal nueva allí. Repite `git status` y `git diff` antes de continuar. Si Windows y WSL leen estados distintos, comprueba de nuevo el montaje antes de confirmar o descartar archivos. Los scripts se detienen si Git no puede comprobar el estado. |
| Falta `source-revision` o no coincide | Repite la suite sobre el commit actual y sin ediciones durante la ejecución. No crees ni modifiques ese archivo a mano. |
| Faltan Clover, JUnit o LCOV | Revisa dónde se interrumpió `quality-ci.sh`, resuelve el fallo y vuelve a ejecutarlo. |
| Token rechazado | Comprueba vigencia y permiso sobre ASCLA-QA; facilita el token al repetir la entrada oculta. |
| Quality Gate fallido | Revisa las condiciones, corrige lo que corresponda, guarda el nuevo commit y repite pruebas y scanner. |
| Se agota la espera del gate | Revisa en SonarQube si el procesamiento sigue pendiente o falló. No registres aprobación hasta conocer el resultado. |

Si habías exportado `SONAR_TOKEN` en la terminal padre, ejecuta `unset SONAR_TOKEN` allí al terminar. El script solo puede limpiar su propio proceso.

<a id="pruebas-http-e2e"></a>

## 5. Ejecutar integración y E2E con Bruno y Playwright

La [entrega vigente](pruebas/README.md) reúne **81 peticiones y 149 comprobaciones de Bruno**, y **seis recorridos de Playwright aprobados el 22 de octubre en el QA publicado**, con 12 videos y 12 capturas. El asistente devuelve **Google Gemini · API real**. La comparación SHA-256 acredita coincidencia del JavaScript y CSS con el paquete local, y las pruebas API/UI verifican el comportamiento observado del backend; no se descargan todos los PHP privados.

La [regresión reciente](pruebas/actualizacion/index.html) añade 14 controles aprobados con 16 videos/16 capturas. Incluye borradores, roles, filtros, Directorio de 4 × 3, tipo de asociado, Foros, Galería, biblioteca integrada y menú de Aliados. La seguridad remota aprueba seis controles en 42 vistas y 19 de 20 controles HTTP: falta ocultar la versión de Apache en el hosting. ZAP activo se identifica por separado como ejecución HTTPS aislada. Estas selecciones no aumentan los CP únicos ni sustituyen UAT.

| Grupo | Fuente vigente | Alcance |
|---|---|---|
| Integración API | [Colección](pruebas/backend/coleccion-bruno/integracion) | 18 peticiones y 26 comprobaciones: acceso, perfil, paginación, validaciones y restricciones del administrador. |
| E2E de backend | [Cuatro flujos](pruebas/backend/coleccion-bruno/flujos-e2e) | 40 peticiones y 79 comprobaciones con IDs y sesiones obtenidos de pasos anteriores. |
| E2E de interfaz | [Playwright](tests/acceptance-ui.cjs) | Cinco flujos mediante controles reales; videos, capturas y diagnóstico tanto al aprobar como al fallar. |

Prepara las [herramientas y los accesos privados](pruebas/README.md#ejecutar-y-actualizar). Desde la raíz, ejecuta los dos grupos de forma secuencial con las mismas cuentas:

```bash
node scripts/run-publishable-backend.cjs
node scripts/run-playwright-test-users.cjs
node scripts/build-qa-delivery.cjs
python3 scripts/verify-qa-delivery.py
python3 scripts/check-docs.py
```

Los dos ejecutores admiten `--fixture` para seleccionar un acceso local explícito. El [ZIP publicable](pruebas/backend/coleccion-bruno.zip) incluye URL, usuarios e IDs, con contraseñas vacías; estas se cargan desde el archivo privado. Cookies y nonces se mantienen en memoria. El ejecutor de Bruno usa una copia temporal para que los IDs locales no reemplacen las variables del QA compartido.

El constructor publica **solo la última ejecución por herramienta** en `pruebas/`, con su estado original, y retira las anteriores de esa carpeta. Los originales quedan en `test-results/`, fuera de Git. Los reportes registran revisión, rama, destino y cambios sin commit; conservarlos no equivale a aprobar UAT ni a instalar el plugin.

<a id="cuatro-flujos-bruno"></a>

### Los mismos seis flujos en Bruno y Playwright

Los pasos, su utilidad y la relación con los Word están en el [README de backend](pruebas/backend/README.md#e2e-flujos-completos-de-negocio). La colección importable de `pruebas/backend/coleccion-bruno/` es la fuente vigente.

| Flujo | Peticiones | Comprobaciones | Secuencia |
|---|---:|---:|---|
| Perfil del asociado | 7 | 14 | Guardar biografía → recargar → rechazar Empresa vacía → conservar datos → restaurar biografía original. |
| Borrador, publicación e inscripción | 12 | 22 | Borrador incompleto → validación → publicación con fechas → inscripción persistente → cancelar evento propio. |
| Conexión, mensaje y bloqueo | 12 | 24 | Login de Ana y Bruno → solicitud → aceptación → mensaje leído → bloqueo → envío rechazado con 404 → desbloqueo sin restaurar conexión. |
| Evento con cupo y espera | 19 | 37 | Crear aforo uno → Ana confirma → Bruno recibe 409 → espera → Ana cancela → oferta → abrir aviso y comprobar lectura → confirmar vacante → cancelar evento → inscripción rechazada con 409. |
| Suspensión y reactivación | 9 | 18 | Acceso de Carla → suspender → sesión revocada con 403 → reactivar → sesión anterior sigue inválida → login nuevo → acceso 200. |
| Asistente IA | 4 | 8 | Pregunta → ID del trabajo → polling → completed → comprobar respuesta → consultar el mismo hilo. |

El asistente consulta cada dos segundos, hasta 120 segundos, y conserva el modo devuelto por el servidor. Un resultado mock no acredita un proveedor externo. El ejecutor intenta reactivar a Carla, retirar el bloqueo y cancelar únicamente su propio evento; revisa `cleanup` en el resumen.

<a id="playwright-usuarios-prueba"></a>

### Playwright con los mismos usuarios de prueba de Bruno

El [ejecutor de interfaz](scripts/run-playwright-test-users.cjs) usa Ana, Bruno, Carla y Administrador desde `test-results/playwright-usuarios.json`, ignorado por Git. En otro equipo copia [el ejemplo sin contraseñas](pruebas/acceso.example.json) y completa los accesos autorizados por el canal interno.

| Recorrido | Usuarios | Secuencia |
|---|---|---|
| Perfil | Carla | Editar biografía → guardar → recargar → rechazar Empresa vacía → conservar datos. |
| Conexión y chat | Ana y Bruno | Solicitud → aceptación → enviar y leer → bloquear → impedir chat → desbloquear sin restaurar conexión. |
| Publicación e inscripción | Administrador y Ana | Inicio administrativo y restricciones sociales → borrador → validar → publicar → inscribirse → persistir → cancelar evento. |
| Aforo y espera | Administrador, Ana y Bruno | Un cupo → confirmación → espera → vacante → abrir aviso → aceptar oferta → cancelar evento. |
| Suspensión | Administrador y Carla | Suspender → revocar sesión → rechazar login → reactivar → login nuevo → conservar biografía. |

En Windows puedes mostrar el navegador con:

```powershell
.\scripts\playwright-usuarios-prueba.ps1 -Visible
```

Para un acceso local preparado añade `-Fixture test-results/playwright-usuarios-local.json`. Se requieren Node.js, las dependencias del proyecto, Microsoft Edge y los binarios de video de Playwright. La sesión se verifica mediante REST; las acciones de negocio se realizan desde la interfaz. No ejecutes ambos grupos simultáneamente con las mismas cuentas.

### Directorio de cuatro columnas y tres filas

Como Asociado o Moderador, usa al menos trece perfiles visibles. En escritorio (1440 o 1280 px) la primera página debe mostrar doce perfiles en cuatro columnas y tres filas; el paginado aparece debajo. Siguiente muestra el perfil restante y Anterior recupera los mismos doce, conservando búsqueda, país y orden. A 1024 px se usan dos columnas y a 390 px una, sin desbordamiento y con el mismo tamaño de página. En Configuración, Perfiles por página debe mostrar doce como solo lectura, incluso si la instalación guardaba antes otro tamaño.

La [evidencia](docs/evidence/release-1.10.9/directory-grid109.json) registra 40 pruebas PHP/255 aserciones y tres controles de navegador. Fuentes: [ProfileDirectoryReviewTest.php](tests/php/ProfileDirectoryReviewTest.php), [directory-grid.cjs](tests/directory-grid.cjs) y su [fixture](tests/directory-grid-fixture.php). Este fixture solo acepta WordPress desechable local en `ascla-directory.test`; genera `/tmp/directory-private-access.json`. Cópialo a `test-results/directory-grid-20261022/private-access.json` y ejecuta `node tests/directory-grid.cjs` con Playwright disponible. `ASCLA_DIRECTORY_OUT` permite otra carpeta de salida. Retira el entorno y las credenciales al finalizar.

### Lista de Foros, biblioteca integrada y separación de botones

La [evidencia dirigida](docs/evidence/release-1.10.9/layout-ui109.json) registra 74 pruebas PHP/631 aserciones y seis controles de navegador. Para revisar estos ajustes:

1. En Foros, comprueba una sola lista con foros y publicaciones, sin Explorar foros. Busca, aplica fechas y filtra por foro; contrasta total, páginas y acceso a la conversación. Mis temas incluye foros y publicaciones propios; Mis borradores conserva los dos tipos propios.
2. Como Administrador y Ejecutivo, abre Archivos: la biblioteca debe aparecer en la página. Busca por nombre, filtra por propietario, pagina y abre un archivo ficticio. Cancela Eliminar y verifica que se conserva. Elimina el único archivo de una última página: la biblioteca debe volver a una página válida, conservar búsqueda/propietario y cerrar la confirmación. En Perfil → Cuenta y seguridad, Mis archivos sigue mostrando únicamente los propios.
3. Como Asociado, revisa una tarjeta ajena en Directorio con Ver perfil, Conectar y Enviar mensaje. Los tres botones deben estar separados por 12 px, también en móvil.

Las fuentes son [UnifiedForumListingTest.php](tests/php/UnifiedForumListingTest.php), [layout-ui.cjs](tests/layout-ui.cjs) y su [fixture](tests/layout-ui-fixture.php). El fixture solo acepta WordPress desechable local en `ascla-layout.test`; escribe accesos en `/tmp/layout-private-access.json` dentro del contenedor. Cópialos a `test-results/layout-20261022/private-access.json` y ejecuta `node tests/layout-ui.cjs` con Playwright disponible. `ASCLA_LAYOUT_OUT` permite cambiar la salida. Retira el entorno y las credenciales al finalizar. Esta validación dirigida no renueva la suite completa, cobertura, ZAP ni UAT.

### Ejecutivo y Administrador con el mismo panel

El ajuste del 22 de octubre iguala la gestión administrativa de ambos roles, salvo Configuración y Administrar WordPress. Como Ejecutivo, comprueba el inicio en Administración ASCLA y las mismas secciones del Administrador, incluidas Usuarios e intereses, Importar intereses, Archivos, Aliados e Historial de cambios. Directorio, Mensajería y Contacto no deben aparecer en el menú de ninguno de los dos roles; las funciones comunitarias siguen disponibles para Asociado y Moderador.

Comprueba que el Ejecutivo puede crear, editar, suspender, reactivar y eliminar un asociado de prueba, además de gestionar contenido y aliados. Intenta abrir Configuración mediante un enlace antiguo: debe volver al Resumen. Las consultas y escrituras REST de `settings`, los diagnósticos de Configuración y la preparación de demo deben responder 403. `/wp-admin/` debe devolver al Ejecutivo a Administración ASCLA. El Administrador conserva sus dos botones y permisos.

La [evidencia de paridad](docs/evidence/release-1.10.9/executive-admin109.json) identifica el paquete y la validación local. Las fuentes específicas son [ExecutiveAdministrationTest.php](tests/php/ExecutiveAdministrationTest.php), [executive-admin.cjs](tests/executive-admin.cjs) y su [fixture](tests/executive-admin-fixture.php). El fixture solo acepta WordPress desechable local en `ascla-executive.test`; escribe los accesos dentro del contenedor en `/tmp/executive-private-access.json`. Cópialos a `test-results/executive-admin-20261022/private-access.json` y ejecuta `node tests/executive-admin.cjs` con Playwright disponible. `ASCLA_EXECUTIVE_OUT` permite cambiar la salida. Retira el entorno y los accesos al finalizar. Las pruebas no renuevan ZAP ni aceptación UAT.

### Interfaz y cuenta de administración

Administrador y Ejecutivo inician en **Administración ASCLA**; `/intranet/` también los lleva a ese panel. El enlace principal y el logo regresan a administración. En escritorio se muestra un único logo y las secciones administrativas encima del contenido, con un solo panel lateral. El menú sigue disponible en móvil. Moderadores y asociados conservan su participación comunitaria.

La [regresión PHP](tests/php/AdministratorRoleTest.php) y la [prueba de navegación](tests/admin-navigation.cjs) comprueban inicio, permisos, directorio y restricciones sociales. El [fixture](tests/admin-navigation-fixture.php) es exclusivo de un WordPress local ya inicializado. Con ese entorno y las herramientas disponibles:

```bash
docker compose exec -T wordpress php /opt/ascla-tests/admin-navigation-fixture.php > test-results/admin-role-access.json
node tests/admin-navigation.cjs
```

Las evidencias quedan en `test-results/admin-role-ui-FECHA/`; los accesos permanecen privados. El fixture admite la limpieza por CLI con `action: cleanup` y valida IDs y usuarios antes de retirar sus propias cuentas.

### Administración y perfil del 22 de octubre

La [regresión dirigida](docs/evidence/release-1.10.9/admin-ui109.json) registra 38 pruebas PHP/335 aserciones y nueve controles de navegador, separados de las colecciones anteriores. Para comprobar este ajuste:

1. Como Administrador o Ejecutivo, verifica que Directorio, Mensajería y Contacto no aparecen en el menú. Administración no debe mostrar Tareas de IA ni su contador; un enlace antiguo a esa sección debe abrir Resumen. Como Asociado, verifica que se mantienen los tres accesos comunitarios.
2. En Aliados, abre los tres puntos con clic o teclado. Cancela y confirma Desactivar/Activar: el ID se conserva. Cancela y confirma Eliminar sobre una ficha de prueba: solo al confirmar desaparece del registro y pasa a la papelera.
3. En Usuarios e intereses, combina búsqueda, país, varias industrias e intereses, área, rol y estado de acceso. Revisa total, páginas, selección conservada y Limpiar filtros. Incluye cuentas suspendidas y que no figuren en el Directorio público. En Directorio, comprueba selecciones múltiples con enlaces permanentes y con `rest_route`.
4. En Perfil, el tipo de asociado o Pendiente de asignación aparece en la cabecera derecha, fuera de la cuadrícula de datos personales. Revisa escritorio y móvil. En Galería, Registro e historial debe quedar junto a Nueva galería.

Las fuentes son [AdminUserFiltersTest.php](tests/php/AdminUserFiltersTest.php), [AlliesLifecycleTest.php](tests/php/AlliesLifecycleTest.php) y [admin-ui.cjs](tests/admin-ui.cjs). El [fixture](tests/admin-ui-fixture.php) solo acepta WordPress local desechable en `ascla-adminui.test`. Crea los accesos en `/tmp/admin-ui-private-access.json` dentro del contenedor; cópialos a `test-results/admin-ui-20261022/private-access.json` para ejecutar `node tests/admin-ui.cjs` con Playwright disponible. `ASCLA_ADMIN_UI_OUT` permite cambiar el directorio de resultados. Retira el entorno, sus volúmenes y los accesos después de probar. Esta etapa no renueva cobertura, ZAP ni UAT.

### Suite complementaria con cuentas temporales

`bash scripts/acceptance-tests.sh` sigue activo para una aceptación aislada: diez peticiones de integración, 23 de negocio y tres recorridos de interfaz, con preparación y limpieza automática de cuentas. Cubre también desconexión con historial y reprogramación de eventos. Sus fuentes están en [tests/http](tests/http); no sustituye los seis flujos compartidos de la entrega vigente. El modo remoto con auxiliar WordPress se explica en la sección 6.

<a id="regresion-borradores"></a>

### Regresión UI/UX de borradores, foros y galerías

El [resumen del 21 de octubre](docs/evidence/release-1.10.9/drafts-ui109.json) identifica el ZIP y la validación dirigida de #573–#576: **76 pruebas PHP/859 aserciones**, **diez controles de navegador** y **49 controles portables**. Esta ejecución local no renueva los cinco flujos publicados del 16 de octubre, la cobertura, ZAP ni UAT.

Para revisar el comportamiento en el entorno autorizado:

1. Como Ejecutivo, crea contenido en Hub, Foros, Galería, Centro de Conocimiento y Eventos. Para crear Aliados, inicia sesión como Administrador. Guarda sin completar todos los campos y comprueba **Mis borradores**. En Foros se reúnen foros y temas; los eventos sin fecha también aparecen en **Mis publicaciones**.
2. Al cancelar una creación con cambios, comprueba **Seguir editando**, **Guardar como borrador** y **Salir sin guardar**. Reabre el borrador y verifica que el contenido se conserva. Al elegir publicar, los campos obligatorios vuelven a exigirse.
3. En Comunidad comprueba las tarjetas de los foros publicados, su descripción y acceso; los borradores propios se consultan en su pestaña. Revisa escritorio y móvil.
4. En Galería abre la portada con clic y con teclado. Ambos deben abrir la misma colección autorizada.

La regresión automatizada está en [DraftWorkflowTest.php](tests/php/DraftWorkflowTest.php) y [draft-workflow.cjs](tests/draft-workflow.cjs). PHPUnit incluye sus casos al ejecutar la suite local. El recorrido adicional de navegador requiere Playwright y el archivo privado `private-access.json` en el directorio indicado por `ASCLA_DRAFT_OUT` (por defecto `test-results/ui573-20261022`). Su [fixture](tests/draft-workflow-fixture.php) solo acepta WordPress local y el host temporal `ascla-ui573.test`; el ejecutor debe resolver ese host en la red Docker de pruebas. No uses cuentas ni datos del servidor publicado. Los accesos se eliminan al retirar el entorno; no se distribuyen en Git.

<a id="exponer-pruebas"></a>

### Cómo exponer las pruebas publicadas

1. Abre el [dashboard general](pruebas/index.html). Identifica fecha, rama, revisión y destino: los flujos principales se ejecutaron el 22 de octubre en el QA publicado. Muestra la comparación de JavaScript/CSS y explica su alcance; el trabajo del asistente devuelve Google Gemini · API real.
2. En [Bruno Run Dashboard](pruebas/backend/dashboard.html), muestra las 18 peticiones de integración y los rechazos esperados de acceso y networking administrativo. Los HTTP 400/401/403/404/409 previstos son comprobaciones aprobadas.
3. Explica los seis flujos compartidos con las flechas del [README de backend](pruebas/backend/README.md). Muestra un ID obtenido por una petición y utilizado por la siguiente; no son valores inventados.
4. En el [dashboard de Playwright](pruebas/frontend/dashboard.html), reproduce los seis recorridos. Son clics y formularios reales; también verifican el inicio administrativo y el logo único.
5. Muestra JSON/JUnit, las fuentes de esa ejecución, `procedencia.json`, `manifest.json` y el ZIP sin secretos. Las huellas permiten detectar modificaciones, no son una certificación independiente.
6. Abre el [dashboard de seguridad](pruebas/seguridad/dashboard.html). Distingue la ejecución ZAP aislada de las 42 vistas autenticadas del QA publicado. Muestra el resultado HTTP 19/20 y la versión de Apache expuesta; los originales completos de ZAP permanecen privados. Identifica el alcance y la integración pendiente en Jenkins.
7. Abre los [14 controles recientes](pruebas/actualizacion/index.html): reproduce los cambios de borradores, roles, filtros, Directorio, perfil, Foros, Galería, Archivos y Aliados. Explica la limpieza de los nueve contenidos ficticios propios. Los conteos de suites distintas no se suman como casos únicos. Las regresiones locales anteriores conservan sus fechas y sus propios resúmenes.

El código se confirma en `development` y luego se promueve a `qa` y `uat`. Los reportes conservan la rama real en que se ejecutaron; preparar un commit en otra rama no cambia sus metadatos. La aceptación UAT exige revisión humana posterior.

## 6. Probar el QA publicado teniendo solo administración de WordPress

Este recorrido corresponde al entorno de pruebas [wordpress.ingsoftware.lat](https://wordpress.ingsoftware.lat/). Requiere permiso para instalar plugins en WordPress y Docker en el equipo que ejecuta las pruebas; **no requiere SSH ni WP-CLI en el servidor**. Administración ASCLA y administración técnica de WordPress tienen permisos distintos.

1. Ejecuta `python3 scripts/build.py` y `python3 scripts/verify-package.py`. Conserva el SHA-256 del ZIP que vas a evaluar.
2. En WordPress, abre **Plugins → Añadir plugin → Subir plugin**. Selecciona `dist/ascla-core.zip` y reemplaza la versión instalada. Comprueba que ASCLA Core **1.11.0** está activo; el mismo número de versión puede contener correcciones distintas, por eso debes registrar también el paquete.
3. Ejecuta `python3 scripts/build-qa-tools.py`. Instala y activa `dist/ascla-qa-tools.zip` desde Plugins. Es un auxiliar independiente del core y restringido a entornos permitidos de pruebas.
4. Abre **Herramientas → ASCLA QA**, confirma el entorno y pulsa **Crear lote y descargar acceso**. Se crean cinco cuentas sintéticas y se descarga `ascla-qa-access.json`.
5. Crea la carpeta `test-results` si no existe y coloca el archivo en `test-results/ascla-qa-access.json`. Es privado y está ignorado por Git. No lo agregues a reportes ni al repositorio.
6. Desde la raíz del repositorio, ejecuta:

```bash
ASCLA_ACCEPTANCE_MODE=remote \
ASCLA_TEST_SITE_URL=https://wordpress.ingsoftware.lat/ \
ASCLA_TEST_FIXTURE=test-results/ascla-qa-access.json \
bash scripts/acceptance-tests.sh
```

7. Abre el reporte indicado, comprueba que el destino es el QA publicado y revisa los tres grupos. Examina `cleanup.log`: el ejecutor intenta retirar el lote temporal con autenticación y comprobación de propiedad.
8. Si no se confirma la limpieza, entra con tu administrador habitual a **Herramientas → ASCLA QA → Limpiar lote temporal**. Conserva el acceso privado hasta confirmar la limpieza. Después retira el JSON descargado y desactiva/elimina **ASCLA QA Tools**. Para repetir, prepara un lote nuevo.

El archivo de acceso debe corresponder a ese sitio, al auxiliar y a Core 1.11.0. El runner rechaza cuentas ajenas al lote. No instala el core ni cambia ramas; prueba lo que ya está instalado. Las pruebas locales no sustituyen este paso.

### Alternativa para quien administra un QA mediante Compose

Solo en el host que gestiona ese proyecto, con su configuración privada y Core 1.11.0 ya activo:

```bash
ASCLA_ACCEPTANCE_MODE=deployed \
ASCLA_TEST_PROJECT=ascla_qa \
ASCLA_TEST_ENV_FILE=.env.qa \
bash scripts/acceptance-tests.sh
```

El nombre debe empezar por `ascla` y contener `qa`. Este modo usa CLI para preparar/limpiar únicamente sus cuentas temporales; no inicia, elimina ni despliega los servicios existentes. Si solo tienes el panel WordPress, utiliza el modo `remote` anterior.

<a id="resultados"></a>

## 7. Abrir reportes y comprobar de dónde salen los datos

En el explorador de archivos, abre la carpeta `test-results/acceptance-FECHA-ID` indicada al finalizar y haz doble clic en **index.html**. `FECHA-ID` representa el nombre real de tu ejecución; no copies literalmente ese marcador.

| Página o archivo | Qué revisar |
|---|---|
| `index.html` | Estado de cada grupo, cantidades, destino y acceso a evidencias. |
| `integration.html` y `business-e2e.html` | Peticiones y comprobaciones de integración/flujos. |
| `integration.bruno.html` y `business-e2e.bruno.html` | Reportes originales de Bruno, separados de la presentación ASCLA. |
| `frontend.html` y `evidence.html` | Pasos de navegador, resultado, videos y capturas. |
| `tools.html` — Herramientas y origen | Herramientas/versiones, colección usada, código y huellas. |
| `summary.json` | Fecha, destino, versión registrada al emitir el acceso, commit local y estado limpio/con cambios. |
| `integration.xml`, `business-e2e.xml` y `frontend.xml` | Resultados JUnit para inspección o entrega técnica. |
| `cleanup.log` | Confirmación o diagnóstico de limpieza. |

Para revisar peticiones en la aplicación **Bruno**, abre la carpeta [tests/http](tests/http), que contiene `bruno.json`. Para inspeccionar la colección capturada en un reporte, descarga `bruno-collection.zip` desde Herramientas y origen, extráela y abre en Bruno la carpeta que contiene `bruno.json`.

Abrir la colección permite leer peticiones, pruebas y encadenamiento de IDs. No prepara cuentas ni limpia datos; ejecuta el flujo completo con `acceptance-tests.sh`, que genera las variables privadas y separa las sesiones de cada actor. La colección descargable no contiene credenciales.

`sources/` y `sources.json` conservan las fuentes capturadas; `evidence-manifest.json` permite contrastar sus huellas SHA-256. Detectan cambios posteriores, pero no son una firma independiente. Los reportes antiguos sin captura de fuentes lo declaran y no se atribuyen la colección actual.

Para regenerar solo la presentación de un reporte existente, con Node.js disponible:

```bash
node tests/acceptance-report.cjs test-results/acceptance-FECHA-ID
```

Sustituye el último argumento por la carpeta real. Esto no repite pruebas ni transforma fallos en aprobaciones. Conserva también los diagnósticos de ejecuciones incompletas. Los reportes omiten cuerpos/cabeceras HTTP sensibles; revisa la evidencia antes de compartirla y no publiques accesos, cookies ni nonces.

Registra fecha, entorno/URL, commit, SHA-256 del ZIP instalado, resultado de cada suite, enlace a SonarQube, limpieza y responsable. El commit del runner no demuestra por sí solo qué ZIP está instalado en el servidor. Los [resúmenes públicos existentes](COMPLIANCE.md#evidencia) identifican su propio alcance y no certifican paquetes posteriores.

<a id="uat"></a>

## 8. Pasar a UAT y completar la aceptación

Después de QA y su Quality Gate, con revisión de testers y autorización del PM:

```bash
git fetch origin
git switch uat
git pull --ff-only origin uat
git merge --no-ff qa -m "merge: integrar qa en uat"
git push origin uat
docker network inspect proxy_net >/dev/null 2>&1 || docker network create proxy_net
BRANCH_NAME=uat bash scripts/quality-ci.sh
bash scripts/sonar-manual.sh uat
```

El scanner selecciona **ASCLA-UAT** y solicita su token. Aplica las mismas comprobaciones de revisión limpia y reportes del commit actual. Instala manualmente en UAT el paquete de esa revisión y registra su SHA-256. Las presentaciones deben corresponder a `uat`; una repetición local no acredita su instalación remota.

La integración desde `qa` conserva los dos historiales mediante un merge. `git pull --ff-only origin uat` solo actualiza la copia local desde su remoto. Si el merge encuentra conflictos, resuélvelos, comprueba el resultado y confirma la integración antes de hacer push o ejecutar las validaciones; no uses un push forzado para borrar la divergencia.

Los testers recorren los casos acordados del [Documento de Análisis y Diseño](requirements/ascla-analysis.md), registran esperado/obtenido, evidencia y defectos. Incluye los controles de entorno de la sección 10 y las excepciones autorizadas de [COMPLIANCE](COMPLIANCE.md#decisiones). Ante un fallo, conserva la evidencia, corrige mediante el flujo del equipo y repite los casos afectados antes de aprobar.

El PM registra la aceptación o sus pendientes. Después abre un pull request **uat → main** y completa revisiones/controles. `main` debe estar protegida, sin push directo. Si el merge genera un commit nuevo, reincorpóralo después en `development` según el procedimiento del equipo; no ocultes divergencias mediante force push. La nomenclatura FE/BCK del curso requiere confirmar la excepción para este repositorio WordPress antes de dividirlo o renombrarlo.

<a id="local"></a>

## 9. Opcional: abrir un WordPress local para revisión manual

No necesitas este entorno para las suites desechables. Para explorar la interfaz, copia `.env.example` a `.env` **solo si todavía no existe** y completa las contraseñas de base, administrador y demo; configura `ASCLA_SITE_URL=http://localhost:8088`. Mantén `.env` privado.

El [Compose base](compose.yaml) no publica puertos al navegador. Para un local nuevo, crea esta extensión privada con puertos de loopback:

```bash
mkdir -p test-results
cat > test-results/compose.local-ports.yaml <<'YAML'
services:
  wordpress:
    ports: ["127.0.0.1:8088:80"]
  mailpit:
    ports: ["127.0.0.1:8025:8025"]
YAML
docker network inspect proxy_net >/dev/null 2>&1 || docker network create proxy_net
docker compose -f compose.yaml -f test-results/compose.local-ports.yaml up -d
docker compose -f compose.yaml -f test-results/compose.local-ports.yaml run --rm cli sh /opt/ascla-scripts/init-wordpress.sh
```

Abre `http://localhost:8088` y el correo capturado en `http://localhost:8025`. Si los puertos están ocupados, ajusta el archivo de puertos y la URL en `.env` antes de inicializar. El inicializador activa el plugin y configura URL/zona horaria; úsalo únicamente en el proyecto local previsto. Usa nombres/volúmenes distintos por entorno y conserva la misma configuración Compose al administrar ese local. El contenedor cron procesa tareas vencidas.

<a id="operacion"></a>

## 10. Completar comprobaciones manuales y del entorno

Estos pasos aportan evidencia para los [pendientes del PDF](COMPLIANCE.md#pendientes). Los controles de hosting corresponden al responsable técnico; el acceso de administrador WordPress puede no ser suficiente. Registra resultados reales por entorno, sin atribuirlos a las suites locales.

<a id="credenciales"></a>

### Credenciales y servicios externos

El responsable técnico conserva una copia privada y configura en entorno o `wp-config.php` solo las claves utilizadas:

| Servicio | Variable/constante |
|---|---|
| Gemini | `ASCLA_AI_KEY` |
| OpenAI | `ASCLA_OPENAI_KEY` |
| DeepSeek | `ASCLA_DEEPSEEK_KEY` |
| Google OAuth | `ASCLA_GOOGLE_CLIENT_SECRET` |
| Turnstile | `ASCLA_TURNSTILE_SECRET` |
| SMTP | `ASCLA_SMTP_PASSWORD` |

1. En **Configuración → Custodia de credenciales**, comprueba el origen sin registrar valores. Para RNF-007, activa `ASCLA_EXTERNAL_SECRETS_ONLY=1` y verifica que llega a HTTP, CLI y cron.
2. Prueba los servicios utilizados y la recuperación ante claves ausentes. El modo externo no recupera claves heredadas; una variable definida vacía deshabilita esa clave. El modo de compatibilidad `0` no acredita RNF-007.
3. Solo después de verificar cada servicio, el responsable puede retirar su opción cifrada heredada. No se elimina automáticamente. Conserva la configuración privada anterior y verifica rotación/protección de logs y backups. Los tokens OAuth personales siguen cifrados para poder renovarse.

<a id="entregas"></a>

### Correo, Calendar y cron

1. Envía una notificación de prueba a una cuenta autorizada y comprueba recepción con el proveedor real. Mailpit solo acredita captura local; véase el [alcance SMTP](COMPLIANCE.md#correo).
2. Comprueba que WP-Cron tiene tráfico suficiente o un ejecutor externo operativo. En **Configuración → Correo y recuperación → Revisar entregas**, revisa pendientes, fallos y reintentos; el operador con CLI dispone de `wp ascla deliveries`.
3. En un entorno desechable, simula un fallo y comprueba recuperación y auditoría del reintento. El esquema 15 conserva entregas cifradas junto con la transacción; tras ocho intentos fallidos requieren revisión administrativa. Verifica preferencias, membresía y caducidad de ofertas antes del envío.
4. Con Calendar real, crea/reprograma/cancela una prueba autorizada. Comprueba que el estado remoto final coincide y que un trabajo atrasado no recrea un evento cancelado. Una aceptación SMTP no prueba lectura ni garantiza recepción exactamente una vez; un resultado ambiguo puede ocasionar reintentos.

<a id="cache"></a>

### Caché y continuidad de transacciones

1. Si se utiliza Redis/Memcached u otro proveedor, pide al operador verificar desconexión y reconexión en QA/UAT. Las pruebas simuladas no certifican ese proveedor ni los hooks de terceros.
2. Abre dos sesiones y comprueba que cambios de perfil/permisos se reflejan entre procesos. Un rollback o savepoint no debe dejar datos no confirmados visibles.
3. El operador prueba interrupción entre commit e invalidación y recuperación por otro proceso/cron, con más de diez pendientes. Una invalidación antigua no debe restaurar valores obsoletos. La recuperación de caché reintenta sin el límite de ocho intentos del correo.
4. Conserva las sales de WordPress mientras haya trabajo cifrado y registra solo metadatos: no expongas claves ni payloads.

<a id="accesibilidad"></a>

### Accesibilidad, privacidad y aceptación funcional

1. Recorre las 13 rutas a 360, 768 y 1024 px en ambos temas, con roles y contenido representativos. Revisa menús, pestañas, tablas, adjuntos, diálogos anidados, imágenes y errores. Tab/Shift+Tab permanecen en el diálogo activo; Escape/cierre restauran foco y el fondo queda inerte.
2. Evalúa los resultados axe `incomplete`, lector de pantalla, zoom/reflujo, orden de lectura, textos alternativos, videos y PDF. Chromium local no acredita las dos versiones exigidas de Chrome, Firefox, Safari y Edge. Referencias: [patrón modal W3C](https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/) y [API axe](https://github.com/dequelabs/axe-core/blob/develop/doc/API.md).
3. Repite en español/inglés ayudas, etiquetas y errores, conservando textos del usuario. Comprueba entradas inválidas desde UI y REST: el rechazo debe conservar lo anterior. Incluye los [formularios implementados](COMPLIANCE.md#formularios).
4. Revisa con cuentas de prueba acceso anónimo/membresía inactiva, descargas y REST; Directorio incompleto autorizado, búsquedas sin inferir identidades, chat inmutable/solo lectura, bloqueos/esperas, borradores, reprogramación y ofertas con vencimiento. Incluye Aliados, intereses automáticos respetando desmarcaciones, permisos acumulados y autorización de Junta. Los [casos QA](COMPLIANCE.md#casos-qa) describen correcciones, no sustituyen su ejecución en destino.
5. Para microeventos, comprueba propuesta, diagnóstico de candidatos, aprobación, publicación, cancelación y archivo/restauración del historial. Los grupos deben cumplir consentimiento, mínimos y afinidad antes de aprobar; registra el motivo del diagnóstico cuando no se genere una propuesta. Como administrador, abre la ficha y la revisión: Personas seleccionadas para el microevento debe mostrar los nombres del grupo y Por invitar antes de publicar. Aprobar por sí solo no debe indicar un envío. Publica, entra con dos asociados invitados, acepta con uno y rechaza con otro; vuelve a abrir la ficha administrativa y contrasta sus estados. Actualizar candidatos debe retirar de la lista a quienes dejaron de ser elegibles. Una cancelación anterior a publicar debe indicar que no hay invitación registrada. Los asociados y moderadores no deben recibir esta lista administrativa, ni siquiera accediendo directamente al detalle REST. Revisa móvil, ambos temas e inglés. La lista no acredita entrega externa de correos o Calendar.
6. En Perfil, guarda los datos del administrador sin activar participación social y comprueba los indicadores obligatorios. Desde Directorio, administración abre Actividad e intereses o Gestionar usuario. Al editar una cuenta antigua sin clasificación, selecciona Individual o Empresa asociada; luego inicia sesión como ese asociado y verifica que el tipo sea de lectura. No hay agrupación de cuentas ni control de cupos empresariales en esta entrega. Una conexión pendiente muestra Solicitud enviada y Cancelar visible, con confirmación. Tras eliminar una conexión, la tarjeta muestra la fecha de disponibilidad y no ofrece Conectar durante la espera; revisa la alineación con tarjetas de otros estados.
7. Para revisar carga, abre las herramientas del navegador → Red y recorre los 13 módulos con un asociado y un administrador de prueba. El arranque normal se incluye en el HTML privado; no necesita un GET adicional a `bootstrap`. Comprueba que puedas cambiar de sección mientras una consulta tarda, reintentar después de un error y guardar sin recibir contadores o perfiles anteriores. Mide el servidor publicado por separado: las comparaciones locales no certifican sus tiempos ni el umbral del análisis con 50 asociados simultáneos.
8. En Teléfono, elige país con ratón y teclado, pega números con + o 00 y comprueba la bandera y el prefijo. Incluye países que comparten +1, cambia de país sin perder la parte nacional, guarda, recarga y finalmente vacía el campo opcional. Otro asociado no debe recibir el teléfono; administración puede editarlo directamente desde Editar usuario. El selector de país y el número deben estar habilitados y guardarse juntos. Comprueba móvil, ambos temas y el campo internacional de respaldo si se bloquea la descarga del selector.
9. En Revisar contenido, alterna Publicaciones, Comentarios y Reportes recibidos: comprueba borradores aparte, aprobación de un comentario y revisión de un reporte sin eliminar el contenido. Abre Reportes ya revisados para consultar su registro. En Crear evento, revisa las explicaciones de asistentes, cupos y avisos y conserva un borrador incompleto. En Perfil, las dos ayudas retiradas no deben aparecer; el asterisco de ubicación queda en País/región y el teléfono muestra su aviso privado.
10. Para comprobar RAG, publica un recurso de prueba con un dato identificable y pregunta por ese tema al Asistente. Abre sus fuentes y contrasta la respuesta; continúa con dos repreguntas y luego inicia una conversación nueva. Un borrador ajeno o una fuente retirada no deben usarse. Repite con los proveedores configurados en el entorno autorizado. Las pruebas locales de los tres transportes interceptan HTTP: comprueban recuperación, permisos y contexto, sin acreditar disponibilidad, coste ni calidad de una respuesta real del proveedor.
11. En Consultas de Contacto, alterna Todas y los cuatro estados; busca por asunto o mensaje y comprueba que se conserve el filtro. Abre una consulta con ratón y teclado, escribe una respuesta y cierra/abre el resumen sin recargar: el texto debe conservarse. Al guardar, la consulta queda abierta. Resolver sin respuesta debe rechazarse. Cambia el responsable y verifica que el anterior deje de recibir asunto, mensaje e historial. Archiva una consulta cerrada y recupérala con Incluir archivadas; su autor debe conservar el historial. Revisa móvil, ambos temas e inglés.
12. Para privacidad de IA, utiliza una fuente sintética con referencias públicas a autores, instituciones, normas y conceptos y una intervención privada. Indica sus identidades y afiliaciones reservadas en el campo del editor y activa Chatham House. Verifica que el Asistente y el resumen conserven las referencias públicas sin mostrar identidad reservada ni revelar los datos marcados. Comprueba también una intervención reconocible por su rol, un correo y un enlace de perfil. El original editorial y la transcripción deben conservarse para el editor autorizado; otro asociado no debe recibirlos. La limpieza del historial no reconstruye nombres omitidos por generaciones antiguas: para recuperar esos nombres, regenera solo el recurso autorizado y revisa el resultado.

13. En Administración → Usuarios e intereses, abre Editar usuario con un asociado de prueba sin consultas de Contacto. Cambia nombres, correo, cargo, empresa, clasificación, teléfono, nacimiento y ubicación; despliega biografía, experiencia, enlaces e intereses, guarda y vuelve a abrir para comprobar persistencia. Prueba un enlace inválido, datos obligatorios vacíos y una edición simultánea desde otra sesión: el error debe conservar lo guardado y el formulario. Revisa Historial de cambios, privacidad y participación del asociado. Si vinculas una consulta opcional, debe estar asignada y activa y no podrá reutilizarse. Contrasta móvil, ambos temas e inglés. Esta edición directa es una excepción autorizada a RN-001/RN-032.

14. En Estadísticas, elige fechas y un tema y compara las tarjetas con las listas de asistencia guardadas. Distingue personas únicas de asistencias por evento, e inscripciones de asistencia real. Comprueba Resumen, Participación, Intereses, Comparar períodos y Temas con IA, sus explicaciones y navegación con teclado. Las vistas y el filtro deben aprovechar el ancho disponible incluso cuando estén cargados los estilos nativos de formularios de WordPress; en Participación deben verse las seis columnas en escritorio y la tabla debe poder desplazarse internamente en móvil. Buscar un título debe actualizar opciones; seleccionar un evento debe filtrar cifras. Quitar filtros conserva las fechas. Envía un período futuro: el servidor debe rechazarlo sin hacer desaparecer el formulario. Un período sin eventos debe orientar para cambiar las fechas y mostrar Sin datos donde no puede calcular una tasa. Abre Revisar asistencias, corrige una persona, revisa/importa un CSV y verifica la lista. La vista previa no debe escribir y una corrección manual debe conservarse al importar Zoom. Repite escritorio/móvil, ambos temas y el acceso del ejecutivo en inglés. La IA propone temas; no aporta las cifras de asistencia.

### Seguridad, rendimiento y copias

Con el responsable del entorno, comprueba HTTPS/TLS, Wordfence/Akismet, Turnstile, bloqueos acordados y logs privados con rotación. Mide 50 asociados simultáneos y los umbrales de navegación, mensajería y estadísticas del PDF; las suites funcionales no equivalen a una prueba de carga.

Verifica copias diarias de base y archivos esenciales, retención mínima de siete días y una restauración ensayada, incluidas las sales para descifrar datos privados. Conserva historial de disponibilidad y mantenimientos. Registra fecha, revisión, destino, responsable y resultado de cada control.

Para comprobar los cambios de perfil, introduce un enlace de otro dominio en LinkedIn o X: el formulario debe impedir guardarlo y explicar el dominio permitido. Repite con linkedin.com, x.com y twitter.com y confirma que se guarda; el sitio personal acepta su propia URL. Comprueba también la edición administrativa y la etiqueta «Individual».

En Administración → Galerías y recursos, busca una publicación y pulsa Ocultar. Introduce el motivo, confirma que aparece como Oculto y abre Historial. Con un asociado distinto del autor, comprueba que desaparece del catálogo y que su enlace directo deja de ser accesible. Vuelve a publicarla desde el registro y verifica que conserva el mismo ID, contenido y archivos. Repite con galería y recurso; un Ejecutivo comparte la gestión editorial administrativa, incluidos los contenidos de otros autores. En Mensajería, una conexión activa permite escribir «Escribe un mensaje…» y no debe pedir aceptar una solicitud antigua.

<a id="zap"></a>

## 11. Pruebas de seguridad con ZAP, sin Jenkins

La integración solicitada de ZAP y CSP en Jenkins, después del despliegue en `qa`, sigue pendiente; también faltan archivado, retención de un build y limpieza final. Este procedimiento se ejecuta independientemente de Jenkins. El proyecto es un monolito WordPress: sus páginas y su API se comprueban por separado en la misma instalación temporal. No se utiliza una aplicación Next.js ni se ejecutan ataques contra el WordPress publicado.

### Preparar y ejecutar

1. Abre Docker Desktop y comprueba que funciona el motor Linux. En Bash/WSL necesitas Docker Compose, Python 3, OpenSSL y `flock`. Reserva al menos 8 GB disponibles para Docker. La primera ejecución descarga las imágenes y las dependencias fijadas en el lockfile.
2. Desde la raíz del repositorio, ejecuta:

```bash
bash scripts/security-tests.sh
```

3. Espera el resultado final y abre `test-results/security-latest/index.html`. La ejecución prepara una base nueva, cuatro cuentas sintéticas, correo capturado e IA simulada; usa HTTPS con un certificado temporal. El verificador HTTP independiente confía expresamente en ese certificado. No necesita contraseñas del sitio ni tokens personales.
4. Conserva los reportes de esta ejecución junto con la revisión del código. El comando sustituye únicamente sus archivos generados conocidos; el directorio contiene la última ejecución. Al terminar elimina sus contenedores, volúmenes de datos, cookies, contraseñas y clave TLS privados. Revisa `cleanup.log` si hubo una interrupción. No publica ni instala el ZIP.

### Qué se comprueba y cómo exponerlo

La [guía de sustento](pruebas/seguridad/sustento.html) reúne los resultados, sus fechas, los enlaces de evidencia y un guion breve. Muestra la autenticación antes/después, las reglas completas, las alertas informativas conservadas y los mismos 20 controles HTTP comparados entre el entorno aislado y QA. No presenta 20/20 como una certificación global ni atribuye el ZAP local al hosting publicado.

La [entrega de fuentes locales](pruebas/seguridad/fuentes.html) conserva ocho archivos de pruebas/configuración con los mismos bytes del análisis, más el [manifiesto de 209 archivos e imagen ZAP](pruebas/seguridad/source-manifest.json). El constructor rechaza una copia si su SHA-256 ya no coincide con la ejecución registrada. El repositorio incluye el lanzador `scripts/security-tests.sh`, los fixtures y Compose para repetir el laboratorio; el push publica fuentes y resultados revisados, mientras los accesos y originales con sesiones permanecen privados.

| Etapa | Prueba y evidencia |
|---|---|
| Páginas ASCLA | Traditional Spider, Client Spider y escaneo activo con ZAP. Reportes nativos `frontend-zap.html` y `frontend-zap.json`. |
| API ASCLA | Importación del contrato OpenAPI generado y escaneo activo de 18 operaciones GET. Reportes `backend-zap.html`, `backend-zap.json` y contrato `openapi.json`. |
| CSP independiente | Peticiones HTTPS fuera de ZAP: política impuesta, restricciones de scripts/estilos, nonce diferente por respuesta y cabeceras. Resultado `csp-validation.json`. |
| Navegador | Administrador y asociado recorren los módulos, guardan el perfil y cambian idioma bajo CSP; comprueba permisos, CORS y acceso nativo con un nonce ajeno. Resultado `browser-security.json`. |
| Resultado conjunto | `security-summary.json` conserva PASS, FAIL, WARN o ERROR; `source-manifest.json` identifica archivos e imagen ZAP. Una modificación de las fuentes durante el análisis invalida el resultado. |

En la exposición muestra primero el resultado conjunto, después ambos HTML nativos y la validación CSP. Explica qué rutas y operaciones se analizaron, qué alertas se corrigieron, qué no se analizó y la revisión exacta utilizada. Una prueba interrumpida o sin autenticación válida es ERROR; no se presenta como aprobada.

La política de `security/zap-rules.tsv` rechaza por defecto toda alerta de riesgo bajo, medio o alto. Las informativas se conservan. No se han añadido exclusiones de alertas para obtener un resultado favorable. Los códigos son **0 PASS**, **1 FAIL**, **2 WARN**, **3 ERROR**; un fallo tiene prioridad sobre una advertencia. El análisis activo ejecuta las reglas aplicables al WordPress/PHP, con intensidad LOW: primero XSS DOM con un solo hilo y navegador, después una pasada HTTP con ocho hilos. Ambas deben completar sus reglas; la unión debe completar las 52 reglas aplicables. El informe registra además la regla 40043 (Log4Shell) como no aplicable: su objetivo es Java/Log4j y este laboratorio ejecuta PHP, Apache y MariaDB, sin ese runtime ni un servicio OAST de Java. No se declara ejecutada ni se filtran sus posibles alertas. La intensidad reduce la cantidad de payloads, por lo que debe constar al presentar el alcance. Los tiempos máximos, la navegación del Client Spider limitada a 60 segundos y los límites de recursos evitan ejecuciones indefinidas. Se exige que cada regla declarada aplicable termine: una regla pendiente, un navegador XSS fallido, falta de recursos o una cola pasiva sin terminar producen ERROR, aunque ZAP indique 100 % en el estado general.

El alcance incluye las 14 rutas HTML ASCLA, acceso anónimo y páginas con una sesión administrativa comprobada antes y después del análisis. El navegador comprueba también al asociado. No incluye todo el administrador nativo de WordPress, rutas arbitrarias de otros temas/plugins, operaciones destructivas o de escritura ni integraciones reales de IA, correo, Calendar o Zoom. Las pruebas de integración/E2E y la revisión de permisos complementan este análisis; un resultado local sin alertas no certifica esos componentes ni demuestra ausencia universal de vulnerabilidades.

El recorrido DOM excluye explícitamente el acceso nativo de WordPress, su cierre de sesión y las acciones de contraseña: puede pulsar contenedores de botones y enlaces, y un simple contexto de inclusión no impide esas salidas. Las suites dedicadas verifican el acceso y sus permisos por separado. El escáner solo acepta el host temporal generado y utiliza una red interna de Docker. No admite un parámetro para atacar producción. Las reglas de fuerza bruta pueden bloquear correctamente la IP compartida del laboratorio: el fixture limpia exclusivamente sus contadores entre fases, con cuentas y entorno local validados. Nunca desactiva la protección ni limpia contadores durante los ataques.

### Configuración que debe llegar al entorno de destino

El plugin impone CSP con nonce por respuesta y `strict-dynamic`, bloquea eventos en atributos, añade SRI a los assets locales servidos por WordPress, restringe CORS de su API y protege cookies y formularios de acceso. `script-src` no autoriza dominios completos: los scripts iniciales requieren nonce y pueden cargar sus dependencias. Los marcos, imágenes y conexiones de Turnstile y los reproductores tienen permisos específicos; los marcos YouTube son `credentialless`. El código no incorpora una clave Turnstile de pruebas al producto.

Para revisar la alerta de JSONP de CSP Evaluator, consulta la cabecera de una respuesta nueva de `/login/` o de un módulo ASCLA. En `script-src` deben aparecer solo el nonce y `strict-dynamic`; YouTube puede seguir apareciendo en `frame-src` y en las directivas de imágenes. Verifica además un video y Turnstile en el navegador: una evaluación de texto de la política no demuestra compatibilidad. El botón «Actualizar datos de YouTube» ya no existe; los metadatos se obtienen automáticamente al guardar o procesar el recurso. Si cambias la política o el ZIP después de un análisis ZAP, conserva el resultado anterior con sus huellas y vuelve a ejecutarlo para atribuir un resultado completo al nuevo paquete.

`config/security.conf` y `config/security.ini` se montan en el WordPress de Docker para ocultar versiones de Apache/PHP, bloquear TRACE y listados de directorios y proteger también los archivos estáticos. **Estos archivos no se instalan con el ZIP del plugin**: el responsable del hosting debe aplicar su equivalente y reiniciar o recargar el servicio. En el proxy HTTPS del destino debe establecer HSTS también para recursos estáticos y errores, TLS 1.2 o superior y un certificado válido; el certificado temporal del laboratorio no se despliega. Comprueba compatibilidad de las políticas con cualquier plugin de terceros antes de extenderlas a todo `wp-admin`.

Tras instalar el plugin y aplicar la configuración del servidor, repite las verificaciones en QA con la revisión y el entorno reales autorizados. El chequeo independiente permite revisar las cabeceras sin realizar ataques:

```bash
node scripts/check-security-headers.cjs https://wordpress.ingsoftware.lat/login/ test-results/csp-qa.json
node scripts/check-server-exposure.cjs https://wordpress.ingsoftware.lat test-results/servidor-qa.json
```

En la recomprobación del 22 de octubre, QA mantiene **19/20** y la versión de Apache aparece también en login nativo, estáticos, API y 404. El acceso disponible es únicamente WordPress. Solicita al operador del hosting aplicar `ServerTokens Prod` y `ServerSignature Off`, validar con `apache2ctl configtest` y, solo si devuelve `Syntax OK`, recargar con `apache2ctl -k graceful`. Debe comprobar además el montaje de `config/security.conf` si utiliza este Compose y la respuesta pública si hay proxy. [Apache documenta](https://httpd.apache.org/docs/2.4/mod/core.html#servertokens) que `ServerTokens` se configura en el servidor, no en `.htaccess`. Repite ambos verificadores después y conserva su fecha; solo entonces se puede acreditar 20/20. Ocultar una versión no actualiza el servidor ni sustituye sus parches.

Para preparar las copias revisadas de una ejecución ya terminada, ejecuta `node scripts/build-security-delivery.cjs` y después los verificadores de entrega y documentación. Publica resúmenes en [pruebas/seguridad](pruebas/seguridad/dashboard.html), actualiza las huellas y enlaza el dashboard general; no vuelve a ejecutar el escaneo ni acredita Jenkins.

Los originales ZAP pueden contener URLs, nonces o cabeceras de sesión: permanecen privados en `test-results/`, ignorado por Git. Comparte únicamente un resumen revisado sin credenciales. Los reportes HTML/JSON y los accesos privados no deben subirse automáticamente a Git.

Referencias del fabricante: [Full Scan](https://www.zaproxy.org/docs/docker/full-scan/), [API Scan](https://www.zaproxy.org/docs/docker/api-scan/) y [API de ZAP](https://www.zaproxy.org/docs/api/). La integración YouTube utiliza el [reproductor oficial sobre un iframe existente](https://developers.google.com/youtube/iframe_api_reference).

## 12. Mantener la guía y las evidencias

Antes de compartir cambios documentales:

```bash
python3 scripts/check-docs.py
```

Si cambias la matriz, edita primero [requirements/ascla-analysis.json](requirements/ascla-analysis.json) y ejecuta `python3 scripts/check-docs.py --write-matrix`. Conserva textos/prioridades originales y justifica estados; una corrección documental no aumenta el porcentaje de cumplimiento.

Se mantienen los cinco MD principales y los tres MD de la [entrega de pruebas](pruebas/README.md), enlazados en [README](README.md). Usa rutas relativas y dominios del proyecto; evita carpetas personales, rutas absolutas de equipos, nombres de dispositivo e IP privadas en texto, enlaces y bloques de comandos. El verificador comprueba estas referencias, enlaces, anclas, exclusiones Git y sincronización de la matriz.

Los resúmenes JSON/JUnit revisados de `docs/evidence/` se permiten en Git. Los originales, accesos y archivos locales siguen en `test-results/`, ignorados. Por solicitud del responsable, `pruebas/` incluye copias publicables de la colección, reportes, videos y capturas, con las credenciales excluidas y la procedencia registrada. Valida la carpeta con `python3 scripts/verify-qa-delivery.py`. Este cambio documental no requiere otra versión ni reconstruir el plugin.

## Subtítulos públicos de YouTube con Supadata

ASCLA Core 1.11.0 permite consultar y conservar transcripciones completas de videos públicos de otros canales sin Google OAuth. La transcripción manual sigue disponible. Google Calendar conserva su conexión independiente.

### Configuración en WordPress

1. En Administración → Configuración → Inteligencia artificial, selecciona **Supadata · videos públicos sin Google OAuth**.
2. Introduce la **Supadata API Key**, indica el idioma preferido (`es` por defecto) y guarda. El campo vuelve vacío: dejarlo vacío conserva la clave. La opción de eliminar la clave permite retirarla.
3. Deja desactivado **Generar la transcripción si no existen subtítulos** para usar únicamente subtítulos existentes (`mode=native`). Si autorizas la generación, ASCLA solicita `mode=auto`: Supadata busca primero subtítulos existentes y solo genera si faltan. El idioma es una preferencia; el proveedor puede devolver otro idioma disponible.
4. Configura también el proveedor de IA y su clave para generar resúmenes reales. Publica un recurso con URL de YouTube: ASCLA obtiene los subtítulos y genera el resumen y la nota automáticamente, sin botones manuales. Los borradores no inician consultas. El proceso continúa si cierras la página; al terminar, la ficha abierta actualiza el resumen y conserva el comentario en curso. La transcripción queda guardada para el procesamiento y la edición autorizada; el desplegable **Transcripción completa** no aparece en la ficha. **Actualizar resumen y nota** permite generar una versión nueva a petición, reutilizando la fuente guardada. Durante la espera conserva el resumen anterior y el comentario sin publicar; evita trabajos duplicados y vuelve a habilitar la acción si hay un error.
5. Si hay un error, la ficha muestra el motivo y el responsable y los administradores reciben una notificación con acceso al recurso. Al corregir y guardar la configuración del proveedor, se reanudan los trabajos automáticos fallidos que sigan vigentes. Las esperas asíncronas y los límites temporales se gestionan sin avisos repetidos. La publicación no queda bloqueada por la preparación del resumen.

La configuración requiere `ascla_configure`. Consultar transcripciones y generar materiales requiere permisos de Ejecutivo o Administrador y membresía activa. La transcripción original conserva su carácter editorial: no se añade a la respuesta de otros asociados.

### Clave y política del servidor

La clave introducida en WordPress se cifra con AES-256-GCM usando las sales del sitio y se guarda en una opción sin carga automática. Los endpoints solo devuelven presencia y origen, nunca el valor. Tras cambiar las sales, puede ser necesario volver a introducirla. Protege también las copias de seguridad y el acceso administrativo.

Como alternativa, define `ASCLA_SUPADATA_KEY` en el entorno o en `wp-config.php` fuera del repositorio. Esa configuración tiene prioridad y bloquea la edición del campo. Si el operador fija explícitamente `ASCLA_EXTERNAL_SECRETS_ONLY=1`, ASCLA respeta esa política y exige la clave externa. Sin ese bloqueo explícito, la clave de Supadata se puede configurar cifrada desde WordPress sin habilitar el almacenamiento de otras claves de servicio en instalaciones nuevas.

### Videos largos, caché y errores

- El endpoint utilizado es `GET https://api.supadata.ai/v1/transcript`, con autenticación `x-api-key` y segmentos temporales (`text=false`). No se establece un recorte por minutos ni se usa el endpoint de extracción de datos.
- Ante HTTP 202, se conserva `jobId` y se consulta `/transcript/{jobId}` desde la cola. `queued` y `active` mantienen la tarea pendiente sin agotar sus intentos. Al recibir `completed`, se guarda toda la respuesta y continúa la tarea original. La consulta no crea nuevas solicitudes de transcripción mientras exista un trabajo activo.
- La consulta se programa cada 30 segundos, con espera adicional ante límites y fallos transitorios. Es necesario que WP-Cron funcione o que el servidor ejecute los eventos pendientes periódicamente. Supadata conserva los resultados de trabajos durante una hora después de completarlos: el trabajador debe seguir activo aunque el usuario cierre la página.
- La transcripción guardada en el recurso se reutiliza sin nuevas llamadas. La caché de resultados dura siete días y se comparte por video, idioma y credencial del sitio. Una transcripción manual tiene prioridad. Cambiar el video elimina su transcripción automática; una respuesta tardía no sobrescribe una edición manual ni otro video.
- Se gestionan 206 sin subtítulos, 401 clave inválida, 402 plan insuficiente, 403 restricciones, 404 video o trabajo no disponible, 429 y errores 5xx. Los límites de frecuencia respetan `Retry-After`; el agotamiento de créditos detiene la tarea para revisión. Los fallos transitorios tienen un máximo de ocho intentos; un trabajo remoto no resuelto tras 24 horas requiere revisión.
- Si no se confirma la respuesta inicial por un fallo de conexión, ASCLA evita repetirla automáticamente: podría haber consumido créditos. Revisa el panel de Supadata antes de usar **Reintentar**. Un trabajo remoto fallido o expirado puede iniciar una solicitud nueva mediante ese reintento explícito o al guardar una corrección de la configuración. La respuesta inicial de consumo incierto queda excluida de la reanudación automática.
- ASCLA admite hasta 2.000.000 de caracteres por transcripción y 16 MiB por respuesta del proveedor. Si se supera un límite o hay segmentos inválidos, falla la operación completa: no se guarda un fragmento como si fuera una transcripción completa. El límite anterior de 100.000 caracteres se ha retirado. El generador recibe la fuente completa; si el modelo de IA elegido no admite su tamaño, su error no elimina ni recorta el original guardado.

Solo se envían a Supadata la URL pública del video y las opciones de consulta. Los comentarios, perfiles y datos privados de ASCLA no se envían a este servicio. La generación posterior de resúmenes sigue usando el proveedor de IA configurado y la política editorial existente.

Los videos privados, restringidos, transmisiones todavía en curso y las restricciones del plan de Supadata pueden impedir una transcripción. Consulta las condiciones y costes actuales en la [documentación oficial de transcripciones](https://docs.supadata.ai/get-transcript) y la [referencia de errores y cuotas](https://docs.supadata.ai/errors/limit-exceeded).

### Verificación de esta entrega

Se comprueba con transcripciones sintéticas de más de dos horas, incluidas sus últimas palabras, y con respuestas nativas/asíncronas, errores, caché, permisos y el flujo existente de resumen. Las pruebas locales simulan las respuestas HTTP de Supadata. El usuario configurará la clave después: esta entrega no acredita una llamada real con su cuenta ni un despliegue remoto. La evidencia del proveedor se conserva en [supadata1110.json](docs/evidence/release-1.11.0/supadata1110.json). Para el flujo automático actual, ejecuta `node tests/video-auto1110.cjs` con el fixture HTTP simulado `tests/supadata1110-fixture.php` y WP-Cron activo; verifica publicación sin botones, espera asíncrona, navegación de vuelta, comentario conservado, notificación de error y reanudación al corregir la clave. La evidencia actual se registra en [readers-auto1110.json](docs/evidence/release-1.11.0/readers-auto1110.json).

## Error de instalación por upload_max_filesize · 1.11.0

Tras la limpieza de código y catálogos, el paquete completo también queda por debajo de 2 MiB y conserva los PO editables. Para una variante todavía más pequeña, ejecutar `python scripts/build-installer.py` después de construir y verificar el paquete completo. La variante `dist/ascla-core-instalable.zip` ocupa menos de 2 MiB: solo omite los dos archivos fuente de traducción `.po`. Conserva los catálogos compilados `.mo`, `en.json` y todos los archivos de ejecución sin cambiar sus bytes. Los fuentes editables permanecen en el repositorio y el ZIP completo. [Verificación del paquete de instalación](docs/evidence/release-1.11.0/upload-limit1110.json).

Si el servidor usa un límite inferior o el error persiste, el operador del hosting debe revisar el PHP que atiende WordPress; puede establecer `upload_max_filesize = 8M` y `post_max_size = 16M` desde su panel o configuración PHP. El proyecto Docker ya permite 5M/8M; cambiar el repositorio no modifica un hosting externo. No debe intentarse corregir una subida anterior a la instalación desde el código del plugin. [Referencia oficial de PHP](https://www.php.net/manual/en/ini.core.php#ini.upload-max-filesize). No se han modificado ajustes del servidor remoto.

## Presentación editorial del resumen · 1.11.0

Abrir un recurso con resumen, nota técnica, conclusiones, referencias, conceptos, palabras clave y cápsulas. Comprobar párrafos completos y letra legible en móvil/escritorio, claro/oscuro. Las conclusiones deben estar numeradas, los marcos ocupar todo el ancho con etiquetas y las normativas tener su propio apartado. Los conceptos y etiquetas deben ajustarse al ancho sin ocultar entradas. Verificar también un resumen breve sin otros apartados, una lista de más de veinte conceptos y una etiqueta extensa. La lectura no debe ejecutar HTML del contenido ni alterar el comentario en curso. Se trata de presentación del material existente: no modifica los textos guardados ni vuelve a consultar a la IA o Supadata. La evidencia específica se registra en [summary1110.json](docs/evidence/release-1.11.0/summary1110.json).


## Regeneración y presentación del resumen

El generador pide entre tres y cinco párrafos, con unas 220 a 350 palabras cuando el contenido lo permite, sin rellenar videos breves ni inventar datos. La nueva extensión se aplica a nuevas generaciones y a «Actualizar resumen y nota»; no modifica automáticamente los resúmenes ya guardados. Marcos de trabajo ocupa todo el ancho y presenta etiquetas como Conceptos importantes; Normativas conserva una sección propia.

En WordPress local desechable ejecuta `tests/php/VideoPreparationTest.php` junto a las regresiones de Supadata, permisos, cola y ciclo editorial. Para navegador usa `node tests/summary-refresh1110.cjs` con los fixtures locales `tests/summary-refresh1110-fixture.php` y `tests/supadata1110-fixture.php`, WP-Cron y `ASCLA_AXE_PATH`. Comprueba regeneración, reutilización de fuente, clics duplicados, versión previa conservada ante errores, comentario en curso, acceso exclusivo editorial, transcripción oculta y marcos adaptables en ambos temas. La IA y Supadata se simulan: no acredita una llamada real a la cuenta del usuario. [Evidencia actual](docs/evidence/release-1.11.0/summary-refresh1110.json).


## Optimización de código y traducciones

Los archivos de recursos y las clases con carga dinámica se revisan antes de eliminarlos. Los adaptadores de LinkedIn y X conservan sus bloqueos explícitos y pruebas; las migraciones, avisos de licencia y dependencias del visor PDF permanecen. Se retiran funciones de interfaz sin puntos de entrada y traducciones antiguas confirmadas, conservando las claves construidas dinámicamente.

`python scripts/translations.py` mantiene los PO editables y compila MO compactos: una traducción igual a su clave utiliza la respuesta original de gettext; una traducción personalizada sigue incluida. El compilador comprueba con GNU gettext todas las entradas originales, incluidas las omitidas. Para comparar con una entrega anterior en WordPress usa `tests/php/CodeOptimizationTest.php` con los snapshots locales en `/tmp` preparados por el laboratorio; ejecuta también las regresiones de contenido, formularios, cola, Supadata y seguridad. `node tests/code-optimization1110.cjs` revisa el flujo vigente de resumen y las consultas de traducción en español e inglés, incluidas pantallas de administración y validaciones dinámicas. [Evidencia de optimización](docs/evidence/release-1.11.0/code-optimization1110.json).
