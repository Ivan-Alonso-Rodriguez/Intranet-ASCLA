# Guía práctica de QA, SonarQube y UAT

Versión documentada: **1.10.8**, esquema **15**. Este proyecto WordPress utiliza un flujo manual, **sin Jenkins**. Aquí se explica cómo ejecutar las herramientas, revisar sus resultados e instalar la entrega. No se exige cobertura del 100 %; las pruebas y el Quality Gate deben satisfacer los criterios acordados para la entrega.

Todos los comandos se ejecutan **desde la raíz del repositorio**, en una terminal Bash; en Windows, usa WSL con Docker Desktop operativo e integrado con esa distribución. Las rutas de los ejemplos son relativas al repositorio. Los dominios publicados corresponden a servicios del proyecto; `localhost` y `127.0.0.1` son ejemplos genéricos de acceso local.

## 1. Elegir qué vas a comprobar

| Objetivo | Comando principal | Dónde actúa | Qué revisar |
|---|---|---|---|
| Verificar documentación | `python3 scripts/check-docs.py` | Repositorio | Mensaje `Documentation OK`. |
| Probar PHP e interfaz y generar cobertura | `BRANCH_NAME=qa bash scripts/quality-ci.sh` | WordPress Docker desechable | Pruebas, JUnit, Clover y LCOV en `coverage/`. |
| Analizar código con SonarQube | `bash scripts/sonar-manual.sh qa` | Fuentes y reportes anteriores | Análisis de ASCLA-QA y su Quality Gate. |
| Probar integración y E2E con Bruno/Playwright | `bash scripts/acceptance-tests.sh` | WordPress desechable; admite también el QA publicado | Reporte HTML, peticiones, comprobaciones, videos y capturas. |
| Preparar el plugin | `python3 scripts/build.py` y `python3 scripts/verify-package.py` | Fuentes del plugin | ZIP, SHA-256 y manifiesto. |

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

El verificador puede actualizar el [manifiesto público](docs/evidence/release-1.10.8/package.json); incorpora ese cambio al commit de la entrega antes de generar cobertura. Revisa y agrega los archivos correspondientes, confirma el commit y comparte `development` según el procedimiento del equipo.

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

Si un recorrido falla con `Timeout`, revisa `test-results/e2e.json` y las capturas `test-results/failure-admin.png` y `test-results/failure-member.png`. Contrasta la sección y los controles visibles con la navegación del caso. El reporte JavaScript se guarda también al fallar y puede ser parcial: su porcentaje no acredita que terminó la suite. SonarQube rechaza esa ejecución porque todavía no existe `coverage/source-revision`; corrige el caso y repite el flujo completo sobre un commit limpio.

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

### Leer y guardar el resultado

1. Abre el enlace al análisis que devuelve el scanner y verifica **ASCLA-QA**, fecha y revisión analizada.
2. Comprueba el **Quality Gate** y abre cada condición incumplida si aparece fallido. Subir los archivos no basta para aprobar el gate.
3. Revisa incidencias, seguridad, duplicación y cobertura. Evalúa los hallazgos que requieren revisión humana; no los cierres solo para cambiar el indicador.
4. Registra enlace al análisis, commit, fecha, estado y condiciones evaluadas. No captures el token.

Los umbrales efectivos son los configurados en SonarQube. Si falla una condición de cobertura, consulta ese umbral y las líneas afectadas; no se requiere convertir todo el proyecto a 100 %. Cambiar las condiciones debe ser una decisión explícita del equipo, no una forma de ocultar fallos.

### Si algo falla

| Situación | Cómo continuar |
|---|---|
| Docker no conecta | Abre Docker Desktop, comprueba la integración WSL y repite `docker version`. |
| Rama incorrecta | Conserva tus cambios y sitúate en `qa`; el argumento del scanner no cambia de rama. |
| Cambios sin commit | Revisa y confirma los cambios intencionados; repite `quality-ci.sh` sobre esa revisión limpia. |
| Falta `source-revision` o no coincide | Repite la suite sobre el commit actual y sin ediciones durante la ejecución. No crees ni modifiques ese archivo a mano. |
| Faltan Clover, JUnit o LCOV | Revisa dónde se interrumpió `quality-ci.sh`, resuelve el fallo y vuelve a ejecutarlo. |
| Token rechazado | Comprueba vigencia y permiso sobre ASCLA-QA; facilita el token al repetir la entrada oculta. |
| Quality Gate fallido | Revisa las condiciones, corrige lo que corresponda, guarda el nuevo commit y repite pruebas y scanner. |
| Se agota la espera del gate | Revisa en SonarQube si el procesamiento sigue pendiente o falló. No registres aprobación hasta conocer el resultado. |

Si habías exportado `SONAR_TOKEN` en la terminal padre, ejecuta `unset SONAR_TOKEN` allí al terminar. El script solo puede limpiar su propio proceso.

<a id="pruebas-http-e2e"></a>

## 5. Ejecutar integración y E2E con Bruno y Playwright

Estas pruebas complementan PHPUnit: verifican HTTP, cookies y nonces de WordPress, flujos de negocio y acciones reales de interfaz. El ejecutor configura cuentas y sesiones; no necesitas preparar a mano las variables de Bruno.

| Grupo | Qué comprueba la colección actual |
|---|---|
| Bruno: integración | 10 peticiones y 15 comprobaciones: acceso, identidad, perfil, conservación tras rechazo, Directorio paginado y fechas inválidas. |
| Bruno: E2E backend | 23 peticiones y 38 comprobaciones encadenadas: conexión, aceptación, chat, disolución y lectura histórica; borrador, publicación, inscripción, reprogramación y reconfirmación de evento. |
| Playwright: E2E frontend | Tres recorridos de interfaz: perfil persistente; conexión/chat/historial; borrador/publicación/inscripción. Guarda videos y capturas por sesión. |

Desde la raíz del repositorio:

```bash
bash scripts/acceptance-tests.sh
```

El modo predeterminado crea otro WordPress desechable y cinco cuentas sintéticas, ejecuta las [colecciones Bruno](tests/http) y los [recorridos Playwright](tests/acceptance-ui.cjs), limpia sus datos y retira su propio entorno. No detiene los otros proyectos Docker. Las herramientas reutilizan un volumen de dependencias separado de WordPress.

La terminal indica una carpeta `test-results/acceptance-FECHA-ID`. Abre su `index.html` como se explica en la sección 7. Un fallo de prueba o de limpieza devuelve una salida distinta de cero. Este comando no genera cobertura para SonarQube ni acredita todos los escenarios del PDF.

## 6. Probar el QA publicado teniendo solo administración de WordPress

Este recorrido corresponde al entorno de pruebas [wordpress.ingsoftware.lat](https://wordpress.ingsoftware.lat/). Requiere permiso para instalar plugins en WordPress y Docker en el equipo que ejecuta las pruebas; **no requiere SSH ni WP-CLI en el servidor**. Administración ASCLA y administración técnica de WordPress tienen permisos distintos.

1. Ejecuta `python3 scripts/build.py` y `python3 scripts/verify-package.py`. Conserva el SHA-256 del ZIP que vas a evaluar.
2. En WordPress, abre **Plugins → Añadir plugin → Subir plugin**. Selecciona `dist/ascla-core.zip` y reemplaza la versión instalada. Comprueba que ASCLA Core **1.10.8** está activo; el mismo número de versión puede contener correcciones distintas, por eso debes registrar también el paquete.
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

El archivo de acceso debe corresponder a ese sitio, al auxiliar y a Core 1.10.8. El runner rechaza cuentas ajenas al lote. No instala el core ni cambia ramas; prueba lo que ya está instalado. Las pruebas locales no sustituyen este paso.

### Alternativa para quien administra un QA mediante Compose

Solo en el host que gestiona ese proyecto, con su configuración privada y Core 1.10.8 ya activo:

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
git merge --ff-only qa
git push origin uat
docker network inspect proxy_net >/dev/null 2>&1 || docker network create proxy_net
BRANCH_NAME=uat bash scripts/quality-ci.sh
bash scripts/sonar-manual.sh uat
```

El scanner selecciona **ASCLA-UAT** y solicita su token. Aplica las mismas comprobaciones de revisión limpia y reportes del commit actual. Instala manualmente en UAT el paquete de esa revisión y registra su SHA-256. Las presentaciones deben corresponder a `uat`; una repetición local no acredita su instalación remota.

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
5. Para microeventos, comprueba propuesta, diagnóstico de candidatos, aprobación, publicación, cancelación y archivo/restauración del historial. Los grupos deben cumplir consentimiento, mínimos y afinidad antes de aprobar; registra el motivo del diagnóstico cuando no se genere una propuesta.

### Seguridad, rendimiento y copias

Con el responsable del entorno, comprueba HTTPS/TLS, Wordfence/Akismet, Turnstile, bloqueos acordados y logs privados con rotación. Mide 50 asociados simultáneos y los umbrales de navegación, mensajería y estadísticas del PDF; las suites funcionales no equivalen a una prueba de carga.

Verifica copias diarias de base y archivos esenciales, retención mínima de siete días y una restauración ensayada, incluidas las sales para descifrar datos privados. Conserva historial de disponibilidad y mantenimientos. Registra fecha, revisión, destino, responsable y resultado de cada control.

## 11. Mantener la guía y las evidencias

Antes de compartir cambios documentales:

```bash
python3 scripts/check-docs.py
```

Si cambias la matriz, edita primero [requirements/ascla-analysis.json](requirements/ascla-analysis.json) y ejecuta `python3 scripts/check-docs.py --write-matrix`. Conserva textos/prioridades originales y justifica estados; una corrección documental no aumenta el porcentaje de cumplimiento.

Se mantienen únicamente los cinco MD enlazados en [README](README.md). Usa rutas relativas y dominios del proyecto; evita carpetas personales, rutas absolutas de equipos, nombres de dispositivo e IP privadas en texto, enlaces y bloques de comandos. El verificador comprueba estas referencias, enlaces, anclas, exclusiones Git y sincronización de la matriz.

Los resúmenes JSON/JUnit revisados de `docs/evidence/` se permiten en Git. Reportes completos, videos, capturas, accesos y archivos locales siguen en `test-results/`, ignorados. Documenta esos artefactos como rutas en código, sin enlaces que aparenten estar publicados. Este cambio documental no requiere otra versión ni reconstruir el plugin.
